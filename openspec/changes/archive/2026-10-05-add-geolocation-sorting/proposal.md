# Proposal

## Why

Merchants with many pickup locations get an unsorted list in checkout, so customers have to scan it for the nearest store. Letting a customer share their approximate position on request puts the closest locations first, without collecting location data from customers who don't opt in.

## What Changes

- New optional cookie consent group for pickup geolocation, translated in en-GB, de-DE and fr-FR.
- New per-sales-channel config `enableGeolocationSorting` (default true).
- New "Use my location" button under the pickup location select. It appears only when the config is on, the browser supports geolocation, and the customer has accepted the consent group. Clicking it triggers the browser permission prompt.
- The coordinates are rounded to 2 decimals in the browser and sent to the location options endpoint. The endpoint then orders locations by distance, nearest first.
- Without usable coordinates, the location list is ordered by name. Today the order is undefined.
- Customer coordinates are never stored and are only used for the single sorting request.

## Capabilities

### New Capabilities
- `pickup-geolocation-sorting`: Consent-gated, customer-initiated proximity ordering of the checkout pickup location list.

### Modified Capabilities
<!-- None: the location options endpoint keeps its filtering contract; ordering is owned by the new capability. -->

## Impact

- Storefront controller `SalesChannelPickupLocationController::index()` (ordering, query parameters).
- Storefront JS plugin `sales-channel-pickup-location` and the shipping-method Twig template (button).
- `config.xml`, plugin config defaults, storefront and admin snippets.
- New listener on Shopware's `CookieGroupCollectEvent`.
- No database or migration changes. No new dependencies.
