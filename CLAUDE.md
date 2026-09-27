# KmhClickAndPickSW

Shopware 6 click-and-collect plugin: pickup-location selection in checkout, a
`Self pick-up` shipping method and a `Pay on pickup` payment method, plus a
`ready_for_pickup` order-delivery state with customer/admin mail automation. PHP
namespace root: `Kommandhub\ClickAndPickSW\` → `src/`.

## Commands

All commands run inside the plugin's own Docker stack (see `Makefile`). The
plugin is mounted into a dockware Shopware install.

- `make up` / `make down` — start / tear down the stack
- `make test` — PHPUnit (`phpunit.dist.xml`). Filter: `make test FILTER=SomeTest`
- `make test-coverage` — HTML + text coverage report
- `make analyse` — PHPStan (`phpstan.dist.neon`, level 9), `src` only
- `make cs` / `make cs-fix` — php-cs-fixer dry-run / apply
- `make validate-plugin` — shopware-cli store-compliance validation
- `make zip` — build a distributable zip into `build/`
- `make shell` — bash into the container

Run `make cs-fix && make analyse && make test` before committing.

CI (`.github/workflows/php.yml`) runs lint + PHPStan + php-cs-fixer + the
kernel-free unit suite with `CI=true`, which makes `tests/bootstrap.php` skip the
Shopware bootstrap and load the plain autoloader.

## Architecture

**Feature-first modules** under `src/`, following Shopware's own plugin layout. A
top-level directory *is* a boundary; inside it, flat Symfony-idiomatic folders
(`Service`, `Listener`, `Controller`, `Event`, `Error`, `Twig`). One obvious
home per class.

- `Entity/PickupLocation/` — the `kmh_pickup_location` DAL entity
  (definition/entity/collection), its `Aggregate/…SalesChannelMapping/`
  many-to-many join to `sales_channel`, and the normalized schedule aggregates
  `Aggregate/PickupLocationOpeningHour` (weekly interval rows, ISO `dayOfWeek`
  1–7, `HH:MM` local times) and `Aggregate/PickupLocationSpecialHour` (date
  overrides: closures or exceptional hours). The location carries an IANA
  `timezone`; the legacy `openDays`/`openingHours`/`closingHours` fields are kept
  **deprecated** for one release (backfilled, not written by the UI).
- `PickupLocation/Availability/` — `PickupLocationAvailabilityService`
  (`isOpenAt`/`filterOpen`) decides openness **in the location's own timezone**:
  local date+weekday, special-date override wins, else the weekly schedule,
  `[open, close)` boundaries, overnight-wrap supported. Pure/stateless — the
  caller loads `openingHoursSchedule` + `specialHours` once and evaluates every
  location without extra queries. `Weekday` maps names↔ISO. This is the seam for
  future cutoff/blackout/capacity rules.
- `Entity/Order/Aggregated/OrderDelivery/` — constants for the added
  `ready_for_pickup` delivery state and its transitions.
- `Checkout/Cart/` — `PayOnPickupCartProcessor` (a `CartValidatorInterface` that
  blocks `Pay on pickup` unless the `Self pick-up` shipping method is selected)
  plus its `Error/`.
- `Checkout/Payment/` — `PayOnPickupPaymentHandler` (a thin `DefaultPayment`; the
  method itself is created by `Installer/PaymentMethodInstaller`).
- `Installer/` — idempotent installers run from the plugin bootstrap:
  `PaymentMethodInstaller` (the `Pay on pickup` method + its self-pickup
  availability rule), `ShippingMethodInstaller` (the `Self pick-up` method,
  delivery time and free price), `CustomFieldsInstaller` (order pickup-location
  custom field set). Each owns its fixed ids and no-ops when the record exists.
- `Listener/` — `OrderListener` (persist pickup location to the order, attach it
  as an extension on load, dispatch the placed-order trigger — it sends **no**
  mail; notifications are Flow Builder's job), `PickupOrderReadyListener`
  (dispatch the ready trigger when a pickup
  order's delivery enters the `ready` state — filtered to the enter side, gated
  on a resolvable pickup location), `SwitchContextEventListener` (validate +
  persist the selected pickup location in the sales-channel context),
  `BusinessEventCollectorListener` (register both flow events).
- `Event/` — two Flow Builder triggers, each `OrderAware` **and**
  `PickupLocationAware` (exposing the order + the pickup location):
  `PickupOrderPlacedEvent` (`pickup.order.placed`, dispatched by `OrderListener`
  on `CheckoutOrderPlacedEvent`) and `PickupOrderReadyEvent`
  (`pickup.order.ready`, dispatched by `PickupOrderReadyListener` on the delivery
  state entering `ready`). Both fire only for orders with a resolvable pickup
  location, so normal delivery orders never trigger them.
- `Flow/` — Flow Builder integration: `Aware/PickupLocationAware` (the reusable
  data contract pickup events implement), `Storer/PickupLocationFlowStorer`
  (stores the location id, lazily reloads the entity; auto-tagged `flow.storer`),
  `Action/SendPickupNotificationToAdminAction` (emails the pickup location so
  it can prepare the order — requires `OrderAware` + `PickupLocationAware`;
  tagged `flow.action` in `services.yml` since that tag is not autoconfigured),
  and `Action/SendSmsToPickupLocationAction` (texts the location's phone). The
  SMS action has a **soft dependency** on `KommandhubSmsSW` — not in composer:
  its gateway is injected with `@?Kommandhub\SmsSW\…\NotificationGatewayInterface`
  (null when that plugin is absent/inactive), typed `?object`, and used by duck
  typing against the local `Flow/Sms/SmsGateway` interface (which nothing
  implements — it exists only to type the call sites). Null gateway → no-op.
  The `pickup.order.placed → notify admin` flow is created by
  `Migration…AddPickupAdminNotificationFlow`, so the admin mail is sent
  exclusively through Flow Builder, not from `OrderListener`. The action's
  Administration integration (so it is selectable/configurable in the Flow
  Builder UI) lives under `Resources/app/administration/src/`:
  `constant/` (action name + group), `extension/sw-flow-sequence-action`
  (title/icon/modal/description override + `flowBuilderService` group
  registration via `addServiceProviderDecorator` — the group must go through the
  service since `actionGroups` derives from `flowBuilderService.getGroups()`),
  `component/sw-flow-pickup-notify-modal` (optional mail-template config), and
  `snippet/` (labels + the `sw-flow.actions.group.kmhClickAndPick` title,
  registered with `Locale.extend`). All are imported from `main.js`.
- `Storefront/Controller/` — `SalesChannelPickupLocationController` (AJAX list +
  detail rendering for the checkout selector).
- `Twig/FormattedCalendarDays.php` — `format_calendar_days` Twig function
  (weekday sorting + range collapsing).
- `Migration/` — pickup-location tables, `ready_for_pickup` state, mail templates
  + flow (Shopware discovers migrations by folder). The shipping-method migration
  is now a recorded no-op — that creation moved to `ShippingMethodInstaller`.
- `Resources/` — `config/{services.yml,routes.yml,config.xml}`, admin (Vue)
  pickup-location module, storefront JS/Twig, snippets. Built assets in
  `Resources/public/` and `app/*/dist/` are generated — never hand-edit.

## Conventions & gotchas

- **DI is autowired** via the `../../*` glob in `services.yml`; `Migration`,
  `Tests`, `DependencyInjection`, `Kernel.php` are excluded. `Entity` stays in
  the glob — the DAL definitions register through their
  `#[AutoconfigureTag('shopware.entity.definition')]` attribute, so excluding it
  would unregister the entity.
- **Symfony does not auto-alias interfaces.** If you add a constructor-injected
  `*Interface` with a single implementation, add an explicit `alias:` entry.
- **Errors are logged, never swallowed.** Optional side effects (e.g. the admin
  pickup notification) catch and continue, but log through the injected
  `Psr\Log\LoggerInterface` (`error` level) so production keeps a record.
- **Custom-field / entity / state ids are constants** on the class that owns
  them (`CustomFieldsInstaller::ORDER_PICKUP_LOCATION_CUSTOM_FIELD`,
  `PickupLocationDefinition::ENTITY_NAME`, ids on `KmhClickAndPickSW`).
  They are global across the install and the lookup key for stored data — a
  rename must happen in exactly one place.
- **The bootstrap delegates to idempotent installers.** `install` and `update`
  both run all three installers (they no-op when the record exists), so a moved
  handler id or a fresh install both converge. `uninstall` deactivates the
  payment and shipping methods rather than deleting them (historical orders
  reference them) and drops pickup tables only when `keepUserData()` is false.
- **Pickup selection is validated server-side.** `SwitchContextEventListener`
  checks the submitted id is an active location mapped to the current sales
  channel before persisting it to the context — the value comes from the
  storefront request.
- Keep a change inside its feature module; reach across modules through a
  service, not by deep-linking another module's internals.
- Tests mirror `src/` under `tests/Unit/` (kernel-free, run in CI) and
  `tests/Integration/` (kernel/database). Add a test with each behaviour change.

## Status

License is `proprietary` (internal Kommandhub plugin), versioned `1.0.0`. Unlike
the public Kommandhub payment plugins, this repo ships no third-party trademark
notice — it integrates no external branded service.
