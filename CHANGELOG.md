# Changelog

## Unreleased

- Simplify the status widget to one seven-day uptime value per service and one history API request.
- Use the status page name as the WordPress dashboard widget title and show status details in collapsible sections.
- Add PostHog reverse proxy, UI host, region, defaults and person profile settings; hide unconfigured PostHog and Feedback dashboard widgets.
- Harden OneUptime response handling, heartbeat validation, caching, dashboard hierarchy, history, and diagnostics.
- Isolate external service failures and mask saved API and heartbeat secrets in settings.
- Validate GitHub release metadata and release ZIP structure before offering updates.

## 1.7.0

- Added OneUptime status page integration and WordPress dashboard widget.
