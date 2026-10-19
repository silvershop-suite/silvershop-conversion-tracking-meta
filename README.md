# SilverShop Conversion Tracking — Meta connector

Meta (Facebook / Instagram) connector for [`silvershop/conversion-tracking`](https://github.com/silvershop/silvershop-conversion-tracking).
It implements the core's `ConversionProvider` seam for Meta:

- **Meta Pixel (`fbq`)** — base `PageView` + `ViewContent` / `AddToCart` / `InitiateCheckout` / `Purchase`, each
  carrying `content_ids` / `content_type` / `value` / `currency` / `contents` and the shared **`eventID`**.
- **Conversions API (CAPI)** — a server-to-server `Purchase` send (via the core's queued dispatch job + Guzzle)
  carrying the **same `event_id`**, so Meta **de-duplicates** the browser/server pair.
- **SHA-256 Advanced Matching** — `em` / `ph` / `fn` / `ln` / `ct` / `st` / `zp` / `country` hashed server-side;
  `client_ip_address`, `client_user_agent` and the `_fbp` / `_fbc` cookies forwarded **unhashed**.
- **Consent** — every tag + the CAPI send are gated on the cookieconsent **`marketing`** category.

## Install

```bash
composer require silvershop/conversion-tracking-meta
```

The connector registers + switches itself on, but stays **inert until configured** (so installing it is safe).
Set the environment:

```
SS_META_PIXEL_ID=1234567890          # required — the Pixel / dataset id
SS_META_CAPI_TOKEN=EAAB...           # required for the CAPI server-side send
SS_META_DATASET_ID=...               # optional — defaults to the Pixel id
SS_META_TEST_EVENT_CODE=TEST12345    # optional — Events Manager → Test Events
SS_META_GRAPH_VERSION=v21.0          # optional — defaults to a pinned recent version
```

With only `SS_META_PIXEL_ID` set, the Pixel fires client-side; add `SS_META_CAPI_TOKEN` to enable the server-side
Purchase. Mode is configurable (`datalayer_only` | `built_in_tags` | `both`, default `both`):

```yaml
SilverShop\ConversionTracking\Meta\MetaProvider:
  mode: both
```

## Deduplication

The browser Pixel `Purchase` and the CAPI `Purchase` share `event_id = purchase.{OrderID}` (minted by the core), so
Meta collapses them into one conversion. A webhook re-send of the paid transition reuses the same id. This is the
single most common Meta misconfiguration — here it is correct by construction.

## Privacy / GDPR

- Advanced-Matching PII is hashed (SHA-256) and sent **only** on the server-side CAPI call, **only** with
  `marketing` consent. The browser Pixel carries **no** PII.
- Data is sent to **Meta (US)** — declare this processor relationship (DPA + SCCs) and the data-sharing level in
  your privacy policy. `_fbp` / `_fbc` are marketing cookies (declare them under cookieconsent's `marketing`
  category).

## Development

```bash
composer install
composer lint    # PHP_CodeSniffer (PSR-12)
composer stan    # PHPStan (silverstan, level 5)
composer test    # PHPUnit
```

`silvershop/conversion-tracking` is an RVXD module resolved from the composer `repositories` VCS entry; a private
core repo needs a GitHub token (see the CI workflow).

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
