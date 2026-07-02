<?php
// Shared HttpClientContract test double + canned-response builders, previously
// reimplemented from scratch as anonymous classes in BtcpayInvoiceServiceTest and
// LndRestInvoiceServiceTest.

use PayCryptoMe\WooCommerce\HttpClientContract;

if (!function_exists('http_ok')) {
    function http_ok($body): array
    {
        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => json_encode($body)];
    }
}
if (!function_exists('http_error')) {
    function http_error(int $code, string $message = 'Error', $body = null): array
    {
        return [
            'response' => ['code' => $code, 'message' => $message],
            'body'     => $body ?? json_encode(['error' => $message]),
        ];
    }
}

if (!class_exists('FakeHttpClient')) {
    class FakeHttpClient implements HttpClientContract
    {
        private array $postResponse = [];
        private array $getResponse = [];
        public ?string $lastPostUrl = null;
        public ?array $lastPostArgs = null;
        public ?string $lastGetUrl = null;
        public ?array $lastGetArgs = null;

        public static function respondingToPost(array $response): self
        {
            $client = new self();
            $client->postResponse = $response;
            return $client;
        }

        public static function respondingToGet(array $response): self
        {
            $client = new self();
            $client->getResponse = $response;
            return $client;
        }

        public function willRespondToPost(array $response): self
        {
            $this->postResponse = $response;
            return $this;
        }

        public function willRespondToGet(array $response): self
        {
            $this->getResponse = $response;
            return $this;
        }

        public function lastPostBody(): array
        {
            return json_decode($this->lastPostArgs['body'] ?? '{}', true) ?? [];
        }

        public function post(string $url, array $args): array
        {
            $this->lastPostUrl = $url;
            $this->lastPostArgs = $args;
            return $this->postResponse;
        }

        public function get(string $url, array $args): array
        {
            $this->lastGetUrl = $url;
            $this->lastGetArgs = $args;
            return $this->getResponse;
        }
    }
}
