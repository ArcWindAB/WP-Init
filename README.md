# ArcWind Init

[![GitHub
Release](https://img.shields.io/github/v/release/ArcWindAB/WP-Init)](https://github.com/ArcWindAB/WP-Init/releases)
[![GitHub
License](https://img.shields.io/github/license/ArcWindAB/WP-Init)](https://github.com/ArcWindAB/WP-Init/blob/main/LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-Plugin-blue)](https://wordpress.org)
[![PostHog](https://img.shields.io/badge/Analytics-PostHog-purple)](https://posthog.com)

ArcWind Init är ett WordPress‑plugin som:

-   initierar **PostHog** i både frontend och admin
-   visar en **dashboard‑widget** med statistik
-   visar en **feedback‑widget**
-   stödjer **felspårning**
-   stödjer **uppdateringar från GitHub**
-   exponerar ett gemensamt API för **JavaScript och PHP tracking**

------------------------------------------------------------------------

# Funktioner

## PostHog i frontend och admin

Pluginet laddar automatiskt PostHog‑snippet beroende på inställningarna.

## Dashboard widgets

Pluginet lägger till:

**Statistik** - aktiva användare (7 dagar) - sidvisningar (7 dagar)

**Feedback** - knapp för att rapportera fel eller föreslå funktioner

## Felspårning

Pluginet kan skicka serverfel till PostHog.

## Custom event tracking

Du kan skicka egna events från:

-   JavaScript
-   PHP

------------------------------------------------------------------------

# Installation

1.  Ladda upp pluginet till

```{=html}
<!-- -->
```
    /wp-content/plugins/arcwind-init/

2.  Aktivera pluginet i WordPress

3.  Gå till

```{=html}
<!-- -->
```
    Inställningar → ArcWind Init

4.  Ange dina PostHog‑uppgifter

------------------------------------------------------------------------

# Filstruktur

    arcwind-init/
    ├── arcwind-init.php
    ├── includes/
    │   └── class-arcwind-init-tracker.php
    └── assets/
        └── js/
            └── arcwind-init-tracker.js

------------------------------------------------------------------------

# JavaScript API

När pluginet är aktivt finns ett globalt objekt:

    window.ArcWindInit

## Skicka event

``` javascript
window.ArcWindInit.track("button_clicked", {
    button_name: "save_settings",
    area: "admin"
});
```

## Identifiera användare

``` javascript
window.ArcWindInit.identify("123", {
    name: "Tobias Jonson",
    role: "admin"
});
```

## Exempel -- tracka knappklick

``` javascript
document.addEventListener("DOMContentLoaded", function () {

    const button = document.querySelector("#saveButton");

    if (!button) return;

    button.addEventListener("click", function () {

        window.ArcWindInit.track("settings_button_clicked", {
            button: "saveButton",
            page: "settings"
        });

    });

});
```

## Exempel -- tracka formulär

``` javascript
document.querySelector("#contactForm").addEventListener("submit", function(){

    window.ArcWindInit.track("form_submitted", {
        form: "contactForm"
    });

});
```

------------------------------------------------------------------------

# PHP API

Server‑side tracking görs via:

    ArcWind_Init_Tracker::capture()

## Skicka event

``` php
ArcWind_Init_Tracker::capture('settings_saved', [
    'section' => 'posthog',
    'area' => 'admin'
]);
```

## Med explicit distinct ID

``` php
ArcWind_Init_Tracker::capture('report_generated', [
    'report_type' => 'weekly'
], 'user-123');
```

## Exempel -- tracka admin‑save

``` php
if (isset($_POST['save_settings'])) {

    ArcWind_Init_Tracker::capture('settings_saved', [
        'page' => 'plugin-settings',
        'user_id' => get_current_user_id()
    ]);

}
```

## Exempel -- AJAX

``` php
add_action('wp_ajax_my_action', function(){

    ArcWind_Init_Tracker::capture('ajax_action_triggered', [
        'action' => 'my_action'
    ]);

    wp_send_json_success();

});
```

## Exempel -- Cron

``` php
add_action('my_cron_job', function(){

    ArcWind_Init_Tracker::capture('cron_executed', [
        'job' => 'my_cron_job'
    ]);

});
```

------------------------------------------------------------------------

# Standard Properties

## Client events

-   source: wordpress
-   source_type: client
-   plugin: arcwind-init
-   plugin_ver
-   page_type
-   is_admin

## Server events

-   source: wordpress
-   source_type: server
-   plugin: arcwind-init
-   plugin_ver
-   site_url
-   current_url

------------------------------------------------------------------------

# GitHub Updates

Pluginet använder:

    Update URI: https://github.com/ArcWindAB/WP-Init

WordPress kan därför uppdatera pluginet direkt från **GitHub Releases**.

För att det ska fungera måste varje release innehålla en ZIP‑fil.

Exempel:

    arcwind-init.zip

Struktur i zip:

    arcwind-init/
     ├ arcwind-init.php
     ├ includes/
     └ assets/

------------------------------------------------------------------------

# Rekommenderade eventnamn

    settings_saved
    button_clicked
    form_submitted
    report_generated
    sync_started
    sync_completed
    sync_failed
    feedback_opened
    feedback_submitted

------------------------------------------------------------------------

# Felsökning

## JS events fungerar inte

Kontrollera:

-   tracking är aktiverat
-   public_api_key är satt
-   PostHog laddas i konsolen

## PHP events fungerar inte

Kontrollera:

-   tracker‑klassen laddas
-   public_api_key är satt
-   servern kan göra utgående HTTP‑requests

------------------------------------------------------------------------

# Licens

GPL‑2.0‑or‑later


## OneUptime

Gå till **Inställningar → ArcWind Init → OneUptime**.

- Aktivera OneUptime och ange adressen till din **publika** statussida (t.ex. `https://status.example.com`).
- Ange statussidans **Status Page ID** (från OneUptime) eller verifierade domän.
- Aktivera incidentbannern för att visa öppna incidenter på webbplatsens frontend. Incidenter hämtas server-side via `/status-page-api/incidents/{id}` och cachelagras i 60 sekunder. Inga API-nycklar behövs för en publik statussida.
- Skapa en **Incoming Request**-monitor i OneUptime, kopiera den hemliga `https://oneuptime.example.com/heartbeat/SECRET`-adressen och aktivera heartbeat.
- Heartbeat skickas från servern varje minut så länge OneUptime och heartbeat är aktiverade.

**Viktigt om cron:** WP-Cron triggas normalt av inkommande webbtrafik och är därför inte minutexakt på webbplatser med lite trafik. För tillförlitlig övervakning behöver du köra verklig cron var 60:e sekund som anropar WordPress `wp-cron.php`, gärna med `DISABLE_WP_CRON` aktiverat i WordPress. Ställ in OneUptimes larmtröskel med marginal för tillfälliga fördröjningar, till exempel utebliven heartbeat under 3 minuter.

Heartbeat-URL:en innehåller en hemlighet. Dela den inte offentligt eller i felsökningsloggar.

## Inställningsflikar

Inställningarna är uppdelade i **PostHog**, **Feedback** och **OneUptime**. Befintliga PostHog-inställningar och feedback-UUID behålls vid uppdateringen. PostHog-spårning och feedback-widgeten kan aktiveras eller stängas av separat.
