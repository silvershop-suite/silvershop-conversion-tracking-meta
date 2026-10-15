<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta;

use GuzzleHttp\Client;
use SilverStripe\Core\Injector\Injectable;

/**
 * Thin Meta Conversions API client (Guzzle). Deliberately minimal — just the Graph `/{PIXEL_ID}/events` POST the
 * provider needs — mirroring the ESP connectors' thin-client choice (no heavy vendor SDK). The payload is built by
 * {@see MetaCapiPayload}; this class only transports it.
 */
class MetaCapiClient
{
    use Injectable;

    public const DEFAULT_API_BASE = 'https://graph.facebook.com/';

    public const DEFAULT_GRAPH_VERSION = 'v21.0';

    private Client $http;

    /**
     * @param array<string, mixed> $httpOptions extra Guzzle config merged last (a test seam for a mock handler)
     */
    public function __construct(
        private string $pixelId,
        private string $accessToken,
        private string $graphVersion = self::DEFAULT_GRAPH_VERSION,
        string $apiBase = self::DEFAULT_API_BASE,
        array $httpOptions = []
    ) {
        $this->http = new Client(array_merge([
            'base_uri' => $apiBase,
            'timeout' => 15,
        ], $httpOptions));
    }

    /**
     * POST an events payload to the Conversions API. Returns the decoded response.
     *
     * @param array<string, mixed> $body the {@see MetaCapiPayload::requestBody()} shape
     * @return array<string, mixed>
     */
    public function send(array $body): array
    {
        $response = $this->http->request(
            'POST',
            $this->graphVersion . '/' . $this->pixelId . '/events',
            [
                'query' => ['access_token' => $this->accessToken],
                'json' => $body,
            ]
        );

        return json_decode((string) $response->getBody(), true) ?: [];
    }
}
