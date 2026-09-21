<?php

namespace App\Services;

use RuntimeException;

/**
 * A wallet operation failure (insufficient balance, invalid gift card, frozen
 * wallet, ...) that maps cleanly to a JSON HTTP response.
 */
class WalletException extends RuntimeException
{
    public int $status;

    public array $extra;

    public function __construct(string $message, int $status = 422, array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->extra = $extra;
    }

    public function toResponse()
    {
        return response()->json(array_merge(['error' => $this->getMessage()], $this->extra), $this->status);
    }
}
