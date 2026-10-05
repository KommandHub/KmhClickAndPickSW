# Contributing

Internal development guide for `KmhClickAndPickSW`. This is proprietary
software (see `LICENSE`); contributions come from Kommandhub developers and
authorized partners.

## Prerequisites

- The plugin lives at `custom/static-plugins/KmhClickAndPickSW` inside a
  Shopware 6.7 install, or use the bundled dev stack.
- PHP 8.2+, Composer, Docker (for the local stack), Node/npm (only for building
  admin/storefront assets).

```bash
make up        # start the dev stack
make shell     # shell into the app container
composer install
```

## Workflow

1. Branch off `develop` (`feature/…`, `fix/…`). `main` is the release branch.
2. Keep each change inside its feature module (`src/<Module>/…`); reach across
   modules through a service, never by deep-linking internals.
3. Add or update tests with every behavior change. Tests mirror `src/` under
   `tests/Unit/` (no kernel) and `tests/Integration/` (`#[Group('kernel')]`).
4. Run the full quality gate before pushing:

   ```bash
   make cs-fix && make analyse && make test
   ```

   - php-cs-fixer must be clean.
   - PHPStan level 9 must pass (0 errors).
   - PHPUnit must pass at **100% line coverage** (CI enforces it).
5. Rebuild generated assets if you touched admin/storefront source:

   ```bash
   bin/build-administration.sh && bin/build-storefront.sh
   ```

6. Open a PR against `develop`. CI (`.github/workflows/php.yml`) runs composer
   validate, PHP lint, PHPStan, cs-fixer (dry-run), and PHPUnit (no-kernel) with
   the coverage gate.

## Conventions

- **Commits:** Conventional Commits (`feat:`, `fix:`, `refactor:`, `test:`,
  `chore:`, `docs:`, `perf:`). One logical change per commit.
- **Money at the Paystack-style boundary** does not apply here; pickup times are
  local wall-clock — go through the availability/slot services, never re-implement
  timezone logic inline.
- **New DAL entities:** add the definition under `src/Entity/…`; it is auto-tagged
  via the `services.yml` glob. If you add a constructor-injected `*Interface` with
  a single implementation, add an explicit `alias:` — Symfony does not auto-alias.
- **New Shopware API reads** stay in the relevant service; do not query the DAL
  from Twig or controllers directly beyond the existing thin controllers.
- **Tests** use `#[CoversClass]`/`#[UsesClass]` (strict coverage metadata is on).
  Kernel-dependent tests must be tagged `#[Group('kernel')]`.

## Migrations & schema

- Add a new `Migration<timestamp><Name>` under `src/Migration/`; make DDL
  idempotent (`CREATE TABLE IF NOT EXISTS`, `indexExists`/`columnExists` guards).
- When adding a table with a foreign key to a plugin table, also extend the
  uninstall drop list in `KmhClickAndPickSW::uninstall()` (child tables are
  dropped before their parent).

## Releasing

- Bump `version` in `composer.json` and add a `CHANGELOG.md` section.
- Rebuild assets, run the full quality gate, and `make validate-plugin`.
- `make zip` produces the distributable package.

## Reporting security issues

Do not open a public issue. Follow `SECURITY.md`.
