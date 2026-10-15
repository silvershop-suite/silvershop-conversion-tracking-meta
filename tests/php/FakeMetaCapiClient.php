<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta\Tests;

use SilverShop\ConversionTracking\Meta\MetaCapiClient;

/**
 * In-memory stand-in for {@link MetaCapiClient} — records the body it is asked to send, makes no HTTP request.
 * Deliberately skips the parent constructor (no Guzzle). The real transport is covered by {@link MetaCapiClientTest}.
 */
class FakeMetaCapiClient extends MetaCapiClient
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    public bool $throw = false;

    public function __construct()
    {
        // No HTTP client.
    }

    public function send(array $body): array
    {
        if ($this->throw) {
            throw new \RuntimeException('Simulated Meta CAPI error');
        }
        $this->sent[] = $body;

        return ['events_received' => 1];
    }
}
