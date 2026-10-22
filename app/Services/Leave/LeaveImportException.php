<?php

namespace App\Services\Leave;

use RuntimeException;
use Throwable;

class LeaveImportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
