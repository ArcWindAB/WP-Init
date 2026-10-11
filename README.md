# ArcWind Init

ArcWind Init är ett WordPress-plugin för PostHog-spårning och statistik, en feedback-länk och en publik OneUptime-statussida. Varje integration kan konfigureras separat under **Inställningar → ArcWind Init**. Externa tjänster är valfria; deras fel ska inte stoppa WordPress.

## Krav och installation

- WordPress 6.8 eller senare och PHP 8.2 eller 8.3 är testmatrisen i CI. Den här arbetskopian kör WordPress 7.1.3 med PHP 8.3.6. Äldre kombinationer är inte verifierade.
- Utgående HTTPS behövs för PostHog, OneUptime, GitHub Releases och heartbeat. En publik OneUptime-statussida kräver ingen API-nyckel.
- Installera den byggda `arcwind-init.zip` via **Tillägg → Lägg till nytt → Ladda upp tillägg**, eller placera mappen `arcwind-init/` under `wp-content/plugins/`. Aktivera sedan tillägget.
- Efter aktivering: öppna inställningsflikarna och ange bara de integrationer som ska användas. Behåll en fungerande WordPress-backup inför uppgradering.

Release-ZIP-filen ska ha `arcwind-init/` som enda rotmapp, med `arcwind-init.php`, `includes/`, `assets/`, README, changelog och licens. Källkodens automatiska GitHub-ZIP används inte som installationspaket.

## Inställningar

### PostHog

| Fält | Standard | Funktion |
| --- | --- | --- |
| PostHog-värd | `https://tel.arc.vin/_p` | HTTPS-värd för serverfrågor och capture. Äldre installationer fortsätter använda den som webbsnippets värd tills de väljer en separat Webb-API-adress. |
| Project ID | tomt | Projekt för statistikwidgetens Query API. |
| Personal API Key | tomt | Hemlig servernyckel för Query API. Visas inte i formuläret efter sparning; tomt fält behåller den, kryssrutan raderar den. |
| Aktivera spårning | på | Laddar PostHog i frontend och wp-admin när publik nyckel finns. |
| Public API Key | tomt | Publik `phc_...`-nyckel för webbläsare och server-side capture. |
| PostHog-region | EU | Väljer direkta `api_host` (`eu.i.posthog.com` eller `us.i.posthog.com`) och `ui_host` (`eu.posthog.com` eller `us.posthog.com`) när egna adresser saknas. Ändra även servervärden om Query API ska byta region. |
| Webb-API / reverse proxy | tomt | Valfri HTTPS-adress för webbsnippets `api_host`, inklusive eventuell sökväg. Tomt använder vald regions direktadress på nya installationer. Äldre installationers befintliga webbadress bevaras vid första sparning. Proxyn måste vidarebefordra både SDK-filer och eventanrop. |
| PostHog UI-värd | tomt | Valfri HTTPS-adress för `ui_host`. Tomt använder vald region. Vid reverse proxy ska detta normalt vara PostHogs riktiga gränssnittsvärd. |
| SDK defaults | tomt | Datum i formatet `YYYY-MM-DD`, t.ex. `2026-05-30`, som skickas som `defaults`. Tomt utelämnar inställningen och bevarar SDK:s tidigare beteende. |
| Person profiles | SDK-standard | `identified_only` eller `always`; SDK-standard utelämnar inställningen. |
| Registrera sidvisning | på | PostHogs `capture_pageview`. Verkar bara när spårningen är aktiv. |
| Autocapture | på | PostHogs automatiska interaktionsspårning. Verkar bara när spårningen är aktiv. |
| Laddningsposition | `head` | `head` eller `footer` i frontend och admin. |
| Aktivera felspårning | på | Skickar pluginets generiska felhändelser via server-side capture när publik nyckel finns. |
| Distinct ID för fel | `arcwind-init:server` | ID för dessa serverhändelser. |

**Statistik** på WordPress dashboard visas först när HTTPS-värd, Project ID och Personal API Key finns. Den visar unika användare och sidvisningar för sju dagar, frågar PostHogs Query API och cachelagrar i tio minuter. API-fel ger tomma värden och kort felcache. Feltexter återger inte API-svar eller nycklar. Manuell cache-rensning finns under Diagnostik.

