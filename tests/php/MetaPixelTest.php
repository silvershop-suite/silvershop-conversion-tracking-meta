<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta\Tests;

use SilverShop\ConversionTracking\EventName;
use SilverShop\ConversionTracking\Meta\MetaPixel;
use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverStripe\Dev\SapphireTest;

/**
 * The Meta Pixel (fbq) client JavaScript: the base loader, the per-event track with the shared eventID (dedup), and
 * the custom_data shape. No PII ever appears in the Pixel JS.
 */
class MetaPixelTest extends SapphireTest
{
    protected $usesDatabase = false;

    private function event(EventName $name): ConversionEvent
    {
        return new ConversionEvent(
            name: $name,
            eventId: 'view.7',
            items: [new ConversionItem('RJ-001', 'RJ', 'Rain Jacket', 12.95, 1)],
            value: 12.95,
            currency: 'EUR',
        );
    }

    public function testBaseScriptInitsPixelAndFiresPageView(): void
    {
        $js = MetaPixel::create()->baseScript('123456');

        $this->assertStringContainsString("fbq('init',\"123456\")", $js);
        $this->assertStringContainsString("fbq('track','PageView')", $js);
        $this->assertStringContainsString('connect.facebook.net/en_US/fbevents.js', $js);
    }

    public function testTrackScriptCarriesSharedEventIdForDedup(): void
    {
        $js = MetaPixel::create()->trackScript($this->event(EventName::VIEW_CONTENT));

        $this->assertStringContainsString("fbq('track',\"ViewContent\"", $js);
        $this->assertStringContainsString('"eventID":"view.7"', $js);
        $this->assertStringContainsString('"content_ids":["RJ-001"]', $js);
        $this->assertStringContainsString('"currency":"EUR"', $js);
    }

    public function testTrackScriptEmptyForPageView(): void
    {
        $this->assertSame('', MetaPixel::create()->trackScript($this->event(EventName::PAGE_VIEW)));
    }

    public function testCustomDataShape(): void
    {
        $data = MetaPixel::create()->customData($this->event(EventName::ADD_TO_CART));

        $this->assertSame(['RJ-001'], $data['content_ids']);
        $this->assertSame('product', $data['content_type']);
        $this->assertSame(12.95, $data['value']);
        $this->assertSame('EUR', $data['currency']);
        $this->assertSame('RJ-001', $data['contents'][0]['id']);
    }
}
