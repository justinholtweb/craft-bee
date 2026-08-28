<?php

namespace justinholtweb\bee\errors;

use Exception;
use Throwable;

/**
 * A Recombee request that did not succeed.
 *
 * Carries the HTTP status and the response body, because Recombee's error bodies are the only
 * place it says *which* property or item was wrong — losing them turns a five-second fix into an
 * afternoon.
 */
class ApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $body = null,
        public readonly ?string $path = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * 4xx means Bee sent something wrong and sending it again will not help.
     */
    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /**
     * Recombee answers 409 when an interaction with the same user, item and timestamp already
     * exists. That is a duplicate, not a failure — a retried request landing twice is exactly what
     * it is there to prevent.
     */
    public function isDuplicate(): bool
    {
        return $this->status === 409;
    }
}
