# AGENT.md — Click and Pick SW Plugin Context

**For AI coding assistants working on `KmhClickAndPickSW`**

This document provides essential context for AI agents (Claude, Cursor, GitHub Copilot, etc.) working on the Click and Pick plugin for Shopware 6. It supplements the technical README.md with architecture patterns, coding conventions, and navigation shortcuts.

---

## Quick Reference

| What | Where |
|------|-------|
| **Plugin Name** | `KmhClickAndPickSW` |
| **Namespace** | `Kommandhub\ClickAndPickSW\` |
| **Shopware Version** | `~6.7.0` |
| **PHP Version** | `8.2+` |
| **Main Plugin Class** | `src/KmhClickAndPickSW.php` |
| **Admin Module** | `src/Resources/app/administration/src/module/kmh-pickup-location/` |
| **Storefront Assets** | `src/Resources/app/storefront/src/` |
| **Tests** | `tests/Unit/` (no kernel), `tests/Integration/` (with kernel) |
| **Migrations** | `src/Migration/` (7 migrations) |

---

## Core Concepts

### 1. What This Plugin Does

**Click and collect for Shopware 6:**
- Adds a `kmh_self_pickup` shipping method and `kmh_pay_on_pickup` payment method
- Manages pickup locations with addresses, opening hours (timezone-aware), and sales channel assignments
- Provides in-checkout pickup location + date/time + comment selection
- Persists selection in sales channel context (not cart payload)
- Creates an order pickup record (`kmh_order_pickup_location`) for every pickup order
- Adds a `ready` order delivery state for pickup-ready orders
- Integrates with Flow Builder for email/SMS notifications

### 2. Data Model (DAL Entities)

#### Core Entities

```
kmh_pickup_location
├── id, name, street, city, postal_code, email, phone_number
├── active (boolean, indexed)
├── default_sales_channel_id (FK to sales_channel, nullable) ⭐ NEW
├── time_format ('12h'|'24h'), timezone (IANA)
├── latitude, longitude, location_code
├── salesChannels (M2M via kmh_pickup_location_sales_channel)
├── openingHoursSchedule (OneToMany → kmh_pickup_location_opening_hour)
└── specialHours (OneToMany → kmh_pickup_location_special_hour)

kmh_pickup_location_opening_hour
├── pickup_location_id, day_of_week (ISO 1–7)
├── open_time, close_time (HH:MM format)
└── Indexed on (pickup_location_id, day_of_week)

kmh_pickup_location_special_hour
├── pickup_location_id, date
├── closed (boolean), open_time, close_time
└── Indexed on (pickup_location_id, date)

kmh_order_pickup_location (OneToOne with order)
├── order_id, order_version_id (composite FK, versioned)
├── pickup_location_id (FK, ON DELETE SET NULL)
├── pickup_time (DATETIME(3)), comment (TEXT)
└── Extended on order.extensions.kmhPickupLocation (autoloaded)
```

**Recent Addition:**
- `default_sales_channel_id` field added to `kmh_pickup_location` (merged into initial migration)
- Used when `enablePickupLocationSelection` config is disabled per sales channel
- When disabled, the default location is auto-selected and locked in the storefront

### 3. Architecture Patterns

#### Feature-First Structure
```
src/
  Checkout/          # Cart validation, payment handler, pickup selection storage
  Entity/            # DAL definitions, entities, collections
  Extension/         # Order extension for OneToOne pickup relationship
  Event/             # Business events (PickupOrderPlacedEvent, PickupOrderReadyEvent)
  Flow/              # Flow Builder aware contracts, storers, actions
  Listener/          # Event subscribers
  PickupLocation/    # Availability, slot generation, validation services
  Installer/         # Payment & shipping method installers (idempotent)
  Migration/         # 7 timestamped migrations
  Storefront/        # Controller for location/slot AJAX endpoints
  Twig/              # Twig extensions
  Resources/         # config, views, snippets, admin/storefront assets
