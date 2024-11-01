<?php

/**
 * @package Abacus PHP REST API
 * @author Thomas Hirter <memurame@tekomail.ch>
 */

namespace AbacusAPIClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class AbacusClient
{
    private $client;
    private $token;
    private $cache;
    private $credentials;

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        
        $this->client = new Client([
            'base_uri' => $this->credentials['base_url'],
        ]);

        $this->cache = new FilesystemAdapter();

        $this->authenticate();
    }

    private function authenticate()
    {
        $cacheItem = $this->cache->getItem('abacus_token');

        if ($cacheItem->isHit()) {
            $this->token = $cacheItem->get();
            return;
        }

        try {
            $response = $this->client->post('/oauth/oauth2/v1/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->credentials['client_id'] . ':' . $this->credentials['client_secret']),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['access_token'])) {
                $this->token = $data['access_token'];
                
                $cacheItem->set($this->token);
                $cacheItem->expiresAfter(600);
                $this->cache->save($cacheItem);
            } else {
                throw new \Exception('No access token in response');
            }
        } catch (RequestException $e) {
            throw new \Exception('Failed to authenticate with Abacus API: ' . $e->getMessage());
        }
    }

    public function hasToken(){
        return ($this->token) ? true : false;
    }

    public function getRequest($path, $params = [])
    {
        $url = '/api/entity/v1/mandants/' . $this->credentials['mandant'] . '/' . $path;

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
                'query' => $params,
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new \Exception("Failed to retrieve data from Abacus API: " . $e->getMessage());
        }
    }

    public function resource($resource_type)
    {
        $resource_object = new $resource_type($this);
        return $resource_object;
    }
}
