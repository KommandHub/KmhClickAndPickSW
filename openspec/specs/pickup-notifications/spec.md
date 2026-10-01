# pickup-notifications Specification

## Purpose
Tell customers and pickup locations about pickup orders through Flow Builder triggers, actions and seeded mail flows.

## Requirements

### Requirement: Pickup order placed trigger
The system SHALL dispatch the `pickup.order.placed` flow event after a pickup order record is created, carrying the order, pickup location and pickup record.

#### Scenario: Placed event
- **WHEN** a pickup order is placed
- **THEN** `pickup.order.placed` fires with `order`, `pickupLocation` and `pickupOrderLocation` data

### Requirement: Pickup order ready trigger
The system SHALL dispatch the `pickup.order.ready` flow event when an order delivery enters the `ready` state and the order has a pickup record with an existing location.

#### Scenario: Ready event
- **WHEN** a pickup order's delivery enters `ready`
- **THEN** `pickup.order.ready` fires with `order`, `pickupLocation` and `pickupOrderLocation` data

#### Scenario: Not a pickup order
- **WHEN** a delivery without a pickup record enters `ready`
- **THEN** no pickup event fires

#### Scenario: Location deleted
- **WHEN** a pickup order whose location was deleted enters `ready`
- **THEN** no pickup event fires

### Requirement: Trigger mail recipient
The pickup triggers SHALL address mail to the order customer, and SHALL provide an empty recipient list when the customer or email is missing.

#### Scenario: Missing customer email
- **WHEN** a ready event fires for an order whose customer has no email
- **THEN** the mail recipient list is empty

### Requirement: Flow data restore
The system SHALL store only the ids of the pickup location and pickup record in flow data and reload the entities when the flow runs, so delayed flows still have the data.

#### Scenario: Delayed flow
- **WHEN** a flow on a pickup trigger is delayed and runs later
- **THEN** `pickupLocation` and `pickupOrderLocation` are reloaded and available

### Requirement: Notify location by email action
The system SHALL provide the `action.kmh.pickup.notify_admin` flow action, requiring order and pickup-location data, that emails the pickup location's own email using the configured mail template or the plugin's default admin template, with the sender taken from `core.basicInformation.email` when valid.

#### Scenario: Location notified
- **WHEN** the action runs for a pickup order whose location email is valid
- **THEN** the location receives the mail with order, customer, location and pickup record data

#### Scenario: Invalid location email
- **WHEN** the location email is missing or invalid
- **THEN** no mail is sent and a warning is logged

#### Scenario: Send failure
- **WHEN** the template is missing or sending throws
- **THEN** an error is logged and the flow continues

### Requirement: Notify location by SMS action
The system SHALL provide the `action.kmh.pickup.notify_sms` flow action that, when the optional `KommandhubSmsSW` gateway is present and configured for the sales channel, texts the location's phone number (digits only) with the order number, location name and pickup time when known.

#### Scenario: SMS plugin absent
- **WHEN** `KommandhubSmsSW` is not installed
- **THEN** the action does nothing

#### Scenario: SMS sent
- **WHEN** the gateway is configured and the location phone is `+49 30 1234`
- **THEN** an SMS goes to `49301234` reading `Pickup order <number> for <location>. Pickup time: <Y-m-d H:i>.`

#### Scenario: No phone number
- **WHEN** the location has no usable phone number
- **THEN** no SMS is sent and a warning is logged

### Requirement: Seeded flows and templates
The system SHALL install mail templates for the customer pickup-ready mail and the location order-placed mail, a flow sending the customer mail on `pickup.order.ready`, and a flow running the notify-location action on `pickup.order.placed`.

#### Scenario: Fresh install
- **WHEN** the plugin is installed and a pickup order later becomes ready
- **THEN** the customer receives the pickup-ready mail showing location and pickup time