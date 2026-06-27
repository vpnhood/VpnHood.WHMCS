<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

/**
 * Exception carrying an HTTP status code, used to short-circuit an API request
 * with a structured error response.
 */
class ApiException extends \Exception
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
