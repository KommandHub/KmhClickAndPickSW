# Design

## Context

See proposal.md for motivation and specs/pickup-geolocation-sorting/spec.md for behavior.

Current state:
- `SalesChannelPickupLocationController::index()` loads active, channel-assigned locations, filters them in PHP with `filterOpenOnDate()`, and renders `<option>` HTML. The criteria have no sorting, so the order is undefined.
- The storefront JS plugin `sales-channel-pickup-location` fetches that HTML into the select. The shipping form auto-submits on every context switch, which re-renders the template and re-initializes the plugin.
- `PickupLocationEntity` stores `latitude`/`longitude` as nullable `StringField`s. Merchants enter them as free text in the admin.
- Shopware 6.7 deprecates `CookieProviderInterface` (to be removed in 6.8) in favor of `CookieGroupCollectEvent`. The storefront announces consent changes on the `CookieConfiguration_Update` emitter event.

## Goals / Non-Goals

**Goals:**
- Order by proximity using data already loaded, with no extra query.
- Keep the endpoint's filtering and HTML contract unchanged.

**Non-Goals:**
- Address or postcode search, maps, or showing the distance in the UI.
- Validating or geocoding the coordinates merchants enter.
- Remembering the customer's position across re-renders.

## Decisions

**Register the consent group through a `CookieGroupCollectEvent` listener, not `CookieProviderInterface`.**
The interface is deprecated in 6.7 and removed in 6.8. The listener appends one optional group with snippet keys. Alternative: the interface, rejected because it adds 6.8 migration debt.

**Gate the button in JS on the consent cookie, and listen for `CookieConfiguration_Update`.**
This follows core plugins such as Google Analytics. The plugin reads the group's cookie on init, and shows or hides the button when the update event fires. Alternative: render the button server-side, rejected because consent can change without a reload.

**Request the position only on a button click.**
Browsers are moving to block permission prompts that have no user gesture. The re-initialization on every context switch would also re-prompt or re-query each time. The position lives in plugin instance memory only. After a re-render it is gone, which is the accepted trade-off in the spec.

**Send coordinates by GET, rounded to 2 decimals.**
About 1.1 km of precision is plenty to rank stores, and it makes access-log exposure coarse. The spec's no-retention rule covers application logs, browser storage and caches; access logs are covered by the rounding. Alternative: a POST body, rejected as a second route plus CSRF handling for a small privacy gain.

**Order in PHP after `filterOpenOnDate()`, using Haversine with R = 6371 km.**
The list is small (typically under 50) and its coordinates are already hydrated. A private helper on the controller is enough, since there is no second caller. Parse stored strings with `is_numeric` and a range check; anything else counts as "no coordinates". The tie-break and fallback order is `strnatcasecmp` on `name`. Alternative: SQL distance ordering, rejected because the strings need parsing and availability filtering already runs in PHP.

**Do not cache this response.**
The endpoint has no HTTP-cache attribute today. Make sure none is added, so responses varying on `lat`/`lon` are never stored.

## Risks / Trade-offs

- [Coarse coordinates still reach web-server access logs] → Rounding to 2 decimals; documented in the proposal.
- [Merchant coordinates entered in the wrong format, e.g. "6,52"] → Treated as missing and listed last. A later change could add admin validation.
- [Feature stays invisible until consent is given] → Accepted. The consent group is optional and translated, and the button appears as soon as consent is given.
- [Sorting lost after every context switch] → Accepted. By then the customer has usually picked a location already.

## Migration Plan

No schema change. Add `enableGeolocationSorting => true` to the plugin's `CONFIG_DEFAULTS`, so `ensureConfigDefaults()` seeds it on install and update without overriding saved values. Rollback: deactivate the config or revert. Nothing is persisted.