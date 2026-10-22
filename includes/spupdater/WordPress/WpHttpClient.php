<?php

namespace SpiralMcp\SPUpdater\WpClient\WordPress;

use SpiralMcp\SPUpdater\WpClient\Contracts\HttpClientInterface;

/**
 * HTTP via the WordPress HTTP API (wp_remote_*). Only loaded inside WP.
 */
class WpHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $params = []): array
    {
        if ($method === 'GET') {
            $response = wp_remote_get(add_query_arg(array_map('rawurlencode', array_map('strval', $params)), $url), [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } else {
            $response = wp_remote_post($url, [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => (string) wp_json_encode($params),
            ]);
        }

        if (is_wp_error($response)) {
            return ['status' => 0, 'body' => ''];
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }
}
