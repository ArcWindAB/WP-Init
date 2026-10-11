# Development and tests

## Architecture

`arcwind-init.php` loads the plugin modules and registers lifecycle hooks. `ArcWind_Init` retains the public WordPress hooks and methods. Its settings, dashboard, Feedback, and PostHog methods live in separate traits. OneUptime, diagnostics, tracking, and the GitHub updater have their own classes.

Preserve the option keys `arcwind_init_settings` and `arcwind_init_oneuptime`, and the heartbeat hook `arcwind_init_oneuptime_heartbeat`. Add migration code and an upgrade test before changing any of them.

## Local checks

Run these commands from the plugin root:

```sh
composer install
find arcwind-init.php includes tests -name '*.php' -print0 | xargs -0 -n1 php -l
composer cs
composer analyse
php tests/oneuptime-dates.php
php tests/oneuptime-responses.php
php tests/oneuptime-heartbeat.php
php tests/github-updater.php
php tests/posthog-options.php
node tests/tracker-js.js
python3 scripts/build-release.py
python3 scripts/build-release.py --validate-only
```

The OneUptime tests use valid, nested, and malformed response fixtures. They treat PHP warnings as failures and check banners, admin notices, alerts, cached status, and failure backoff. The updater tests check release validation, cache behavior, package staging, and failure handling. The PostHog tests check region and host selection, server capture, software development kit (SDK) settings, and dashboard visibility. The JavaScript test checks the public client interface.

## WordPress integration tests

Run `tests/wp-smoke.php` and `tests/wp-updater-smoke.php` with `wp eval-file` against an **isolated** WordPress installation. The tests modify plugin options and replace the installed plugin. Do not run them against a production database. The updater test needs a reachable local site address so WordPress can complete its loopback check. Continuous integration installs `tests/wp-offline-fixture.php` as a temporary must-use plugin, applies `tests/wp-http-failure-setup.php`, and runs `tests/wp-admin-pages.sh`. That shell test checks dashboard, plugins, settings, login, REST, and WP-Cron routes with configured integrations while third-party requests fail.

GitHub Actions tests PHP 8.2 and 8.3 against WordPress 6.8 and the latest WordPress version. It builds the release ZIP, installs it in fresh WordPress, tests an upgrade from a synthetic 1.6.2 fixture based on the published 1.6.1 release, and runs the WordPress checks. Version 1.6.2 has no published release or tag, so the fixture changes only the plugin version header. The release workflow builds the ZIP from the release tag with `scripts/build-release.py`.

In the PHP 8.3/latest WordPress job, `tests/wp-module-failure.sh` also removes one optional module at a time from the isolated installation and verifies that the frontend, login, and REST routes still load. Restore the test installation from the ZIP before running this check locally.

## Integration boundaries

- Page rendering uses cached PostHog and OneUptime results. WordPress Cron (WP-Cron) fetches fresh data in the background. The first render can show a pending or unavailable state. The separate seven-day uptime request also runs in WP-Cron.
- Diagnostics runs external connection checks only on an administrator's request. It uses `ui_host` or the selected PostHog region for private queries. Server capture uses `api_host`.
- External failures, timeouts, rate limits, exceptions, and malformed responses must not break the site or administration. Do not log URLs with tokens, keys, response bodies, or personal data.
- Add or update a fixture when a remote response format changes. Check that saving one settings tab retains the other integrations' options.

Follow [RELEASE.md](RELEASE.md) for a release. See the root [AGENTS.md](../AGENTS.md) for language, security, and accessibility rules.
