<?php

namespace WHMCS\Module\Addon\VpnHoodPartnerHub;

/**
 * Exception carrying an HTTP status code, used to short-circuit an API request
 * with a structured error response.
 *
 * $errorCode names the failures a caller must handle differently (retry, reconcile, stop and
 * contact support) and $details carries their data; both are additive to the "error" text, so
 * a caller that reads only the text keeps working.
 */
class ApiException extends \Exception
{
    private int $httpStatus;
    private string $errorCode;
    private array $details;

    public function __construct(string $message, int $httpStatus = 400, string $errorCode = '', array $details = [])
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
