<?php

namespace SpiralMcp\SPUpdater\WpClient;

/**
 * Parsed SPUpdater API envelope: {ok: bool, data: {...}} or {ok: false, error: slug}.
 *
 * Readonly properties instead of a readonly class to stay PHP 8.1 compatible.
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly int $status = 0,
    ) {}

    public static function fromHttp(int $status, string $body): self
    {
        $json = json_decode($body, true);

        if (! is_array($json)) {
            return new self(ok: false, error: 'invalid_response', status: $status);
        }

        return new self(
            ok: (bool) ($json['ok'] ?? false),
            data: is_array($json['data'] ?? null) ? $json['data'] : [],
            error: isset($json['error']) && is_string($json['error']) ? $json['error'] : null,
            status: $status,
        );
    }
}
