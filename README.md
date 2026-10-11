# ArcWind Init

ArcWind Init samlar tre valfria WordPress-integrationer: PostHog-spårning och dashboardstatistik, en länk till ett feedbackformulär samt OneUptime-status med driftinformation, alerts och heartbeat. Varje del ställs in separat under **Inställningar → ArcWind Init**.

## Krav och installation

- CI testar WordPress 6.8 och den senaste WordPress-versionen med PHP 8.2 och 8.3. Andra kombinationer omfattas inte av testmatrisen.
- Servern behöver kunna göra utgående HTTPS-anrop för PostHogs statistikfrågor och serverhändelser, OneUptime, heartbeat och GitHub Releases. Den publika OneUptime-statussidan kräver ingen API-nyckel; alerts i admin gör det.
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
| Aktivera felspårning | på | Skickar fel från dashboardens PostHog Query API-frågor som händelser via serverns `api_host` när projekt-token finns. |
| Distinct ID för fel | `arcwind-init:server` | ID för de felhändelserna. |

PostHog-inställningarna visas i en gemensam tabell. **PostHog-anslutning** är öppen från början och innehåller region, API-värd, UI-värd, projekt-ID och projekt-token. Webbspårning innehåller även SDK defaults, person profiles och laddningsposition och öppnas när projekt-token finns. Felspårning kräver också projekt-token; båda använder vald regions API-värd när fältet är tomt. Dashboardstatistik kräver projekt-ID. Ett låst avsnitt visas grått och kan öppnas direkt när dess nödvändiga fält fyllts i. UI-värden är valfri och används för PostHogs gränssnitt och privata Query API.

Ikoner bredvid avsnitten visar den sparade inställningens status: grön bock betyder aktiv, grått streck betyder av och orange varning betyder att något som behövs saknas. Ikonerna visar konfiguration, inte resultatet av ett anslutningstest.

**Statistik** på WordPress dashboard visas när projekt-ID och personlig API-nyckel finns. Den använder UI-värden eller vald regions HTTPS-adress för PostHogs Query API, räknar unika `distinct_id` och sidvisningar för de senaste sju dagarna och cachelagrar resultatet i tio minuter. Uppgifterna hämtas via WP-Cron; innan första hämtningen eller vid API-fel visas ett streck i stället för ett värde. Vid fel används en kortare cachetid på en minut. När felspårning är på skickas dessa Query API-fel som generiska PostHog-händelser. Manuell cache-rensning finns under Diagnostik.

Webbsnippet laddas bara när spårning är aktiverad och projekt-token finns. Det körs både på webbplatsen och i wp-admin. Inloggade användare identifieras med WordPress-användar-ID och attribut som visningsnamn, användarnamn, roller, administratörsstatus och SHA-256-hash av e-post. Pluginet innehåller ingen egen samtyckeshanterare.

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

Feedbackkortet visas på WordPress dashboard när feedback är aktiverad och ett giltigt UUID finns. Det länkar till formuläret i en ny flik och bäddar inte in något formulär på publika sidor. Sparade PostHog- och Feedback-inställningar behålls när andra flikar sparas.

### OneUptime

| Fält | Standard | Funktion |
| --- | --- | --- |
| OneUptime-värd | tomt | HTTPS-basadress, exempelvis `https://oneuptime.example.com/`. |
| Statussidans ID | tomt | ID för den publika statussidan. Statussidans adress byggs som `<värd>/status-page/<ID>`. |
| Driftinformation på webbplatsen | på | Visar valda händelser i en banner när värd och status-ID finns. |
| Incidenter, meddelanden, underhåll på webbplatsen | på | Tre separata val för webbplatsen. |
| Driftinformation i admin | på | Visar valda händelser som WordPress-notiser när värd och status-ID finns. |
| Incidenter, meddelanden, underhåll i admin | på | Tre separata val för admin. |
| Skicka heartbeat | av | Schemalägger heartbeat när värd och heartbeat-ID finns. |
| Heartbeat-ID | tomt | ID från en Incoming Request-monitor. En fullständig URL av formen `<värd>/heartbeat/<ID>` kan klistras in och kortas till ID:t i fältet. |
| API-nyckel för alerts | tomt | Nyckel med läsbehörighet för Alert och Alert State. En sparad nyckel visas inte i formuläret; en knapp kan spara formuläret och ta bort nyckeln. |
| Visa alerts i admin | av | Visar högst tio olösta alerts från statussidans monitorer i en adminnotis för administratörer. Klicka på en titel för att läsa beskrivningen. |

Statussida, Driftinformation, Heartbeat och Alerts finns som öppningsbara avsnitt i samma inställningstabell. Heartbeat är låst tills OneUptime-värden har fyllts i. Driftinformation och Alerts är låsta tills både värd och status-ID har fyllts i. Äldre sparade URL:er och visningsval läses in automatiskt.

Varje OneUptime-avsnitt har samma statusikoner som PostHog: grön bock för aktiv sparad konfiguration, grått streck för av och orange varning när ett aktiverat avsnitt saknar uppgifter.