Webbsnippet laddas bara när spårning är aktiverad och den publika nyckeln finns. Inloggade användare identifieras med WordPress-användar-ID och attribut som visningsnamn, användarnamn, roller, administratörsstatus och SHA-256-hash av e-post. Även admin-sidor omfattas. Bedöm därför rättslig grund, information till användare och eventuellt samtycke för er webbplats innan spårningen aktiveras. Pluginet innehåller ingen egen samtyckeshanterare.

JavaScript-API:t `window.ArcWindInit.track(eventName, properties)` och `identify(distinctId, properties)` returnerar `false` om PostHog saknas eller kastar fel. PHP-API:t `ArcWind_Init_Tracker::capture($event, $properties, $distinctId)` returnerar bool och använder ett säkert HTTPS-anrop med begränsad timeout. Exempel:

```js
window.ArcWindInit?.track('button_clicked', {area: 'settings'});
```

```php
ArcWind_Init_Tracker::capture('report_generated', ['kind' => 'weekly'], 'example-user');
```

### Feedback

| Fält | Standard | Funktion |
| --- | --- | --- |
| Aktivera feedback | på | Visar feedback-widgeten på WordPress dashboard. |
| Feedback-UUID | tomt | Alfanumeriskt ID som bygger länken `https://t.arc.vin/s/UUID`. |

Widgeten visas först när feedback är aktiverad och ett giltigt UUID finns. Den öppnar formuläret i en ny flik och bäddar inte in tredjepartsskript på publika sidor. UUID och PostHog-inställningar behålls när andra flikar sparas.

### OneUptime

| Fält | Standard | Funktion |
| --- | --- | --- |
| Aktivera OneUptime | av | Aktiverar statusintegration och dashboard-widget. |
| Publik statussida (URL) | tomt | HTTPS-adress, exempelvis `https://status.example.com`. |
| Status Page ID (eller domän) | tomt | ID eller verifierad domän för publika status-API:et. Uptime-API:et kräver ID. |
| Visa informationsbanner | på | Huvudbrytare för banner och adminmeddelanden. |
| Visa banner på webbplatsen | på | Visar statusmeddelanden i frontend. |
| Visa meddelanden i admin | på | Visar statusmeddelanden som WordPress-notiser. |
| Visa incidenter | på | Visar öppna incidenter. |
| Visa meddelanden | på | Visar aktuella announcements. |
| Visa planerade underhåll | på | Visar planerat/pågående underhåll. |
| Aktivera heartbeat | av | Schemalägger heartbeat när giltig adress finns. |
| Heartbeat-URL | tomt | Hemlig publik HTTPS-adress av formen `https://monitor.example.com/heartbeat/SECRET`. Lämna fältet tomt för att behålla sparad adress, eller använd kryssrutan för att radera den. |

OneUptime-data hämtas från statussidans publika API. Bannern visar högst tio meddelanden och filtrerar framtida announcements, avslutade incidenter och avslutat underhåll. Dismiss-knappar använder webbläsarens session storage per användare; nya incident-ID:n påverkas inte av tidigare dismiss. Incidenter utan ID kan inte avfärdas. WordPress admin-bar flyttas efter bannerns uppmätta höjd när den visas.

Dashboard-widgeten får statussidans eget namn som rubrik. Övergripande status syns direkt; komponenter, nästlade grupper, enskilda tjänster och händelser kan öppnas för detaljer med tangentbord eller mus. Aktuell status hämtas högst en gång per minut. Drifttid för 24 timmar, 7 dagar och 30 dagar hämtas i ett separat WP-Cron-jobb och kan saknas på första sidladdningen. Utebliven drifttid märks **uppgift saknas**. När API:et fallerar backar kontrollen i 60 sekunder; senast kända data kan visas i upp till ett dygn och märks **ej aktuell** med grå statusindikator och tidsstämpel. Den visas aldrig som ny grön status. API-svar utan förväntade fält ignoreras och loggas utan innehåll eller hemliga URL:er.

### Heartbeat och WP-Cron

Skapa en Incoming Request-monitor i OneUptime och kopiera dess hemliga heartbeat-adress. Anropet använder `wp_safe_remote_get()`, HTTPS, inga omdirigeringar och fem sekunders timeout. WordPress validerar publika adresser även efter DNS-upplösning. HTTP-kod utanför 2xx registreras som fel; senaste försök och framgång syns under Diagnostik utan token.

