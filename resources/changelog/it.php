<?php

return [
    'group_backup_size_reporting' => [
        'title' => 'Report della dimensione dei backup di gruppo',
        'description' => 'Le esecuzioni di backup di gruppo ora riportano la dimensione totale degli archivi dei loro membri. Un nuovo widget opzionale della dashboard, «Dimensione dell\'ultimo backup di gruppo riuscito», può essere attivato dal pannello Personalizza della dashboard, e la dimensione aggregata compare anche nelle esecuzioni di gruppo recenti e nella cronologia delle esecuzioni di ogni gruppo. Tramite l\'API, le esecuzioni di gruppo espongono total_backup_size_bytes e la dashboard aggiunge una statistica last_successful_group_backup_size. Le dimensioni possono comparire con un breve ritardo dopo la fine di un\'esecuzione, perché la dimensione di ogni archivio membro viene registrata in modo asincrono.',
    ],
    'inclusive_backup_filter' => [
        'title' => 'Filtraggio dei backup per inclusione',
        'description' => 'Le attività di backup ora possono conservare solo le cartelle o i file che elenchi, invece di escluderne soltanto alcuni. Scegli «Includi solo» nel modulo dell\'attività e inserisci un elenco di percorsi separati da virgole, relativi alla radice della sorgente di backup (ad esempio «Backups, config/app.conf»); tutto il resto viene ignorato, mantenendo gli archivi piccoli. La modalità avanzata di esclusione tramite regex resta disponibile e le attività di sola inclusione possono essere create anche tramite l\'API.',
    ],
    'grouped_backup_jobs' => [
        'title' => 'Job di backup raggruppati',
        'description' => 'Esegui il backup di più volumi come un\'unica operazione pianificata: un gruppo di backup possiede la pianificazione e le notifiche e i job vengono collegati ad esso dal modulo del job di backup. Il gruppo invia un\'unica notifica di avvio e una di successo/errore per tutti i suoi volumi — ideale per un unico monitor di tipo dead man\'s switch — e puoi scegliere se un volume fallito interrompe l\'esecuzione o se il gruppo continua segnalando comunque l\'errore. I gruppi di backup sono disponibili anche tramite l\'API.',
    ],
    'user_date_format_preference' => [
        'title' => 'Preferenza formato data per utente',
        'description' => 'Ogni utente puo ora scegliere dal proprio profilo il formato regionale usato per visualizzare le date. Per esempio, l\'interfaccia puo restare in inglese mentre le date passano dall\'ordine statunitense mese/giorno all\'ordine australiano o britannico giorno/mese. Il fuso orario dell\'applicazione continua a controllare l\'ora locale mostrata.',
    ],
    'trusted_2fa_device_password_revocation' => [
        'title' => 'Dispositivi 2FA attendibili revocati alle modifiche della password',
        'description' => 'La modifica o il ripristino della password di un utente ora revoca i suoi dispositivi 2FA attendibili. I record dei dispositivi attendibili esistenti vengono eliminati durante l\'aggiornamento, cosi i browser devono superare di nuovo la verifica 2FA prima di poter essere considerati attendibili.',
    ],
    'installation_save_two_factor_reencryption' => [
        'title' => 'Segreti 2FA ricifrati durante l\'importazione dell\'installazione',
        'description' => 'Le importazioni dei salvataggi di installazione ora ricifrano i segreti TOTP e i codici di recupero degli utenti con l\'APP_KEY della nuova istanza, come destinazioni e notifiche, prevenendo blocchi 2FA dopo la migrazione.',
    ],
    'docker_volume_destinations' => [
        'title' => 'Destinazioni volume Docker',
        'description' => 'Una nuova destinazione «Volume Docker» esegue il backup su un volume Docker con nome — qualsiasi driver, incluso NFS o altre condivisioni di rete. Dichiara il volume nel tuo file Compose e VolumeVault lo monta per nome nel container di backup temporaneo, così backup, ripristini, elenco e utilizzo dello spazio funzionano senza condividere alcun percorso host con VolumeVault. Se il volume non esiste più, la destinazione fallisce con un errore chiaro invece di scrivere silenziosamente in un volume vuoto appena creato.',
    ],
    'webhook_notifications' => [
        'title' => 'Notifiche tramite webhook',
        'description' => 'Un nuovo canale di notifica «Webhook» chiama i tuoi URL agli eventi di backup e ripristino. Imposta un URL diverso per ogni azione — avvio, successo ed errore — e VolumeVault chiama quello corrispondente quando un backup o un ripristino inizia, riesce o fallisce. Compila quelli che vuoi; imposta il livello del canale su «Ogni backup e ripristino» per inviare anche gli URL di avvio e di successo. Semplifica l\'integrazione con i servizi di monitoraggio e di tipo «interruttore dell\'uomo morto». I canali di notifica ora possono anche essere aggiornati tramite l\'API.',
    ],
    'backup_start_notifications' => [
        'title' => 'Notifiche di avvio del backup',
        'description' => 'I backup ora inviano una notifica all\'avvio di un\'esecuzione, non solo al termine, come già fanno i ripristini. I messaggi di avvio vengono inviati ai canali impostati per ricevere ogni esecuzione; i canali solo errori non sono interessati. I servizi di monitoraggio possono così misurare la durata di un backup.',
    ],
    'unencrypted_smtp_notifications' => [
        'title' => 'Supporto per i server SMTP non crittografati',
        'description' => 'I canali di notifica SMTP possono ora inviare verso server che non usano la crittografia. Una nuova opzione "Server SMTP non crittografato" nel modulo di notifica disattiva TLS e STARTTLS, così VolumeVault può raggiungere un relay SMTP locale attendibile che altrimenti rifiuterebbe la connessione con un errore "unencrypted connection". L\'invio crittografato rimane l\'impostazione predefinita ed è invariato.',
    ],
    'optional_two_factor_auth' => [
        'title' => 'Autenticazione a due fattori facoltativa',
        'description' => 'Ora puoi proteggere il tuo account con un\'autenticazione a due fattori facoltativa basata su una password monouso temporanea (TOTP). Attivala dal tuo profilo scansionando un codice QR con un\'app di autenticazione come Google Authenticator o Authy e confermando con un codice generato. Una volta attivata, l\'accesso richiede un codice di sei cifre subito dopo la password. Viene fornita una serie di codici di recupero monouso nel caso in cui tu perda l\'accesso all\'app di autenticazione e gli amministratori possono reimpostare l\'autenticazione a due fattori di qualsiasi utente dalla pagina Utenti. Nella schermata del codice puoi anche contrassegnare un browser come attendibile per saltare il codice — mai la password — per 30 giorni.',
    ],
    'backup_initiator_tracking' => [
        'title' => 'Traccia chi ha avviato ogni backup',
        'description' => 'I backup ora registrano quale utente li ha avviati. Le esecuzioni manuali (dall\'interfaccia o dall\'API) e i backup dell\'intero stack sono attribuiti all\'utente connesso, il backup di sicurezza eseguito prima di un ripristino in loco eredita l\'utente che ha avviato il ripristino e le esecuzioni pianificate restano senza attribuzione. L\'iniziatore compare nella cronologia delle esecuzioni del processo e nei dettagli del backup, è incluso nelle notifiche di backup ed è disponibile come nuovo token {{ user }} per i modelli di notifica personalizzati.',
    ],
    'restore_history_on_job' => [
        'title' => 'Cronologia dei ripristini nei processi di backup',
        'description' => 'La pagina di ogni processo di backup suddivide ora la cronologia in due schede: «Cronologia» elenca i backup del processo e una nuova scheda «Cronologia dei ripristini» elenca ogni ripristino eseguito per quel processo, con stato, modalità, volumi di origine e destinazione, ora di inizio, durata e un collegamento ai dettagli completi del ripristino. Entrambe le schede sono ora paginate, quindi le cronologie lunghe non sono più limitate a 50 righe.',
    ],
    'restore_in_place_modes' => [
        'title' => 'Modalità di ripristino sul posto',
        'description' => 'La procedura di ripristino ora può ripristinare un backup direttamente nel suo volume Docker di origine. «Ripristina sul posto» svuota e sostituisce il volume dopo aver ridigitato il suo nome per confermare; «Ripristino sul posto sicuro» arresta inoltre i container che usano il volume durante il ripristino e li riavvia al termine. Il selettore dei backup ora mostra in modo predefinito gli archivi del job selezionato, aggiunge filtri per nome e data, evidenzia l\'archivio più recente e un pulsante «Ripristina questo backup» apre la procedura direttamente da un\'esecuzione di backup. Entrambe le modalità sul posto possono, facoltativamente, eseguire un backup del contenuto attuale del volume prima di sovrascriverlo; il ripristino viene annullato se questo backup di sicurezza fallisce.',
    ],
    'restore_notifications' => [
        'title' => 'Notifiche di ripristino',
        'description' => 'VolumeVault ora ti avvisa quando un ripristino inizia, riesce o fallisce, riutilizzando i canali di notifica già configurati sul job di backup. I messaggi di avvio e di successo vengono inviati ai canali impostati per ricevere ogni esecuzione, mentre gli errori raggiungono tutti i canali. Un problema di notifica non interrompe mai il ripristino stesso.',
    ],
    'stack_bulk_backup' => [
        'title' => 'Backup di un intero stack in una volta',
        'description' => 'La pagina Stack ora consente di eseguire il backup di un intero stack con un clic. Gli stack completamente configurati mostrano un pulsante "Esegui tutti i processi" che mette in coda un\'esecuzione per ogni processo; gli stack con volumi non coperti mostrano una finestra "Backup dello stack" che crea un processo di backup (giornaliero o personalizzato) per ogni volume che non ne ha uno, poi mette in coda un backup dell\'intero stack. La stessa operazione è disponibile tramite l\'API (POST /stacks/backup).',
    ],
    'busybox_restore_tar_compat' => [
        'title' => 'Estrazione di ripristino compatibile',
        'description' => 'I ripristini verso un nuovo volume Docker non passano più opzioni tar disponibili solo in GNU tar e ora inviano l\'archivio in streaming al container di ripristino, quindi l\'estrazione funziona con BusyBox tar e con deployment containerizzati il cui storage vive in un volume Docker.',
    ],
    'stable_stack_volume_search' => [
        'title' => 'Ricerca stabile di stack e volumi',
        'description' => 'Digitando nella ricerca di stack o volumi, il filtro resta ora attivo invece di reimpostarsi dopo la sincronizzazione dell\'URL.',
    ],
    'backup_archive_name_templates' => [
        'title' => 'Nomi personalizzati per gli archivi backup',
        'description' => 'I processi backup possono ora definire un modello di nome archivio con token come {name}, {source}, {id}, {year}, {month}, {day} e {time}. I processi esistenti mantengono la precedente denominazione volumevault-source-run-id finche non viene configurato un modello, e il modulo avvisa quando un modello puo sovrascrivere archivi precedenti.',
    ],
    'russian_translation_revisions' => [
        'title' => 'Testi dell’interfaccia in russo migliorati',
        'description' => 'Le traduzioni dell’interfaccia in russo hanno ricevuto ulteriori correzioni di formulazione per migliorare coerenza e leggibilita. Grazie a @artyomboyko per questo contributo alla traduzione.',
    ],
    'complete_i18n_coverage' => [
        'title' => 'Traduzioni dell\'interfaccia più complete',
        'description' => 'Molti testi dell\'interfaccia ancora visualizzati in inglese — comprese le pagine dei token API e dei salvataggi dell\'installazione — sono ora completamente tradotti. Le nove lingue sono state sincronizzate e le traduzioni mancanti completate, così gli utenti non anglofoni non vedono più etichette, pulsanti e messaggi non tradotti.',
    ],
    'reliable_run_logs' => [
        'title' => 'Log di esecuzione più affidabili',
        'description' => 'I log dei backup e dei ripristini ora vengono aggiunti in modo atomico, quindi le scritture simultanee (ad esempio il gestore di fallimento di un job che si attiva mentre un\'esecuzione termina) non possono più sovrascriversi a vicenda. Il troncamento dei log rispetta anche UTF-8, mantenendo validi i log accorciati ed evitando che compromettano la vista dei dettagli dell\'esecuzione.',
    ],
    'stale_run_liveness_reconcile' => [
        'title' => 'Recupero più rapido dei backup interrotti',
        'description' => 'Le esecuzioni bloccate dopo un crash, un timeout o un riavvio del worker ora vengono recuperate molto più rapidamente. Il riconciliatore verifica se il container di backup è ancora attivo invece di attendere un ritardo fisso: le esecuzioni morte falliscono in pochi minuti, mentre i backup realmente lunghi restano intatti. Il recupero viene eseguito anche automaticamente all\'avvio del container e riavvia i container applicativi rimasti fermi.',
    ],
    'local_destination_listing_cap' => [
        'title' => 'Elenchi limitati delle destinazioni locali',
        'description' => 'L\'elenco dei backup di una destinazione su filesystem locale è ora limitato a 1000 voci, come gli altri provider di archiviazione, così una destinazione con una directory di archivi molto grande non carica più l\'intero albero in un\'unica risposta.',
    ],
    'per_job_schedule_timezone' => [
        'title' => 'Fuso orario per processo',
        'description' => 'Ogni processo di backup può ora definire il proprio fuso orario, così una pianificazione come «ogni giorno alle 02:00» viene eseguita alle 02:00 ora locale invece che nel fuso orario globale dell\'applicazione. Lascialo su «Predefinito dell\'applicazione» per mantenere il comportamento precedente.',
    ],
    'http_security_headers' => [
        'title' => 'Intestazioni HTTP di sicurezza',
        'description' => 'Le risposte ora includono intestazioni di sicurezza di difesa in profondità (X-Frame-Options, X-Content-Type-Options e Referrer-Policy), oltre a HSTS quando servite tramite HTTPS. Le installazioni in HTTP semplice e su rete locale non sono interessate: nessuna richiesta viene mai forzata da HTTP a HTTPS.',
    ],
    'local_destination_path_error_feedback' => [
        'title' => 'Errori di percorso più chiari per le destinazioni locali',
        'description' => 'Durante la creazione di una destinazione su filesystem locale, gli errori di convalida del percorso — ad esempio un percorso bloccato dalla allowlist dei percorsi host — vengono ora mostrati direttamente nel modulo, invece di tornare silenziosamente alla pagina di creazione.',
    ],
    'russian_translation_consistency' => [
        'title' => 'Traduzioni russe rifinite',
        'description' => 'I testi dell\'interfaccia in russo sono stati aggiornati per maggiore coerenza e il glossario per i traduttori russi è stato spostato fuori dai file di lingua distribuiti, in una documentazione dedicata del progetto. In questo modo le risorse linguistiche incluse restano più pulite, mantenendo comunque il glossario per chi contribuisce. Grazie a @artyomboyko per questo contributo alle traduzioni.',
    ],
    'customizable_dashboard' => [
        'title' => 'Dashboard personalizzabile',
        'description' => 'Ora puoi scegliere quali widget mostrare nella dashboard e in quale ordine. Fai clic su "Personalizza" per nascondere o mostrare qualsiasi scheda statistica o sezione, trascinale per riordinarle, quindi fai clic su "Fine" per salvare. Ogni utente mantiene la propria disposizione e "Ripristina predefiniti" ripristina la disposizione originale.',
    ],
    'self_container_backup_guard' => [
        'title' => 'VolumeVault non arresta piu il proprio container durante un backup',
        'description' => 'Quando un\'attivita di backup ha attivo "arresta i container prima del backup" e ha come destinazione un volume montato anche dal container VolumeVault stesso, VolumeVault non arresta piu il proprio container - cosa che avrebbe interrotto il backup in corso. Il container viene rilevato automaticamente dal suo hostname e dal cgroup; imposta VOLUMEVAULT_CONTAINER_ID o VOLUMEVAULT_CONTAINER_NAME se il rilevamento automatico non e affidabile (hostname personalizzato o rete host).',
    ],
    'host_path_stop_containers' => [
        'title' => 'Ferma i container selezionati per i backup di percorso host',
        'description' => 'Le attivita di backup di tipo percorso host possono ora fermare i container prima del backup e riavviarli al termine, come gia facevano le attivita su volume Docker. Poiche un percorso host non puo essere associato automaticamente ai container, li scegli per nome nel modulo dell\'attivita. La selezione viene salvata per nome, quindi sopravvive alla ricreazione dei container; i container che non esistono piu o gia fermi vengono ignorati, e VolumeVault non ferma mai il proprio container.',
    ],
    'ssrf_destination_guard' => [
        'title' => 'Le destinazioni di backup con IP privato ora sono protette (SSRF)',
        'description' => 'VolumeVault ora rifiuta per impostazione predefinita di connettersi a una destinazione di backup il cui host si risolve in un indirizzo privato, di loopback o link-local (incluso l\'endpoint dei metadati cloud 169.254.169.254). Questo riguarda solo le destinazioni con IP privato, come un NAS in LAN o un S3/MinIO self-hosted; le destinazioni cloud raggiungibili tramite un URL pubblico non sono interessate. I backup pianificati continuano a essere eseguiti, ma il test della destinazione, il ripristino (elenco e download) e l\'avviso sulla quota di archiviazione sono bloccati finche non si elenca l\'intervallo della destinazione in VOLUMEVAULT_SSRF_ALLOWED_IPS (CIDR separati da virgole, ad es. 192.168.1.0/24). I canali di notifica non sono protetti.',
    ],
    'host_path_allowlist_fail_closed' => [
        'title' => 'L\'elenco di autorizzazione dei percorsi host ora e fail-closed',
        'description' => 'VOLUMEVAULT_HOST_PATH_ALLOWLIST ora nega in modo predefinito: quando e vuoto, le sorgenti di backup per percorso host e le destinazioni locali vengono rifiutate invece di consentire qualsiasi percorso. Lo stesso elenco ora protegge anche le destinazioni locali e i percorsi vengono ricontrollati in fase di esecuzione per bloccare la sostituzione dei collegamenti simbolici. Le installazioni esistenti che si basavano sul precedente comportamento aperto devono elencare i propri percorsi: esegui "php artisan volumevault:host-path-allowlist:audit" per ottenere il valore esatto da impostare.',
    ],
    'auth_rate_limiting' => [
        'title' => 'Accesso e reimpostazione password con limite di frequenza',
        'description' => 'Le richieste di accesso e di reimpostazione della password sono ora limitate a 5 tentativi al minuto, rallentando gli attacchi a forza bruta contro la password dell\'amministratore. Superando il limite viene restituita una risposta temporanea "troppe richieste" che si reimposta dopo un minuto.',
    ],
    'restore_input_hardening' => [
        'title' => 'Convalida piu rigorosa degli input di ripristino e backup',
        'description' => 'Il backup selezionato per un ripristino ora deve corrispondere all\'elenco della destinazione, bloccando le chiavi di attraversamento dei percorsi come "../../etc/passwd". I nomi dei volumi Docker sono limitati a caratteri sicuri e l\'estrazione di ripristino e confinata in modo che un archivio contraffatto non possa scrivere al di fuori del volume di destinazione.',
    ],
    'sftp_host_key_pinning' => [
        'title' => 'Blocco della chiave host SSH per le destinazioni SFTP',
        'description' => 'Le destinazioni SSH/SFTP ora possono bloccare la chiave host del server per impedire gli attacchi man-in-the-middle. Usa il pulsante "Recupera la chiave dal server" - o il nuovo endpoint POST /api/v1/destinations/host-key - per considerare attendibile la chiave presentata, oppure incolla una chiave host o un\'impronta SHA256. La chiave viene verificata prima di inviare qualsiasi credenziale, per le operazioni SFTP eseguite da VolumeVault (test, elenco, ripristino). Lasciarla vuota mantiene il comportamento precedente.',
    ],
    'api_token_expiration' => [
        'title' => 'I token API ora scadono per impostazione predefinita',
        'description' => 'I token API ora scadono 60 giorni dopo la creazione per impostazione predefinita, limitando l\'impatto di un token trafugato. I token esistenti piu vecchi smettono di funzionare dopo l\'aggiornamento e devono essere ricreati. Imposta SANCTUM_TOKEN_EXPIRATION (in minuti) per modificare la durata, oppure null per mantenere token senza scadenza. Una scadenza per token puo solo ridurre questa durata, mai estenderla.',
    ],
    'alert_check_isolation' => [
        'title' => 'Controlli degli avvisi piu robusti',
        'description' => 'Una regola di avviso che genera un errore non impedisce piu il controllo delle altre regole. Ogni regola viene ora valutata in modo indipendente e gli errori vengono registrati, cosi un singolo controllo difettoso non puo piu disattivare silenziosamente gli altri avvisi.',
    ],
    'restore_volume_cleanup' => [
        'title' => 'Nuovi tentativi piu puliti dopo un ripristino fallito',
        'description' => 'Quando un ripristino fallisce dopo aver creato il volume di destinazione, VolumeVault ora rimuove il volume creato parzialmente cosi che il tentativo successivo riparta pulito invece di essere bloccato da un errore "esiste gia".',
    ],
    'schedule_drift_prevention' => [
        'title' => 'Pianificazione dei backup piu affidabile',
        'description' => 'I backup pianificati non saltano piu un\'esecuzione quando un worker e in ritardo. La prossima esecuzione viene ora ancorata alla fascia prevista invece che all\'orario di fine dell\'esecuzione precedente, cosi un\'esecuzione lenta o in ritardo non puo piu far slittare la pianificazione.',
    ],
    'destination_usage_efficiency' => [
        'title' => 'Calcolo piu efficiente dell\'utilizzo dello spazio della destinazione',
        'description' => 'L\'utilizzo dello spazio delle destinazioni di backup viene ora calcolato scorrendo gli oggetti in streaming invece di caricare l\'intero elenco in memoria, e le connessioni SFTP vengono sempre chiuse al termine. Le destinazioni che contengono molti backup vengono misurate in modo piu affidabile, senza esaurire la memoria ne lasciare connessioni aperte.',
    ],
    'run_log_integrity' => [
        'title' => 'Log delle esecuzioni piu affidabili',
        'description' => 'I log delle esecuzioni di backup e ripristino vengono ora aggiunti in modo atomico, cosi gli aggiornamenti concorrenti - come un messaggio di errore e un avviso di riavvio del container - non si sovrascrivono piu a vicenda. La loro dimensione e inoltre limitata, mantenendo l\'output piu recente invece di crescere senza limiti.',
    ],
    'stale_run_reconciliation' => [
        'title' => 'Recupero automatico delle esecuzioni interrotte',
        'description' => 'Le esecuzioni di backup e ripristino interrotte da un crash del worker, un timeout o un riavvio ora vengono contrassegnate automaticamente come fallite invece di restare bloccate, cosi i backup pianificati continuano a funzionare. Anche i container applicativi fermati per un backup vengono riavviati automaticamente se un crash li ha lasciati spenti.',
    ],
    'advanced_alerting' => [
        'title' => 'Avvisi avanzati',
        'description' => 'VolumeVault puo monitorare i processi di backup per rilevare backup obsoleti, errori ripetuti, stati di errore prolungati e dimensioni di archivio insolite.',
    ],
    'destination_storage_limit_alerts' => [
        'title' => 'Avvisi limite storage destinazione',
        'description' => 'Le destinazioni possono ora definire soglie assolute warning e critiche con canali di notifica dedicati.',
    ],
    'mobile_navigation_redesign' => [
        'title' => 'Navigazione mobile migliorata',
        'description' => "L'intestazione mobile ora usa un pulsante menu compatto e un pannello di navigazione strutturato invece di impilare tutti i link nell'intestazione.",
    ],
    'keyboard_shortcuts' => [
        'title' => 'Scorciatoie da tastiera',
        'description' => 'Su desktop, usa Ctrl+K per la navigazione rapida, scorciatoie con prefisso g per le viste e / per focalizzare la ricerca nelle liste.',
    ],
    'in_app_update_summaries' => [
        'title' => "Riepiloghi aggiornamento nell'app",
        'description' => "VolumeVault ora puo mostrare agli utenti cosa e cambiato dopo un aggiornamento dell'applicazione.",
    ],
    'available_update_checks' => [
        'title' => 'Controlli aggiornamenti disponibili',
        'description' => 'VolumeVault ora puo indicare quando e disponibile una nuova versione su GitHub.',
    ],
    'backup_job_detail_deletion' => [
        'title' => 'Eliminazione dal dettaglio processo',
        'description' => 'I processi backup ora possono essere eliminati direttamente dalla loro pagina dettaglio.',
    ],
    'per_job_notification_channels' => [
        'title' => 'Canali di notifica per processo',
        'description' => 'I processi backup ora possono scegliere quali canali di notifica attivi ricevono i risultati.',
    ],
    'notification_defaults_migration' => [
        'title' => 'Migrazione notifiche predefinite',
        'description' => 'Questa versione aggiunge impostazioni di notifica ai processi backup e tracciamento del canale predefinito ai canali di notifica.',
    ],
    'host_path_backup_sources' => [
        'title' => 'Sorgenti backup percorso host',
        'description' => "Gli amministratori possono salvare directory selezionate dall'host Docker insieme ai volumi Docker.",
    ],
    'host_path_safety_controls' => [
        'title' => 'Controlli sicurezza percorso host',
        'description' => 'I percorsi host sono montati in sola lettura e possono essere limitati con VOLUMEVAULT_HOST_PATH_ALLOWLIST.',
    ],
    'stack_backup_coverage' => [
        'title' => 'Copertura backup per stack',
        'description' => 'I volumi Docker sono raggruppati per stack Compose o Swarm con stati di copertura backup.',
    ],
    'backup_archive_metadata' => [
        'title' => 'Metadati archivio backup',
        'description' => 'Le esecuzioni riuscite ora possono mostrare chiavi e dimensioni degli archivi quando i metadati della destinazione sono disponibili.',
    ],
    'trusted_proxy_support' => [
        'title' => 'Supporto proxy attendibili',
        'description' => 'VolumeVault puo considerare attendibili i reverse proxy configurati affinche gli URL generati usino lo schema HTTPS pubblico.',
    ],
    'cleaner_docker_volume_sync' => [
        'title' => 'Sincronizzazione volumi Docker piu pulita',
        'description' => 'La sincronizzazione ora rimuove vecchi record di volumi mancanti che non sono piu referenziati da processi backup.',
    ],
    'list_search_and_filters' => [
        'title' => 'Ricerca e filtri nelle liste',
        'description' => 'Volumi e processi backup hanno ricevuto ricerca, filtri e un selettore volume ricercabile.',
    ],
    'php_85_container_runtime' => [
        'title' => 'Runtime container PHP 8.5',
        'description' => 'Il container e passato al runtime ServerSideUp PHP 8.5 con servizi coda e scheduler supervisionati.',
    ],
    'first_stable_release' => [
        'title' => 'Prima versione stabile',
        'description' => 'VolumeVault e stato lanciato con backup pianificati, ripristini sicuri, destinazioni cifrate, notifiche, utenti, token API e salvataggi installazione.',
    ],
    'pagination_with_user_preference' => [
        'title' => 'Liste paginate con preferenza per pagina',
        'description' => 'Tutte le viste elenco ora supportano la paginazione con elementi configurabili per pagina (10, 20, 50, 100, o Tutti). Puoi impostare il tuo predefinito nelle impostazioni del profilo.',
    ],
    'dark_pagination_menu' => [
        'title' => 'Menu scuro di paginazione',
        'description' => 'Il selettore degli elementi per pagina mantiene ora uno stile scuro quando viene aperto, con un contrasto migliore nelle viste elenco paginate.',
    ],
    'filter_toolbar_action_buttons' => [
        'title' => 'Pulsanti primari rinnovati',
        'description' => 'I pulsanti di azione principali condividono ora lo stesso stile azzurro delineato in tutta l applicazione, sia in tema chiaro sia scuro.',
    ],
    'shareable_filter_urls' => [
        'title' => 'URL filtri condivisibili',
        'description' => 'I filtri delle liste Volumi, Stack, Processi backup e Avvisi ora sono riflessi nell URL, permettendo di copiare e condividere viste filtrate direttamente.',
    ],
    'safer_default_environment_settings' => [
        'title' => 'Impostazioni ambiente predefinite piu sicure',
        'description' => '.env.example ora imposta le nuove distribuzioni con APP_ENV=production e APP_DEBUG=false. Aggiunge anche una guida per SESSION_SECURE_COOKIE, cosi i deploy HTTPS possono abilitare cookie sicuri senza rompere accidentalmente le installazioni solo HTTP.',
    ],
    'trusted_proxy_host_hardening' => [
        'title' => "Hardening dell'host proxy attendibile",
        'description' => 'La gestione dei proxy attendibili ora ignora gli header host e prefisso inoltrati e i link di reset password vengono generati da APP_URL per prevenire link alterati.',
    ],
];
