<?php

/**
 * @package Abacus PHP REST API
 * @author welante GmbH <info@welante.ch>
 * @author Thomas Hirter <memurame@tekomail.ch>
 */

namespace AbacusAPIClient;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Http\Message\RequestInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use AbacusAPIClient\Client\Response;

class AbacusClient
{
    private Client $client;
    private GenericProvider $tokenProvider;
    private FilesystemAdapter $cache;
    private array $credentials;
    private string $cachekey;

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->cache = new FilesystemAdapter();

        $this->tokenProvider = new GenericProvider([
            'clientId'                => $this->credentials['client_id'],
            'clientSecret'            => $this->credentials['client_secret'],
            'urlAccessToken'          => $this->credentials['base_url'] . '/oauth/oauth2/v1/token',
            'urlAuthorize'            => '',
            'urlResourceOwnerDetails' => '',
        ]);

        $stack = HandlerStack::create();
        $stack->push($this->getTokenRefreshMiddleware());

        $this->client = new Client([
            'base_uri' => $this->credentials['base_url'],
            'handler' => $stack,
            // 30 seconds
            'timeout' => 30.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'abacus-rest-api/1.0',
            ],
        ]);

        $this->cachekey = 'abacus_token_' . parse_url($this->credentials['base_url'], PHP_URL_HOST) . '_' . $this->credentials['client_id'];
    }

    private function getAccessToken(): string
    {
        return $this->cache->get($this->cachekey, function ($item) {
            $token = $this->tokenProvider->getAccessToken('client_credentials');

            // 30 seconds grace period
            $item->expiresAfter($token->getExpires() - time() - 30);
            return $token->getToken();
        });
    }

    private function getTokenRefreshMiddleware()
    {
        return Middleware::mapRequest(function (RequestInterface $request) {
            $accessToken = $this->getAccessToken();
            return $request
                ->withHeader('Authorization', 'Bearer ' . $accessToken);
        });
    }

    private function request(string $method, string $path, array $params = [], array $values = [])
    {
        $url = '/api/entity/v1/mandants/' . $this->credentials['mandant'] . '/' . $path;

        $request = [
            'query' => $params,
            'body' => json_encode($values),
        ];

        try {
            $response = $this->client->request($method, $url, $request);

            $response = new Response(
                $response->getStatusCode(),
                '',
                $response->getBody(),
                $response->getHeaders()
            );

            return $response;
        } catch (BadResponseException | GuzzleException $e) {
            throw new \Exception("Failed to retrieve data from Abacus API: " . $e->getResponse()->getBody());
        }
    }

    public function getRequest(string $path, array $params = []){
        return $this->request('GET', $path, $params);
    }

    public function postRequest(string $path, array $values = []){
        return $this->request('POST', $path, [], $values);
    }

    public function patchRequest(string $path, array $values = []){
        return $this->request('PATCH', $path, [], $values);
    }

    public function deleteRequest(string $path, array $params = []){
        return $this->request('DELETE', $path, $params);
    }

    public function hasToken(){
        return $this->getAccessToken();
    }

    public function resource($resource_type)
    {
        $resource_object = new $resource_type($this);
        return $resource_object;
    }
}
