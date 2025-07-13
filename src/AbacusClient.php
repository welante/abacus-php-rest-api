<?php

/**
 * @package Abacus PHP REST API
 * @author welante GmbH <info@welante.ch>
 * @author Thomas Hirter <memurame@tekomail.ch>
 */

namespace AbacusAPIClient;

use AbacusAPIClient\Client\Response;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\MultipartStream;
use Psr\Http\Message\RequestInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

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

    public function batch(array $requests)
    {
        $multipart = [];
        foreach ($requests as $i => $request) {
            $contentPart =
                $request['method'] . ' ' . $request['url'] . " HTTP/1.1\r\n" .
                "Content-Type: application/json;odata.metadata=minimal\r\n\r\n" .
                ($request['data'] ? json_encode($request['data']) : '');

            $multipart[] = [
                'name' => 'part' . ($i + 1),
                'contents' => $contentPart,
                'headers'  => [
                    'Content-Type' => 'application/http',
                    'Content-Transfer-Encoding' => 'binary',
                ],
            ];
        }

        $url = '/api/entity/v1/mandants/' . $this->credentials['mandant'] . '/$batch';

        $multipartStream = new MultipartStream($multipart);

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type' => 'multipart/mixed; boundary=' . $multipartStream->getBoundary(),
                    'Accept'       => 'application/json',
                ],
                'body' => $multipartStream,
            ]);

            $body = $response->getBody()->getContents();
            $contentType = $response->getHeader('Content-Type')[0];

            // Boundary aus Content-Type extrahieren
            preg_match('/boundary=(.+)$/', $contentType, $matches);
            $boundary = trim($matches[1], '"');

            // Parts aufteilen
            $parts = explode("--$boundary", $body);

            foreach ($parts as $part) {
                if (trim($part) === '' || trim($part) === '--') {
                    continue;
                }

                // Headers und Body trennen
                $sections = explode("\r\n\r\n", $part, 2);
                if (count($sections) < 2) continue;

                $headers = $sections[0];
                $body = $sections[1];

                // Header parsen
                $headerLines = explode("\r\n", $headers);
                $parsedHeaders = [];
                foreach ($headerLines as $line) {
                    if (strpos($line, ':') !== false) {
                        [$key, $value] = explode(':', $line, 2);
                        $parsedHeaders[trim($key)] = trim($value);
                    }
                }

                // Mit dem Part arbeiten
                echo "Content-Type: " . ($parsedHeaders['Content-Type'] ?? 'unknown') . "\n";
                echo "Body length: " . strlen($body) . "\n\n";
                echo "Body:\n";
                echo $body . "\n\n";
                // Schritt 1: Body vom Header trennen
                // HTTP-Header und Body sind durch zwei Zeilenumbrüche getrennt
                $parts = preg_split("/\R\R/", $body, 2);
                $body = $parts[1] ?? '';

                // Schritt 2: JSON dekodieren
                $data = json_decode($body, true);
                print_r($data);
            }
        } catch (BadResponseException | GuzzleException $e) {
            throw new \Exception("Failed to retrieve data from Abacus API: " . $e->getResponse()->getBody());
        }
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
