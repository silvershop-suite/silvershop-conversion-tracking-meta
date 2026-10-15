<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Meta\MetaCapiPayload;
use SilverShop\ConversionTracking\Meta\MetaHash;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverStripe\Dev\SapphireTest;

/**
 * The Conversions API payload: standard event envelope, the deterministic shared event_id (dedup), SHA-256 Advanced
 * Matching, unhashed fbp/fbc/ip/ua, and the ecommerce custom_data.
 */
class MetaCapiPayloadTest extends SapphireTest
{
    protected $usesDatabase = false;

    private function purchaseEvent(): ConversionEvent
    {
        return new ConversionEvent(
            name: EventName::PURCHASE,
            eventId: 'purchase.42',
            items: [new ConversionItem('RJ-001', 'RJ', 'Rain Jacket', 12.95, 2, 'Acme')],
            value: 25.90,
            currency: 'EUR',
            contentType: ConversionEvent::CONTENT_TYPE_PRODUCT,
            user: new ConversionUser(
                email: 'Buyer@Example.com',
                phone: '+31 6 1234 5678',
                firstName: 'Ada',
                clientIp: '203.0.113.9',
                userAgent: 'UA/1.0',
                fbp: 'fb.1.100.xyz',
                fbc: 'fb.1.200.abc',
            ),
            sourceUrl: 'https://shop.test/checkout/complete/42',
            occurredAt: 1_700_000_000,
            orderID: 42,
            consentedCategories: ['marketing'],
        );
    }

    public function testEventEnvelope(): void
    {
        $event = MetaCapiPayload::create()->event($this->purchaseEvent());

        $this->assertSame('Purchase', $event['event_name']);
        $this->assertSame('purchase.42', $event['event_id']);
        $this->assertSame(1_700_000_000, $event['event_time']);
        $this->assertSame('website', $event['action_source']);
        $this->assertSame('https://shop.test/checkout/complete/42', $event['event_source_url']);
    }

    public function testAdvancedMatchingIsHashedAndCookiesAreNot(): void
    {
        $user = MetaCapiPayload::create()->event($this->purchaseEvent())['user_data'];

        // Hashed PII (lowercased/normalised then SHA-256).
        $this->assertSame(MetaHash::hash('buyer@example.com'), $user['em']);
        $this->assertSame(MetaHash::hashPhone('+31 6 1234 5678'), $user['ph']);
        $this->assertSame(hash('sha256', 'ada'), $user['fn']);
        $this->assertSame(64, strlen($user['em']), 'em is a SHA-256 hex digest');
        // No raw PII leaks through.
        $this->assertStringNotContainsString('buyer@example.com', json_encode($user));

        // Forwarded unhashed.
        $this->assertSame('203.0.113.9', $user['client_ip_address']);
        $this->assertSame('UA/1.0', $user['client_user_agent']);
        $this->assertSame('fb.1.100.xyz', $user['fbp']);
        $this->assertSame('fb.1.200.abc', $user['fbc']);
    }

    public function testCustomData(): void
    {
        $custom = MetaCapiPayload::create()->event($this->purchaseEvent())['custom_data'];

        $this->assertSame('EUR', $custom['currency']);
        $this->assertSame(25.90, $custom['value']);
        $this->assertSame('product', $custom['content_type']);
        $this->assertSame(['RJ-001'], $custom['content_ids']);
        $this->assertSame('RJ-001', $custom['contents'][0]['id']);
        $this->assertSame(2, $custom['contents'][0]['quantity']);
        $this->assertSame(12.95, $custom['contents'][0]['item_price']);
        $this->assertSame(2, $custom['num_items']);
        $this->assertSame('42', $custom['order_id']);
    }

    public function testRequestBodyWrapsEventAndTestCode(): void
    {
        $body = MetaCapiPayload::create()->requestBody($this->purchaseEvent(), 'TEST123');

        $this->assertCount(1, $body['data']);
        $this->assertSame('purchase.42', $body['data'][0]['event_id']);
        $this->assertSame('TEST123', $body['test_event_code']);
    }

    public function testRequestBodyOmitsTestCodeWhenEmpty(): void
    {
        $body = MetaCapiPayload::create()->requestBody($this->purchaseEvent());
        $this->assertArrayNotHasKey('test_event_code', $body);
    }
}
