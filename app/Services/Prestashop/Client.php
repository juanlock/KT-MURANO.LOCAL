<?php

namespace App\Services\Prestashop;

use App\Models\PrestashopSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the PrestaShop Webservice ({store}/api).
 *
 * Auth is HTTP Basic with the webservice key as the username and an empty
 * password. PrestaShop's quirk: READS can return JSON (output_format=JSON),
 * but WRITES must send XML bodies — hence postXml()/putXml().
 */
class Client
{
    public function __construct(private PrestashopSetting $settings)
    {
    }

    public static function make(?PrestashopSetting $settings = null): self
    {
        return new self($settings ?: PrestashopSetting::current());
    }

    public function get(string $path, array $query = []): Response
    {
        return Http::withBasicAuth($this->key(), '')
            ->acceptJson()
            ->timeout(30)
            ->get($this->url($path), $query + ['output_format' => 'JSON']);
    }

    public function postXml(string $path, string $xml): Response
    {
        return Http::withBasicAuth($this->key(), '')
            ->withBody($xml, 'text/xml')
            ->timeout(30)
            ->post($this->url($path));
    }

    public function putXml(string $path, string $xml): Response
    {
        return Http::withBasicAuth($this->key(), '')
            ->withBody($xml, 'text/xml')
            ->timeout(30)
            ->put($this->url($path));
    }

    public function delete(string $path): Response
    {
        return Http::withBasicAuth($this->key(), '')
            ->timeout(30)
            ->delete($this->url($path));
    }

    /** Human-readable error from a PrestaShop JSON or XML error payload. */
    public static function error(Response $response): string
    {
        $json = $response->json();
        if (is_array($json) && ! empty($json['errors'])) {
            $messages = array_filter(array_map(
                fn ($e) => is_array($e) ? (string) ($e['message'] ?? '') : (string) $e,
                (array) $json['errors']
            ));
            if ($messages) {
                return implode('; ', array_slice($messages, 0, 3));
            }
        }

        $body = (string) $response->body();
        if (str_contains($body, '<error>')) {
            $xml = self::parseXml($body);
            $message = $xml['errors']['error']['message'] ?? null;
            if (is_array($message)) {
                $message = $message[0] ?? null;
            }
            if ($message) {
                return (string) $message;
            }
        }

        return 'HTTP '.$response->status();
    }

    /** Parse an XML body into a nested array (write responses are XML-only). */
    public static function parseXml(string $body): array
    {
        try {
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
            libxml_use_internal_errors($prev);
            if ($xml === false) {
                return [];
            }

            return (array) json_decode((string) json_encode($xml), true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function key(): string
    {
        $key = (string) $this->settings->api_key;
        if ($key === '') {
            throw new RuntimeException('PrestaShop is not configured — add the store URL and webservice key first.');
        }

        return $key;
    }

    private function url(string $path): string
    {
        $base = rtrim((string) $this->settings->store_url, '/');
        if ($base === '') {
            throw new RuntimeException('PrestaShop is not configured — add the store URL and webservice key first.');
        }

        return $base.'/api/'.ltrim($path, '/');
    }
}
