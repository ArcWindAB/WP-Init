# Releasechecklista

1. Uppdatera versionsheadern i `arcwind-init.php` och flytta relevanta punkter från **Unreleased** till samma version i `CHANGELOG.md`.
2. Uppdatera README och utvecklarguiden när beteende, standardvärden, PHP/WordPress-stöd eller begränsningar ändrats. Kontrollera att exemplen bara innehåller platshållare.
3. Kör PHP-lint, regressionstester, WordPress-rökprov och ZIP-validering. Kontrollera CI-matrisen och att uppgradering bevarar options.
4. Bygg med `python3 scripts/build-release.py --tag vX.Y.Z`. ZIP-filen ska ha `arcwind-init/` som rot och samma version i pluginfilen.
5. Skapa GitHub Release för taggen `vX.Y.Z`. Arbetsflödet `build-release.yml` skapar och laddar upp `arcwind-init.zip` när releasen publiceras. Kontrollera att assetet finns och att dess versionsheader stämmer med taggen. GitHubs genererade source-ZIP är inte pluginets installationspaket.
6. På en separat WordPress-testinstallation: kontrollera att uppdateringen syns under Installerade tillägg, att WordPress egen **Aktivera automatiska uppdateringar** går att använda och att manuell uppdatering fungerar. Prova återställning med föregående ZIP vid ett avbrutet/ogiltigt paket.
7. Kontrollera efter uppgradering att PostHog-, Feedback- och OneUptime-inställningar finns kvar, att högst ett heartbeat-jobb finns och att frontend, wp-admin och dashboard laddar under simulerade API-fel.
