# pickup-geolocation-sorting Specification

## Purpose
Lets a consenting customer order the checkout pickup location list by distance from their approximate position, without the shop retaining that position.

## Requirements

### Requirement: Geolocation consent group
The system SHALL offer an optional (not required) storefront cookie consent group `kmh_pickup_location_geolocation` with a translated name and description in en-GB, de-DE and fr-FR.

#### Scenario: Consent group listed
- **WHEN** a customer opens the storefront cookie settings
- **THEN** the pickup geolocation group is listed as optional with its translated name and description

### Requirement: Geolocation sorting config
The system SHALL provide a per-sales-channel boolean config `enableGeolocationSorting`, defaulting to true on install.

#### Scenario: Default after install
- **WHEN** the plugin is installed and the config was never saved
- **THEN** `enableGeolocationSorting` is true

### Requirement: Use my location button
The system SHALL show a "Use my location" button with the pickup location select only when `enableGeolocationSorting` is true for the sales channel, the browser supports geolocation, and the customer has accepted the geolocation consent group.

#### Scenario: All conditions met
- **WHEN** the config is true, the browser supports geolocation and consent is accepted
- **THEN** the button is shown

#### Scenario: Consent not accepted
- **WHEN** the customer has not accepted the geolocation consent group
- **THEN** the button is not shown and no geolocation request is made

#### Scenario: Consent accepted during the visit
- **WHEN** the customer accepts the consent group while the checkout page is open
- **THEN** the button appears without a page reload

#### Scenario: Config disabled
- **WHEN** `enableGeolocationSorting` is false
- **THEN** the button is not shown

### Requirement: Customer-initiated position request
The system SHALL request the browser position only when the customer clicks the button, and SHALL then reload the location options ordered by proximity; when the browser denies, times out or fails, the system SHALL keep the current list and tell the customer their location could not be determined.

#### Scenario: Position granted
- **WHEN** the customer clicks the button and grants browser permission
- **THEN** the location options reload with the nearest location first

#### Scenario: No prompt without a click
- **WHEN** the checkout page loads or re-renders after a context switch
- **THEN** no browser position prompt is triggered

#### Scenario: Permission denied
- **WHEN** the customer clicks the button and denies browser permission
- **THEN** the location list stays as it was and a short notice says the location could not be determined

### Requirement: Coarse coordinate transmission
The system SHALL round the customer's latitude and longitude to 2 decimal places in the browser before sending them, and SHALL send them only as `lat` and `lon` query parameters on the location options request.

#### Scenario: Rounded before sending
- **WHEN** the browser reports latitude 6.524379 and longitude 3.379206
- **THEN** the location options request carries `lat=6.52` and `lon=3.38`

### Requirement: Proximity ordering
The system SHALL order the filtered location options by ascending great-circle distance from the supplied coordinates when `enableGeolocationSorting` is true and both `lat` (-90 to 90) and `lon` (-180 to 180) are valid numbers; locations without usable stored coordinates SHALL come last, ordered by name.

#### Scenario: Nearest first
- **WHEN** valid coordinates are supplied and three listed locations lie 2 km, 12 km and 40 km away
- **THEN** the options are ordered 2 km, 12 km, 40 km

#### Scenario: Availability filter still applies
- **WHEN** the nearest location is closed today
- **THEN** it is not listed, and the remaining locations are ordered by distance

#### Scenario: Location without coordinates
- **WHEN** valid coordinates are supplied and one location has a blank or non-numeric stored latitude
- **THEN** that location is listed after all locations with coordinates

#### Scenario: Equal distance
- **WHEN** two locations are the same distance away
- **THEN** they are ordered by name

### Requirement: Name ordering fallback
The system SHALL order the location options by name, ascending, whenever proximity ordering does not apply: no coordinates, only one of `lat` and `lon`, a non-numeric or out-of-range value, or `enableGeolocationSorting` set to false.

#### Scenario: No coordinates
- **WHEN** the location options are requested without `lat` and `lon`
- **THEN** the options are ordered by name

#### Scenario: Out-of-range latitude
- **WHEN** the request carries `lat=95&lon=3.38`
- **THEN** the coordinates are ignored and the options are ordered by name

#### Scenario: Missing longitude
- **WHEN** the request carries only `lat=6.52`
- **THEN** the options are ordered by name

#### Scenario: Config disabled with coordinates
- **WHEN** `enableGeolocationSorting` is false and the request carries valid coordinates
- **THEN** the coordinates are ignored and the options are ordered by name

### Requirement: Customer coordinates are not retained
The system SHALL use customer coordinates only to order the single request they arrive with. It SHALL NOT write them to the database, the sales-channel context, the server session, browser storage, application logs or caches, and SHALL NOT include distances in the response.

#### Scenario: Nothing stored
- **WHEN** a customer's coordinates have been used to order the list
- **THEN** no database row, context payload, session entry, browser storage entry or application log line contains them

#### Scenario: No distance in response
- **WHEN** the ordered options are returned
- **THEN** the response contains location options only, with no distance values

#### Scenario: Re-render forgets position
- **WHEN** the checkout form re-renders after a context switch
- **THEN** the list is no longer ordered by proximity until the customer clicks the button again