```

#### Key Services

| Service | Purpose | Location |
|---------|---------|----------|
| `PickupContextStorage` | Persist/load selection in sales channel context | `Checkout/PickupSelection/` |
| `PickupLocationSelectionResolver` | Resolve stored selection to entities | `PickupLocation/` |
| `PickupLocationAvailabilityService` | Timezone-aware open/closed checks | `PickupLocation/Availability/` |
| `PickupTimeSlotService` | Generate bookable time slots | `PickupLocation/Availability/` |
| `PayOnPickupCartProcessor` | Cart validator (blocks checkout on errors) | `Checkout/Cart/` |
| `OrderPickupLocationWriter` | Create order pickup record on order placement | `Checkout/PickupSelection/` |
| `SalesChannelPickupLocationController` | AJAX endpoints for locations & slots | `Storefront/Controller/` |

---

## Configuration System

### Plugin Config (`config.xml`)

| Key | Type | Scope | Default | Purpose |
|-----|------|-------|---------|---------|
| `enablePickupLocationSelection` | bool | **Per sales channel** | `true` | Enable/disable customer selection ⭐ |
| `showStreetNameInPickupLocationSelectionField` | bool | Global | `false` | Show street in dropdown |
| `showContactDetailInPickupLocationInfo` | bool | Global | `true` | Show contact in info card |

⭐ **New behavior**: When `enablePickupLocationSelection` is disabled:
- Controller filters to only locations where `defaultSalesChannelId == current sales channel`
- Storefront select field is disabled (`disabled` attribute)
- Default location is auto-selected and locked (no "Please select" option)
- Template receives `selectionDisabled = true` flag

---

## Workflow: Selection & Validation

### 1. Storefront Selection Flow

```
Customer selects pickup shipping method
    ↓
JS plugin fetches locations (GET /kmh/sales-channel/{id}/pickup-locations)
    ├── If enablePickupLocationSelection = true:
    │     └── Shows all active locations for sales channel (open today)
    └── If enablePickupLocationSelection = false:
          └── Shows only location with defaultSalesChannelId == sales channel (locked)
    ↓
Customer picks date → JS fetches slots (GET .../location/{id}/slots?date=YYYY-MM-DD)
    ↓
Customer submits form → SwitchContextEvent → context switch endpoint
    ↓
SwitchContextEventListener validates & persists to sales_channel_api_context.payload
    ↓
Cart recalculation → PayOnPickupCartProcessor reads persisted selection
    ├── Valid location + time → allow checkout
    └── Invalid → cart blocker error
    ↓
Order placement → OrderListener creates kmh_order_pickup_location record
    └── Dispatches PickupOrderPlacedEvent (Flow Builder trigger)
```

### 2. Validation Gates

| Gate | Location | Blocks Checkout? | Error |
|------|----------|------------------|-------|
| Location exists + active + assigned | `PickupLocationValidator` | Yes | `ConstraintViolationException` |
| Pickup time is bookable | `PayOnPickupCartProcessor` | Yes | `InvalidPickupTimeCartBlockerError` |
| Pickup location selected when required | `PayOnPickupCartProcessor` | Yes | `PickupLocationRequiredCartBlockerError` |
| Pay-on-pickup requires self-pickup shipping | `PayOnPickupCartProcessor` | Yes | `UnsupportedDeliveryMethodCartBlockerError` |

---

## Admin Integration

### Pickup Location Module

**Path**: `src/Resources/app/administration/src/module/kmh-pickup-location/`

**Components**:
- `kmh-pickup-location-list` — data grid with filters
- `kmh-pickup-location-create` — create/edit page with base form + schedule editor
- `kmh-pickup-location-base-form` — address, contact, sales channels, **default sales channel** ⭐
- `kmh-pickup-location-schedule` — timezone + weekly intervals + special dates

**Key Fields**:
- `sw-entity-single-select` for `defaultSalesChannelId` (required) ⭐
- `sw-entity-multi-select` for `salesChannels` (M2M)
- Timezone selector (IANA)
- Time format toggle (12h/24h)

**Criteria**: Always load `salesChannels`, `defaultSalesChannel`, `openingHoursSchedule`, `specialHours` associations

### Order Detail Tab

**Path**: `src/Resources/app/administration/src/module/kmh-order-pickup-info/`

- Injects tab into `sw-order-detail` via route middleware
- Loads `kmh_order_pickup_location` by `orderId` (association: `pickupLocation`)
- Read-only view, handles deleted location gracefully

---

## Testing Strategy

### Unit Tests (`tests/Unit/`)
- **No kernel**, pure PHPUnit with mocks
- Mirrors `src/` structure
- 100% line coverage enforced (excludes `Migration/`, `Resources/`, `Entity/`)
- Every test requires `#[CoversClass]`

**Recent Fix**: `SalesChannelPickupLocationControllerTest` now mocks `SystemConfigService` (4th constructor arg)

### Integration Tests (`tests/Integration/`)
- Kernel-backed, tagged `#[Group('kernel')]`
- Requires booted Shopware + database
- Example: `CartValidationTest`

