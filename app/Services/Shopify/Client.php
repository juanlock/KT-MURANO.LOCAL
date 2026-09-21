<?php

namespace App\Services\Shopify;

/**
 * Shopify Admin REST client.
 *
 * Built on the same no-hang curl_multi approach as the WooCommerce client
 * (App\Services\WooCommerce\Client) so it behaves identically on Windows, but
 * with the three things Shopify does differently:
 *
 *  1. Auth is a header token, not query-string credentials — so the token never
 *     lands in a URL, an access log or an exception message.
 *  2. Rate limiting is a leaky bucket (40 calls, drains 2/sec). Every response
 *     reports the current fill via X-Shopify-Shop-Api-Call-Limit, so the client
 *     slows itself down as the bucket fills instead of waiting to be refused.
 *  3. Pagination is a cursor in the Link header, not a page number. Page numbers
 *     skip and repeat records when the underlying set changes mid-walk; the
 *     cursor does not.
 */
class Client
{
    private string $shopDomain;

    private string $token;

    private string $apiVersion;

    /** Bucket fill from the last response, e.g. 32 of 40. */
    private int $bucketUsed = 0;

    private int $bucketCapacity = 40;

    public function __construct(string $shopDomain, string $token, ?string $apiVersion = null)
    {
        $this->shopDomain = $this->normaliseDomain($shopDomain);
        $this->token = trim($token);
        $this->apiVersion = $apiVersion ?: '2024-10';
    }

    private function normaliseDomain(string $input): string
    {
        $value = preg_replace('#^https?://#i', '', trim($input));
        $value = explode('/', (string) $value)[0];

        return strtolower(trim((string) $value));
    }

    public function shopDomain(): string
    {
        return $this->shopDomain;
    }

    // ------------------------------------------------------------ requests --

    public function get(string $endpoint, array $query = [], int $timeout = 30)
    {
        return $this->request('GET', $endpoint, $query, null, $timeout);
    }

    public function post(string $endpoint, array $body = [], int $timeout = 30)
    {
        return $this->request('POST', $endpoint, [], $body, $timeout);
    }

    public function put(string $endpoint, array $body = [], int $timeout = 30)
    {
        return $this->request('PUT', $endpoint, [], $body, $timeout);
    }

    public function delete(string $endpoint, array $query = [], int $timeout = 30)
    {
        return $this->request('DELETE', $endpoint, $query, null, $timeout);
    }

