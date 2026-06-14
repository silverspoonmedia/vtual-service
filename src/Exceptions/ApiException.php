<?php

namespace Silverspoonmedia\VtualService\Exceptions;

use Exception;

/**
 * Replaces the upstream `dieWithJsonMessage()` helper.
 *
 * Instead of killing the PHP process with a JSON body, we throw an exception
 * that the HTTP layer renders as a YouTube-Data-API-v3-shaped error response,
 * while programmatic callers can catch it normally.
 */
class ApiException extends Exception
{
    public function __construct(string $message, protected int $apiCode = 400)
    {
        parent::__construct($message, $apiCode);
    }

    public function apiCode(): int
    {
        return $this->apiCode;
    }

    /**
     * The error payload, matching upstream `dieWithJsonMessage()` shape.
     *
     * @return array{error: array{code: int, message: string}}
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->apiCode,
                'message' => $this->getMessage(),
            ],
        ];
    }
}