### Running Tests
```bash
make test                      # All tests
make test FILTER=SomeTest      # Filter
make test-coverage             # Coverage report (text + HTML)
make analyse                   # PHPStan level 9
make cs-fix                    # Auto-fix code style
```

---

## Common Tasks for AI Agents

### 1. Adding a New Field to Pickup Location

1. **Migration**: Add column to `Migration1759696668PickupLocation.php` (in dev, already merged for `default_sales_channel_id`)
2. **Definition**: Add field to `PickupLocationDefinition::defineFields()`
   - Use appropriate field type (`StringField`, `FkField`, `BoolField`, etc.)
   - Add flags (`Required`, `ApiAware`, etc.)
   - Add association if FK (e.g., `ManyToOneAssociationField`)
3. **Entity**: Add property + getter/setter to `PickupLocationEntity`
4. **Admin Form**: Add input to `kmh-pickup-location-base-form.html.twig`
5. **Admin Component**: Add to error mapping in `index.js` (if validated)
6. **Translations**: Update `snippet/{en-GB,de-DE,fr-FR}.json`
7. **Tests**: Update `PickupLocationControllerTest` criteria callback

### 2. Extending Slot Generation Logic

**Decorate** `PickupTimeSlotService` and override `isAllowed(\DateTimeImmutable $when, PickupLocationEntity $location): bool`

Example use cases:
- Cutoff time (e.g., no slots within 2 hours)
- Blackout periods (e.g., block next Monday)
- Capacity limits (e.g., max 5 orders per slot)

### 3. Adding a Flow Action

1. **Action class**: Create in `src/Flow/Action/`, extend `FlowAction`
2. **Service registration**: Explicitly tag in `services.yml` with `flow.action`
3. **Requirements**: Implement `requirements(): array` (aware contracts)
4. **Handle**: Implement `handleFlow(StorableFlow $flow): void`
5. **Translations**: Add action name to admin snippets

### 4. Modifying Storefront Template

**Files**:
- `src/Resources/views/storefront/component/shipping/custom/shipping-method.html.twig`
- `src/Resources/views/storefront/page/checkout/finish/finish-address.html.twig`

**Pattern**: Extend core blocks, check config flags, use context extensions

**Rebuild**: After changes, run `bin/build-storefront.sh`

---

## Timezone Handling

**All availability decisions run in the location's own IANA timezone:**
- Opening times are wall-clock strings (`HH:MM`)
- `PickupLocationAvailabilityService` evaluates intervals in `location.timezone`
- Storefront date picker builds `YYYY-MM-DD 00:00:00` in location timezone
- Stored `pickup_time` is `DATETIME(3)` (ISO-8601 with offset)

**Defaults**: When `timezone` is null/invalid, falls back to `UTC`

---

## Coding Conventions

### PHP
- PSR-12 style (enforced by `php-cs-fixer`)
- PHPStan level 9 (no `@var`, minimal `@phpstan-ignore`)
- Type hints everywhere (strict types, return types, property types)
- Named arguments for clarity (e.g., `new Criteria(page: 1, limit: 25)`)
- No `@internal` in public API unless genuinely internal

### Documentation & Comments
**IMPORTANT**: Always add proper documentation and comments to explain code intent and behavior.

#### When to Document:
1. **Every public method** — include a docblock with:
   - Brief description of what it does
   - `@param` tags with types and descriptions
   - `@return` tag with type and description
   - `@throws` if the method can throw exceptions

2. **Complex logic** — add inline comments explaining:
   - **Why** the code does something (not just what)
   - Business rules being enforced
   - Workarounds or special cases
   - Performance considerations

3. **Class-level docblocks** — explain:
   - Purpose of the class
   - Responsibility within the architecture
   - Key design decisions

4. **Non-obvious patterns** — document:
   - Timezone handling (always mention which timezone context)
   - Filtering logic (explain criteria intent)
   - State transitions (why this state change matters)
   - Extension seams (how to properly extend)

#### Example of Good Documentation:
```php
/**
 * Generates bookable time slots for a pickup location on a specific date.
 *
 * Slots are stepped by slotStepMinutes (default 30) within each open interval,
 * keeping the entire slot inside the interval and excluding past/disallowed slots.
 *
 * Extension seam: Override isAllowed() to add cutoff/blackout/capacity rules.
 *
 * @param PickupLocationEntity $location The location with timezone context
 * @param \DateTimeImmutable $date The target date in location's timezone
 * @param \DateTimeImmutable|null $now Optional reference time (defaults to now)
 * @return list<\DateTimeImmutable> Array of bookable slot start times
 */
public function getSlots(
    PickupLocationEntity $location,
    \DateTimeImmutable $date,
    ?\DateTimeImmutable $now = null
): array {
    // Implementation with inline comments explaining non-obvious steps
}
```

