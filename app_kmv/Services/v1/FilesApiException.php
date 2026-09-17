<?php

namespace App\Services\v1;

use RuntimeException;

class FilesApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly mixed $response = null
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }
}
