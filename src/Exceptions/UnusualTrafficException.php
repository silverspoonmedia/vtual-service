<?php

namespace Silverspoonmedia\VtualService\Exceptions;

/**
 * Thrown when YouTube answers an upstream scraping request with one of the
 * HTTP codes configured as "unusual traffic" (302/403/429 by default).
 *
 * Upstream equivalent: `detectedAsSendingUnusualTraffic()`.
 */
class UnusualTrafficException extends ApiException
{
    public function __construct(
        string $message = 'YouTube has detected unusual traffic from this YouTube operational API instance. Please try your request again later or see alternatives at https://github.com/Benjamin-Loison/YouTube-operational-API/issues/11'
    ) {
        parent::__construct($message, 403);
    }
}
