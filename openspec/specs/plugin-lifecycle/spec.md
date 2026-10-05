# plugin-lifecycle Specification

## Purpose
Install, update, activate, deactivate and uninstall the plugin without breaking historical orders or merchant settings.

## Requirements

### Requirement: Idempotent install and update
The system SHALL create the shipping method, payment method and their supporting records on install, and SHALL re-run the same installers on update without duplicating records; an existing payment method keeps its rule and only has its handler re-pointed, and an existing shipping method is left untouched.

#### Scenario: Update over customised method
- **WHEN** a merchant renamed the self pick-up method and the plugin is updated
- **THEN** the merchant's name is kept

### Requirement: Sales-channel assignment of methods
The system SHALL link the shipping and payment methods to every sales channel on install, update and activate.

#### Scenario: Methods selectable after install
- **WHEN** the plugin is installed and activated
- **THEN** self pick-up and pay on pickup are assigned to all sales channels

### Requirement: Config defaults seeding
The system SHALL persist the defaults `enablePickupLocationSelection = true`, `showStreetNameInPickupLocationSelectionField = false` and `showContactDetailInPickupLocationInfo = true` on install and update, only for keys without a stored value.

#### Scenario: Merchant choice survives update
- **WHEN** a merchant set the street option to true and the plugin is updated
- **THEN** the street option stays true

### Requirement: Activate and deactivate methods
The system SHALL activate the shipping and payment methods on plugin activation and deactivate them on plugin deactivation.

#### Scenario: Deactivate plugin
- **WHEN** the plugin is deactivated
- **THEN** customers can no longer choose self pick-up or pay on pickup

### Requirement: Uninstall preserves orders
The system SHALL deactivate, never delete, the shipping and payment methods on uninstall, and SHALL drop the plugin tables child-first only when user data is not kept, never deleting orders.

#### Scenario: Uninstall keeping data
- **WHEN** the plugin is uninstalled with "keep user data"
- **THEN** all pickup tables remain and the methods are inactive

#### Scenario: Uninstall removing data
- **WHEN** the plugin is uninstalled without keeping user data
- **THEN** the order pickup record, opening hour, special hour, sales-channel mapping and location tables are dropped in that order
- **AND** existing orders remain