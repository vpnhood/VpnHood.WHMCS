<?php

namespace WHMCS\Module\Server\VpnHoodStore;

use Exception;
use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VpnHoodStore\AsyncApiClientFactory;

class ApiService
{
    private $client;
    private $projectId;
    private $endpoint = "https://api.vpnhood.com/api";

    /** fetch global settings from the VpnHood addon module using Capsule ORM **/
    private function get_vpnhoodstore_addon_params(): array {
        $settings = Capsule::table('tbladdonmodules')
            ->where('module', 'vpnhoodconfig')
            ->pluck('value', 'setting');

        return [
            'apikey'   => $settings['APIKey'] ?? '',
            'endpoint' => rtrim($this->endpoint, '/'),
            'projectId'    => $settings['ProjectId'] ?? '',
        ];
    }

    public function __construct()
    {
        $settings = $this->get_vpnhoodstore_addon_params();

        // Validate required keys
        if (empty($settings['apikey']) || empty($settings['projectId'])) {
            logModuleCall('vpnhoodstore','ApiService construct', $settings, 'Missing required addon settings: APIKey or ProjectId');
        }

        $this->projectId = $settings['projectId'];
        $factory = AsyncApiClientFactory::getInstance($settings['endpoint'], $settings['apikey']);
        $this->client = $factory->createAsyncClient();
    }

    public function createAccessToken(array $createParams): string{
        return ($this->client)("/projects/{$this->projectId}/access-tokens", 'POST', $createParams);
    }

    public function updateAccessToken(string $accessTokenId, array $updateParams):void{
        ($this->client)("/projects/{$this->projectId}/access-tokens/{$accessTokenId}", 'PATCH', $updateParams);
    }

    public function getAccessCode(string $accessTokenId): string{
        return ($this->client)("/projects/{$this->projectId}/access-tokens/{$accessTokenId}", 'GET');
    }

    public function getServerFarms(): array{
        $serverFarms = ($this->client)("/projects/{$this->projectId}/server-farms", 'GET');
        return json_decode($serverFarms);
    }

    public function getAccessTokenProfiles(): array{
        $accessTokenProfiles = ($this->client)("/projects/{$this->projectId}/access-token-profiles", 'GET');
        return json_decode($accessTokenProfiles);
    }
}