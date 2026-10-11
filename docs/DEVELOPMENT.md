# Utveckling och test

## Arkitektur

`arcwind-init.php` läser versionen, laddar moduler och registrerar activation/deactivation/uninstall. `ArcWind_Init` behåller de publika WordPress-hooks och metoder som äldre installationer använder, men metodgrupperna ligger i traits för inställningar, dashboard, feedback och PostHog. OneUptime, diagnostik, tracker och GitHub-uppdaterare har egna klasser.

Option keys som måste förbli kompatibla är `arcwind_init_settings` och `arcwind_init_oneuptime`. Scheduled hook för heartbeat är `arcwind_init_oneuptime_heartbeat`. Ändra inte dem utan migrationskod och uppgraderingstest.

## Lokala kontroller

Kör från pluginroten:

```sh
find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/oneuptime-dates.php
php tests/oneuptime-responses.php
php tests/oneuptime-heartbeat.php
php tests/github-updater.php
python3 scripts/build-release.py
```

Svarstesterna använder fixtures med giltiga, nästlade och trasiga OneUptime-svar. De gör PHP-varningar till testfel och provar frontend-banner, adminmeddelanden, dashboard, frånkoppling, stale-data och backoff. Heartbeat-testet verifierar validering och att hemligheten inte visas i HTML. Updater-testet verifierar versionsjämförelse, release-format och API-fel.

`tests/wp-smoke.php` är avsett för en **isolerad WordPress-installation** med pluginet aktivt och körs med `wp eval-file tests/wp-smoke.php`. Det provar inställningar, dashboard, banner och externa fel via `pre_http_request`. Kör det inte mot produktionsdatabasen; det skriver till och återställer pluginets options.

GitHub Actions testar PHP 8.2/8.3 och WordPress 6.8/senaste, bygger ZIP och installerar pluginet i en färsk WordPress-installation. En matrispost installerar först GitHub Release `v1.6.1`, sparar PostHog- och Feedback-inställningar och installerar sedan aktuell ZIP. Issue-textens `1.6.2` saknar publicerad release/tagg i repot; när en sådan finns bör uppgraderingstestet kompletteras.

## Integrationsgränser

- OneUptime och GitHub läses via säkra HTTP-anrop och typkontrolleras före rendering. Felcache begränsar upprepade anrop.
- Historiska OneUptime-fönster hämtas av WP-Cron, så dashboarden visar först ett vänteläge.
- PostHog-frågor görs bara i admin-widgeten och har kort timeout. Klient-API:t fångar tredjepartsundantag.
- Diagnostiska fel får inte innehålla URL med token, nycklar, HTTP-svarskroppar eller persondata.

Vid ändring av ett externt svar: lägg till eller ändra en fixture och kör regressionstesterna. Vid ändring av inställningar: kontrollera att befintliga options och andra flikar behåller sina värden. Vid release: följ [RELEASE.md](RELEASE.md).
