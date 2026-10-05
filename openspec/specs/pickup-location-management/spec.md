# pickup-location-management Specification

## Purpose
Merchants maintain the physical locations where customers collect orders: address, contact, timezone, schedule, and which sales channels offer each location.

## Requirements

### Requirement: Pickup location entity
The system SHALL store pickup locations in `kmh_pickup_location` with required `name`, `street`, `email`, `city`, `postalCode` and `defaultSalesChannelId`, and optional `phoneNumber`, additional address lines, `timeFormat`, `timezone` (IANA), `latitude`, `longitude`, `locationCode` and `active`.

#### Scenario: Create with required fields
- **WHEN** an admin saves a location with name, street, email, city, postal code and default sales channel
- **THEN** the location is persisted

#### Scenario: Missing required field
- **WHEN** an admin saves a location without an email
- **THEN** the write is rejected with a field error on `email`

### Requirement: Sales-channel assignment
The system SHALL link a location to zero or more sales channels through `kmh_pickup_location_sales_channel`, and SHALL offer a location to customers only in sales channels it is assigned to.

#### Scenario: Location not assigned to channel
- **WHEN** a location is active but not assigned to sales channel A
- **THEN** customers in sales channel A cannot list or select it

### Requirement: Weekly opening schedule
The system SHALL store weekly opening intervals per location as `day_of_week` (ISO 1-7) with `open_time` and `close_time` as local `HH:MM` wall-clock strings, allowing several intervals per day.

#### Scenario: Split day
- **WHEN** a location has Monday intervals 09:00-12:00 and 14:00-18:00
- **THEN** both intervals are stored and used for Monday availability

### Requirement: Special-date overrides
The system SHALL store date-specific overrides per location, each either marked `closed` or carrying an `open_time`/`close_time`, and these overrides SHALL take precedence over the weekly schedule for that date.

#### Scenario: Holiday closure
- **WHEN** a location has a special hour on 2026-12-25 with `closed` set
- **THEN** the location is treated as closed all of 2026-12-25 regardless of its weekly schedule

### Requirement: Schedule removal with location
The system SHALL delete a location's opening hours and special hours when the location is deleted.

#### Scenario: Delete location
- **WHEN** an admin deletes a location
- **THEN** its opening-hour and special-hour rows are removed

### Requirement: Administration module
The system SHALL provide a `kmh-pickup-location` administration module under Content with a list page, a create/edit page, sales-channel assignment, and a schedule editor for timezone, weekly intervals and special dates, guarded by `kmh_pickup_location` viewer, editor, creator and deleter ACL roles.

#### Scenario: Viewer cannot edit
- **WHEN** an admin user has only the viewer role for `kmh_pickup_location`
- **THEN** the module shows locations read-only and offers no create, edit or delete actions