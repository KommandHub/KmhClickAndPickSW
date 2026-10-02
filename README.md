<p align="center">
  <a href="https://kommandhub.com" target="_blank">
    <img src="src/Resources/config/kommandhub.png" alt="Kommandhub Logo">
  </a>
</p>

# Click and Pick for Shopware 6

Click and collect for Shopware 6.7. Customers choose a store or collection point at checkout, pick a date and a time slot within its opening hours, and collect the order themselves — optionally paying at the counter.

**Documentation: [docs.kommandhub.com/plugins/click-and-pick](https://docs.kommandhub.com/plugins/click-and-pick/)** — the single source of truth for merchants and developers.

## Features

- Pickup locations with address, contact, sales channels and a time-zone-aware opening schedule (several intervals per day, holidays and special dates)
- Location, date, 30-minute time slot and notes in checkout, validated on the server
- Optional "Use my location" to list the nearest locations first (consent-gated, position never stored)
- *Self pick-up* shipping method and *Pay on pickup* payment method
- *Ready* delivery status that emails the customer where and when to collect
- Store notification email, Flow Builder triggers and actions (email, optional SMS)
- *Pickup information* tab on every pickup order

## Requirements

- Shopware 6.7, PHP 8.2+
- Optional: the KommandHub SMS plugin, for SMS to pickup locations

## Installation

```bash
composer require kommandhub/click-and-pick-sw
bin/console plugin:refresh
bin/console plugin:install --activate KmhClickAndPickSW
bin/console cache:clear
```

The package is proprietary and not on public Packagist — see the [installation guide](https://docs.kommandhub.com/plugins/click-and-pick/installation) for the repository setup, ZIP installation, updating and uninstalling. Then create a pickup location under **Content → Pickup Locations**.

## Documentation

| For | Read |
| --- | --- |
| Merchants | [Overview](https://docs.kommandhub.com/plugins/click-and-pick/) · [Installation](https://docs.kommandhub.com/plugins/click-and-pick/installation) · [Configuration](https://docs.kommandhub.com/plugins/click-and-pick/configuration) · [Pickup locations](https://docs.kommandhub.com/plugins/click-and-pick/pickup-locations) · [Orders, emails & flows](https://docs.kommandhub.com/plugins/click-and-pick/orders) · [Troubleshooting](https://docs.kommandhub.com/plugins/click-and-pick/troubleshooting) |
| Developers | [Architecture & data flow](https://docs.kommandhub.com/plugins/click-and-pick/developer) · [Extending](https://docs.kommandhub.com/plugins/click-and-pick/extending) · [Changelog](https://docs.kommandhub.com/plugins/click-and-pick/changelog) |

Contributing: [`CONTRIBUTING.md`](CONTRIBUTING.md). Quick start from the plugin root: `make up`, then `make cs-fix && make analyse && make test`.

## Support

[info@kommandhub.com](mailto:info@kommandhub.com) · Security issues: see [`SECURITY.md`](SECURITY.md)

## License

Proprietary — © Kommandhub Limited. See [`LICENSE`](LICENSE).
