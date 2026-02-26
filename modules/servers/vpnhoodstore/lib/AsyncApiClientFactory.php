<?php

namespace WHMCS\Module\Server\VpnHoodStore;

use Closure;
use Exception;

class AsyncApiClientFactory
{
    private $baseUrl;
    private $apiKey;
    private static $instance = null;

    private function __construct($baseUrl, $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
    }

    public static function getInstance($baseUrl, $apiKey): AsyncApiClientFactory
    {
        if (self::$instance === null) {
            self::$instance = new AsyncApiClientFactory($baseUrl, $apiKey);
        }
        return self::$instance;
    }

    private function buildQuery($params): string
    {
        $query = [];
        foreach ($params as $key => $value) {
            $query[] = urlencode($key) . '=' . urlencode(is_bool($value) ? ($value ? 'true' : 'false') : $value);
        }
        return implode('&', $query);
    }

    private function createHttpClient(): Closure
    {
        return function ($endpoint, $method = 'GET', $queryParams = []) {
            $url = $this->baseUrl . $endpoint;
            $ch = curl_init();

            $headers = [
                'Authorization: Bearer ' . $this->apiKey
            ];

            if (strtoupper($method) === 'POST' || strtoupper($method) === 'PATCH') {
                $payload = is_array($queryParams) ? json_encode($queryParams) : $queryParams;
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $headers[] = 'Content-Type: application/json';
            } elseif (!empty($queryParams)) {
                $url .= '?' . $this->buildQuery($queryParams);
                curl_setopt($ch, CURLOPT_URL, $url);
            } else {
                curl_setopt($ch, CURLOPT_URL, $url);
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($ch));
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                throw new Exception('HTTP Error: ' . $httpCode . ' - ' . $response);
            }

            curl_close($ch);
            return $response;
        };
    }

    public function createAsyncClient(): Closure
    {
        return $this->createHttpClient();
    }
}