#### What NOT to Document:
- Obvious getters/setters (unless they have side effects)
- Self-explanatory code (e.g., `$sum = $a + $b;`)
- Implementation details that PHPStan/type hints already convey

**Rule of thumb**: If you need to read the implementation to understand what it does, it needs documentation.

### JavaScript (Admin)
- Vue 3 composition pattern
- Shopware's `Criteria` builder for queries
- Use `repositoryFactory.create('entity_name')`
- Always load associations explicitly
- Use `sw-entity-single-select` / `sw-entity-multi-select` for relationships
- **Add JSDoc comments** for complex computed properties and methods

### Twig
- Block-first: every section wrapped in `{% block %}`
- Config checks: `config('KmhClickAndPickSW.config.key')`
- Context extensions: `context.extensions.pickupLocation`
- **Add Twig comments** (`{# #}`) to explain conditional logic and complex blocks

---

## Important Gotchas & Patterns

### DI & Service Configuration
- **Autowiring is enabled** via `../../*` glob in `services.yml`
- Excluded from autowiring: `Migration/`, `Tests/`, `DependencyInjection/`
- `Entity/` stays in the glob (DAL definitions need `#[AutoconfigureTag('shopware.entity.definition')]`)
- **Symfony does not auto-alias interfaces**: If you add a constructor-injected interface with a single implementation, add an explicit `alias:` entry in `services.yml`

### Constants & IDs
- **Custom field/entity/state IDs are constants** on the owning class:
  - `CustomFieldsInstaller::ORDER_PICKUP_LOCATION_CUSTOM_FIELD`
  - `PickupLocationDefinition::ENTITY_NAME`
  - IDs on `KmhClickAndPickSW` class
- These are global across the install and the lookup key for stored data
- A rename must happen in exactly one place

### Bootstrap & Installers
- **Bootstrap delegates to idempotent installers**
- Both `install()` and `update()` run all installers (they no-op when record exists)
- `uninstall()` deactivates (not deletes) payment/shipping methods (historical orders reference them)
- Tables only dropped when `keepUserData() = false`

### Validation & Security
- **Pickup selection is validated server-side**
- `SwitchContextEventListener` checks submitted ID is active location mapped to current sales channel
- Value comes from storefront request, never trust client-side

### Error Handling
- **Errors are logged, never swallowed**
- Optional side effects (e.g., admin notification) catch and continue, but log through `Psr\Log\LoggerInterface` (error level)
- Production keeps a record of all failures

### Module Boundaries
- **Keep changes inside their feature module**
- Reach across modules through a service, not by deep-linking internals
- One obvious home per class

---

## Debugging Tips

### Selection Not Persisting?
- Check `sales_channel_api_context.payload` for `pickupLocationId`, `pickupTime`, `pickupComment`
- Verify `SwitchContextEventListener` is fired (breakpoint in `onSwitchContext`)
- Confirm shipping method is `kmh_self_pickup` (listener is gated)

### Cart Blocker Appearing?
- `PayOnPickupCartProcessor` reads from **persisted context**, not request
- Use `PickupLocationSelectionResolver::resolve()` to see what validator sees
- Check `PickupTimeSlotService::isBookable()` for time validation

### Admin Field Not Showing?
- Did you rebuild admin? `NODE_OPTIONS="--max-old-space-size=4096" ./bin/build-administration.sh`
- Check browser console for JS errors
- Verify criteria loads the association (e.g., `defaultSalesChannel`)
- Clear browser cache (hard refresh: Cmd+Shift+R / Ctrl+Shift+R)

### Migration Not Running?
- `bin/console database:migrate --all KmhClickAndPickSW`
- Check `migration` table for timestamp
- In dev, drop + recreate tables if structure changed

---

## Performance Considerations

