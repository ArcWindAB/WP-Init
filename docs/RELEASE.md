# Release checklist

1. Update the version header in `arcwind-init.php`. Move the relevant entries from **Unreleased** in `CHANGELOG.md` to the new version.
2. Update the README and development guide when behavior, defaults, security, supported PHP or WordPress versions, or limitations change. Use placeholders in examples.
3. Run PHP lint, WordPress Coding Standards, PHPStan, regression tests, WordPress integration tests, and archive validation. Check the continuous integration matrix and option preservation during upgrade. See [DEVELOPMENT.md](DEVELOPMENT.md).
4. Build with `python3 scripts/build-release.py --tag vX.Y.Z`. The ZIP must have `arcwind-init/` as its root and the same version in the plugin header.
5. Publish a GitHub Release for tag `vX.Y.Z`. The `build-release.yml` workflow checks out that tag, builds the ZIP, and uploads `arcwind-init.zip`. Verify that the asset exists and its version matches the tag. GitHub's generated source ZIP is not the installable package.
6. On a separate WordPress test installation, check that the update appears under **Installed Plugins**, that WordPress's **Enable auto-updates** control works, and that a valid package installs. Check that an invalid package leaves the installed code intact.
7. After the upgrade, verify that PostHog, Feedback, and OneUptime options remain available. Check that at most one heartbeat job exists. Confirm that the frontend, login, administration, and dashboard load when external services fail.

## Optional Nexus reporter

The [Nexus inventory reporter](integrations/nexus-inventory.md) is disabled by default.
Its source delivery does not change the plugin version or authorize a release.
Before activation, review the Nexus reporting contract, accepted schema migration,
receiver flags, per-deployment enrollment and external clone-secret handling. Never
copy production enrollment into a demo database export. Run synthetic end-to-end
acceptance and the documented compatibility matrix before a separately approved release.