Pluginet har högst ett återkommande heartbeat-event när integrationen, heartbeat och en giltig adress är aktiverade. Eventet tas bort när pluginet inaktiveras eller avinstalleras. WP-Cron körs normalt först när webbplatsen får trafik. För mer pålitliga kontroller, låt systemcron anropa `wp-cron.php` ungefär varje minut och sätt OneUptimes larmgräns med marginal, till exempel tre missade minuter. Exempel (byt domän):

```cron
* * * * * curl --silent --show-error --fail https://example.com/wp-cron.php?doing_wp_cron >/dev/null
```

Om `DISABLE_WP_CRON` är aktiv måste en extern cron finnas. Diagnostik varnar om nästa heartbeat är mer än två minuter försenad.

### Diagnostik

Fliken **Diagnostik** visar aktiverings- och konfigurationsstatus för integrationerna, PHP-version, senaste heartbeat, nästa cron-tid och varning om försenad cron. Endast administratörer med `manage_options` får köra **Testa anslutningar** och **Rensa cache**. Åtgärderna kräver WordPress nonce. Testresultat är generiska och innehåller inte API-nycklar, token eller privata svarskroppar. Testet utför nätverksanrop bara när en administratör trycker på knappen.

## Uppdateringar från GitHub Releases

Pluginfilen har `Update URI: https://github.com/ArcWindAB/WP-Init`. Uppdateraren använder WordPress standardfilter för tillägg med egen Update URI. Den godtar endast en publicerad, stabil semantisk version med release-asset `arcwind-init.zip` på rätt GitHub-repo och via HTTPS. API-fel och rate limits cachelagras i 15 minuter. Den aktiverar inte automatiska uppdateringar åt dig: använd WordPress egen **Aktivera automatiska uppdateringar** på sidan Installerade tillägg, om er globala policy tillåter det.

Inställningar lagras i WordPress options och överlever filuppdateringar. En felaktig release-asset erbjuds inte som uppdatering; installationspaketet måste innehålla pluginfilen och en giltig versionsheader. Ta backup och använd WordPress återställningsfunktion eller installera föregående ZIP om en uppdatering misslyckas. Läs [CHANGELOG.md](CHANGELOG.md) före release.

Bygg och validera en release lokalt:

```sh
python3 scripts/build-release.py --tag v1.7.0
python3 scripts/build-release.py --validate-only
```

Publicera sedan ZIP-filen som `arcwind-init.zip` på en GitHub Release vars tagg matchar versionsheadern. Källkodens GitHub-ZIP räcker inte. CI bygger och kontrollerar samma struktur och sparar ZIP som artifact på taggar. Se [releasechecklistan](docs/RELEASE.md).

## Felsökning

| Symtom | Kontroll |
| --- | --- |
| PostHog-statistik saknas | Ange HTTPS-värd, Project ID och Personal API Key. Kör anslutningstest under Diagnostik. |
| JavaScript-events saknas | Aktivera spårning, ange publik API-nyckel och kontrollera webbläsarens nätverkslogg. Samtyckesblockering kan stoppa skriptet. |
| Feedback-knapp saknas | Aktivera feedback och spara ett alfanumeriskt UUID. |
| OneUptime-widget saknas | Aktivera integrationen och ange publik status-URL och ID. Kontrollera att statussidan är publik. |
| Status visas som äldre data | Kontrollera status-API och serverns utgående HTTPS. Rensa cache under Diagnostik när anslutningen fungerar igen. |
| Drifttidshistorik saknas | Kontrollera att ID används i stället för domänalias och att WP-Cron körs. |
| Heartbeat uteblir | Kontrollera giltig publik HTTPS-adress, senaste försök och cron-schema i Diagnostik; använd systemcron vid låg trafik. |
| Ingen GitHub-uppdatering syns | Publicera en nyare stabil Release med korrekt `arcwind-init.zip`; vänta upp till en timme eller rensa cache. |

## Utveckling och struktur

`arcwind-init.php` är bootstrap och registrerar livscykel-hooks. `includes/` innehåller fokuserade moduler för inställningar, PostHog, feedback, dashboard, OneUptime, diagnostik och uppdateringar. `assets/js/` innehåller klient-API:t. `tests/` innehåller fixture-baserade regressionstester och ett WordPress-rökprov. `scripts/build-release.py` bygger och validerar ZIP-filen. Se [utvecklarguiden](docs/DEVELOPMENT.md) för testkommandon och CI-matris.

## Licens

[GPL-2.0-or-later](LICENSE).