1. **Context payload is small**: Selection is 3 string keys, not entity hydration
2. **No N+1**: Schedule associations load batched (`IN(...)` queries)
3. **Filtered hydration**: Controller loads `salesChannels.id` filter without hydrating the association
4. **Indexed queries**: `active`, `(pickup_location_id, day_of_week)`, `(order_id, order_version_id)`
5. **Timezone computation in PHP**: Small working sets (merchant's locations), not cacheable across zones

---

## Extension Seams

1. **Slot rules**: Decorate `PickupTimeSlotService::isAllowed()`
2. **Flow contracts**: Reuse `PickupLocationAware`, `OrderPickupLocationAware` in custom events
3. **Twig blocks**: All storefront templates are fully block-wrapped
4. **Admin components**: Override `kmh-pickup-location-*` components

---

## Dependencies & Integration Points

### Required
- `shopware/core` `~6.7.0`
- `shopware/storefront` `~6.7.0`

### Optional (Duck-typed)
- `KommandhubSmsSW` — SMS notifications via `@?` service injection

### Shopware APIs Used
- DAL (entities, repositories, criteria, search)
- Cart validators (`shopware.cart.validator` tag)
- Flow Builder (events, storers, actions)
- Sales channel context persistence
- State machines (order delivery states)
- Mail service
- Twig extensions

---

## Recent Changes (Last Session)

### Default Sales Channel Feature
**Context**: Allow store owners to force a specific pickup location per sales channel when customer selection is disabled.

**Changes**:
1. ✅ Added `default_sales_channel_id` to `kmh_pickup_location` table (merged into initial migration)
2. ✅ Updated `PickupLocationDefinition` with `FkField` + `ManyToOneAssociationField`
3. ✅ Updated `PickupLocationEntity` with property + getter/setter
4. ✅ Made `enablePickupLocationSelection` config **per-sales-channel** (`salesChannelScope: true`)
5. ✅ Updated controller to filter by `defaultSalesChannelId` when selection disabled
6. ✅ Added `SystemConfigService` to controller constructor (breaking change for tests!)
7. ✅ Updated admin form to include required `sw-entity-single-select` for default sales channel
8. ✅ Added translations (en-GB, de-DE, fr-FR)
9. ✅ Updated storefront template to disable select when config is off
10. ✅ Fixed test: `SalesChannelPickupLocationControllerTest` now mocks `SystemConfigService`

**Behavior**:
- When `enablePickupLocationSelection = false` for a sales channel:
  - Controller returns only the location with `defaultSalesChannelId == sales_channel_id`
  - Select field is disabled (`<select disabled>`)
  - Location is auto-selected (no "Please select" option)
  - Template receives `selectionDisabled: true`

---

## Files to Know

### Core Logic
- `src/Checkout/Cart/PayOnPickupCartProcessor.php` — cart validation gate
- `src/Checkout/PickupSelection/PickupContextStorage.php` — context persistence
- `src/PickupLocation/PickupLocationSelectionResolver.php` — resolve stored → entity
- `src/PickupLocation/Availability/PickupLocationAvailabilityService.php` — timezone logic
- `src/PickupLocation/Availability/PickupTimeSlotService.php` — slot generation

### Storefront
- `src/Storefront/Controller/SalesChannelPickupLocationController.php` — AJAX endpoints
- `src/Resources/views/storefront/component/shipping/custom/shipping-method.html.twig` — checkout form
- `src/Resources/app/storefront/src/sales-channel-pickup-location/sales-channel-pickup-location.plugin.js`

### Admin
- `src/Resources/app/administration/src/module/kmh-pickup-location/` — location module
- `src/Resources/app/administration/src/module/kmh-order-pickup-info/` — order tab

### Configuration
- `src/Resources/config/services.yml` — DI container config
- `src/Resources/config/config.xml` — plugin settings
- `src/Resources/config/routes.yml` — storefront routes

---

## When in Doubt

1. **Check README.md** for architecture deep-dives
2. **Run tests** to verify behavior: `make test FILTER=YourTest`
3. **Check PHPStan** for type errors: `make analyse`
4. **Rebuild assets** after JS/Twig changes: `bin/build-administration.sh && bin/build-storefront.sh`
5. **Look at existing code**: The plugin follows consistent patterns — find a similar feature and mirror its structure

---

## Commands Cheat Sheet

```bash
# Development
make up                 # Start Docker stack
make shell              # Enter container
make test               # Run all tests
make test-coverage      # Coverage report
make analyse            # PHPStan
make cs-fix             # Fix code style

# Shopware
bin/console plugin:refresh
bin/console plugin:install --activate KmhClickAndPickSW
bin/console plugin:update KmhClickAndPickSW
bin/console cache:clear
bin/console database:migrate --all KmhClickAndPickSW

# Assets (increase memory for Node)
export NODE_OPTIONS="--max-old-space-size=4096"
./bin/build-administration.sh
./bin/build-storefront.sh

# Distribution
make zip                # Build release package
```

---

**Last Updated**: 2026-09-30 (after default sales channel implementation)
