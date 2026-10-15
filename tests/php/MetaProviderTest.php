<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Meta\MetaProvider;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Provider\ConversionProvider;
use SilverShop\ConversionTracking\Service\ConsentGate;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * The Meta provider: env-driven configuration, consent-gated Pixel snippets, and the Purchase-only CAPI send that
 * honours the consent frozen onto the event.
 */
class MetaProviderTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic consent: no cookieconsent + assume → tags emitted live so content is assertable.
        Config::modify()->set(ConsentGate::class, 'consent_state_class', '\\NoConsentModule');
        Config::modify()->set(ConsentGate::class, 'assume_consent_without_module', true);
    }

    protected function tearDown(): void
    {
        foreach (['SS_META_PIXEL_ID', 'SS_META_CAPI_TOKEN', 'SS_META_TEST_EVENT_CODE'] as $key) {
            Environment::setEnv($key, '');
        }
        parent::tearDown();
    }

    private function purchaseEvent(array $consented = ['marketing']): ConversionEvent
    {
        return new ConversionEvent(
            name: EventName::PURCHASE,
            eventId: 'purchase.42',
            items: [new ConversionItem('RJ-001', null, 'Rain Jacket', 12.95, 1)],
            value: 12.95,
            currency: 'EUR',
            orderID: 42,
            consentedCategories: $consented,
        );
    }

    private function viewEvent(): ConversionEvent
    {
        return new ConversionEvent(
            name: EventName::VIEW_CONTENT,
            eventId: 'view.7',
            items: [new ConversionItem('RJ-001', null, 'Rain Jacket', 12.95, 1)],
            value: 12.95,
            currency: 'EUR',
        );
    }

    public function testIsConfiguredRequiresPixelId(): void
    {
        $provider = MetaProvider::create();
        $this->assertFalse($provider->isConfigured());

        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        $this->assertTrue($provider->isConfigured());
    }

    public function testSupportsServerSideRequiresPixelAndToken(): void
    {
        $provider = MetaProvider::create();
        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        $this->assertFalse($provider->supportsServerSide(), 'no token yet');

        Environment::setEnv('SS_META_CAPI_TOKEN', 'tok');
        $this->assertTrue($provider->supportsServerSide());
    }

    public function testConsentCategoryIsMarketing(): void
    {
        $this->assertSame('marketing', MetaProvider::create()->consentCategory(EventName::PURCHASE));
        $this->assertSame('marketing', MetaProvider::create()->consentCategory(EventName::VIEW_CONTENT));
    }

    public function testBaseTagSnippetsEmitPixelInit(): void
    {
        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        $tags = MetaProvider::create()->baseTagSnippets();

        $this->assertNotEmpty($tags);
        $this->assertStringContainsString("fbq('init',\"123456\")", (string) $tags[0]->getValue());
    }

    public function testBaseTagSnippetsEmptyWhenUnconfigured(): void
    {
        $this->assertSame([], MetaProvider::create()->baseTagSnippets());
    }

    public function testClientSnippetsEmitTrackWithEventId(): void
    {
        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        $snippets = MetaProvider::create()->clientSnippets($this->viewEvent());

        $this->assertNotEmpty($snippets);
        $html = (string) $snippets[0]->getValue();
        $this->assertStringContainsString("fbq('track',\"ViewContent\"", $html);
        $this->assertStringContainsString('"eventID":"view.7"', $html);
    }

    public function testClientSnippetsRespectDatalayerOnlyMode(): void
    {
        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        Config::modify()->set(MetaProvider::class, 'mode', ConversionProvider::MODE_DATALAYER_ONLY);

        $this->assertSame([], MetaProvider::create()->clientSnippets($this->viewEvent()));
        $this->assertSame([], MetaProvider::create()->baseTagSnippets());
    }

    public function testSendServerSideSendsPurchaseWithConsent(): void
    {
        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        Environment::setEnv('SS_META_CAPI_TOKEN', 'tok');

        $fake = new FakeMetaCapiClient();
        $provider = MetaProvider::create()->setClient($fake);

        $this->assertTrue($provider->sendServerSide($this->purchaseEvent(['marketing'])));
        $this->assertCount(1, $fake->sent);
        $this->assertSame('purchase.42', $fake->sent[0]['data'][0]['event_id']);
        $this->assertSame('Purchase', $fake->sent[0]['data'][0]['event_name']);
    }

    public function testSendServerSideSkipsWithoutMarketingConsent(): void
    {
        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        Environment::setEnv('SS_META_CAPI_TOKEN', 'tok');

        $fake = new FakeMetaCapiClient();
        $provider = MetaProvider::create()->setClient($fake);

        $this->assertTrue($provider->sendServerSide($this->purchaseEvent([])), 'consent-denied = graceful no-op');
        $this->assertCount(0, $fake->sent);
    }

    public function testSendServerSideIgnoresNonPurchaseEvents(): void
    {
        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        Environment::setEnv('SS_META_CAPI_TOKEN', 'tok');

        $fake = new FakeMetaCapiClient();
        $provider = MetaProvider::create()->setClient($fake);

        $this->assertTrue($provider->sendServerSide($this->viewEvent()));
        $this->assertCount(0, $fake->sent, 'v1 CAPI is Purchase-only');
    }

    public function testSendServerSideNoOpWithoutToken(): void
    {
        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        // No token → cannot send; graceful no-op (not a failure).
        $fake = new FakeMetaCapiClient();
        $provider = MetaProvider::create()->setClient($fake);

        $this->assertTrue($provider->sendServerSide($this->purchaseEvent(['marketing'])));
        $this->assertCount(0, $fake->sent);
    }

    public function testSendServerSideReturnsFalseOnTransportFailure(): void
    {
        Environment::setEnv('SS_META_PIXEL_ID', '123456');
        Environment::setEnv('SS_META_CAPI_TOKEN', 'tok');

        $fake = new FakeMetaCapiClient();
        $fake->throw = true;
        $provider = MetaProvider::create()->setClient($fake);

        $this->assertFalse($provider->sendServerSide($this->purchaseEvent(['marketing'])), 'transport error → retry');
    }
}
