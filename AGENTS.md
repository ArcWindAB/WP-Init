# ArcWind Init agent guide

Use this guide when changing ArcWind Init. Read [README.md](README.md) for behavior, [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for test commands, and [docs/RELEASE.md](docs/RELEASE.md) for releases. Keep this file short and use those documents for details.

Follow the user's authorized task and applicable higher-priority instructions. This file defines project rules; the linked documents provide operational detail.

## Agent workflow

1. Read relevant code and documentation before editing. Identify dependencies, compatibility constraints, and likely side effects.
2. Reuse established patterns. Make the smallest complete, maintainable change that meets the request.
3. Add or update relevant regression tests. Run applicable checks and record any check that could not run.
4. Update related documentation and [CHANGELOG.md](CHANGELOG.md) when behavior changes.
5. Summarize the result, verification, remaining limitations, and known risks.

Agents must not silently remove functionality, bypass checks, weaken security, or hide a critical defect to finish a task.

## Normative words and controlled language

Use these words consistently in requirements and translations. A recommendation does not replace a requirement.

| Meaning | English | Swedish |
| --- | --- | --- |
| Requirement | `must` | `ska` |
| Prohibition | `must not` | `ska inte` |
| Recommendation | `should` | `bör` |
| Recommendation against | `should not` | `bör inte` |
| Capability | `can` | `kan` |
| Permission | `may` | `får` |

Preserve normative meaning when translating. Do not weaken a mandatory rule with qualifiers such as *normally*, *preferably*, *generally*, or *if possible* (or *normalt*, *helst*, *generellt*, or *om möjligt*). Do not use `can` for permission or `may` for capability. Write “The plugin must validate input,” not “The plugin should normally validate input.”

Apply these seven principles at each audience level:

1. Use a controlled vocabulary and consistent terms.
2. Give each term one meaning where practical.
3. Use short sentences with predictable grammar.
4. Use one verb for one action. Name the actor when it matters.
5. Put a governing condition before the action: “If the connection fails, check the service address.”
6. Put procedure steps in the order the reader must perform them.
7. Avoid unnecessary synonyms, abbreviations, and implicit references.

Technical, legal, security, and product terms may be used when they add necessary precision. Explain a specialist term before continued use if the audience may not know it. Do not show internal implementation terms to public users when they do not help them.

## Language: three levels

### Level 1: source code and technical work

Source code, identifiers, comments, docblocks, logs, developer-facing errors, commit messages, and technical documentation must use professional English. Keep code precise, well formatted, and consistent with WordPress conventions. Human-readable technical prose must use formal, controlled English guided by ASD-STE100 Simplified Technical English (STE): short, direct sentences, consistent terms, and explicit actions. This guidance does not claim STE certification and must not distort programming syntax, public interfaces, third-party identifiers, or official WordPress terms.

### Level 2: WordPress administration

Settings, notices, help, diagnostics, and instructions must use formal, understandable language for WordPress administrators. State the subject and action clearly. Give one instruction at a time. Explain operational meaning, consequences, and necessary technical terms. Give a corrective step for errors when relevant. Visible text must use WordPress localization functions and the `arcwind-init` text domain. The administrator's locale determines the displayed language; this level does not require English on a Swedish site.

### Level 3: public interface

Public notices, status banners, and feedback prompts must use simple, accessible, inclusive language. Use familiar words, short sentences, and clear actions. State what happened, whether the visitor needs to act, and what to do next when relevant. Avoid irrelevant implementation details, unexplained abbreviations, and alarming or ambiguous wording. Public text must use WordPress localization functions and the `arcwind-init` text domain. Preserve meaning in translations and respect the visitor's locale.

At every level, prefer a full term when practical. If an abbreviation is needed, expand it at first use in each logical context: **Application Programming Interface (API)**. Later in the same context, **API** is sufficient. Apply the rule again on a separate screen, document, or message where the definition is not visible. Keep established code identifiers, protocols, product names, and language keywords unchanged when expansion would reduce clarity or correctness.

| Context | Example | Check |
| --- | --- | --- |
| Code comment (Level 1) | `// Return cached data if the remote service is unavailable.` | Precise technical English. |
| Admin notice (Level 2) | “The connection failed. Check the service address and try again.” | Clear cause and action; translate for the site locale. |
| Public notice (Level 3) | “Service information is unavailable right now. Please try again later.” | Plain language; no implementation detail. |
| First abbreviation use | “Application Programming Interface (API)” | Define it in the current context. |

The English interface examples show tone. They do not require English on a Swedish site.

## Architecture, compatibility, and dependencies

`arcwind-init.php` is the bootstrap. `includes/` contains integration and administration modules, `assets/` contains client assets, `tests/` contains regression and WordPress checks, and `scripts/build-release.py` builds and validates the distribution archive. Keep PostHog, Feedback, OneUptime, diagnostics, and the GitHub updater separate. Avoid unnecessary coupling and duplicate code.

Preserve public hooks and interfaces, stored data, saved configuration, and scheduled events across updates. In particular, preserve option keys `arcwind_init_settings` and `arcwind_init_oneuptime`, and scheduled hook `arcwind_init_oneuptime_heartbeat`. A breaking change requires an explicitly approved migration plan and regression and upgrade tests against an existing installation. Do not silently change defaults or integration behavior.

Use WordPress application programming interfaces and existing patterns before adding a dependency. Before proposing a new dependency, evaluate compatibility, security, maintenance, and licensing. Introducing an external dependency requires explicit authorization.

## Security, privacy, and diagnostics

Apply Security by Design, Privacy by Design, Least Privilege, Defense in Depth, Secure Defaults, and Data Minimization. Validate and sanitize input, escape output for its context, check capabilities and nonces for privileged actions, and use safe WordPress outbound HTTP functions. Protect keys, tokens, personal data, and private remote response bodies in analytics, logs, diagnostics, and user-visible errors. Do not weaken security controls.

Diagnostics must be actionable and bounded. Log a safe error category and enough context to locate the failing integration; do not log secrets, tokens, personal data, or private remote response bodies.

## Resilience, performance, and accessibility

The public site and WordPress administration must remain operational if an optional integration fails, times out, or returns malformed data. Isolate failures so one integration cannot break another integration or WordPress. Use bounded timeouts, appropriate caching and fallback states, and no uncaught fatal errors. Represent missing or stale service data clearly; do not fabricate a healthy status.

Avoid unnecessary database queries and remote requests. Use caching and scheduled or background work where suitable. Do not make external services block frontend or administration page rendering unnecessarily.

Use Web Content Accessibility Guidelines (WCAG) 2.2 Level AA as the target for plugin interfaces. Use semantic HTML, keyboard operation, visible focus, sufficient contrast, meaningful control and status labels, and error states that do not rely on color alone. Follow established WordPress interface patterns and use progressive disclosure for advanced settings. Keep Swedish and English experiences understandable as localization support evolves.

## Testing, documentation, and releases

Applicable checks must pass before completion, or the final summary must identify unverified checks and their limits. Add a regression test for a bug fix where technically feasible. Test malformed remote responses, disabled integrations, and preservation of existing settings when relevant. Follow the commands and PHP/WordPress matrix in [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md). Never run state-changing WordPress smoke tests against production.

Update related documentation and [CHANGELOG.md](CHANGELOG.md) when behavior, configuration, security, or release steps change. Preserve the GitHub Releases self-update flow and the installable `arcwind-init.zip` structure. Follow [docs/RELEASE.md](docs/RELEASE.md) and validate the built package before a release.

## Decision boundaries

Agents may choose routine implementation details within an authorized task. These actions require explicit authorization: removing an existing feature; making a breaking change or changing a storage format; introducing an external dependency; materially changing analytics or personal-data collection; and publishing a release. Material data-collection changes also require a privacy review. Disclose trade-offs and seek authorization before expanding scope. Weakening security controls is prohibited.

## Definition of done

Before reporting completion, verify each applicable item or disclose why it remains unverified:

- [ ] Requested behavior is complete.
- [ ] Existing integrations, settings, hooks, and data remain compatible, or an approved migration and upgrade tests are in place.
- [ ] Security, privacy, failure isolation, performance, and accessibility have been evaluated.
- [ ] Applicable tests passed, or missing verification and its limits are stated.
- [ ] Code and visible language follow the rules above.
- [ ] Related documentation and change history are current where needed.
- [ ] No known critical defect is concealed; remaining risks and limitations are reported.
