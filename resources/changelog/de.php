<?php

return [
    'restore_in_place_modes' => [
        'title' => 'Modi für direkte Wiederherstellung',
        'description' => 'Der Wiederherstellungsassistent kann ein Backup jetzt direkt in sein Docker-Quellvolume zurückspielen. „Direkt wiederherstellen" leert und ersetzt das Volume, nachdem du seinen Namen zur Bestätigung erneut eingegeben hast; „Sichere direkte Wiederherstellung" stoppt während der Wiederherstellung zusätzlich die Container, die das Volume verwenden, und startet sie danach neu. Die Backup-Auswahl zeigt jetzt standardmäßig die Archive des gewählten Auftrags, bietet Filter nach Name und Datum, hebt das neueste Archiv hervor, und eine Schaltfläche „Dieses Backup wiederherstellen" öffnet den Assistenten direkt aus einem Backup-Lauf.',
    ],
    'restore_notifications' => [
        'title' => 'Benachrichtigungen bei Wiederherstellung',
        'description' => 'VolumeVault benachrichtigt dich jetzt, wenn eine Wiederherstellung startet, erfolgreich ist oder fehlschlägt, und nutzt dafür die bereits für den Backup-Auftrag konfigurierten Benachrichtigungskanäle. Start- und Erfolgsmeldungen gehen an Kanäle, die jeden Lauf erhalten, während Fehler an alle Kanäle gehen. Ein Benachrichtigungsproblem unterbricht die Wiederherstellung selbst nie.',
    ],
    'stack_bulk_backup' => [
        'title' => 'Ganzen Stack auf einmal sichern',
        'description' => 'Die Stack-Seite kann jetzt einen ganzen Stack mit einem Klick sichern. Vollständig konfigurierte Stacks erhalten eine Schaltfläche "Alle Jobs ausführen", die für jeden Job eine Sicherung einreiht; Stacks mit nicht abgedeckten Volumes erhalten einen Dialog "Stack sichern", der für jedes Volume ohne Job einen täglichen (oder benutzerdefinierten) Backup-Job anlegt und anschließend eine Sicherung für den gesamten Stack einreiht. Dieselbe Operation ist auch über die API verfügbar (POST /stacks/backup).',
    ],
    'busybox_restore_tar_compat' => [
        'title' => 'Kompatible Restore-Extraktion',
        'description' => 'Wiederherstellungen in ein neues Docker-Volume uebergeben keine nur von GNU tar unterstuetzten Optionen mehr und streamen das Archiv jetzt in den Restore-Container, sodass die Extraktion mit BusyBox-tar und containerisierten Deployments funktioniert, deren Storage in einem Docker-Volume liegt.',
    ],
    'stable_stack_volume_search' => [
        'title' => 'Stabile Stack- und Volume-Suche',
        'description' => 'Eingaben in der Stack- oder Volume-Suche halten den Filter jetzt aktiv, statt nach der URL-Synchronisierung auf den Standardzustand zurueckzuspringen.',
    ],
    'backup_archive_name_templates' => [
        'title' => 'Anpassbare Backup-Archivnamen',
        'description' => 'Backup-Jobs koennen jetzt eine Vorlage fuer Archivnamen mit Tokens wie {name}, {source}, {id}, {year}, {month}, {day} und {time} definieren. Bestehende Jobs behalten die bisherige Benennung volumevault-source-run-id, bis eine Vorlage konfiguriert wird. Das Formular warnt, wenn eine Vorlage fruehere Archive ueberschreiben koennte.',
    ],
    'russian_translation_revisions' => [
        'title' => 'Verfeinerte russische Oberflaechentexte',
        'description' => 'Die russischen Uebersetzungen der Oberflaeche wurden mit weiteren Formulierungsanpassungen fuer mehr Einheitlichkeit und bessere Lesbarkeit verbessert. Danke an @artyomboyko fuer diesen Uebersetzungsbeitrag.',
    ],
    'complete_i18n_coverage' => [
        'title' => 'Vollständigere Übersetzungen der Oberfläche',
        'description' => 'Viele Oberflächentexte, die noch auf Englisch angezeigt wurden – darunter die Seiten für API-Tokens und Installationssicherungen –, sind jetzt vollständig übersetzt. Alle neun Sprachen wurden synchronisiert und fehlende Übersetzungen ergänzt, sodass nicht englischsprachige Nutzer keine unübersetzten Beschriftungen, Schaltflächen und Meldungen mehr sehen.',
    ],
    'reliable_run_logs' => [
        'title' => 'Zuverlässigere Ausführungsprotokolle',
        'description' => 'Sicherungs- und Wiederherstellungsprotokolle werden jetzt atomar angehängt, sodass gleichzeitige Schreibvorgänge (etwa der Fehler-Handler eines Jobs, der auslöst, während eine Ausführung endet) sich nicht mehr gegenseitig überschreiben. Das Kürzen der Protokolle ist außerdem UTF-8-fähig, sodass gekürzte Protokolle gültig bleiben und die Detailansicht nicht mehr beschädigen.',
    ],
    'stale_run_liveness_reconcile' => [
        'title' => 'Schnellere Wiederherstellung unterbrochener Sicherungen',
        'description' => 'Nach einem Worker-Absturz, Timeout oder Neustart hängengebliebene Ausführungen werden nun viel schneller wiederhergestellt. Der Abgleich prüft, ob der Sicherungs-Container noch aktiv ist, statt eine feste Wartezeit abzuwarten: tote Ausführungen schlagen innerhalb von Minuten fehl, während wirklich lange Sicherungen unangetastet bleiben. Die Wiederherstellung läuft außerdem automatisch beim Start des Containers und startet gestoppte Anwendungs-Container neu.',
    ],
    'local_destination_listing_cap' => [
        'title' => 'Begrenzte Auflistung lokaler Ziele',
        'description' => 'Die Auflistung der Sicherungen eines lokalen Dateisystemziels ist nun auf 1000 Einträge begrenzt, wie bei den anderen Speicheranbietern, sodass ein Ziel mit einem sehr großen Archivverzeichnis nicht mehr den gesamten Baum in einer einzigen Antwort lädt.',
    ],
    'per_job_schedule_timezone' => [
        'title' => 'Zeitzone pro Auftrag',
        'description' => 'Jeder Sicherungsauftrag kann nun eine eigene Zeitzone festlegen, sodass ein Zeitplan wie „täglich um 02:00“ um 02:00 Ortszeit statt in der globalen Anwendungszeitzone läuft. Belassen Sie es auf „Anwendungsstandard“, um das bisherige Verhalten beizubehalten.',
    ],
    'http_security_headers' => [
        'title' => 'HTTP-Sicherheitsheader',
        'description' => 'Antworten enthalten nun Sicherheitsheader zur Verteidigung in der Tiefe (X-Frame-Options, X-Content-Type-Options und Referrer-Policy) sowie HSTS bei Auslieferung über HTTPS. Reine HTTP- und LAN-Bereitstellungen sind nicht betroffen — keine Anfrage wird jemals von HTTP auf HTTPS gezwungen.',
    ],
    'local_destination_path_error_feedback' => [
        'title' => 'Klarere Pfadfehler für lokale Ziele',
        'description' => 'Beim Anlegen eines lokalen Dateisystem-Ziels werden Pfad-Validierungsfehler — etwa ein durch die Host-Pfad-Allowlist blockierter Pfad — jetzt direkt im Formular angezeigt, statt unbemerkt zur Erstellungsseite zurückzukehren.',
    ],
    'russian_translation_consistency' => [
        'title' => 'Überarbeitete russische Übersetzungen',
        'description' => 'Die russischen Oberflächentexte wurden für mehr Einheitlichkeit überarbeitet, und das Glossar für russische Übersetzer wurde aus den mitgelieferten Sprachdateien in eine eigene Projektdokumentation verschoben. So bleiben die gebündelten Sprachressourcen sauberer, während das Glossar für Mitwirkende erhalten bleibt. Danke an @artyomboyko für den Übersetzungsbeitrag.',
    ],
    'customizable_dashboard' => [
        'title' => 'Anpassbares Dashboard',
        'description' => 'Sie konnen jetzt auswahlen, welche Dashboard-Widgets angezeigt werden und in welcher Reihenfolge. Klicken Sie auf "Anpassen", um beliebige Statistikkarten oder Abschnitte aus- oder einzublenden, ziehen Sie sie zum Neuordnen und klicken Sie dann auf "Fertig" zum Speichern. Jeder Benutzer behalt sein eigenes Layout, und "Auf Standard zurucksetzen" stellt die ursprungliche Anordnung wieder her.',
    ],
    'self_container_backup_guard' => [
        'title' => 'VolumeVault stoppt waehrend eines Backups nicht mehr den eigenen Container',
        'description' => 'Wenn fuer einen Backup-Auftrag "Container vor dem Backup stoppen" aktiviert ist und er auf ein Volume zielt, das auch der VolumeVault-Container selbst einbindet, stoppt VolumeVault nicht mehr den eigenen Container - was das laufende Backup beendet haette. Der Container wird automatisch anhand seines Hostnamens und seiner cgroup erkannt; setze VOLUMEVAULT_CONTAINER_ID oder VOLUMEVAULT_CONTAINER_NAME, falls die automatische Erkennung unzuverlaessig ist (eigener Hostname oder Host-Netzwerk).',
    ],
    'host_path_stop_containers' => [
        'title' => 'Ausgewaehlte Container bei Host-Pfad-Backups stoppen',
        'description' => 'Host-Pfad-Backup-Auftraege koennen jetzt Container vor dem Backup stoppen und danach neu starten, wie es bei Docker-Volume-Auftraegen bereits moeglich war. Da ein Host-Pfad nicht automatisch Containern zugeordnet werden kann, waehlst du sie im Auftragsformular nach Namen aus. Die Auswahl wird nach Namen gespeichert und ueberdauert so das Neuerstellen von Containern; Container, die nicht mehr existieren oder bereits gestoppt sind, werden uebersprungen, und VolumeVault stoppt niemals den eigenen Container.',
    ],
    'ssrf_destination_guard' => [
        'title' => 'Backup-Ziele mit privater IP sind jetzt geschuetzt (SSRF)',
        'description' => 'VolumeVault weigert sich jetzt standardmaessig, eine Verbindung zu einem Backup-Ziel herzustellen, dessen Host zu einer privaten, Loopback- oder Link-Local-Adresse aufgeloest wird (einschliesslich des Cloud-Metadaten-Endpunkts 169.254.169.254). Dies betrifft nur Ziele mit privater IP, etwa ein NAS im LAN oder ein selbst gehostetes S3/MinIO - Cloud-Ziele ueber eine oeffentliche URL sind nicht betroffen. Geplante Backups laufen weiter, aber der Zieltest, die Wiederherstellung (Auflistung und Download) und die Speicherkontingent-Warnung sind blockiert, bis Sie den Bereich des Ziels in VOLUMEVAULT_SSRF_ALLOWED_IPS eintragen (kommagetrennte CIDRs, z. B. 192.168.1.0/24). Benachrichtigungskanaele werden nicht geschuetzt.',
    ],
    'host_path_allowlist_fail_closed' => [
        'title' => 'Die Hostpfad-Zulassungsliste ist jetzt fail-closed',
        'description' => 'VOLUMEVAULT_HOST_PATH_ALLOWLIST verweigert jetzt standardmaessig: wenn sie leer ist, werden Hostpfad-Sicherungsquellen und lokale Ziele abgelehnt, statt jeden Pfad zuzulassen. Dieselbe Liste schuetzt nun auch lokale Ziele, und Pfade werden zur Laufzeit erneut geprueft, um den Austausch symbolischer Links zu blockieren. Bestehende Installationen, die sich auf das bisherige offene Standardverhalten verlassen haben, muessen ihre Pfade auflisten - fuehren Sie "php artisan volumevault:host-path-allowlist:audit" aus, um den genau einzutragenden Wert zu erhalten.',
    ],
    'auth_rate_limiting' => [
        'title' => 'Ratenbegrenzte Anmeldung und Passwortruecksetzung',
        'description' => 'Anmelde- und Passwortruecksetzungsanfragen sind jetzt auf 5 Versuche pro Minute begrenzt, was Brute-Force-Angriffe auf das Administratorpasswort verlangsamt. Beim Ueberschreiten des Limits wird eine voruebergehende "zu viele Anfragen"-Antwort zurueckgegeben, die sich nach einer Minute zuruecksetzt.',
    ],
    'restore_input_hardening' => [
        'title' => 'Strengere Pruefung von Wiederherstellungs- und Sicherungseingaben',
        'description' => 'Die fuer eine Wiederherstellung ausgewaehlte Sicherung muss jetzt mit der Auflistung des Ziels uebereinstimmen, wodurch Pfaddurchquerungs-Schluessel wie "../../etc/passwd" blockiert werden. Docker-Volumenamen sind auf sichere Zeichen beschraenkt, und die Wiederherstellungsentpackung wird eingegrenzt, sodass ein gefaelschtes Archiv nicht ausserhalb des Zielvolumes schreiben kann.',
    ],
    'sftp_host_key_pinning' => [
        'title' => 'Anpinnen des SSH-Hostschluessels fuer SFTP-Ziele',
        'description' => 'SSH/SFTP-Ziele koennen jetzt den Hostschluessel des Servers anpinnen, um Man-in-the-Middle-Angriffe zu blockieren. Verwenden Sie die Schaltflaeche "Schluessel vom Server abrufen" - oder den neuen Endpunkt POST /api/v1/destinations/host-key -, um dem praesentierten Schluessel zu vertrauen, oder fuegen Sie einen Hostschluessel oder SHA256-Fingerabdruck ein. Der Schluessel wird vor dem Senden von Anmeldedaten geprueft, fuer die von VolumeVault durchgefuehrten SFTP-Vorgaenge (Test, Auflistung, Wiederherstellung). Leer lassen behaelt das bisherige Verhalten bei.',
    ],
    'api_token_expiration' => [
        'title' => 'API-Tokens laufen jetzt standardmaessig ab',
        'description' => 'API-Tokens laufen jetzt standardmaessig 60 Tage nach der Erstellung ab, was die Auswirkungen eines geleakten Tokens begrenzt. Bestehende, aeltere Tokens funktionieren nach dem Upgrade nicht mehr und muessen neu erstellt werden. Setzen Sie SANCTUM_TOKEN_EXPIRATION (in Minuten), um den Zeitraum zu aendern, oder null, um nicht ablaufende Tokens zu behalten. Ein Ablauf pro Token kann diesen Zeitraum nur verkuerzen, niemals verlaengern.',
    ],
    'alert_check_isolation' => [
        'title' => 'Robustere Alarmpruefungen',
        'description' => 'Eine Alarmregel, die einen Fehler ausloest, verhindert nicht mehr die Pruefung der uebrigen Regeln. Jede Regel wird jetzt unabhaengig ausgewertet und Fehler werden protokolliert, sodass eine fehlerhafte Pruefung die anderen Alarme nicht mehr stillschweigend deaktivieren kann.',
    ],
    'restore_volume_cleanup' => [
        'title' => 'Sauberere Wiederholungen nach fehlgeschlagener Wiederherstellung',
        'description' => 'Wenn eine Wiederherstellung nach dem Anlegen des Zielvolumes fehlschlaegt, entfernt VolumeVault jetzt das teilweise erstellte Volume, damit der naechste Versuch sauber startet und nicht durch einen "existiert bereits"-Fehler blockiert wird.',
    ],
    'schedule_drift_prevention' => [
        'title' => 'Zuverlaessigere Backup-Planung',
        'description' => 'Geplante Backups ueberspringen keinen Durchlauf mehr, wenn ein Worker in Verzug geraet. Der naechste Lauf wird jetzt am geplanten Zeitfenster verankert statt an der Endzeit des vorherigen Laufs, sodass ein langsamer oder verspaeteter Lauf den Zeitplan nicht mehr verschieben kann.',
    ],
    'destination_usage_efficiency' => [
        'title' => 'Effizientere Ermittlung der Zielspeichernutzung',
        'description' => 'Die Speichernutzung von Backup-Zielen wird jetzt per Streaming durch die Objekte ermittelt, statt die gesamte Liste in den Speicher zu laden, und SFTP-Verbindungen werden anschliessend immer geschlossen. Ziele mit vielen Backups werden zuverlaessiger gemessen, ohne den Speicher zu erschoepfen oder Verbindungen offen zu lassen.',
    ],
    'run_log_integrity' => [
        'title' => 'Zuverlaessigere Laufprotokolle',
        'description' => 'Protokolle von Backup- und Wiederherstellungslaeufen werden jetzt atomar angehaengt, sodass gleichzeitige Aktualisierungen - etwa eine Fehlermeldung und ein Hinweis auf den Container-Neustart - sich nicht mehr gegenseitig ueberschreiben. Die Protokollgroesse ist zudem begrenzt und behaelt die neueste Ausgabe, statt unbegrenzt zu wachsen.',
    ],
    'stale_run_reconciliation' => [
        'title' => 'Automatische Wiederherstellung unterbrochener Laeufe',
        'description' => 'Backup- und Wiederherstellungslaeufe, die durch einen Worker-Absturz, ein Timeout oder einen Neustart unterbrochen wurden, werden jetzt automatisch als fehlgeschlagen markiert, statt haengen zu bleiben, sodass geplante Backups weiterlaufen. Anwendungscontainer, die fuer ein Backup gestoppt wurden, werden ebenfalls automatisch neu gestartet, falls ein Absturz sie ausgeschaltet zurueckliess.',
    ],
    'advanced_alerting' => [
        'title' => 'Erweiterte Benachrichtigungen',
        'description' => 'VolumeVault kann Backup-Jobs auf veraltete Backups, wiederholte Fehler, lang anhaltende Fehlerzustaende und ungewoehnliche Archivgroessen ueberwachen.',
    ],
    'destination_storage_limit_alerts' => [
        'title' => 'Speicherlimit-Warnungen fuer Ziele',
        'description' => 'Backup-Ziele koennen jetzt absolute Warn- und kritische Speicherschwellen mit eigenen Benachrichtigungskanaelen festlegen.',
    ],
    'mobile_navigation_redesign' => [
        'title' => 'Verbesserte mobile Navigation',
        'description' => 'Die mobile Kopfzeile nutzt jetzt eine kompakte Menu-Schaltflaeche und ein strukturiertes Navigationspanel, statt alle Links in der Kopfzeile zu stapeln.',
    ],
    'keyboard_shortcuts' => [
        'title' => 'Tastaturkuerzel',
        'description' => 'Auf dem Desktop nutzen Sie Ctrl+K fuer die Schnellnavigation, g-Kuerzel fuer Ansichten und / zum Fokussieren der Listensuche.',
    ],
    'in_app_update_summaries' => [
        'title' => 'Update-Zusammenfassungen in der App',
        'description' => 'VolumeVault kann Benutzern jetzt anzeigen, was sich nach einem Anwendungsupdate geaendert hat.',
    ],
    'available_update_checks' => [
        'title' => 'Pruefung auf verfuegbare Updates',
        'description' => 'VolumeVault kann jetzt anzeigen, wenn ein neueres GitHub-Release verfuegbar ist.',
    ],
    'backup_job_detail_deletion' => [
        'title' => 'Loeschen aus der Job-Detailseite',
        'description' => 'Backup-Jobs koennen jetzt direkt von ihrer Detailseite geloescht werden.',
    ],
    'per_job_notification_channels' => [
        'title' => 'Benachrichtigungskanaele pro Job',
        'description' => 'Backup-Jobs koennen jetzt auswaehlen, welche aktiven Benachrichtigungskanaele ihre Ergebnisse erhalten.',
    ],
    'notification_defaults_migration' => [
        'title' => 'Migration der Standardbenachrichtigungen',
        'description' => 'Dieses Release fuegt Backup-Jobs Benachrichtigungseinstellungen und Benachrichtigungskanaelen die Nachverfolgung des Standardkanals hinzu.',
    ],
    'host_path_backup_sources' => [
        'title' => 'Host-Pfad-Backup-Quellen',
        'description' => 'Admins koennen ausgewaehlte Verzeichnisse vom Docker-Host zusaetzlich zu Docker-Volumes sichern.',
    ],
    'host_path_safety_controls' => [
        'title' => 'Sicherheitskontrollen fuer Host-Pfade',
        'description' => 'Host-Pfade werden schreibgeschuetzt eingebunden und koennen mit VOLUMEVAULT_HOST_PATH_ALLOWLIST eingeschraenkt werden.',
    ],
    'stack_backup_coverage' => [
        'title' => 'Stack-Backup-Abdeckung',
        'description' => 'Docker-Volumes werden nach Compose- oder Swarm-Stack mit Backup-Abdeckungsstatus gruppiert.',
    ],
    'backup_archive_metadata' => [
        'title' => 'Backup-Archiv-Metadaten',
        'description' => 'Erfolgreiche Laeufe koennen jetzt Archivschluessel und Groessen anzeigen, wenn Zielmetadaten verfuegbar sind.',
    ],
    'trusted_proxy_support' => [
        'title' => 'Unterstuetzung vertrauenswuerdiger Proxys',
        'description' => 'VolumeVault kann konfigurierten Reverse Proxys vertrauen, damit generierte URLs das oeffentliche HTTPS-Schema verwenden.',
    ],
    'cleaner_docker_volume_sync' => [
        'title' => 'Sauberere Docker-Volume-Synchronisierung',
        'description' => 'Die Synchronisierung entfernt jetzt veraltete fehlende Volume-Eintraege, die von keinen Backup-Jobs mehr referenziert werden.',
    ],
    'list_search_and_filters' => [
        'title' => 'Listensuche und Filter',
        'description' => 'Volumes und Backup-Jobs haben Suche, Filter und einen durchsuchbaren Volume-Selektor erhalten.',
    ],
    'php_85_container_runtime' => [
        'title' => 'PHP 8.5 Container-Runtime',
        'description' => 'Der Container wurde auf die ServerSideUp PHP 8.5 Runtime mit ueberwachter Queue und Scheduler-Diensten umgestellt.',
    ],
    'first_stable_release' => [
        'title' => 'Erstes stabiles Release',
        'description' => 'VolumeVault startete mit geplanten Backups, sicheren Wiederherstellungen, verschluesselten Zielen, Benachrichtigungen, Benutzern, API-Tokens und Installationssicherungen.',
    ],
    'pagination_with_user_preference' => [
        'title' => 'Paginierte Listen mit Einstellung pro Seite',
        'description' => 'Alle Listenansichten unterstuetzen jetzt Paginierung mit konfigurierbaren Eintraegen pro Seite (10, 20, 50, 100 oder Alle). Sie koennen Ihren Standardwert in den Profileinstellungen festlegen.',
    ],
    'dark_pagination_menu' => [
        'title' => 'Dunkles Paginierungsmenue',
        'description' => 'Das Auswahlfeld fuer Eintraege pro Seite behaelt jetzt beim Oeffnen eine dunkle Darstellung bei und verbessert so den Kontrast in paginierten Listenansichten.',
    ],
    'filter_toolbar_action_buttons' => [
        'title' => 'Aktualisierte Primaer-Schaltflaechen',
        'description' => 'Primaere Aktionsschaltflaechen verwenden jetzt in der gesamten Anwendung denselben blau umrandeten Stil in heller und dunkler Darstellung.',
    ],
    'shareable_filter_urls' => [
        'title' => 'Teilbare Filter-URLs',
        'description' => 'Filter in den Listen Volumes, Stacks, Backup-Jobs und Warnungen werden jetzt in der URL abgebildet, sodass Sie gefilterte Ansichten direkt kopieren und teilen koennen.',
    ],
    'safer_default_environment_settings' => [
        'title' => 'Sicherere Standard-Umgebungseinstellungen',
        'description' => 'Neue Deployments verwenden in der .env.example jetzt standardmaessig APP_ENV=production und APP_DEBUG=false. Ausserdem gibt es einen Hinweis zu SESSION_SECURE_COOKIE, damit HTTPS-Deployments sichere Cookies aktivieren koennen, ohne versehentlich reine HTTP-Setups auszusperren.',
    ],
];
