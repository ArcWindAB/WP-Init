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
php tests/posthog-options.php
node tests/tracker-js.js
python3 scripts/build-release.py
python3 scripts/build-release.py --validate-only
```

Svarstesterna använder fixtures med giltiga, nästlade och trasiga OneUptime-svar. De gör PHP-varningar till testfel och provar webbplatsens driftbanner, adminnotiser, alerts, dashboard, äldre cachad data och väntetid efter fel. Heartbeat-testet verifierar adressvalidering och schemaläggning. Updater-testet verifierar versionsjämförelse, release-format och API-fel. PostHog-testet verifierar region, proxy, UI-värd, Query API-adress, serverhändelser, SDK-val och att okonfigurerade dashboardkort inte registreras. JavaScript-testet provar det publika klient-API:t.

`tests/wp-smoke.php` är avsett för en **isolerad WordPress-installation** med pluginet aktivt och körs med `wp eval-file tests/wp-smoke.php`. Det provar inställningar, dashboard, banner och externa fel via `pre_http_request`. Kör det inte mot produktionsdatabasen; det skriver till och återställer pluginets options.

GitHub Actions testar PHP 8.2/8.3 och WordPress 6.8/senaste, bygger ZIP och installerar pluginet i en färsk WordPress-installation. En matrispost installerar först GitHub Release `v1.6.1`, sparar PostHog- och Feedback-inställningar och installerar sedan aktuell ZIP. Vid publicering av en GitHub Release bygger ett separat arbetsflöde en ZIP och laddar upp den till releasen.

## Integrationsgränser

- OneUptime och GitHub läses via säkra HTTP-anrop och typkontrolleras före rendering. Felcache begränsar upprepade anrop.
- OneUptimes drifttid för de senaste sju dagarna hämtas av ett enskilt WP-Cron-jobb, så dashboarden kan först visa ett vänteläge.
- Privata PostHog-frågor görs i admin-widgeten och på begäran i Diagnostik via `ui_host` eller regionens appadress. Serverhändelser skickas via `api_host`. Klient-API:t fångar tredjepartsundantag.
- Diagnostiska fel får inte innehålla URL med token, nycklar, HTTP-svarskroppar eller persondata.

Vid ändring av ett externt svar: lägg till eller ändra en fixture och kör regressionstesterna. Vid ändring av inställningar: kontrollera att befintliga options och andra flikar behåller sina värden. Vid release: följ [RELEASE.md](RELEASE.md).