    /** Cheap call that proves the domain and token are both good. */
    public function testConnection(): array
    {
        try {
            $res = $this->get('shop', ['fields' => 'id,name,email,currency,domain,myshopify_domain']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $res->successful()) {
            return [
                'ok' => false,
                'status' => $res->status(),
                'error' => $this->explain($res),
            ];
        }

        $shop = $res->json()['shop'] ?? [];

        return [
            'ok' => true,
            'shop' => [
                'id' => $shop['id'] ?? null,
                'name' => $shop['name'] ?? null,
                'email' => $shop['email'] ?? null,
                'currency' => $shop['currency'] ?? null,
                'domain' => $shop['domain'] ?? null,
                'myshopify_domain' => $shop['myshopify_domain'] ?? null,
            ],
            'api_version' => $this->apiVersion,
        ];
    }

    /**
     * Turn a failed response into something a human can act on. Shopify's own
     * messages are good; the value added here is naming the two failures people
     * actually hit — a bad token and a missing scope — instead of showing 401.
     */
    public function explain($res): string
    {
        $status = $res->status();
        $json = $res->json();

        if ($status === 401) {
            return 'Shopify rejected the access token (401). Check the token, and that it belongs to this shop.';
        }
        if ($status === 403) {
            return 'The token is valid but lacks the required scope (403). Grant the matching read/write scope in the app configuration.';
        }
        if ($status === 404) {
            return 'Shopify returned 404 — check the shop domain and the API version.';
        }
        if ($status === 423) {
            return 'The shop is locked or frozen (423).';
        }

        if (is_array($json)) {
            if (isset($json['errors'])) {
                return is_string($json['errors'])
                    ? $json['errors']
                    : json_encode($json['errors'], JSON_UNESCAPED_SLASHES);
            }
            if (isset($json['error'])) {
                return (string) $json['error'];
            }
        }

        $body = trim($res->body());

        return $body !== '' ? mb_substr($body, 0, 500) : ('HTTP '.$status);
    }

    // ---------------------------------------------------------- pagination --

    /**
     * One page of a collection, plus the cursor for the next one.
     *
     * Returns ['items' => [...], 'next' => ?string, 'ok' => bool, 'error' => ?string].
     * Pass the returned `next` back in as $pageInfo to continue. Shopify forbids
     * sending any other filter alongside page_info, so this drops them — sending
     * them anyway is a 400 that reads like a bug in your query.
     */
    public function page(string $endpoint, string $collectionKey, array $query = [], ?string $pageInfo = null, int $limit = 250): array
    {
        $query = $pageInfo
            ? ['limit' => $limit, 'page_info' => $pageInfo]
            : array_merge($query, ['limit' => $limit]);

        try {
            $res = $this->get($endpoint, $query);
        } catch (\Throwable $e) {
            return ['ok' => false, 'items' => [], 'next' => null, 'error' => $e->getMessage()];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'items' => [], 'next' => null, 'error' => $this->explain($res)];
        }

        $json = $res->json();

        return [
            'ok' => true,
            'items' => is_array($json) && isset($json[$collectionKey]) && is_array($json[$collectionKey])
                ? $json[$collectionKey]
                : [],
            'next' => $this->nextPageInfo($res->header('link')),
            'error' => null,
        ];
    }

    /** Pull the page_info cursor out of a Link header's rel="next" entry. */
    public function nextPageInfo(?string $linkHeader): ?string
    {
        if (! $linkHeader) {
            return null;
        }

        foreach (explode(',', $linkHeader) as $part) {
            if (! str_contains($part, 'rel="next"')) {
                continue;
            }
            if (preg_match('/<([^>]+)>/', $part, $m)) {
                $qs = parse_url($m[1], PHP_URL_QUERY);
                parse_str((string) $qs, $params);
                if (! empty($params['page_info'])) {
                    return (string) $params['page_info'];
                }
            }
        }

        return null;
    }

