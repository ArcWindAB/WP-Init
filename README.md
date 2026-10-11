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
| Projekt-ID | tomt | Projekt för statistikwidgetens Query API. |
| Personal API Key | tomt | Hemlig servernyckel för Query API. Efter sparning visas en knapp som sparar formuläret och tar bort nyckeln. Inmatningsfältet visas igen när nyckeln har tagits bort. |
| Aktivera spårning | på | Laddar PostHog i frontend och wp-admin när publik nyckel finns. |
| Projekt-token | tomt | Publik `phc_...`-nyckel för webbläsare och server-side capture. |
| PostHog-region | EU | Väljer direkta `api_host` (`eu.i.posthog.com` eller `us.i.posthog.com`) och `ui_host` (`eu.posthog.com` eller `us.posthog.com`) när egna adresser saknas. |
| PostHog API-värd eller proxy | tomt | HTTPS-adress för webbsnippets `api_host` och serverns event, inklusive eventuell sökväg. Tomt använder vald regions direktadress på nya installationer. Äldre installationers befintliga webbadress bevaras vid första sparning. En proxy måste vidarebefordra både SDK-filer och eventanrop. |
| PostHog UI-värd | tomt | Valfri HTTPS-adress för `ui_host` och privata Query API. Tomt använder vald region. Vid reverse proxy ska detta normalt vara PostHogs riktiga gränssnittsvärd, inte proxyadressen. |
| SDK defaults | SDK-standard | Välj en av PostHogs daterade standardprofiler i en lista. SDK-standard utelämnar `defaults`; ett datum aktiverar standardbeteenden från den profilen. |
| Person profiles | SDK-standard | `identified_only` skapar profiler för identifierade besökare; `always` även för anonyma. SDK-standard utelämnar inställningen. |
| Registrera sidvisning | på | Skickar `$pageview` när sidan laddas. Avmarkera om sidvisningar redan registreras på annat sätt. |
| Autocapture | på | Registrerar vanliga klick och formulärhändelser automatiskt. |
| Laddningsposition | `head` | `head` startar tidigt; `footer` laddar skriptet senare, både i frontend och admin. |
| Aktivera felspårning | på | Skickar pluginets generiska felhändelser via server-side capture när publik nyckel finns. |
| Distinct ID för fel | `arcwind-init:server` | ID för dessa serverhändelser. |

PostHog-inställningarna visas i en gemensam tabell. **PostHog-anslutning** är öppen från början och innehåller region, API-värd, UI-värd, projekt-ID och projekt-token. Webbspårning innehåller även SDK defaults, person profiles och laddningsposition och öppnas när API-värd och projekt-token finns. Dashboardstatistik kräver projekt-ID och felspårning kräver API-värd och projekt-token. Ett låst avsnitt visas grått och kan öppnas direkt när dess fält fyllts i. UI-värden är valfri och används för PostHogs gränssnitt och privata Query API.

Ikoner bredvid avsnitten visar den sparade inställningens status: grön bock betyder aktiv, grått streck betyder av och orange varning betyder att något som behövs saknas. Ikonerna visar konfiguration, inte resultatet av ett anslutningstest.

**Statistik** på WordPress dashboard visas när projekt-ID och personlig API-nyckel finns. Den använder UI-värden eller vald regions HTTPS-adress för PostHogs Query API, visar unika användare och sidvisningar för sju dagar och cachelagrar i tio minuter. API-fel ger tomma värden och kort felcache. Feltexter återger inte API-svar eller nycklar. Manuell cache-rensning finns under Diagnostik.

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
| OneUptime-värd | tomt | HTTPS-basadress, exempelvis `https://oneuptime.example.com/`. |
| Statussidans ID | tomt | ID för den publika statussidan. Statussidans adress byggs som `<värd>/status-page/<ID>`. |
| Driftinformation på webbplatsen | på | Visar valda händelser i en banner. Kräver värd och status-ID. |
| Incidenter, meddelanden, underhåll på webbplatsen | på | Tre separata val för webbplatsen. |
| Driftinformation i admin | på | Visar valda händelser som WordPress-notiser. Kräver värd och status-ID. |
| Incidenter, meddelanden, underhåll i admin | på | Tre separata val för admin. |
| Skicka heartbeat | av | Schemalägger heartbeat när värd och heartbeat-ID finns. |
| Heartbeat-ID | tomt | ID från en Incoming Request-monitor. En fullständig URL av formen `<värd>/heartbeat/<ID>` kan klistras in och kortas till ID:t i fältet. |
| API-nyckel för alerts | tomt | Nyckel med läsbehörighet för Alert och Alert State. Efter sparning visas en knapp som sparar formuläret och tar bort nyckeln. Inmatningsfältet visas igen när nyckeln har tagits bort. |
| Visa alerts i admin | av | Visar högst tio olösta alerts från statussidans monitorer i en adminnotis för administratörer. Klicka på en titel för att läsa beskrivningen. |

