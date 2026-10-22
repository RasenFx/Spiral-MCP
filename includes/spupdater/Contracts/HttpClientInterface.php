<?php

namespace SpiralMcp\SPUpdater\WpClient\Contracts;

interface HttpClientInterface
{
    /**
     * Perform an HTTP request and return status and raw body.
     *
     * @param  'GET'|'POST'  $method
     * @param  array<string, mixed>  $params  Query string for GET, JSON body for POST
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $params = []): array;
}
