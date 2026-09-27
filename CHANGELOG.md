# 0.9.0

First public (pre-1.0) release of Click and Pick for Shopware 6.7. Feature-complete
and fully unit/integration tested; the API and database schema may still change
before 1.0.0.

## Checkout & storefront
- Pickup-location selector in checkout for the `Self pick-up` shipping method,
  filtered by active state and sales-channel assignment.
- Customer can choose a pickup date and time, limited to the location's configured
  opening schedule (timezone-aware), plus optional pickup instructions.
- Selected pickup location, time and comment are persisted in the sales-channel
  context and shown on the confirmation/finish page.
- Server-side cart validation blocks checkout when the pickup shipping method is
  selected without a valid location, or with a time outside the schedule.

## Payment & shipping
- `Pay on pickup` payment method (installed on activate), restricted to the
  `Self pick-up` shipping context.
- `Self pick-up` shipping method (technical name `kmh_self_pickup`).

## Pickup data model
- Dedicated `kmh_order_pickup_location` entity stores each order's pickup
  location, chosen time and instructions — a OneToOne order extension and the
  single source of truth (replaces the previous order custom field).
- Deleting a pickup location nulls the reference (`ON DELETE SET NULL`) and keeps
  the order's historical pickup data.
- Normalized opening-hours and special-date (holiday/override) schedule with an
  IANA timezone per location and timezone-aware availability evaluation.

## Administration
- `Pickup Locations` module under **Content**: create/edit locations, assign sales
  channels, configure the opening schedule, contact and geo fields, active state.
- `Pickup information` tab on the order detail page showing the selected location,
  address/contact, chosen date/time and customer instructions (read-only, handles
  deleted locations gracefully).

## Order state, mail & Flow Builder
- Pickup-ready `order_delivery` state (technical name `ready`) with transitions.
- Mail templates for the customer pickup-ready mail and the admin
  pickup-order-placed notification.
- `pickup.order.placed` and `pickup.order.ready` Flow Builder triggers exposing the
  order, pickup location and pickup record (time/comment) as flow data.
- `Send pickup notification to admin` (mail) and `Send pickup SMS to location`
  Flow Builder actions; the SMS action is an optional soft dependency on
  KommandhubSmsSW and does nothing when that plugin is absent.

## Configuration
- Enable pickup-location selection, show street name in the selector, show contact
  details in the pickup info block.
