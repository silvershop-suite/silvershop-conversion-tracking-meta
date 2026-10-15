<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta;

use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverStripe\Core\Injector\Injectable;

/**
 * Builds the Meta Pixel (`fbq`) client-side JavaScript: the once-per-page base loader + `PageView`, and the
 * per-event `track` call carrying `content_ids` / `content_type` / `value` / `currency` / `contents` and — crucially
 * — the shared `eventID` so the browser event de-duplicates against the CAPI send. Advanced Matching (hashed PII)
 * is carried on the CAPI server send ({@see MetaCapiPayload}); the Pixel carries no PII.
 *
 * The JS is wrapped as an inert, consent-gated `<script type="text/plain" data-consent-category="marketing">` by
 * the core {@see \SilverShop\ConversionTracking\Service\ConsentGate}, so nothing loads/fires before marketing
 * consent.
 */
class MetaPixel
{
    use Injectable;

    /**
     * The standard Meta Pixel bootstrap + `init` + base `PageView`.
     */
    public function baseScript(string $pixelId): string
    {
        $id = json_encode($pixelId);

        return "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?"
            . "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;"
            . "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;"
            . "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"
            . "'script','https://connect.facebook.net/en_US/fbevents.js');"
            . "fbq('init',{$id});fbq('track','PageView');";
    }

    /**
     * The per-event `fbq('track', …, {eventID})` call. Returns '' for the bare PageView (handled by the base).
     */
    public function trackScript(ConversionEvent $event): string
    {
        if (!$event->name->isEcommerce()) {
            return '';
        }

        $customData = json_encode($this->customData($event), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $options = json_encode(['eventID' => $event->eventId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "fbq('track'," . json_encode($event->name->metaName()) . "," . $customData . "," . $options . ");";
    }

    /**
     * The Pixel `custom_data` object (no PII).
     *
     * @return array<string, mixed>
     */
    public function customData(ConversionEvent $event): array
    {
        $contents = array_map(static fn (ConversionItem $item): array => [
            'id' => $item->id,
            'quantity' => $item->quantity,
            'item_price' => round($item->price, 2),
        ], $event->items);

        return [
            'content_ids' => $event->contentIds(),
            'content_type' => $event->contentType,
            'value' => round($event->value, 2),
            'currency' => $event->currency,
            'contents' => $contents,
        ];
    }
}
