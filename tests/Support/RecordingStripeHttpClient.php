<?php

namespace Tests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * Stand-in for stripe-php's HTTP layer: records every request and replays queued JSON responses.
 */
class RecordingStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, path: string, params: array<string, mixed>, headers: list<string>}> */
    public array $requests = [];

    /** @var list<array{string, int, array<string, string>}> */
    private array $responses = [];

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers  extra response headers (e.g. Stripe-Notice)
     */
    public function queue(array $body, int $status = 200, array $headers = []): self
    {
        $this->responses[] = [json_encode($body, JSON_THROW_ON_ERROR), $status, $headers];

        return $this;
    }

    /**
     * @param  'delete'|'get'|'post'  $method
     * @param  string  $absUrl
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $params
     * @param  bool  $hasFile
     * @param  'v1'|'v2'  $apiMode
     * @param  int|null  $maxNetworkRetries
     * @return array{string, int, CaseInsensitiveArray}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'path' => (string) parse_url($absUrl, PHP_URL_PATH),
            'params' => $params,
            'headers' => $headers,
        ];

        $response = array_shift($this->responses) ?? throw new RuntimeException("No queued Stripe response for {$method} {$absUrl}.");

        // Like CurlClient, hand back case-insensitive headers (the error factory looks up 'Request-Id').
        return [$response[0], $response[1], new CaseInsensitiveArray(['Request-Id' => 'req_test', ...$response[2]])];
    }

    /**
     * The value of a request header (e.g. Idempotency-Key), or null when it was not sent.
     */
    public function header(int $index, string $name): ?string
    {
        foreach ($this->requests[$index]['headers'] as $line) {
            if (str_starts_with(strtolower($line), strtolower($name).':')) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }
}