Statussida, Driftinformation, Heartbeat och Alerts finns som öppningsbara avsnitt i samma inställningstabell. Heartbeat är låst tills OneUptime-värden har fyllts i. Driftinformation och Alerts är låsta tills både värd och status-ID har fyllts i. Äldre sparade URL:er och visningsval läses in automatiskt.

Varje OneUptime-avsnitt har samma statusikoner som PostHog: grön bock för aktiv sparad konfiguration, grått streck för av och orange varning när ett aktiverat avsnitt saknar uppgifter.

OneUptime-data hämtas från statussidans publika API. Bannern visar högst tio meddelanden och filtrerar framtida announcements, avslutade incidenter och avslutat underhåll. Dismiss-knappar använder webbläsarens session storage per användare; nya incident-ID:n påverkas inte av tidigare dismiss. Incidenter utan ID kan inte avfärdas. WordPress admin-bar flyttas efter bannerns uppmätta höjd när den visas.

Alerts hämtas separat från OneUptimes autentiserade API med den sparade nyckeln. Pluginet läser monitorerna direkt från den konfigurerade statussidan, inklusive monitorer i sidans monitorgrupper, och visar bara deras olösta alerts. Om monitorerna inte kan läsas visas inga alerts. Resultatet cachas i en minut. Alerts visas aldrig i den publika bannern.

Dashboard-widgeten får statussidans eget namn som rubrik. Övergripande status syns direkt; komponenter och nästlade grupper öppnas vid behov, medan tjänsterna visas som enkla rader. Aktuell status hämtas högst en gång per minut. Drifttid för **de senaste 7 dagarna** hämtas i ett separat WP-Cron-jobb och kan saknas på första sidladdningen. Utebliven drifttid märks **uppgift saknas**. När API:et fallerar backar kontrollen i 60 sekunder; senast kända data kan visas i upp till ett dygn och märks **ej aktuell** med grå statusindikator och tidsstämpel. Den visas aldrig som ny grön status. API-svar utan förväntade fält ignoreras och loggas utan innehåll eller hemliga URL:er.

### Heartbeat och WP-Cron

Skapa en Incoming Request-monitor i OneUptime och kopiera dess heartbeat-ID eller hela adress. Anropet använder `wp_safe_remote_get()`, HTTPS, inga omdirigeringar och fem sekunders timeout. WordPress validerar publika adresser även efter DNS-upplösning. HTTP-kod utanför 2xx registreras som fel; senaste försök och framgång syns under Diagnostik.

Pluginet har högst ett återkommande heartbeat-event när integrationen, heartbeat och en giltig adress är aktiverade. Eventet tas bort när pluginet inaktiveras eller avinstalleras. WP-Cron körs normalt först när webbplatsen får trafik. För mer pålitliga kontroller, låt systemcron anropa `wp-cron.php` ungefär varje minut och sätt OneUptimes larmgräns med marginal, till exempel tre missade minuter. Exempel (byt domän):

```cron
* * * * * curl --silent --show-error --fail https://example.com/wp-cron.php?doing_wp_cron >/dev/null
```

