<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta;

use SilverShop\ConversionTracking\Model\ConversionEvent;
use SilverShop\ConversionTracking\Model\ConversionItem;
use SilverShop\ConversionTracking\Model\ConversionUser;
use SilverStripe\Core\Injector\Injectable;

/**
 * Builds the Meta Conversions API (CAPI) request body from a normalised {@see ConversionEvent}. Kept separate from
 * the HTTP client so the payload can be asserted in a unit test (the real network round-trip is pending the shop's
 * Pixel ID + CAPI token).
 *
 * The event's deterministic {@see ConversionEvent::$eventId} is sent as `event_id`, shared with the browser Pixel's
 * `eventID`, so Meta de-duplicates the browser/server pair. Advanced Matching fields are SHA-256 hashed
 * ({@see MetaHash}); IP, user-agent and the `_fbp` / `_fbc` cookies are forwarded unhashed to strengthen matching.
 */
class MetaCapiPayload
{
    use Injectable;

    /**
     * The full request body: one event under `data`, plus the optional Events-Manager test-event code.
     *
     * @return array<string, mixed>
     */
    public function requestBody(ConversionEvent $event, string $testEventCode = ''): array
    {
        $body = ['data' => [$this->event($event)]];
        if ($testEventCode !== '') {
            $body['test_event_code'] = $testEventCode;
        }

        return $body;
    }

    /**
     * One CAPI event object.
     *
     * @return array<string, mixed>
     */
    public function event(ConversionEvent $event): array
    {
        return [
            'event_name' => $event->name->metaName(),
            'event_time' => $event->occurredAt > 0 ? $event->occurredAt : time(),
            'event_id' => $event->eventId,
            'action_source' => 'website',
            'event_source_url' => $event->sourceUrl,
            'user_data' => $this->userData($event->user),
            'custom_data' => $this->customData($event),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(ConversionUser $user): array
    {
        $data = [
            'em' => MetaHash::hash($user->email),
            'ph' => MetaHash::hashPhone($user->phone),
            'fn' => MetaHash::hash($user->firstName),
            'ln' => MetaHash::hash($user->lastName),
            'ct' => MetaHash::hash($user->city),
            'st' => MetaHash::hash($user->state),
            'zp' => MetaHash::hash($user->postcode),
            'country' => MetaHash::hash($user->country),
            'external_id' => MetaHash::hash($user->externalId),
            // Forwarded UNHASHED (Meta's spec) to strengthen matching.
            'client_ip_address' => $user->clientIp,
            'client_user_agent' => $user->userAgent,
            'fbp' => $user->fbp,
            'fbc' => $user->fbc,
        ];

        return array_filter($data, static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function customData(ConversionEvent $event): array
    {
        $contents = array_map(static fn (ConversionItem $item): array => [
            'id' => $item->id,
            'quantity' => $item->quantity,
            'item_price' => round($item->price, 2),
        ], $event->items);

        $data = [
            'currency' => $event->currency,
            'value' => round($event->value, 2),
            'content_type' => $event->contentType,
            'content_ids' => $event->contentIds(),
            'contents' => $contents,
            'num_items' => array_sum(
                array_map(static fn (ConversionItem $item): int => $item->quantity, $event->items)
            ),
        ];

        if ($event->orderID > 0) {
            $data['order_id'] = (string) $event->orderID;
        }

        return $data;
    }
}
