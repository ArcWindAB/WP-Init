# Optional Nexus inventory reporter

This source increment coordinates with
[Nexus issue #61](https://github.com/ArcWindAB/Nexus/issues/61) and its
[reporting v1 contract](https://github.com/ArcWindAB/Nexus/blob/codex/61-system-inventory/docs/api/inventory-v1.md).
It does not publish a plugin release, activate production reporting or change
PostHog, Feedback, OneUptime, heartbeat or updater settings.

The reporter is a separate optional module. It sends technical inventory to Nexus.
It must not send site content, user lists, names, emails, arbitrary options, secrets,
OneUptime credentials or SQL statements. It must not perform remote updates or change
OneUptime incidents, monitoring or availability. The module has no public or
administration route and no browser script. Existing settings tabs remain unchanged.

## Enrollment outside the database

An operator must create a separate installation in Nexus for every deployment,
including production and demo. Copy its installation universally unique identifier
(UUID), deployment UUID, exact primary HTTPS address and newly issued credential.
Store them in a deployment secret file **outside the WordPress root and database**.
The runtime user must have read access; other users must not have access. Mount the
file read-only. Do not commit it, include it in site exports or show it in diagnostics.

Define the file path in deployment-managed `wp-config.php`:

```php
define('ARCWIND_INIT_NEXUS_CONFIG_FILE', '/run/secrets/nexus-inventory.json');
```

The secret JSON shape is:

```json
{
  "schema": 1,
  "enabled": false,
  "nexus_origin": "https://nexus.example.test",
  "installation_id": "11111111-1111-4111-8111-111111111111",
  "deployment_id": "22222222-2222-4222-8222-222222222222",
  "site_url": "https://wordpress.example.test/",
  "credential": "REPLACE_WITH_OPERATOR_ISSUED_64_LOWERCASE_HEX_CHARACTERS"
}
```

Enable only after reviewing the exact target installation, site address and required
Nexus flags. `enabled` must be JSON boolean true; missing/invalid configuration keeps
reporting disabled. Unknown keys are rejected. The Nexus address must be an HTTPS
origin without credentials, query, fragment or non-root path. The site address must
match `home_url('/')` exactly. The receiver independently checks identity and address.

A cloned database must not copy the deployment file. Configure a separate installation,
new deployment UUID, actual demo address and new credential before enabling the clone.
A copied pending report is discarded when the external enrollment fingerprint changes.
If the database still has the production home address while the external enrollment
expects demo, the reporter must not send. Operators must change the home address first.
No receiver can distinguish a clone that copies **every** valid production address,
identity and external secret; deployment isolation is mandatory. On multisite, enroll
only the explicitly configured site's `home_url('/')`; other sites fail closed.

Credential rotation in Nexus invalidates the old key immediately. Replace the external
file atomically. Configuration changes require no WordPress option migration. Invalid
configuration or disabling the module clears its scheduled hooks. Plugin deactivation
clears only this module's hooks. Uninstall also removes only its own state/lock options.

## Reports and retries

The module registers one daily WordPress Cron (WP-Cron) event with an initial delay
of 60 through 900 seconds. Installed plugin/core/theme upgrades, plugin activation,
other plugin deactivation, theme switching and local update-cache changes request one
deduplicated report after 60 seconds. Rendering schedules work but makes no remote
request. A real deployment scheduler must invoke WP-Cron if traffic is insufficient;
this feature does not create another system scheduler.

Each full snapshot contains WordPress, PHP and database versions, every plugin from
`get_plugins()` and every theme from `wp_get_themes(['errors' => null])`, including
inactive components. Network-active plugins are active; the selected theme and its
parent template are active. Other installed themes are inactive. Stable lowercase
technical paths are preserved as component IDs. Unsupported casing/characters/length
use a SHA-256 identity of the exact path, avoiding case-folding collisions. Duplicate
identities or more than 500 components stop the report rather than truncating it.
Missing/unrepresentable version values are null, never invented.

Available versions and check times come only from existing `update_core`,
`update_plugins` and `update_themes` site transients. The reporter must not trigger
an external update check. Missing/malformed caches and unavailable check timestamps
remain null. A null available version must not be interpreted as up-to-date software.
WP-Init's installed version is included in the reporter identity and plugin inventory.

A database option stores a monotonically increasing sequence and the exact pending
JSON body before sending. The sequence uses the greater of the previous value plus
one and the current microsecond timestamp. The credential stays in the external
file; only a one-way enrollment fingerprint is stored in the option. A database lock
prevents parallel claims; expired locks use a conditional claim comparison so an old
owner cannot delete a replacement lock. Request bodies are at most 256 KiB.

The sender uses `wp_safe_remote_post()` with Transport Layer Security (TLS)
verification, unsafe-address rejection, no redirects, a five-second timeout and an
8 KiB response cap. Only an HTTP 200 response with `state` equal to `accepted` or
`duplicate` acknowledges a report. HTTP success without that body is uncertain.

Network failures, 429, 5xx and malformed HTTP 200 acknowledgements retain the exact
body/report UUID for at most three attempts, with 60/180-second retry delays. After
that budget, the next daily/version-change run creates a new full snapshot with a
higher sequence. Reporting is idempotent technical observation, not a delivery or
update command. Other response codes stop automatic requests with `enrollment_review`;
review the enrollment/receiver status and rotate enrollment credentials to resume.
If a clock or restored sequence conflicts with Nexus's high-water mark, review the
operator-visible sequence in Nexus before resuming; never reset Nexus replay history.

The sender retains one pending body and fixed status/timestamps, not raw provider
responses or a history of report bodies. Successful acknowledgement removes the body.
Options use autoload false. Nexus controls accepted snapshot/history retention.
A crash after send preserves the pending body for safe duplicate acknowledgement.
A failed durable-state write must prevent sending. A runtime exception records only
`inventory_unavailable`; private exception text must not enter logs or options.

For diagnosis, an authorized operator can inspect the `status`, `attempted_at` and
`last_success` fields of `arcwind_init_nexus_inventory_state` with WordPress command
line tools. Do not expose this option through public routes or analytics: it can
contain the technical pending snapshot. `accepted` describes report acknowledgement,
not OneUptime health or current software. The Nexus UI shows observation freshness.

## Verification and release boundary

`php tests/nexus-inventory.php` uses synthetic functions and fake outbound responses.
It verifies default-off behavior, enrollment/address/clone checks, full components,
known/unknown updates, secret-free state, bounded retries, duplicate acknowledgements,
permanent failure, lock recovery, durable-state failure and lifecycle cleanup. It
cannot contact a provider. The existing PHP 8.2/8.3 and WordPress 6.8/latest matrix
runs it alongside compatibility tests. The installable archive requires the module;
the optional-module failure test removes it and checks the site still loads.

Run the existing coding standards, static analysis, regression, WordPress smoke,
upgrade, failure-isolation and archive checks before release. Keep reporting disabled
until Nexus's receiver and migration are reviewed and both sides pass synthetic
end-to-end acceptance. Publishing or activating a release requires the owner's
separate authorization. No change in analytics or personal-data collection is included.
