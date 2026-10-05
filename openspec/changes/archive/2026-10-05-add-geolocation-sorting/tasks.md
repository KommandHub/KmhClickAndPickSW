# Tasks

## 1. Config toggle

- [x] 1.1 Add the `enableGeolocationSorting` bool field (sales-channel scope, default true, en-GB/de-DE/fr-FR label and help text) to `src/Resources/config/config.xml`, and verify the field appears in the admin plugin config
- [x] 1.2 Add `'enableGeolocationSorting' => true` to `KmhClickAndPickSW::CONFIG_DEFAULTS`, and verify that `ensureConfigDefaults()` seeds it on install and keeps a saved `false` across `plugin:update`
- [x] 1.3 Add the key to the README §11 configuration table, and verify the table lists all four keys with their defaults

## 2. Consent group

- [x] 2.1 Add a `CookieGroupCollectEvent` listener in `src/Storefront/Cookie/` that appends the optional `kmh_pickup_location_geolocation` group with name and description snippet keys, and verify the new unit test asserts the group is added once, as not required, with the expected snippet keys
- [x] 2.2 Add the group's name and description snippets to `translation.en-GB.json`, `translation.de-DE.json` and `translation.fr-FR.json`, and verify the storefront cookie settings show the translated group in all three locales
- [x] 2.3 Document the consent group in the README Storefront integration section, and verify the section names the group's technical name

## 3. Server-side ordering

- [x] 3.1 In `SalesChannelPickupLocationController::index()`, read `lat`/`lon` and accept them only when both are numeric, in range, and `enableGeolocationSorting` is true for the sales channel. Verify with controller unit tests for valid, out-of-range, single-parameter, non-numeric and config-disabled requests
- [x] 3.2 Order the locations after `filterOpenOnDate()`: by Haversine distance (R = 6371 km) when coordinates are accepted, otherwise by name using `strnatcasecmp`. Locations with blank or non-numeric stored coordinates go last, and ties are broken by name. Verify the unit tests cover nearest-first, missing stored coordinates, equal distance, and name fallback
- [x] 3.3 Confirm the response contains option markup only (no distance values) and that no logger, cache attribute or context write touches `lat`/`lon`. Verify with a unit-test assertion on the rendered template parameters and a review of the diff
- [x] 3.4 Update the README §7 endpoint description with the optional `lat`/`lon` parameters and the ordering rules, and verify it matches the spec scenarios
- [x] 3.5 Run `make cs-fix && make analyse && make test`, and verify PHPStan level 9 passes with the 100% coverage gate

## 4. Storefront button

- [x] 4.1 Add a hidden "Use my location" button and a notice element in the location field container in `shipping-method.html.twig`, with the config value passed in the plugin options and snippet-based button and notice text in all three locales. Verify the button markup renders only when `enableGeolocationSorting` is true
- [x] 4.2 In `sales-channel-pickup-location.plugin.js`, show the button only when `navigator.geolocation` exists and the consent cookie is set, and toggle it on `CookieConfiguration_Update`. Verify in the browser that the button appears after accepting the group without a reload, and is hidden when the group is declined
- [x] 4.3 On button click, call `getCurrentPosition()`, round both values to 2 decimals, and re-run `_fetchPickupLocations()` with `lat`/`lon` appended (`?` or `&`, depending on the URL). On error, keep the list and show the notice. Verify in the browser network tab that the request carries rounded values, that denying permission shows the notice, and that page load makes no position request
- [x] 4.4 Keep the coordinates in plugin instance memory only (no `localStorage`, `sessionStorage` or cookies). Verify with browser devtools that nothing is stored and that the list reverts to name order after selecting a location (page reload)
- [x] 4.5 Rebuild storefront assets with `bin/build-storefront.sh`, and verify the updated `dist/storefront/js/kmh-click-and-pick-s-w/kmh-click-and-pick-s-w.js` is committed

## 5. Integration check

- [x] 5.1 Run an end-to-end check in the dev stack (`make up`) with three locations at known coordinates plus one without coordinates. Accept consent, click the button, and confirm nearest-first order with the no-coordinate location last. Then disable the config and confirm the button is gone and the list is in name order
- [x] 5.2 Run `openspec validate add-geolocation-sorting --strict`, and verify it passes