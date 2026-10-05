# pickup-checkout Specification

## Purpose
Let a storefront customer choose self pick-up, pick a location, time and comment, and block the order until that selection is valid.

## Requirements

### Requirement: Self pick-up and pay-on-pickup methods
The system SHALL provide a free `kmh_self_pickup` shipping method and a `kmh_pay_on_pickup` payment method whose availability rule requires the self pick-up shipping method.

#### Scenario: Pay on pickup offered
- **WHEN** a customer selects the self pick-up shipping method
- **THEN** the pay-on-pickup payment method is available

#### Scenario: Pay on pickup with other shipping
- **WHEN** the context has pay-on-pickup payment and a different shipping method
- **THEN** the cart carries a blocking `UnsupportedDeliveryMethodCartBlockerError` naming that shipping method

### Requirement: Storefront selection form
The system SHALL render, under the self pick-up shipping method in checkout, a location select, a date input, a time select and a comment textarea, submitted through the core shipping-form context switch, while the `enablePickupLocationSelection` config is true.

#### Scenario: Selection enabled
- **WHEN** the config is true and the customer views the shipping methods
- **THEN** the location select is shown under self pick-up

#### Scenario: Selection disabled
- **WHEN** the config is false
- **THEN** no pickup selection fields are rendered

### Requirement: Location options endpoint
The system SHALL serve `frontend.kmh.sales-channel.pickup-locations.index` returning option markup for locations that are active, assigned to the sales channel, and open on the current local date.

#### Scenario: Closed today
- **WHEN** an assigned active location has a closure override for today
- **THEN** it is not listed

### Requirement: Slot options endpoint
The system SHALL serve `frontend.kmh.sales-channel.pickup-locations.slots` returning option markup for the bookable slots of an active, channel-assigned location on a `date` query in `YYYY-MM-DD` format, interpreted as midnight in the location's timezone, and SHALL return an empty list for an unknown location or malformed date.

#### Scenario: Malformed date
- **WHEN** the slots endpoint is called with `date=tomorrow`
- **THEN** it renders no slot options

### Requirement: Selection persistence in context
The system SHALL persist the pickup location id, time and comment in the sales-channel context payload, scoped to the customer only when genuinely logged in (not while impersonated), so the selection survives the guest-to-customer login and never leaks across customers or sales channels.

#### Scenario: Survives login
- **WHEN** a guest selects a location and then logs in during checkout
- **THEN** the selection is still present after login

#### Scenario: Impersonation
- **WHEN** an admin impersonates a customer and changes the selection
- **THEN** the customer's own stored selection is not overwritten

### Requirement: Context switch handling
The system SHALL update the selection only when a context switch carries `pickupLocationId`: an empty value clears the selection, and a non-empty value is accepted only for an active location assigned to the current sales channel, otherwise the switch fails with a constraint violation.

#### Scenario: Unrelated switch
- **WHEN** a context switch changes only the payment method
- **THEN** the pickup selection is unchanged

#### Scenario: Remove selection
- **WHEN** a context switch sends an empty `pickupLocationId`
- **THEN** location, time and comment are cleared

#### Scenario: Foreign location
- **WHEN** a context switch sends the id of a location assigned only to another sales channel
- **THEN** the switch fails and nothing is saved

### Requirement: Cart validation gate
The system SHALL, when self pick-up is selected, read the persisted selection and add a blocking `PickupLocationRequiredCartBlockerError` when no valid active, channel-assigned location is selected, and a blocking `InvalidPickupTimeCartBlockerError` when a chosen time is not bookable.

#### Scenario: No location
- **WHEN** self pick-up is selected and no location is stored
- **THEN** the order is blocked with the location-required error

#### Scenario: Location deactivated after selection
- **WHEN** the stored location is deactivated before checkout completes
- **THEN** the order is blocked with the location-required error

#### Scenario: Unbookable time
- **WHEN** the stored time falls outside the location's opening hours
- **THEN** the order is blocked with the invalid-time error

#### Scenario: No time chosen
- **WHEN** a valid location is stored with no pickup time
- **THEN** no pickup error is added

### Requirement: Storefront display options
The system SHALL show the street in location option labels when `showStreetNameInPickupLocationSelectionField` is true, and SHALL show contact details in the location info card when `showContactDetailInPickupLocationInfo` is true.

#### Scenario: Street hidden by default
- **WHEN** the street option is false
- **THEN** option labels read `<name>, <city>`