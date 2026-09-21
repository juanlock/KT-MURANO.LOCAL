<?php

namespace App\Services\Shopify;

/**
 * A single HTTP response.
 *
 * A named class rather than the anonymous one the WooCommerce client returns,
 * so the sync service can type-hint it and so a `Link` header (which Shopify
 * uses for pagination and Woo does not) is reachable in a documented way.
 */
class Response
{
    private int $status;

    private string $body;

    /** @var array<string, string[]> lower-cased header name => values */
    private array $headers;

    private int $errno;

    private string $error;

    private int $durationMs;

    public function __construct(int $status, string $body, array $headers, int $errno, string $error, int $durationMs)
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
        $this->errno = $errno;
        $this->error = $error;
        $this->durationMs = $durationMs;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Decoded JSON, or an empty array when the body is not JSON. */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        $key = strtolower(trim($name));

        return isset($this->headers[$key][0]) ? (string) $this->headers[$key][0] : null;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function errno(): int
    {
        return $this->errno;
    }

    public function error(): ?string
    {
        return $this->error !== '' ? $this->error : null;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }
}
