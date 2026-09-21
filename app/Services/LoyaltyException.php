<?php

namespace App\Services;

use RuntimeException;

/**
 * A loyalty operation failure (not enough points, reward unavailable, not
 * eligible, ...) that maps cleanly to a JSON HTTP response.
 */
class LoyaltyException extends RuntimeException
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