OneUptime-data hämtas från statussidans publika API i bakgrunden via WP-Cron. Bannern visar högst tio meddelanden och filtrerar framtida meddelanden, avslutade incidenter och avslutat underhåll. Stäng-knappar sparar valet i webbläsarens lokala lagring i högst 24 timmar per användare och meddelande. En incident med nytt ID eller ändrad titel visas igen. Incidenter utan ID kan inte stängas. Bannerns uppmätta höjd ger plats ovanför WordPress verktygsfält på dator och mobil.

Alerts hämtas separat i bakgrunden från OneUptimes autentiserade API med den sparade nyckeln. Pluginet läser monitorerna direkt från den konfigurerade statussidan, inklusive monitorer i sidans monitorgrupper, och visar bara deras olösta alerts. Om monitorerna inte kan läsas visas inga alerts. Resultatet cachas i en minut. Alerts visas aldrig i den publika bannern.

Dashboard-widgeten använder statussidans namn som rubrik när API:et kan läsa det; annars visas **Driftstatus**. Övergripande status syns direkt; grupper och nästlade grupper öppnas vid behov, medan tjänsterna visas som enkla rader. Aktuell status hämtas via WP-Cron och cachas i en minut. Drifttid för **de senaste 7 dagarna** hämtas i ett separat WP-Cron-jobb och kan saknas på första sidladdningen. Utebliven drifttid märks **uppgift saknas**. När API:et fallerar väntar widgeten 60 sekunder innan ett nytt försök; senast kända data kan visas i upp till ett dygn och märks **ej aktuell** med grå statusindikator och tidsstämpel. Oväntade API-format hanteras utan att svarskroppar eller URL:er loggas.

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

Pluginfilen har `Update URI: https://github.com/ArcWindAB/WP-Init`. Uppdateraren använder WordPress filter för tillägg med egen Update URI. Informationsrutan för tillägget visar funktioner, installation, vanliga frågor och releasens versionsnyheter; grundinformationen finns kvar även när GitHub inte svarar. Uppdateraren läser den senaste publicerade GitHub-releasen, avvisar utkast och releaser markerade som förhandsversioner och kräver en semantisk versionstagg samt ett HTTPS-asset som heter `arcwind-init.zip` på rätt repo. En uppdatering erbjuds först om versionen är nyare än den installerade. Lyckade svar cachas i en timme; API-fel och rate limits i 15 minuter. WordPress automatiska uppdateringar kan aktiveras på sidan Installerade tillägg.

Inställningar lagras i WordPress options och överlever filuppdateringar. En felaktig release-asset erbjuds inte som uppdatering; installationspaketet måste innehålla pluginfilen och en giltig versionsheader. Ta backup och använd WordPress återställningsfunktion eller installera föregående ZIP om en uppdatering misslyckas. Läs [CHANGELOG.md](CHANGELOG.md) före release.

Bygg och validera en release lokalt:

```sh
python3 scripts/build-release.py --tag v2.0.1
python3 scripts/build-release.py --validate-only
```

Publicera sedan ZIP-filen som `arcwind-init.zip` på en GitHub Release vars tagg matchar versionsheadern. Källkodens GitHub-ZIP räcker inte. CI validerar en installationsbar ZIP och sparar den som artifact på taggar. Vid publicering av en GitHub Release körs även ett separat arbetsflöde som skapar och laddar upp en ZIP. Se [releasechecklistan](docs/RELEASE.md).

## Felsökning

| Symtom | Kontroll |
| --- | --- |
| PostHog-statistik saknas | Ange projekt-ID och personlig API-nyckel. Kontrollera `ui_host` eller vald region och kör anslutningstest under Diagnostik. |
| JavaScript-händelser saknas | Aktivera spårning, ange projekt-token och kontrollera `api_host` samt webbläsarens nätverkslogg. |
| Feedback-knapp saknas | Aktivera feedback och spara ett alfanumeriskt UUID. |
| OneUptime-widget saknas | Ange OneUptime-värd och statussidans ID. Kontrollera att statussidan är publik. |
| Status visas som äldre data | Kontrollera status-API och serverns utgående HTTPS. Rensa cache under Diagnostik när anslutningen fungerar igen. |
| Drifttidshistorik saknas | Kontrollera att ID används i stället för domänalias och att WP-Cron körs. |
| Heartbeat uteblir | Kontrollera giltig publik HTTPS-adress, senaste försök och cron-schema i Diagnostik; använd systemcron vid låg trafik. |
| Ingen GitHub-uppdatering syns | Kontrollera att en nyare, publicerad Release har korrekt `arcwind-init.zip`. Vänta upp till en timme eller rensa cache under Diagnostik. |

## Utveckling och struktur

`arcwind-init.php` är bootstrap och registrerar livscykel-hooks. `includes/` innehåller fokuserade moduler för inställningar, PostHog, feedback, dashboard, OneUptime, diagnostik och uppdateringar. `assets/js/` innehåller klient-API:t. `tests/` innehåller fixture-baserade regressionstester och WordPress-integrationskontroller. `scripts/build-release.py` bygger och validerar ZIP-filen. Se [utvecklarguiden](docs/DEVELOPMENT.md) för testkommandon och CI-matris samt [AGENTS.md](AGENTS.md) för språk- och utvecklingsregler.

## Licens

[GPL-2.0-or-later](LICENSE).