    /**
     * Total number of records, when the endpoint offers a /count. Used only to
     * show a meaningful progress bar, so a failure here is not an error.
     */
    public function count(string $endpoint, array $query = []): ?int
    {
        try {
            $res = $this->get($endpoint.'/count', $query, 20);
            if (! $res->successful()) {
                return null;
            }
            $json = $res->json();

            return isset($json['count']) ? (int) $json['count'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // --------------------------------------------------------------- guts --

    private function buildUrl(string $endpoint, array $query): string
    {
        $endpoint = ltrim($endpoint, '/');
        if (! str_ends_with($endpoint, '.json')) {
            $endpoint .= '.json';
        }

        $url = 'https://'.$this->shopDomain.'/admin/api/'.$this->apiVersion.'/'.$endpoint;
        if (! empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        return $url;
    }

    /**
     * Stay ahead of the leaky bucket: once it is more than ~80% full, pause for
     * roughly the time it takes to drain a slot (it drains 2/sec). Waiting a
     * beat here is cheaper than a 429 and the retry it costs.
     */
    private function throttle(): void
    {
        if ($this->bucketCapacity <= 0) {
            return;
        }

        $fill = $this->bucketUsed / $this->bucketCapacity;
        if ($fill >= 0.8) {
            usleep(($fill >= 0.95 ? 1000 : 500) * 1000);
        }
    }

    private function readBucket($res): void
    {
        $header = $res->header('x-shopify-shop-api-call-limit');
        if (! $header || ! str_contains($header, '/')) {
            return;
        }

        [$used, $capacity] = array_map('intval', explode('/', $header, 2));
        $this->bucketUsed = max(0, $used);
        $this->bucketCapacity = $capacity > 0 ? $capacity : 40;
    }

    private function request(string $method, string $endpoint, array $query, ?array $body, int $timeout)
    {
        $url = $this->buildUrl($endpoint, $query);

        $maxRetries = 3;
        $attempt = 0;

        while (true) {
            $attempt++;
            $this->throttle();

            $res = $this->exec($method, $url, $body, $timeout, 10);
            $this->readBucket($res);

            if ($res->successful() || $attempt > $maxRetries) {
                return $res;
            }

            $status = $res->status();
            $errno = $res->errno();

            // 429 tells us exactly how long to wait; obey it rather than guessing.
            if ($status === 429) {
                $retryAfter = (float) ($res->header('retry-after') ?? 2);
                usleep((int) (max(0.5, min(10, $retryAfter)) * 1000000));

                continue;
            }

            $transient = $errno !== 0 || in_array($status, [408, 500, 502, 503, 504], true);
            if (! $transient) {
                return $res;
            }

            // Exponential backoff with jitter.
            $sleepMs = min(4000, 400 * (2 ** ($attempt - 1))) + random_int(0, 150);
            usleep($sleepMs * 1000);
        }
    }

    /**
     * curl_multi with a hard wall-clock deadline. A plain curl_exec can wedge
     * indefinitely on Windows when a DNS or TLS handshake stalls, which would
     * hang the whole sync request; this cannot.
     */
    private function exec(string $method, string $url, ?array $body, int $timeoutSeconds, int $connectTimeoutSeconds)
    {
        $timeoutSeconds = max(1, $timeoutSeconds);
        $connectTimeoutSeconds = max(1, $connectTimeoutSeconds);
        $deadlineMs = $timeoutSeconds * 1000;

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Shopify-Access-Token: '.$this->token,
            'User-Agent: Stocky-ERP-Shopify-Integration',
        ];

        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw new \RuntimeException('JSON encode failed: '.json_last_error_msg());
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_TIMEOUT_MS => $deadlineMs,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutSeconds * 1000,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => min(15, $timeoutSeconds),
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $start = microtime(true);
        $running = null;
        $timedOut = false;

        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($mrc > CURLM_OK) {
                break;
            }
            if ((microtime(true) - $start) * 1000 >= $deadlineMs) {
                $timedOut = true;
                break;
            }
            if (curl_multi_select($mh, 0.2) === -1) {
                usleep(100000);
            }
        } while ($running > 0);

        $raw = (string) curl_multi_getcontent($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $errno = (int) curl_errno($ch);
        $error = (string) curl_error($ch);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        curl_multi_close($mh);

        if ($timedOut && $errno === 0) {
            $errno = CURLE_OPERATION_TIMEOUTED;
            $error = 'Request exceeded the '.$timeoutSeconds.'s deadline';
        }

        $headerStr = $headerSize > 0 ? substr($raw, 0, $headerSize) : '';
        $bodyStr = $headerSize > 0 ? substr($raw, $headerSize) : $raw;

        $parsed = [];
        if ($headerStr !== '') {
            $blocks = preg_split("/\r\n\r\n/", trim($headerStr));
            $last = is_array($blocks) && count($blocks) ? $blocks[count($blocks) - 1] : $headerStr;
            foreach (preg_split("/\r\n/", (string) $last) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line === '' || stripos($line, 'HTTP/') === 0) {
                    continue;
                }
                $pos = strpos($line, ':');
                if ($pos === false) {
                    continue;
                }
                $k = strtolower(trim(substr($line, 0, $pos)));
                if ($k === '') {
                    continue;
                }
                $parsed[$k][] = trim(substr($line, $pos + 1));
            }
        }

        return new Response($status, $bodyStr, $parsed, $errno, $error, $durationMs);
    }
}
