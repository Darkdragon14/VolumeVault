<?php

return [
    'busybox_restore_tar_compat' => [
        'title' => 'Compatibele restore-extractie',
        'description' => 'Herstelacties naar een nieuw Docker-volume geven geen GNU-only tar-opties meer door en streamen het archief nu naar de restore-container, zodat extractie werkt met BusyBox-tar en met containerdeployments waarvan de opslag in een Docker-volume staat.',
    ],
    'stable_stack_volume_search' => [
        'title' => 'Stabiele stack- en volumezoekfunctie',
        'description' => 'Typen in de zoekfunctie voor stacks of volumes houdt het filter nu actief in plaats van terug te vallen naar de standaardtoestand nadat de URL is gesynchroniseerd.',
    ],
    'backup_archive_name_templates' => [
        'title' => 'Aangepaste namen voor back-uparchieven',
        'description' => 'Back-uptaken kunnen nu een sjabloon voor archiefnamen gebruiken met tokens zoals {name}, {source}, {id}, {year}, {month}, {day} en {time}. Bestaande taken behouden de vorige naamgeving volumevault-source-run-id totdat een sjabloon is ingesteld, en het formulier waarschuwt wanneer een sjabloon eerdere archieven kan overschrijven.',
    ],
    'russian_translation_revisions' => [
        'title' => 'Verfijnde Russische interfacetext',
        'description' => 'De Russische interfacevertalingen kregen extra tekstcorrecties voor betere consistentie en leesbaarheid. Dank aan @artyomboyko voor deze vertaalbijdrage.',
    ],
    'complete_i18n_coverage' => [
        'title' => 'Vollediger vertaalde interface',
        'description' => 'Veel interfaceteksten die nog in het Engels werden weergegeven – waaronder de pagina\'s voor API-tokens en installatieback-ups – zijn nu volledig vertaald. Alle negen talen zijn gesynchroniseerd en ontbrekende vertalingen aangevuld, zodat niet-Engelstalige gebruikers geen onvertaalde labels, knoppen en berichten meer zien.',
    ],
    'reliable_run_logs' => [
        'title' => 'Betrouwbaardere uitvoeringslogboeken',
        'description' => 'Logboeken van back-ups en herstelacties worden nu atomair toegevoegd, zodat gelijktijdige schrijfacties (bijvoorbeeld de mislukt-handler van een taak die afgaat terwijl een uitvoering eindigt) elkaar niet meer kunnen overschrijven. Het inkorten van logboeken is bovendien UTF-8-bewust, zodat ingekorte logboeken geldig blijven en de detailweergave van de uitvoering niet meer breken.',
    ],
    'stale_run_liveness_reconcile' => [
        'title' => 'Sneller herstel van onderbroken back-ups',
        'description' => 'Uitvoeringen die vastlopen na een crash, time-out of herstart van de worker worden nu veel sneller hersteld. De reconciler controleert of de back-upcontainer nog actief is in plaats van een vaste vertraging af te wachten: dode uitvoeringen mislukken binnen minuten, terwijl echt lange back-ups ongemoeid blijven. Het herstel draait ook automatisch bij het opstarten van de container en herstart applicatiecontainers die gestopt zijn achtergelaten.',
    ],
    'local_destination_listing_cap' => [
        'title' => 'Begrensde lijsten van lokale bestemmingen',
        'description' => 'Het opsommen van back-ups op een lokale bestandssysteembestemming is nu beperkt tot 1000 items, net als bij de andere opslagproviders, zodat een bestemming met een zeer grote archiefmap niet langer de hele boom in één antwoord laadt.',
    ],
    'per_job_schedule_timezone' => [
        'title' => 'Tijdzone per taak',
        'description' => 'Elke back-uptaak kan nu een eigen tijdzone instellen, zodat een schema als "dagelijks om 02:00" om 02:00 lokale tijd draait in plaats van in de globale applicatietijdzone. Laat het op "Standaard van applicatie" staan om het vorige gedrag te behouden.',
    ],
    'http_security_headers' => [
        'title' => 'HTTP-beveiligingsheaders',
        'description' => 'Antwoorden bevatten nu beveiligingsheaders voor verdediging in de diepte (X-Frame-Options, X-Content-Type-Options en Referrer-Policy), plus HSTS bij levering via HTTPS. Implementaties met gewone HTTP en op een LAN worden niet beïnvloed — geen enkel verzoek wordt ooit van HTTP naar HTTPS gedwongen.',
    ],
    'local_destination_path_error_feedback' => [
        'title' => 'Duidelijkere padfouten voor lokale bestemmingen',
        'description' => 'Bij het aanmaken van een lokale bestandssysteembestemming worden padvalidatiefouten — zoals een pad dat door de host-pad-allowlist wordt geblokkeerd — nu rechtstreeks in het formulier getoond, in plaats van stilletjes terug te keren naar de aanmaakpagina.',
    ],
    'russian_translation_consistency' => [
        'title' => 'Bijgewerkte Russische vertalingen',
        'description' => 'De Russische interface-teksten zijn bijgewerkt voor meer consistentie, en de Russische vertalerswoordenlijst is uit de meegeleverde taalbestanden verplaatst naar aparte projectdocumentatie. Daardoor blijven de meegeleverde taalresources schoner, terwijl de woordenlijst beschikbaar blijft voor bijdragers. Dank aan @artyomboyko voor deze vertaalbijdrage.',
    ],
    'customizable_dashboard' => [
        'title' => 'Aanpasbaar dashboard',
        'description' => 'U kunt nu kiezen welke dashboardwidgets worden weergegeven en in welke volgorde. Klik op "Aanpassen" om een statistiekkaart of sectie te verbergen of te tonen, sleep ze om de volgorde te wijzigen en klik daarna op "Klaar" om op te slaan. Elke gebruiker behoudt zijn eigen indeling, en "Standaardwaarden herstellen" zet de oorspronkelijke indeling terug.',
    ],
    'self_container_backup_guard' => [
        'title' => 'VolumeVault stopt zijn eigen container niet meer tijdens een back-up',
        'description' => 'Wanneer een back-uptaak "containers stoppen voor back-up" heeft ingeschakeld en gericht is op een volume dat de VolumeVault-container zelf ook koppelt, stopt VolumeVault zijn eigen container niet langer - wat de lopende back-up zou hebben afgebroken. De container wordt automatisch gedetecteerd via zijn hostnaam en cgroup; stel VOLUMEVAULT_CONTAINER_ID of VOLUMEVAULT_CONTAINER_NAME in als automatische detectie onbetrouwbaar is (aangepaste hostnaam of host-netwerk).',
    ],
    'host_path_stop_containers' => [
        'title' => 'Geselecteerde containers stoppen bij back-ups van host-pad',
        'description' => 'Back-uptaken van het type host-pad kunnen nu containers stoppen voor de back-up en ze daarna opnieuw starten, zoals Docker-volumetaken al konden. Omdat een host-pad niet automatisch aan containers kan worden gekoppeld, kies je ze op naam in het taakformulier. De selectie wordt op naam opgeslagen en overleeft zo het opnieuw aanmaken van containers; containers die niet meer bestaan of al gestopt zijn, worden overgeslagen, en VolumeVault stopt nooit zijn eigen container.',
    ],
    'ssrf_destination_guard' => [
        'title' => 'Back-upbestemmingen met een prive-IP zijn nu beveiligd (SSRF)',
        'description' => 'VolumeVault weigert nu standaard verbinding te maken met een back-upbestemming waarvan de host wordt herleid naar een prive-, loopback- of link-local-adres (inclusief het cloud-metadata-eindpunt 169.254.169.254). Dit betreft alleen bestemmingen met een prive-IP, zoals een NAS in het LAN of een zelf-gehoste S3/MinIO - cloudbestemmingen via een openbare URL worden niet beinvloed. Geplande back-ups blijven draaien, maar de bestemmingstest, het herstel (lijst en download) en de waarschuwing voor het opslagquotum worden geblokkeerd totdat u het bereik van de bestemming opgeeft in VOLUMEVAULT_SSRF_ALLOWED_IPS (door komma\'s gescheiden CIDR-reeksen, bijv. 192.168.1.0/24). Notificatiekanalen worden niet beveiligd.',
    ],
    'host_path_allowlist_fail_closed' => [
        'title' => 'De hostpad-toelatingslijst is nu fail-closed',
        'description' => 'VOLUMEVAULT_HOST_PATH_ALLOWLIST weigert nu standaard: wanneer deze leeg is, worden back-upbronnen op basis van een hostpad en lokale bestemmingen geweigerd in plaats van elk pad toe te staan. Dezelfde lijst beschermt nu ook lokale bestemmingen, en paden worden tijdens runtime opnieuw gecontroleerd om het verwisselen van symbolische koppelingen te blokkeren. Bestaande installaties die op het vorige open standaardgedrag vertrouwden, moeten hun paden opgeven - voer "php artisan volumevault:host-path-allowlist:audit" uit voor de exacte in te stellen waarde.',
    ],
    'auth_rate_limiting' => [
        'title' => 'Aanmelding en wachtwoordherstel met snelheidslimiet',
        'description' => 'Aanmeldings- en wachtwoordherstelverzoeken zijn nu beperkt tot 5 pogingen per minuut, wat brute-force-aanvallen op het beheerderswachtwoord vertraagt. Bij het overschrijden van de limiet wordt een tijdelijk "te veel verzoeken"-antwoord geretourneerd dat na een minuut wordt gereset.',
    ],
    'restore_input_hardening' => [
        'title' => 'Strengere validatie van herstel- en back-upinvoer',
        'description' => 'De voor een herstel geselecteerde back-up moet nu overeenkomen met de lijst van de bestemming, waardoor pad-traversal-sleutels zoals "../../etc/passwd" worden geblokkeerd. Docker-volumenamen zijn beperkt tot veilige tekens en de herstelextractie wordt ingeperkt zodat een vervalst archief niet buiten het doelvolume kan schrijven.',
    ],
    'sftp_host_key_pinning' => [
        'title' => 'SSH-hostsleutel vastzetten voor SFTP-bestemmingen',
        'description' => 'SSH/SFTP-bestemmingen kunnen nu de hostsleutel van de server vastzetten om man-in-the-middle-aanvallen te blokkeren. Gebruik de knop "Sleutel van server ophalen" - of het nieuwe eindpunt POST /api/v1/destinations/host-key - om de gepresenteerde sleutel te vertrouwen, of plak een hostsleutel of SHA256-vingerafdruk. De sleutel wordt geverifieerd voordat er inloggegevens worden verzonden, voor de SFTP-bewerkingen die door VolumeVault worden uitgevoerd (test, lijst, herstel). Leeg laten behoudt het vorige gedrag.',
    ],
    'api_token_expiration' => [
        'title' => 'API-tokens verlopen nu standaard',
        'description' => 'API-tokens verlopen nu standaard 60 dagen na het aanmaken, wat de impact van een gelekte token beperkt. Bestaande oudere tokens werken na de upgrade niet meer en moeten opnieuw worden aangemaakt. Stel SANCTUM_TOKEN_EXPIRATION (in minuten) in om de periode te wijzigen, of null om niet-verlopende tokens te behouden. Een verloopdatum per token kan deze periode alleen verkorten, nooit verlengen.',
    ],
    'alert_check_isolation' => [
        'title' => 'Robuustere alertcontroles',
        'description' => 'Een alertregel die een fout veroorzaakt, verhindert niet langer dat de overige regels worden gecontroleerd. Elke regel wordt nu onafhankelijk geevalueerd en fouten worden gelogd, zodat een enkele falende controle je overige alerts niet meer stilletjes kan uitschakelen.',
    ],
    'restore_volume_cleanup' => [
        'title' => 'Schonere nieuwe pogingen na een mislukte restore',
        'description' => 'Wanneer een restore mislukt nadat het doelvolume is aangemaakt, verwijdert VolumeVault nu het gedeeltelijk aangemaakte volume zodat de volgende poging schoon start in plaats van te worden geblokkeerd door een "bestaat al"-fout.',
    ],
    'schedule_drift_prevention' => [
        'title' => 'Betrouwbaardere back-upplanning',
        'description' => 'Geplande back-ups slaan geen uitvoering meer over wanneer een worker achterloopt. De volgende uitvoering wordt nu verankerd aan het geplande tijdvak in plaats van aan de eindtijd van de vorige uitvoering, zodat een trage of vertraagde uitvoering de planning niet meer kan laten verschuiven.',
    ],
    'destination_usage_efficiency' => [
        'title' => 'Efficientere berekening van opslaggebruik van bestemming',
        'description' => 'Het opslaggebruik van back-upbestemmingen wordt nu berekend door de objecten te streamen in plaats van de hele lijst in het geheugen te laden, en SFTP-verbindingen worden daarna altijd gesloten. Bestemmingen met veel back-ups worden betrouwbaarder gemeten, zonder het geheugen uit te putten of verbindingen open te laten.',
    ],
    'run_log_integrity' => [
        'title' => 'Betrouwbaardere uitvoeringslogs',
        'description' => 'Logs van back-up- en restore-uitvoeringen worden nu atomair toegevoegd, zodat gelijktijdige updates - zoals een foutmelding en een melding over het herstarten van een container - elkaar niet meer overschrijven. De omvang is ook begrensd, waarbij de meest recente uitvoer behouden blijft in plaats van eindeloos te groeien.',
    ],
    'stale_run_reconciliation' => [
        'title' => 'Automatisch herstel van onderbroken runs',
        'description' => 'Back-up- en herstelruns die zijn onderbroken door een worker-crash, time-out of herstart worden nu automatisch als mislukt gemarkeerd in plaats van vast te blijven zitten, zodat geplande back-ups blijven draaien. Applicatiecontainers die voor een back-up zijn gestopt, worden ook automatisch herstart als een crash ze uitgeschakeld liet.',
    ],
    'advanced_alerting' => [
        'title' => 'Geavanceerde waarschuwingen',
        'description' => 'VolumeVault kan back-uptaken bewaken op verouderde back-ups, herhaalde mislukkingen, langdurige foutstatussen en ongebruikelijke archiefgroottes.',
    ],
    'destination_storage_limit_alerts' => [
        'title' => 'Opslaglimietwaarschuwingen',
        'description' => 'Backupbestemmingen kunnen nu absolute waarschuwings- en kritieke opslagdrempels met eigen meldingskanalen instellen.',
    ],
    'mobile_navigation_redesign' => [
        'title' => 'Verbeterde mobiele navigatie',
        'description' => 'De mobiele kop gebruikt nu een compacte menuknop en een gestructureerd navigatiepaneel in plaats van alle links in de kop te stapelen.',
    ],
    'keyboard_shortcuts' => [
        'title' => 'Sneltoetsen',
        'description' => 'Gebruik op desktop Ctrl+K voor snelle navigatie, g-sneltoetsen voor weergaven en / om zoeken in lijsten te focussen.',
    ],
    'in_app_update_summaries' => [
        'title' => 'Update-overzichten in de app',
        'description' => 'VolumeVault kan gebruikers nu tonen wat er is gewijzigd na een applicatie-update.',
    ],
    'available_update_checks' => [
        'title' => 'Controle op beschikbare updates',
        'description' => 'VolumeVault kan nu aangeven wanneer een nieuwere GitHub-release beschikbaar is.',
    ],
    'backup_job_detail_deletion' => [
        'title' => 'Verwijderen vanaf taakdetail',
        'description' => 'Back-uptaken kunnen nu direct vanaf hun detailpagina worden verwijderd.',
    ],
    'per_job_notification_channels' => [
        'title' => 'Meldingskanalen per taak',
        'description' => 'Back-uptaken kunnen nu kiezen welke actieve meldingskanalen hun resultaten ontvangen.',
    ],
    'notification_defaults_migration' => [
        'title' => 'Migratie van standaardmeldingen',
        'description' => 'Deze release voegt meldingsinstellingen toe aan back-uptaken en standaardkanaaltracking aan meldingskanalen.',
    ],
    'host_path_backup_sources' => [
        'title' => 'Hostpad-back-upbronnen',
        'description' => 'Admins kunnen geselecteerde mappen van de Docker-host back-uppen naast Docker-volumes.',
    ],
    'host_path_safety_controls' => [
        'title' => 'Veiligheidscontroles voor hostpaden',
        'description' => 'Hostpaden worden alleen-lezen gekoppeld en kunnen worden beperkt met VOLUMEVAULT_HOST_PATH_ALLOWLIST.',
    ],
    'stack_backup_coverage' => [
        'title' => 'Back-updekking per stack',
        'description' => 'Docker-volumes worden gegroepeerd per Compose- of Swarm-stack met back-updekkingsstatussen.',
    ],
    'backup_archive_metadata' => [
        'title' => 'Metadata van back-uparchief',
        'description' => 'Geslaagde runs kunnen nu archiefsleutels en groottes tonen wanneer bestemmingsmetadata beschikbaar is.',
    ],
    'trusted_proxy_support' => [
        'title' => 'Ondersteuning voor vertrouwde proxies',
        'description' => "VolumeVault kan geconfigureerde reverse proxies vertrouwen zodat gegenereerde URL's het publieke HTTPS-schema gebruiken.",
    ],
    'cleaner_docker_volume_sync' => [
        'title' => 'Schonere Docker-volume synchronisatie',
        'description' => 'Synchronisatie verwijdert nu verouderde ontbrekende volumerecords die niet langer door back-uptaken worden gebruikt.',
    ],
    'list_search_and_filters' => [
        'title' => 'Zoeken en filters in lijsten',
        'description' => 'Volumes en back-uptaken kregen zoeken, filters en een doorzoekbare volumeselector.',
    ],
    'php_85_container_runtime' => [
        'title' => 'PHP 8.5 container-runtime',
        'description' => 'De container is overgezet naar de ServerSideUp PHP 8.5 runtime met beheerde queue- en schedulerdiensten.',
    ],
    'first_stable_release' => [
        'title' => 'Eerste stabiele release',
        'description' => 'VolumeVault werd gelanceerd met geplande back-ups, veilige restores, versleutelde bestemmingen, meldingen, gebruikers, API-tokens en installatiesaves.',
    ],
    'pagination_with_user_preference' => [
        'title' => 'Gepagineerde lijsten met per-pagina-voorkeur',
        'description' => 'Alle lijstweergaven ondersteunen nu paginering met configureerbaar aantal items per pagina (10, 20, 50, 100, of Alle). U kunt uw standaard instellen in de profielinstellingen.',
    ],
    'dark_pagination_menu' => [
        'title' => 'Donker paginatiemenu',
        'description' => 'De keuzelijst voor items per pagina behoudt nu een donker thema wanneer die wordt geopend, met beter contrast in gepagineerde lijstweergaven.',
    ],
    'filter_toolbar_action_buttons' => [
        'title' => 'Vernieuwde primaire knoppen',
        'description' => 'Primaire actieknoppen delen nu in de hele applicatie dezelfde omlijnde blauwe stijl in zowel lichte als donkere modus.',
    ],
    'shareable_filter_urls' => [
        'title' => 'Deelbare filter-URLs',
        'description' => 'Lijstfilters voor Volumes, Stacks, Back-uptaken en Waarschuwingen worden nu weerspiegeld in de URL, zodat u gefilterde weergaven direct kunt kopieren en delen.',
    ],
    'safer_default_environment_settings' => [
        'title' => 'Veiligere standaard omgevingsinstellingen',
        'description' => '.env.example zet nieuwe deployments nu standaard op APP_ENV=production en APP_DEBUG=false. Er is ook uitleg toegevoegd voor SESSION_SECURE_COOKIE, zodat HTTPS-deployments veilige cookies kunnen inschakelen zonder per ongeluk alleen-HTTP-opstellingen te breken.',
    ],
];
