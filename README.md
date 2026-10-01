<p align="center">
  <a href="https://kommandhub.com" target="_blank">
    <img src="src/Resources/config/kommandhub.png" alt="Kommandhub Logo">
  </a>
</p>

# Click and Pick for Shopware 6 — Technical Documentation

`KmhClickAndPickSW` adds click-and-collect to Shopware 6.7: a self-pickup
shipping method, a pay-on-pickup payment method, pickup-location management with a
timezone-aware opening schedule, in-checkout selection of a pickup location + date/
time, server-side validation, a pickup-ready order-delivery state, and Flow Builder
triggers/actions for mail and (optional) SMS notifications.

This document targets developers who maintain, test, or extend the plugin. It is
not a merchant/end-user guide.

- **Namespace:** `Kommandhub\ClickAndPickSW\` → `src/` (PSR-4)
- **Plugin class:** `Kommandhub\ClickAndPickSW\KmhClickAndPickSW`
- **Shopware:** `~6.7.0` (`shopware/core`, `shopware/storefront`)
- **License:** Proprietary (see `LICENSE`)

---

## 1. Scope

Implemented, and only this:

- Pickup-location entity + admin module (address/contact/geo, sales-channel
  assignment, per-location IANA timezone, weekly opening intervals, special-date
  overrides).
- `kmh_self_pickup` shipping method and `kmh_pay_on_pickup` payment
  method (payment restricted to the pickup shipping context).
- In-checkout pickup-location + pickup date/time + free-text comment selection,
  persisted in the sales-channel context and validated in the cart.
- Order pickup record entity (`kmh_order_pickup_location`) as the single
  source of truth for an order's pickup data; a OneToOne order extension.
- a pickup-ready order-delivery state (technical name `ready`, added to the
  `order_delivery.state` machine); mail templates; `pickup.order.placed`
  and `pickup.order.ready` Flow Builder triggers; admin-mail and optional-SMS
  actions.
- Read-only "Pickup information" tab on the admin order detail page.

Out of scope: capacity/slot-quota booking, cut-off/lead-time rules, blackout
periods (there is an extension seam for these — see §12), delivery routing, POS.

## 2. Architecture & design decisions

- **Feature-first modules** under `src/` (mirrors Shopware's own plugin layout).
  A top-level directory is a boundary; inside it, flat Symfony-idiomatic folders
  (`Service`-like classes, `Listener`, `Handler`, `Struct`, `Event`). One obvious
  home per class; cross-module access goes through a service, not deep-linking.
- **DAL-first data model.** Pickup data lives in dedicated DAL entities, never in
  order custom fields. The order's pickup record is a versioned OneToOne extension.
- **Selection is context state, not cart state.** The in-progress selection is
  stored in the `sales_channel_api_context` payload via Shopware's
  `SalesChannelContextPersister` — the same store as the selected shipping/payment
  method — so it inherits login-migration, customer scoping, and per-sales-channel
  isolation for free. The cart validator reads the persisted store (authoritative),
  never a request-local extension, so a valid selection cannot intermittently
  disappear between cart recalculations.
- **Timezone-correct availability.** All open/closed decisions are evaluated in the
  location's own IANA timezone in PHP; opening times are local wall-clock strings.
- **Autowiring** via the `../../*` glob (`services.yml`); `autoconfigure: true`
  auto-tags entity definitions, extensions, cart validators, and flow storers.
  Only Flow *actions* and the storefront controllers need explicit service config.

## 3. Project structure

```
src/
  Checkout/
    Cart/                     PayOnPickupCartProcessor (cart validator) + Error/ blockers
    Payment/                  PayOnPickupPaymentHandler
    PickupSelection/          PickupContextStorage/Keys, StoredPickupSelection (raw),
                              PickupSelection (resolved), OrderPickupLocationWriter
  Entity/
    PickupLocation/           definition/entity/collection + Aggregate/ (OpeningHour,
                              SpecialHour, SalesChannelMapping)
    OrderPickupLocation/      order pickup record entity
    Order/Aggregated/…        OrderDeliveryStates, transition-action constants
  Extension/OrderExtension    OneToOne order → order pickup record
  Event/                      PickupOrderPlacedEvent, PickupOrderReadyEvent
  Flow/
    Aware/                    PickupLocationAware, OrderPickupLocationAware
    Storer/                   flow storers for the two aware contracts
    Action/                   SendPickupNotificationToAdminAction, SendSmsToPickupLocationAction
    Sms/SmsGateway            duck-typed interface for the optional SMS plugin
  Listener/                   SwitchContextEventListener, OrderListener,
                              PickupOrderReadyListener, BusinessEventCollectorListener
  PickupLocation/
    Availability/             PickupLocationAvailabilityService, PickupTimeSlotService
    PickupLocationSelectionResolver, PickupLocationValidator
  Installer/                  PaymentMethodInstaller, ShippingMethodInstaller
  Migration/                  7 migrations (see §14)
  Storefront/Controller/      SalesChannelPickupLocationController
  Twig/FormattedCalendarDays  weekday-name formatting helper
  Resources/
    config/                   services.yml, routes.xml, config.xml
    app/administration/       Vue admin (pickup-location module + order tab)
    app/storefront/           storefront JS plugins (src + built dist)
    views/                    storefront Twig overrides
    snippet/                  storefront snippets (en-GB, de-DE, fr-FR)
    public/                   built administration assets (generated)
tests/
  Unit/                       mirrors src/; no kernel
  Integration/                kernel-backed (@group kernel)
```

## 4. Data model & DAL

| Table | Entity | Notes |
|-------|--------|-------|
| `kmh_pickup_location` | `PickupLocationDefinition` | address/contact/geo, `time_format`, `timezone`, `active`, `location_code`. Index on `active`. |
| `kmh_pickup_location_sales_channel` | `…SalesChannelMappingDefinition` | M2M mapping. PK `(pickup_location_id, sales_channel_id)`; FK to `sales_channel` auto-indexes the reverse direction. |
| `kmh_pickup_location_opening_hour` | `…OpeningHourDefinition` | weekly intervals: `day_of_week` (ISO 1–7), `open_time`/`close_time` (`HH:MM`). Index `(pickup_location_id, day_of_week)`. |
| `kmh_pickup_location_special_hour` | `…SpecialHourDefinition` | date overrides: `date`, `closed`, optional `open_time`/`close_time`. Index `(pickup_location_id, date)`. |
| `kmh_order_pickup_location` | `OrderPickupLocationDefinition` | one row per pickup order: `order_id`+`order_version_id`, nullable `pickup_location_id`, `pickup_time`, `comment`. |

**Associations**
- `PickupLocation.salesChannels` — M2M to `sales_channel` via the mapping table.
- `PickupLocation.openingHoursSchedule` / `.specialHours` — OneToMany, `CascadeDelete`.
- `OrderExtension` adds `order.kmhPickupLocation` — **OneToOne, autoloaded**,
  `CascadeDelete`. Every order read (finish page, account, admin API, Flow order
  data) carries the pickup record without a criteria subscriber.
- `OrderPickupLocation.pickupLocation` — ManyToOne, autoloaded.

**Referential integrity**
- Order pickup record → `order` FK is `(order_id, order_version_id) → order(id,
  version_id)` `ON DELETE CASCADE` (versioned). Unique on `(order_id,
  order_version_id)`.
- Order pickup record → pickup location FK is **`ON DELETE SET NULL`**: deleting a
  location preserves the order's historical pickup data (time/comment) and nulls
  the reference. The admin tab renders a "location deleted" state for this case.

## 5. Cart & checkout lifecycle

Selection is a two-stage value model:
- `StoredPickupSelection` — raw strings read from the context payload.
- `PickupSelection` — resolved: a loaded `PickupLocationEntity` (+ its schedule
  associations) plus a parsed `\DateTimeImmutable` time and comment.

**Persisting a selection** — `SwitchContextEventListener`
- On `SwitchContextEvent::CONSISTENT_CHECK`: only acts when the request carries
  `pickupLocationId` (so payment/address/language switches never disturb it). An
  empty value clears the selection (remove control); a value is validated
  (`PickupLocationValidator`, an `EntityExists` on active + assigned-to-sales-channel)
  and saved via `PickupContextStorage`.
- On `SalesChannelContextResolvedEvent` (gated on the pickup shipping method, so
  no lookup on other requests): re-attaches the selection as the `pickupLocation`
  context extension for the storefront to read.

**Persistence layer** — `PickupContextStorage` wraps `SalesChannelContextPersister`
(`load`/`save`/`clear`). It scopes the payload to the customer only when genuinely
logged in (not while an admin impersonates), so `token OR customer_id` migrates the
selection across the guest→customer login token change without leaking between
customers or sales channels. Keys are centralized in `PickupContextKeys`.

**Validation gate** — `PayOnPickupCartProcessor` (`shopware.cart.validator`)
- If payment is Pay-on-pickup but the shipping method is not self-pickup →
  `UnsupportedDeliveryMethodCartBlockerError`.
- If shipping is self-pickup: resolve the selection via
  `PickupLocationSelectionResolver` (which reads the **persisted store**, not the
  transient extension). No valid location → `PickupLocationRequiredCartBlockerError`.
  A chosen time that fails `PickupTimeSlotService::isBookable()` →
  `InvalidPickupTimeCartBlockerError`. All blockers set `blockOrder() = true`. The
  read is idempotent across recalculations.

**Order placement** — `OrderListener` on `CheckoutOrderPlacedEvent`
- For pickup orders: `OrderPickupLocationWriter` creates the
  `kmh_order_pickup_location` row; `PickupOrderPlacedEvent` is dispatched;
  the context selection is then cleared so a new cart in the same session starts
  clean.

## 6. Pickup scheduling & availability

`PickupLocationAvailabilityService` (pure, stateless, reads only loaded
associations):
- `isOpenAt(location, ?ref)` — open at an instant (special-date override wins over
  weekly; `[open, close)` half-open; supports overnight wrap).
- `isOpenOnDate(location, ?ref)` — open at any point on the reference local date
  (the correct check for the selection list).
- `getOpenIntervalsForDate(location, date)` — concrete `{start, end}` instants for
  a date; the seam the slot generator builds on.
- `filterOpen` / `filterOpenOnDate` — filter loaded collections.

`PickupTimeSlotService`:
- `getSlots(location, date, ?now)` — steps each open interval by
  `slotStepMinutes` (default 30), keeping the whole slot inside the interval and
  excluding past + disallowed slots.
- `isBookable(location, when)` — the checkout gate: `isOpenAt && isAllowed`.
- `protected isAllowed()` — returns `true`; **extension seam** for cutoff/blackout/
  capacity rules (decorate the service and override).

**Timezone handling:** decisions run in `location.timezone` (defaults to UTC when
unset/invalid). The slots controller builds the requested day at midnight in the
location's timezone so it maps to the intended calendar day for any offset. The
chosen `pickup_time` is stored as a `DATETIME(3)` (offset carried through the
ISO-8601 value the storefront submits, parsed back by the resolver).

## 7. Storefront integration

- Twig: `views/storefront/component/shipping/custom/shipping-method.html.twig`
  extends the core shipping-method component and renders, under the self-pickup
  method, a location `<select name="pickupLocationId">`, a date helper input, a
  `<select name="pickupTime">`, and a `<textarea name="pickupComment">`. Selection
  reaches the context via the core shipping-form auto-submit (context switch).
- The finish page (`page/checkout/finish/finish-address.html.twig`) renders the
  pickup card from `order.extensions.kmhPickupLocation`.
- JS plugins (`app/storefront/src/`): `SalesChannelPickupLocation` fetches the
  location option list; `SalesChannelPickupTime` fetches time-slot options for the
  chosen date and keeps the date helper from auto-submitting the form.
- Controller `SalesChannelPickupLocationController` (storefront route scope):
  - `…pickup-locations.index` — active + sales-channel-assigned locations open on
    the current date (renders `<option>` HTML). Filters by `salesChannels.id`
    **without hydrating** the association (avoids per-row over-fetch); loads the
    schedule associations, batched. Ordered by name (`strnatcasecmp`). Optional
    `lat`/`lon` query parameters (sent rounded to 2 decimals by the storefront)
    switch to nearest-first ordering by Haversine distance (R = 6371 km) when both
    are numeric and in range (±90 / ±180) and `enableGeolocationSorting` is on for
    the sales channel; otherwise they are ignored. Locations with blank or
    non-numeric stored coordinates come last; ties go by name. Coordinates are used
    for that response only — not stored, logged or cached, and no distances are
    rendered. The response must stay uncacheable (no HTTP-cache attribute).
  - `…pickup-locations.slots` — bookable time-slot `<option>` HTML for a location +
    `date` query.
- Geolocation consent: `Storefront\Cookie\PickupLocationGeolocationCookieListener`
  adds the optional cookie group `kmh_pickup_location_geolocation` (via
  `CookieGroupCollectEvent`, not the deprecated `CookieProviderInterface`). The
  group is its own cookie; accepting it sets `kmh_pickup_location_geolocation=1`.
  Name/description snippets: `general.kmh-click-and-pick.cookie.*`.

## 8. Administration integration

- Module `kmh-pickup-location` (under **Content**): list + create/edit,
  sales-channel assignment, and a schedule editor
  (`kmh-pickup-location-schedule`) for timezone + weekly intervals + special
  dates.
- Order detail tab `kmh-order-pickup-info`: the child route
  `sw.order.detail.pickup` is injected into the core `sw.order.detail` route via a
  lightweight module `routeMiddleware`, and the tab item is added by overriding the
  `sw-order-detail` template's `sw_order_detail_content_tabs_extension` block. The
  tab component loads the `kmh_order_pickup_location` record by `orderId`
  (`pickupLocation` association) and renders a read-only, deleted-location-aware
  view (`pickup-view.js` derives the display state).

## 9. Flow Builder

Triggers (business events), registered with the flow collector by
`BusinessEventCollectorListener`:

| Event name | Class | Dispatched by | Available data |
|------------|-------|---------------|----------------|
| `pickup.order.placed` | `PickupOrderPlacedEvent` | `OrderListener` on order placement | `order`, `pickupLocation`, `pickupOrderLocation` |
| `pickup.order.ready` | `PickupOrderReadyEvent` | `PickupOrderReadyListener` on delivery entering the `ready` state | `order`, `pickupLocation`, `pickupOrderLocation` |

Both implement `OrderAware`, `MailAware`, `CustomerAware`, `PickupLocationAware`,
`OrderPickupLocationAware` (placed also carries sales-channel-context/group).
`getMailStruct()` targets the order customer, and returns an empty recipient list
when the customer/email is missing.

Flow data is restored by storers (auto-tagged `flow.storer`):
`PickupLocationFlowStorer` and `OrderPickupLocationFlowStorer` store only the id and
lazily reload the entity, so delayed flows stay small. `pickupOrderLocation` exposes
`pickupTime`/`comment` (and the linked location) as first-class flow variables.

Actions (`flow.action`, declared explicitly in `services.yml`):
- `action.kmh.pickup.notify_admin` — `SendPickupNotificationToAdminAction`:
  emails the order's pickup location (recipient = location email). Requires
  `OrderAware` + `PickupLocationAware`; `pickupOrderLocation` is optional template
  data. Template id comes from the action config or the plugin's seeded default.
- `action.kmh.pickup.notify_sms` — `SendSmsToPickupLocationAction`: texts the
  location's phone number; appends the pickup time when present.

## 10. Email & optional SMS

- **Email:** templates are seeded by migrations (`…PickupReadyMailTemplate`) and
  sent through the core `MailService` from the admin-notify action. Template data:
  `order`, `customer`, `pickupLocation`, `pickupOrderLocation`.
- **SMS:** a **soft dependency** on `KommandhubSmsSW`. The gateway is injected with
  `@?Kommandhub\SmsSW\Notification\Gateway\NotificationGatewayInterface` (optional
  service) and used by duck typing against the local `Flow\Sms\SmsGateway` interface
  — this plugin does not require the SMS plugin in `composer.json`. When absent, the
  argument is `null` and the action no-ops. Do not type-hint container services
  against `SmsGateway`.

## 11. Configuration

`src/Resources/config/config.xml` — four booleans, read in storefront Twig via
`config('KmhClickAndPickSW.config.<key>')` and, where noted, by the storefront
controller. The plugin seeds the defaults on install/update for keys never saved
(`KmhClickAndPickSW::CONFIG_DEFAULTS`):

| Key | Default | Effect |
|-----|---------|--------|
| `enablePickupLocationSelection` | `true` | gates the in-checkout selector (Twig + controller) |
| `showStreetNameInPickupLocationSelectionField` | `false` | street in the option label |
| `showContactDetailInPickupLocationInfo` | `true` | contact rows in the info card |
| `enableGeolocationSorting` | `true` | "Use my location" button + proximity ordering of the location list (Twig + controller, see §7) |

The admin-notify action reads the sender email from core system config
(`SystemConfigService`), not from plugin config.

## 12. Extension & customization points

- **Slot rules:** decorate `PickupTimeSlotService` and override the protected
  `isAllowed()` to add cutoff/blackout/capacity logic (used by both slot generation
  and the checkout gate).
- **Twig blocks:** the storefront shipping-method override and the info card are
  fully block-wrapped; the admin `sw-order-detail` tab uses the core extension block.
- **Flow contracts:** `PickupLocationAware` / `OrderPickupLocationAware` let other
  pickup-related events reuse the same storers and actions.
- **Reuse services**, don't reach across modules: `PickupLocationSelectionResolver`
  (context → resolved selection), `PickupLocationAvailabilityService`,
  `PickupContextStorage`, `OrderPickupLocationWriter`.

## 13. Installation & compatibility (developers)

- `shopware/core` and `shopware/storefront` `~6.7.0`.
- PHP 8.2+ (CI runs 8.2; the dev Docker image is `dockware/shopware:6.7.8.0`).

### Composer

Package: `kommandhub/click-and-pick-sw`. It is **proprietary and not on public
Packagist**, so first make it resolvable — add Kommandhub's private Composer
registry, or the Git repository as a VCS source, to your project's
`composer.json`:

```jsonc
"repositories": [
  { "type": "vcs", "url": "git@github.com:KommandHub/KmhClickAndPickSW.git" }
]
```

Then require and enable it from the Shopware project root:

```bash
composer require kommandhub/click-and-pick-sw
bin/console plugin:refresh
bin/console plugin:install --activate KmhClickAndPickSW
bin/console cache:clear
```

Update an installed copy:

```bash
composer update kommandhub/click-and-pick-sw
bin/console plugin:refresh
bin/console plugin:update KmhClickAndPickSW
bin/console cache:clear
```

Without registry/VCS access, install from a release archive by unpacking it into
`custom/plugins/KmhClickAndPickSW`, then run the same `plugin:refresh` /
`plugin:install --activate` commands. Rebuild assets afterwards when needed
(`bin/build-administration.sh && bin/build-storefront.sh`).

### Lifecycle

Install/activate lifecycle (`KmhClickAndPickSW`):

- `install` / `update` → run the payment + shipping installers (idempotent).
- `activate` / `deactivate` → activate/deactivate the payment + shipping methods.
- Migrations create all tables/state/mail/flow entries.

## 14. Migrations, update & uninstall

Ordered by timestamp:

1. `1759696668PickupLocation` — location + sales-channel mapping tables (+ `active` index).
2. `1760109246AddReadyForPickupOrderState` — pickup-ready `ready` `order_delivery` state + transitions.
3. `1760113852PickupReadyMailTemplate` — mail template types/templates.
4. `1760115677AddPickupMailSendFlow` — flow that sends the customer pickup-ready mail.
5. `1760200000AddPickupAdminNotificationFlow` — flow for the admin pickup-order-placed mail.
6. `1760300000NormalizedPickupSchedule` — `timezone` column + opening-hour/special-hour tables.
7. `1760400000OrderPickupLocation` — order pickup record table.

**Update:** `update()` re-runs the installers so the payment/shipping methods
migrate if their ids/classes move; each installer is idempotent.

**Uninstall:** payment and shipping methods are **deactivated, never deleted**
(historical orders reference them). Without "keep user data", the five plugin tables
are dropped **child-first** (`order_pickup_location` → opening/special hours →
sales-channel mapping → `pickup_location`) so foreign keys don't block removal.
Orders are never deleted.

## 15. Performance, caching & indexing

- Selection lives in the context payload → no cart-payload bloat; reads are single
  indexed lookups scoped to `(token/customer, sales_channel)`.
- The context-resolved listener is gated on the pickup shipping method, so
  non-pickup requests do zero pickup work.
- Storefront `index()` filters by `salesChannels.id` without hydrating the
  association (one fewer batched query + no `SalesChannelEntity` hydration per row);
  to-many schedule associations load batched (`IN(...)`), so there is no N+1.
- Indexing: `pickup_location.active`; mapping PK + FK auto-index cover both
  directions (InnoDB appends the PK, so a single-column index is effectively
  `(col, id)`); schedule aggregates indexed on `(pickup_location_id, day_of_week|date)`;
  order pickup record unique on `(order_id, order_version_id)` + index on
  `pickup_location_id`.
- Availability is computed in PHP (per-location timezone) rather than SQL, so
  results are not cacheable across timezones — the working sets are small (a
  merchant's locations), and queries stay index-served.

## 16. Error handling, logging & debugging

- Cart problems surface as blocking `Error`s (see §5) with message keys under
  `checkout.kmh-click-and-pick.*` / `error.…`.
- An invalid/foreign/inactive location selection throws a
  `ConstraintViolationException` at the context switch (aborts the switch).
- Flow actions inject a PSR `logger` and log-and-swallow provider failures (missing
  template, mail/SMS send errors) so a flow run is not aborted by a notification.
- Debugging tips: the selection is in `sales_channel_api_context.payload`
  (`pickupLocationId`/`pickupTime`/`pickupComment`); the resolved selection is what
  the cart validator sees; the order record is in `kmh_order_pickup_location`
  and on `order.extensions.kmhPickupLocation`.

## 17. Local development

The plugin ships its own dev stack (`docker-compose.yml`, `Dockerfile`, `Makefile`).
Run from the plugin root:

```bash
make up            # start the dev stack
make shell         # bash into the app container
make test          # PHPUnit (phpunit.dist.xml). Filter: make test FILTER=SomeTest
make test-coverage # coverage (text + clover + html)
make analyse       # PHPStan (phpstan.dist.neon), src only
make cs / cs-fix   # php-cs-fixer dry-run / apply
make validate-plugin   # shopware-cli store compliance (needs make build)
make zip               # distributable package into build/ (needs make build)
make help          # full target list
```

Assets (generated; rebuild after changing admin/storefront source):

```bash
bin/build-administration.sh && bin/build-storefront.sh
```

## 18. Testing

- `tests/Unit/` mirrors `src/`; no kernel, PHPUnit `TestCase` with mocks.
- `tests/Integration/` is kernel-backed and tagged `#[Group('kernel')]` (e.g.
  `CartValidationTest`); it needs a booted Shopware + database.
- Run all: `make test`. No-kernel (CI-equivalent): `--exclude-group kernel`.
- **100% line coverage** is enforced in CI (`coverage-check … 100`); coverage source
  is `src/` excluding `Migration/`, `Resources/`, `DependencyInjection/`, `Entity/`,
  and the plugin bootstrap. `beStrictAboutCoverageMetadata` is on — every test
  declares `#[CoversClass]`/`#[UsesClass]`.

## 19. Static analysis & coding standards

- **PHPStan** level 9 (`phpstan.dist.neon`), analysing `src/`.
- **php-cs-fixer** (`.php-cs-fixer.dist.php`) and a `phpcs.dist.xml` ruleset.
- **CI** (`.github/workflows/php.yml`): composer validate → PHP lint → PHPStan →
  cs-fixer (dry-run) → PHPUnit (no-kernel) with the 100% coverage gate.
- Run locally before committing: `make cs-fix && make analyse && make test`.

## 20. Known technical limitations

- Slot generation offers times but does not enforce capacity/quota, lead-time/
  cutoff, or blackout windows — these are the `isAllowed()` extension seam (§12).
- Availability is timezone-evaluated in PHP and not cross-timezone cacheable
  (small working sets; queries stay index-served).
- The SMS integration is best-effort and duck-typed; there is no compile-time
  guarantee the optional plugin's gateway matches `SmsGateway`.
- Built admin/storefront assets must be regenerated as a release/packaging step.

## 21. Contributing

See `CONTRIBUTING.md` for the branch/commit conventions and the required quality
gates. In short: branch off `develop`, keep the change inside its feature module,
add/adjust tests with each behavior change, and ensure `make cs-fix && make analyse
&& make test` (100% coverage) passes before opening a PR.

## 22. License

Proprietary — © Kommandhub Limited. Licensed, not sold, for use with Shopware 6
under a separate commercial agreement. See `LICENSE`. Security policy: `SECURITY.md`.
