<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Provider\ConversionProvider;
use SilverShop\ConversionTracking\Service\ConsentGate;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Meta (Facebook / Instagram) implementation of the conversion-tracking {@see ConversionProvider}: the Meta Pixel
 * client events + the Conversions API server send for Purchase, with the shared `event_id` dedup, SHA-256 Advanced
 * Matching and `fbp`/`fbc` forwarding. Everything is gated on the cookieconsent `marketing` category.
 *
 * Configuration comes from the environment:
 *   SS_META_PIXEL_ID         the Pixel / dataset id (required to do anything)
 *   SS_META_CAPI_TOKEN       the Conversions API access token (required for the server-side send)
 *   SS_META_DATASET_ID       optional CAPI dataset id (defaults to the Pixel id)
 *   SS_META_TEST_EVENT_CODE  optional Events-Manager Test Events code
 *   SS_META_GRAPH_VERSION    optional Graph API version (defaults to a pinned recent version)
 */
class MetaProvider implements ConversionProvider
{
    use Injectable;
    use Configurable;

    /**
     * One of the ConversionProvider::MODE_* values. Default both (Pixel + CAPI + dataLayer).
     */
    private static string $mode = ConversionProvider::MODE_BOTH;

    private ?MetaCapiClient $client = null;

    public function setClient(?MetaCapiClient $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getKey(): string
    {
        return 'meta';
    }

    public function getName(): string
    {
        return _t(self::class . '.NAME', 'Meta (Facebook / Instagram)');
    }

    public function isConfigured(): bool
    {
        return $this->pixelId() !== '';
    }

    public function mode(): string
    {
        return (string) static::config()->get('mode');
    }

    public function consentCategory(EventName $name): string
    {
        // Meta Pixel + CAPI are advertising — always the marketing category.
        return 'marketing';
    }

    public function baseTagSnippets(): array
    {
        if (!$this->isConfigured() || $this->mode() === self::MODE_DATALAYER_ONLY) {
            return [];
        }

        $js = MetaPixel::create()->baseScript($this->pixelId());

        return [ConsentGate::create()->script($js, 'marketing')];
    }

    public function clientSnippets(ConversionEvent $event): array
    {
        if (!$this->isConfigured() || $this->mode() === self::MODE_DATALAYER_ONLY) {
            return [];
        }

        $js = MetaPixel::create()->trackScript($event);
        if ($js === '') {
            return [];
        }

        return [ConsentGate::create()->script($js, 'marketing')];
    }

    public function supportsServerSide(): bool
    {
        return $this->pixelId() !== '' && $this->capiToken() !== '';
    }

    public function sendServerSide(ConversionEvent $event): bool
    {
        // v1 CAPI covers Purchase only; other events are a no-op (nothing to send).
        if ($event->name !== EventName::PURCHASE) {
            return true;
        }
        if (!$this->supportsServerSide()) {
            return true;
        }
        // Consent is re-checked from the state frozen onto the event at mint — a denied event sends nothing.
        if (!$event->hasConsent($this->consentCategory($event->name))) {
            return true;
        }

        try {
            $body = MetaCapiPayload::create()->requestBody($event, $this->testEventCode());
            $this->client()->send($body);

            return true;
        } catch (\Throwable) {
            // Signal a retry to the dispatch job.
            return false;
        }
    }

    private function client(): MetaCapiClient
    {
        if ($this->client instanceof MetaCapiClient) {
            return $this->client;
        }

        $graphVersion = $this->envOr('SS_META_GRAPH_VERSION', MetaCapiClient::DEFAULT_GRAPH_VERSION);

        return $this->client = MetaCapiClient::create($this->datasetId(), $this->capiToken(), $graphVersion);
    }

    private function pixelId(): string
    {
        return (string) Environment::getEnv('SS_META_PIXEL_ID');
    }

    private function datasetId(): string
    {
        $dataset = (string) Environment::getEnv('SS_META_DATASET_ID');

        return $dataset !== '' ? $dataset : $this->pixelId();
    }

    private function capiToken(): string
    {
        return (string) Environment::getEnv('SS_META_CAPI_TOKEN');
    }

    private function testEventCode(): string
    {
        return (string) Environment::getEnv('SS_META_TEST_EVENT_CODE');
    }

    private function envOr(string $key, string $default): string
    {
        $value = (string) Environment::getEnv($key);

        return $value !== '' ? $value : $default;
    }
}
