# ArcWind Init agent guide

Use this guide when changing ArcWind Init. Read [README.md](README.md) for behavior, [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for test commands, and [docs/RELEASE.md](docs/RELEASE.md) for releases. Keep this file short and use those documents for details.

## Language: three levels

### Level 1: source code and technical work

Write source code, identifiers, comments, docblocks, logs, developer-facing errors, commit messages, and technical documentation in professional English. Keep code precise, well formatted, and consistent with WordPress conventions. For human-readable technical prose, apply the principles of ASD-STE100 Simplified Technical English (STE): use short, direct sentences, consistent terms, explicit actions, and no unnecessary idioms. This is guidance, not a claim of STE certification. Do not change programming syntax, public interfaces, third-party identifiers, or official WordPress terms to force STE vocabulary.

### Level 2: WordPress administration

Write settings, notices, help, diagnostics, and instructions for a WordPress administrator. Use formal, understandable language. State the subject and action clearly. Give one instruction at a time. Explain technical terms when needed, and give a corrective step for errors. Use WordPress localization functions and the `arcwind-init` text domain. The administrator's locale determines the displayed language; this level does not require English on a Swedish site.

### Level 3: public interface

Write public notices, status banners, and feedback prompts in simple, accessible, inclusive language. Use familiar words, short sentences, and clear actions. State what happened and whether the visitor needs to do anything. Avoid internal details, unexplained abbreviations, and alarming or ambiguous wording. Preserve meaning in translations and respect the visitor's locale.

At every level, prefer a full term when practical. If an abbreviation is needed, expand it at first use in each logical context: **Application Programming Interface (API)**. Later in the same context, **API** is sufficient. Apply the rule again on a separate screen, document, or message where the definition is not visible. Keep established code identifiers, protocols, product names, and language keywords unchanged when expansion would reduce clarity or correctness.

| Context | Example | Check |
| --- | --- | --- |
| Code comment (Level 1) | `// Return cached data if the remote service is unavailable.` | Precise technical English. |
| Admin notice (Level 2) | “The connection failed. Check the service address and try again.” | Clear cause and action; translate for the site locale. |
| Public notice (Level 3) | “Service information is unavailable right now. Please try again later.” | Plain language; no implementation detail. |
| First abbreviation use | “Application Programming Interface (API)” | Define it in the current context. |

## Architecture and change scope

`arcwind-init.php` is the bootstrap. `includes/` contains integration and administration modules, `assets/` contains client assets, `tests/` contains regression and WordPress checks, and `scripts/build-release.py` builds and validates the distribution archive. Keep PostHog, Feedback, OneUptime, diagnostics, and the updater loosely coupled. Preserve existing option keys, hooks, and saved settings. Make focused, backward-compatible changes. Do not silently change defaults or unrelated integrations.

## Security, resilience, and accessibility

Validate and sanitize input. Escape output for its context. Check capabilities and nonces before privileged actions. Use WordPress APIs. Never expose keys, tokens, private responses, or personal data in logs, diagnostics, or public output.

Keep the site and administration usable when PostHog, OneUptime, GitHub, or another service is slow, unavailable, or returns malformed data. Bound external requests, cache failures, and provide safe fallbacks without fatal errors. Make controls keyboard accessible and label their purpose and state. Keep Swedish and English experiences understandable as localization support evolves.

## Tests, documentation, and releases

Run the relevant commands in [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md). Add regression coverage for failures, malformed responses, disabled integrations, and visible language when applicable. Use the continuous integration matrix documented there; do not infer support guarantees for untested versions.

Update documentation when behavior, configuration, security, or release steps change. Record user-visible changes in [CHANGELOG.md](CHANGELOG.md). Preserve the GitHub Releases self-update flow and the installable `arcwind-init.zip` structure. Follow [docs/RELEASE.md](docs/RELEASE.md) and validate the built package. Record important trade-offs and test limits in a pull request or change summary.
