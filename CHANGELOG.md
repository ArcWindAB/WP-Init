# Changelog

## Unreleased

## 2.0.1

- Align PostHog settings with the regional API host fallback: a project token unlocks web and error tracking when the custom API host is empty.
- Add accessible names and translation hooks to integration controls and status messages, and keep OneUptime uninstall safe when its dashboard module is missing.
- Fetch PostHog dashboard data and OneUptime banners, status, and alerts in background WordPress Cron jobs so external failures do not delay page rendering.
- Place the public status banner above the WordPress toolbar on desktop and mobile. Keep dismissals for at most 24 hours and show changed incidents again.
- Fix the PostHog tracking bridge URL on frontend and admin pages. Disable WordPress navigation transitions on the ArcWind settings page to avoid an aborted browser transition.
- Stage GitHub update packages inside WordPress's temporary upgrade directory to preserve core backup and rollback behavior. Validate automatic updates, invalid packages, and saved settings in WordPress integration tests.
- Add WordPress Coding Standards, PHPStan, HTTP admin-page checks, and a documented synthetic 1.6.2 upgrade fixture to continuous integration.
- Add `AGENTS.md` with source, administrator, and public language guidelines. Update development and release documentation.

## 2.0.0

- Use `api_host` for server-side event capture and `ui_host` (or the selected region) for private Query API requests, removing the separate PostHog server-host field.
- Explain PostHog web tracking options in the settings form and offer known SDK `defaults` profiles in a dropdown.
- Show saved active, inactive, and incomplete configuration states beside PostHog and OneUptime settings sections.
- Present PostHog settings in one table with a connection section for region, API host, UI host, project ID, and project token. Put SDK options under web tracking, lock dependent sections until their required connection fields are present, and show a save-and-remove button for a saved personal API key.
- Add an Alerts settings section with a saved API key and an admin-only notice for unresolved alerts from monitors on the configured status page. When a key exists, show a save-and-remove button instead of the key input. Show each alert description in an expandable row. Keep notices fixed during admin page load and place alerts below the settings tabs.
- Rework OneUptime settings around host and status page ID, with separate public and admin event controls and a heartbeat ID field. Group the settings in collapsible table sections, lock heartbeat until the host is present, and lock status information until the host and page ID are present.
- Improve the WordPress plugin information dialog with clear feature, installation and FAQ sections, including an offline fallback.
- Simplify the status widget to one seven-day uptime value per service and one history API request.
- Use the status page name as the WordPress dashboard widget title and show status details in collapsible sections.
- Add PostHog reverse proxy, UI host, region, defaults and person profile settings; hide unconfigured PostHog and Feedback dashboard widgets.
- Harden OneUptime response handling, heartbeat validation, caching, dashboard hierarchy, history, and diagnostics.
- Isolate external service failures and mask saved PostHog API secrets in settings.
- Validate GitHub release metadata and release ZIP structure before offering updates.

## 1.7.0

- Added OneUptime status page integration and WordPress dashboard widget.
