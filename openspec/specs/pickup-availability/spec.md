# pickup-availability Specification

## Purpose
Decide when a pickup location is open and which pickup time slots a customer may book, evaluated in the location's own timezone.

## Requirements

### Requirement: Location timezone evaluation
The system SHALL evaluate all open/closed decisions in the location's IANA `timezone`, and SHALL fall back to UTC when the timezone is empty or invalid.

#### Scenario: Location ahead of server
- **WHEN** a location in `Asia/Tokyo` opens 09:00-18:00 and the instant is 2026-10-01T01:00Z
- **THEN** the location is open (10:00 local)

#### Scenario: Invalid timezone
- **WHEN** a location's timezone is `Not/AZone`
- **THEN** its schedule is evaluated in UTC

### Requirement: Open at an instant
The system SHALL treat a location as open at an instant when the local time falls inside an interval for that local date, with special-date overrides replacing the weekly schedule, intervals half-open `[open, close)`, and an interval whose close is earlier than its open wrapping past midnight.

#### Scenario: Closing time exclusive
- **WHEN** a location opens 09:00-18:00 and the local time is 18:00
- **THEN** the location is closed

#### Scenario: Overnight interval
- **WHEN** a location opens 22:00-02:00 and the local time is 01:00
- **THEN** the location is open

#### Scenario: Zero-length interval
- **WHEN** an interval has equal open and close times
- **THEN** it never counts as open

#### Scenario: Closure row wins
- **WHEN** a date has one special hour marked `closed` and another with times
- **THEN** the location is closed for the whole date

### Requirement: Open on a date
The system SHALL treat a location as open on a local date when that date has at least one usable interval (from overrides when present, otherwise the weekly schedule), regardless of the current time of day.

#### Scenario: Opens later today
- **WHEN** the local time is 07:00 and the location opens 09:00-18:00 today
- **THEN** the location counts as open on today's date

### Requirement: Time slot generation
The system SHALL generate pickup slots for a location and date by stepping each open interval in increments of the configured step (default 30 minutes), offering a slot start T only when `[T, T+step)` fits inside the interval, T is in the future, and the slot passes the allowed-rule check.

#### Scenario: Last slot fits before close
- **WHEN** a location opens 09:00-10:00 with a 30-minute step and the date is in the future
- **THEN** the slots are 09:00 and 09:30

#### Scenario: Past slots excluded
- **WHEN** slots are requested for today at local 09:40 for a 09:00-11:00 interval
- **THEN** the slots are 10:00 and 10:30

### Requirement: Bookable time check
The system SHALL consider a pickup time bookable only when the location is open at that instant and the time passes the allowed-rule check.

#### Scenario: Time outside schedule
- **WHEN** a customer's chosen time is 20:00 local and the location closes at 18:00
- **THEN** the time is not bookable

### Requirement: Allowed-rule extension seam
The system SHALL route both slot generation and the bookable check through one overridable allowed-rule hook, which accepts every time by default; capacity, cut-off and blackout rules are not enforced.

#### Scenario: Default rules
- **WHEN** no decorator overrides the allowed-rule hook
- **THEN** every time inside an open interval is allowed