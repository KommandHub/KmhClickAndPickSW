# pickup-order-record Specification

## Purpose
Keep each pickup order's location, time and comment as durable order data, and track when a pickup order is ready for collection.

## Requirements

### Requirement: Order pickup record on placement
The system SHALL, when an order is placed with self pick-up and a valid selection, create one `kmh_order_pickup_location` row with the order id and version, location id, pickup time and comment, and then clear the selection from the context.

#### Scenario: Pickup order placed
- **WHEN** a customer places an order with self pick-up, a location and a time
- **THEN** a pickup record holds that location and time
- **AND** a new cart in the same session starts with no pickup selection

#### Scenario: Non-pickup order
- **WHEN** an order is placed with another shipping method
- **THEN** no pickup record is created

### Requirement: Order extension
The system SHALL expose the pickup record as the autoloaded one-to-one order extension `kmhPickupLocation`, with its location association autoloaded, and SHALL delete the record when the order is deleted.

#### Scenario: Order read
- **WHEN** an order is loaded through the DAL without extra associations
- **THEN** `order.extensions.kmhPickupLocation` carries the pickup record

### Requirement: Location deletion keeps history
The system SHALL set the record's location reference to null when the pickup location is deleted, keeping the pickup time and comment.

#### Scenario: Location deleted
- **WHEN** a location referenced by past orders is deleted
- **THEN** those orders keep pickup time and comment with a null location

### Requirement: Ready-for-pickup delivery state
The system SHALL add a `ready` state to the `order_delivery.state` machine with transitions `ready` (open to ready), `reopen` (ready to open), `ship` (ready to shipped) and `cancel` (ready to cancelled).

#### Scenario: Mark ready
- **WHEN** an admin applies the `ready` transition to an open delivery
- **THEN** the delivery state becomes `ready`

### Requirement: Finish page pickup card
The system SHALL show the pickup location, pickup time and comment on the checkout finish page for orders with a pickup record.

#### Scenario: Finish page
- **WHEN** a customer completes a pickup order
- **THEN** the finish page shows the location card with time and comment

### Requirement: Admin order pickup tab
The system SHALL add a read-only "Pickup information" tab to the administration order detail page that shows an empty state for orders without a record, the location with time and comment when the location exists, and a location-deleted state with time and comment when it was deleted.

#### Scenario: Deleted location in admin
- **WHEN** an admin opens a pickup order whose location was deleted
- **THEN** the tab shows the location-deleted state with the stored time and comment