Om `DISABLE_WP_CRON` är aktiv måste en extern cron finnas. Diagnostik varnar om nästa heartbeat är mer än två minuter försenad.

### Diagnostik

Fliken **Diagnostik** visar aktiverings- och konfigurationsstatus för integrationerna, PHP-version, senaste heartbeat, nästa cron-tid och varning om försenad cron. Endast administratörer med `manage_options` får köra **Testa anslutningar** och **Rensa cache**. Åtgärderna kräver WordPress nonce. Testresultat är generiska och innehåller inte API-nycklar, token eller privata svarskroppar. Testet utför nätverksanrop bara när en administratör trycker på knappen.

## Uppdateringar från GitHub Releases

Pluginfilen har `Update URI: https://github.com/ArcWindAB/WP-Init`. Uppdateraren använder WordPress standardfilter för tillägg med egen Update URI. Informationsrutan för tillägget visar funktioner, installation, vanliga frågor och publicerade versionsnyheter; grundinformationen finns kvar även när GitHub inte svarar. Uppdateraren godtar endast en publicerad, stabil semantisk version med release-asset `arcwind-init.zip` på rätt GitHub-repo och via HTTPS. API-fel och rate limits cachelagras i 15 minuter. Den aktiverar inte automatiska uppdateringar åt dig: använd WordPress egen **Aktivera automatiska uppdateringar** på sidan Installerade tillägg, om er globala policy tillåter det.

Inställningar lagras i WordPress options och överlever filuppdateringar. En felaktig release-asset erbjuds inte som uppdatering; installationspaketet måste innehålla pluginfilen och en giltig versionsheader. Ta backup och använd WordPress återställningsfunktion eller installera föregående ZIP om en uppdatering misslyckas. Läs [CHANGELOG.md](CHANGELOG.md) före release.

Bygg och validera en release lokalt:

```sh
python3 scripts/build-release.py --tag v2.0.0
python3 scripts/build-release.py --validate-only
```

Publicera sedan ZIP-filen som `arcwind-init.zip` på en GitHub Release vars tagg matchar versionsheadern. Källkodens GitHub-ZIP räcker inte. CI bygger och kontrollerar samma struktur och sparar ZIP som artifact på taggar. Se [releasechecklistan](docs/RELEASE.md).

## Felsökning

| Symtom | Kontroll |
| --- | --- |
| PostHog-statistik saknas | Ange HTTPS-värd, Project ID och Personal API Key. Kör anslutningstest under Diagnostik. |
| JavaScript-events saknas | Aktivera spårning, ange publik API-nyckel och kontrollera webbläsarens nätverkslogg. Samtyckesblockering kan stoppa skriptet. |
| Feedback-knapp saknas | Aktivera feedback och spara ett alfanumeriskt UUID. |
| OneUptime-widget saknas | Ange OneUptime-värd och statussidans ID. Kontrollera att statussidan är publik. |
| Status visas som äldre data | Kontrollera status-API och serverns utgående HTTPS. Rensa cache under Diagnostik när anslutningen fungerar igen. |
| Drifttidshistorik saknas | Kontrollera att ID används i stället för domänalias och att WP-Cron körs. |
| Heartbeat uteblir | Kontrollera giltig publik HTTPS-adress, senaste försök och cron-schema i Diagnostik; använd systemcron vid låg trafik. |
| Ingen GitHub-uppdatering syns | Publicera en nyare stabil Release med korrekt `arcwind-init.zip`; vänta upp till en timme eller rensa cache. |

## Utveckling och struktur

`arcwind-init.php` är bootstrap och registrerar livscykel-hooks. `includes/` innehåller fokuserade moduler för inställningar, PostHog, feedback, dashboard, OneUptime, diagnostik och uppdateringar. `assets/js/` innehåller klient-API:t. `tests/` innehåller fixture-baserade regressionstester och ett WordPress-rökprov. `scripts/build-release.py` bygger och validerar ZIP-filen. Se [utvecklarguiden](docs/DEVELOPMENT.md) för testkommandon och CI-matris.

## Licens

[GPL-2.0-or-later](LICENSE).
