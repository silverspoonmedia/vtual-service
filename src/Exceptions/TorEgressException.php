<?php

namespace Silverspoonmedia\VtualService\Exceptions;

/**
 * Raised when Tor proxy is enabled but the outbound IP is not a valid Tor exit.
 *
 * Scraping is aborted so the application server IP is never sent to YouTube/Google.
 */
class TorEgressException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 503);
    }
}
