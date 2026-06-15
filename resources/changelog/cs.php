<?php

return [
    'restore_in_place_modes' => [
        'title' => 'Režimy obnovy na místě',
        'description' => 'Průvodce obnovou nyní umí obnovit zálohu přímo do jejího zdrojového Docker svazku. „Obnovit na místě" vymaže a nahradí svazek po opětovném napsání jeho názvu pro potvrzení; „Bezpečná obnova na místě" navíc během obnovy zastaví kontejnery používající svazek a po dokončení je restartuje. Výběr zálohy se nyní ve výchozím nastavení omezuje na archivy vybrané úlohy, přidává filtry podle názvu a data, označuje nejnovější archiv a tlačítko „Obnovit tuto zálohu" otevře průvodce přímo z běhu zálohy.',
    ],
    'restore_notifications' => [
        'title' => 'Oznámení o obnovení',
        'description' => 'VolumeVault vás nyní upozorní, když obnovení začne, uspěje nebo selže, a využívá k tomu oznamovací kanály již nastavené pro zálohovací úlohu. Zprávy o spuštění a úspěchu se odesílají na kanály nastavené pro každé spuštění, zatímco selhání dorazí na všechny kanály. Problém s oznámením nikdy nepřeruší samotné obnovení.',
    ],
    'stack_bulk_backup' => [
        'title' => 'Záloha celého stacku najednou',
        'description' => 'Stránka Stacky nyní umožňuje zálohovat celý stack jedním kliknutím. Plně nakonfigurované stacky mají tlačítko "Spustit všechny úlohy", které zařadí spuštění pro každou úlohu; stacky s nepokrytými svazky mají dialog "Zálohovat stack", který pro každý svazek bez úlohy vytvoří denní (nebo vlastní) zálohovací úlohu a poté zařadí zálohu celého stacku. Stejná operace je dostupná i přes API (POST /stacks/backup).',
    ],
    'busybox_restore_tar_compat' => [
        'title' => 'Kompatibilni extrakce obnovy',
        'description' => 'Obnovy do noveho Docker volume uz nepredavaji volby tar dostupne jen v GNU tar a archiv se ted streamuje primo do obnovovaciho kontejneru, takze extrakce funguje s BusyBox tar i v kontejnerizovanych nasazenich, kde storage bezi v Docker volume.',
    ],
    'stable_stack_volume_search' => [
        'title' => 'Stabilni hledani stacku a volumes',
        'description' => 'Pri psani do hledani stacku nebo volumes zustane filtr aktivni misto toho, aby se po synchronizaci URL vratil do vychoziho stavu.',
    ],
    'backup_archive_name_templates' => [
        'title' => 'Vlastni nazvy zaloznich archivu',
        'description' => 'Zalozni ulohy ted mohou definovat sablonu nazvu archivu pomoci tokenu jako {name}, {source}, {id}, {year}, {month}, {day} a {time}. Existujici ulohy si ponechaji puvodni pojmenovani volumevault-source-run-id, dokud neni nastavena sablona, a formular upozorni, kdyz by sablona mohla prepsat starsi archivy.',
    ],
    'russian_translation_revisions' => [
        'title' => 'Vyladene ruske texty rozhrani',
        'description' => 'Ruske preklady rozhrani dostaly dalsi upravy formulaci pro lepsi konzistenci a citelnost. Dekujeme @artyomboyko za tento prekladatelsky prispevek.',
    ],
    'complete_i18n_coverage' => [
        'title' => 'Úplnější překlady rozhraní',
        'description' => 'Mnoho textů rozhraní, které se stále zobrazovaly anglicky – včetně stránek s API tokeny a uloženími instalace –, je nyní plně přeloženo. Všech devět jazyků bylo sjednoceno a chybějící překlady doplněny, takže neanglicky mluvící uživatelé již nevidí nepřeložené popisky, tlačítka a zprávy.',
    ],
    'reliable_run_logs' => [
        'title' => 'Spolehlivější protokoly běhů',
        'description' => 'Záznamy do protokolů záloh a obnov se nyní přidávají atomicky, takže souběžné zápisy (například obslužná rutina selhání úlohy, která se spustí během dokončování běhu) se již nemohou navzájem přepsat. Zkracování protokolů respektuje UTF-8, takže zkrácené protokoly zůstávají platné a nerozbíjejí zobrazení detailů běhu.',
    ],
    'stale_run_liveness_reconcile' => [
        'title' => 'Rychlejší obnova přerušených záloh',
        'description' => 'Běhy, které uvíznou po pádu, vypršení časového limitu nebo restartu workeru, se nyní obnovují mnohem rychleji. Sesouhlasení kontroluje, zda je zálohovací kontejner stále aktivní, místo čekání na pevné zpoždění: mrtvé běhy selžou během několika minut, zatímco skutečně dlouhé zálohy zůstanou nedotčené. Obnova také probíhá automaticky při startu kontejneru a restartuje aplikační kontejnery ponechané zastavené.',
    ],
    'local_destination_listing_cap' => [
        'title' => 'Omezené výpisy místních cílů',
        'description' => 'Výpis záloh u cíle na místním souborovém systému je nyní omezen na 1000 položek, stejně jako u ostatních úložných poskytovatelů, takže cíl s velmi velkým adresářem archivů již nenačítá celý strom do jediné odpovědi.',
    ],
    'per_job_schedule_timezone' => [
        'title' => 'Časové pásmo pro každou úlohu',
        'description' => 'Každá zálohovací úloha může nyní definovat vlastní časové pásmo, takže plán jako „denně ve 02:00“ běží ve 02:00 místního času namísto globálního časového pásma aplikace. Ponechte „Výchozí nastavení aplikace“ pro zachování předchozího chování.',
    ],
    'http_security_headers' => [
        'title' => 'Bezpečnostní hlavičky HTTP',
        'description' => 'Odpovědi nyní obsahují bezpečnostní hlavičky pro vícevrstvou ochranu (X-Frame-Options, X-Content-Type-Options a Referrer-Policy) a také HSTS při servírování přes HTTPS. Nasazení na prostém HTTP a v místní síti nejsou ovlivněna — žádný požadavek není nikdy nucen z HTTP na HTTPS.',
    ],
    'local_destination_path_error_feedback' => [
        'title' => 'Jasnější chyby cest u místních cílů',
        'description' => 'Při vytváření cíle v místním souborovém systému se chyby ověření cesty — například cesta blokovaná seznamem povolených cest hostitele — nyní zobrazují přímo ve formuláři, místo tichého návratu na stránku vytvoření.',
    ],
    'russian_translation_consistency' => [
        'title' => 'Vyladěné ruské překlady',
        'description' => 'Ruské texty rozhraní byly upraveny pro větší konzistenci a glosář pro ruské překladatele byl přesunut z dodávaných jazykových souborů do samostatné projektové dokumentace. Přibalené jazykové prostředky tak zůstávají čistší a glosář je stále k dispozici přispěvatelům. Díky @artyomboyko za tento překladatelský příspěvek.',
    ],
    'customizable_dashboard' => [
        'title' => 'Prizpusobitelny prehled',
        'description' => 'Nyni si muzete vybrat, ktere widgety prehledu se maji zobrazit a v jakem poradi. Kliknutim na "Prizpusobit" muzete skryt nebo zobrazit libovolnou statistickou kartu nebo sekci, pretazenim zmenite jejich poradi a kliknutim na "Hotovo" ulozite. Kazdy uzivatel ma vlastni rozlozeni a "Obnovit vychozi" obnovi puvodni usporadani.',
    ],
    'self_container_backup_guard' => [
        'title' => 'VolumeVault jiz behem zalohovani nezastavuje vlastni kontejner',
        'description' => 'Kdyz ma zalohovaci uloha zapnuto "zastavit kontejnery pred zalohou" a cili na svazek, ktery pripojuje i samotny kontejner VolumeVault, VolumeVault jiz nezastavuje vlastni kontejner - coz by prerusilo probihajici zalohu. Kontejner je automaticky rozpoznan podle nazvu hostitele (hostname) a cgroup; nastavte VOLUMEVAULT_CONTAINER_ID nebo VOLUMEVAULT_CONTAINER_NAME, pokud automaticka detekce neni spolehliva (vlastni hostname nebo sit hostitele).',
    ],
    'host_path_stop_containers' => [
        'title' => 'Zastaveni vybranych kontejneru u zaloh cesty na hostiteli',
        'description' => 'Zalohovaci ulohy typu cesta na hostiteli nyni mohou pred zalohou zastavit kontejnery a pote je znovu spustit, jak to jiz umely ulohy s Docker svazkem. Protoze cestu na hostiteli nelze automaticky priradit ke kontejnerum, vyberete je podle nazvu ve formulari ulohy. Vyber se uklada podle nazvu, takze prezije znovuvytvoreni kontejneru; kontejnery, ktere jiz neexistuji nebo jsou jiz zastavene, se preskoci a VolumeVault nikdy nezastavi vlastni kontejner.',
    ],
    'ssrf_destination_guard' => [
        'title' => 'Cilove destinace zaloh se soukromou IP jsou nyni chranene (SSRF)',
        'description' => 'VolumeVault nyni ve vychozim nastaveni odmita pripojeni k zalozni destinaci, jejiz hostitel se preklada na soukromou, smyckovou (loopback) nebo link-local adresu (vcetne cloudoveho metadatoveho koncoveho bodu 169.254.169.254). Tyka se to pouze destinaci se soukromou IP, jako je NAS v LAN nebo vlastni S3/MinIO - cloudove destinace dostupne pres verejnou URL nejsou dotceny. Naplanovane zalohy stale bezi, ale test destinace, obnoveni (vypis a stazeni) a upozorneni na kvotu uloziste jsou blokovany, dokud rozsah destinace neuvedete v VOLUMEVAULT_SSRF_ALLOWED_IPS (CIDR oddelene carkami, napr. 192.168.1.0/24). Notifikacni kanaly nejsou chranene.',
    ],
    'host_path_allowlist_fail_closed' => [
        'title' => 'Seznam povolenych cest hostitele je nyni fail-closed',
        'description' => 'VOLUMEVAULT_HOST_PATH_ALLOWLIST nyni ve vychozim nastaveni odmita: kdyz je prazdny, jsou zdroje zaloh podle cesty hostitele a mistni cile odmitnuty namisto povoleni libovolne cesty. Stejny seznam nyni chrani i mistni cile a cesty se pri behu znovu kontroluji, aby se zablokovala zamena symbolickych odkazu. Stavajici instalace, ktere se spolehaly na predchozi otevrene vychozi chovani, musi uvest sve cesty - spustte "php artisan volumevault:host-path-allowlist:audit" pro ziskani presne hodnoty k nastaveni.',
    ],
    'auth_rate_limiting' => [
        'title' => 'Prihlaseni a obnoveni hesla s omezenim rychlosti',
        'description' => 'Pozadavky na prihlaseni a obnoveni hesla jsou nyni omezeny na 5 pokusu za minutu, coz zpomaluje utoky hrubou silou na heslo administratora. Pri prekroceni limitu se vrati docasna odpoved "prilis mnoho pozadavku", ktera se po minute resetuje.',
    ],
    'restore_input_hardening' => [
        'title' => 'Prisnejsi overovani vstupu pro obnoveni a zalohovani',
        'description' => 'Zaloha vybrana pro obnoveni se nyni musi shodovat se seznamem cile, coz blokuje klice pro prochazeni cest jako "../../etc/passwd". Nazvy svazku Docker jsou omezeny na bezpecne znaky a extrakce pri obnoveni je omezena tak, aby podvrzeny archiv nemohl zapisovat mimo cilovy svazek.',
    ],
    'sftp_host_key_pinning' => [
        'title' => 'Pripnuti SSH klice hostitele pro cile SFTP',
        'description' => 'Cile SSH/SFTP nyni mohou pripnout klic hostitele serveru a blokovat tak utoky typu man-in-the-middle. Pomoci tlacitka "Nacist klic ze serveru" - nebo noveho endpointu POST /api/v1/destinations/host-key - duverujte predlozenemu klici, nebo vlozte klic hostitele ci otisk SHA256. Klic se overuje pred odeslanim jakychkoli prihlasovacich udaju, pro operace SFTP provadene aplikaci VolumeVault (test, vypis, obnoveni). Ponechani prazdne zachova predchozi chovani.',
    ],
    'api_token_expiration' => [
        'title' => 'Tokeny API nyni ve vychozim nastaveni vyprsi',
        'description' => 'Tokeny API nyni ve vychozim nastaveni vyprsi 60 dni po vytvoreni, coz omezuje dopad uniku tokenu. Stavajici starsi tokeny po aktualizaci prestanou fungovat a je nutne je znovu vytvorit. Nastavte SANCTUM_TOKEN_EXPIRATION (v minutach) pro zmenu doby platnosti, nebo null pro zachovani tokenu bez expirace. Expirace jednotliveho tokenu muze tuto dobu pouze zkratit, nikdy prodlouzit.',
    ],
    'alert_check_isolation' => [
        'title' => 'Odolnejsi kontroly upozorneni',
        'description' => 'Pravidlo upozorneni, ktere skonci chybou, jiz nebrani kontrole ostatnich pravidel. Kazde pravidlo se nyni vyhodnocuje samostatne a chyby se zaznamenavaji, takze jedna chybna kontrola jiz nemuze tise vypnout ostatni upozorneni.',
    ],
    'restore_volume_cleanup' => [
        'title' => 'Cistejsi opakovani po neuspesnem obnoveni',
        'description' => 'Kdyz obnoveni selze po vytvoreni ciloveho svazku, VolumeVault nyni castecne vytvoreny svazek odstrani, aby dalsi pokus zacal cisty a nebyl blokovan chybou "jiz existuje".',
    ],
    'schedule_drift_prevention' => [
        'title' => 'Spolehlivejsi planovani zaloh',
        'description' => 'Naplanovane zalohy jiz nevynechaji spusteni, kdyz se worker zpozdi. Dalsi spusteni se nyni ukotvi k planovanemu oknu misto k casu dokonceni predchoziho spusteni, takze pomale nebo opozdene spusteni jiz nemuze zpusobit posun rozvrhu.',
    ],
    'destination_usage_efficiency' => [
        'title' => 'Efektivnejsi vypocet vyuziti uloziste cile',
        'description' => 'Vyuziti uloziste cilu zaloh se nyni pocita prubeznym prochazenim objektu misto nacitani celeho seznamu do pameti a SFTP spojeni se po dokonceni vzdy uzavre. Cile s mnoha zalohami se meri spolehliveji, bez vycerpani pameti nebo ponechani otevrenych spojeni.',
    ],
    'run_log_integrity' => [
        'title' => 'Spolehlivejsi protokoly behu',
        'description' => 'Protokoly behu zaloh a obnoveni se nyni pripojuji atomicky, takze soubezne aktualizace - napriklad chybova zprava a upozorneni na restart kontejneru - se jiz vzajemne neprepisuji. Velikost protokolu je take omezena a zachovava nejnovejsi vystup misto neomezeneho rustu.',
    ],
    'stale_run_reconciliation' => [
        'title' => 'Automaticke obnoveni preruseny behu',
        'description' => 'Behy zalohovani a obnovy preruseny padem workeru, timeoutem nebo restartem jsou nyni automaticky oznaceny jako neuspesne, misto aby zustaly zaseknute, takze planovane zalohy bezi dal. Aplikacni kontejnery zastavene kvuli zaloze se take automaticky znovu spusti, pokud je pad nechal vypnute.',
    ],
    'advanced_alerting' => [
        'title' => 'Pokrocile upozornovani',
        'description' => 'VolumeVault muze sledovat zalozni ulohy a hlidat zastarale zalohy, opakovane selhani, dlouhotrvajici chybove stavy a neobvykle velikosti archivu.',
    ],
    'destination_storage_limit_alerts' => [
        'title' => 'Upozorneni na limit uloziste cile',
        'description' => 'Cile zaloh mohou nyni nastavit absolutni varovne a kriticke prahy uloziste s vlastnimi notifikacnimi kanaly.',
    ],
    'mobile_navigation_redesign' => [
        'title' => 'Vylepsena mobilni navigace',
        'description' => 'Mobilni hlavicka ted pouziva kompaktni tlacitko menu a strukturovany navigacni panel misto skladani vsech odkazu v hlavicce.',
    ],
    'keyboard_shortcuts' => [
        'title' => 'Klavesove zkratky',
        'description' => 'Na desktopu pouzijte Ctrl+K pro rychlou navigaci, zkratky s predponou g pro zobrazeni a / pro zamereni hledani v seznamech.',
    ],
    'in_app_update_summaries' => [
        'title' => 'Souhrny aktualizaci v aplikaci',
        'description' => 'VolumeVault ted muze uzivatelum ukazat, co se po aktualizaci aplikace zmenilo.',
    ],
    'available_update_checks' => [
        'title' => 'Kontroly dostupnych aktualizaci',
        'description' => 'VolumeVault ted muze upozornit, kdyz je dostupne novejsi vydani na GitHubu.',
    ],
    'backup_job_detail_deletion' => [
        'title' => 'Smazani ze stranky detailu ulohy',
        'description' => 'Zalozni ulohy lze ted smazat primo z jejich stranky detailu.',
    ],
    'per_job_notification_channels' => [
        'title' => 'Kanaly oznameni pro jednotlive ulohy',
        'description' => 'Zalozni ulohy ted mohou vybrat, ktere aktivni kanaly oznameni dostanou jejich vysledky.',
    ],
    'notification_defaults_migration' => [
        'title' => 'Migrace vychozich oznameni',
        'description' => 'Toto vydani pridava nastaveni oznameni k zaloznim uloham a sledovani vychoziho kanalu ke kanalum oznameni.',
    ],
    'host_path_backup_sources' => [
        'title' => 'Zdroje zaloh z cest hostitele',
        'description' => 'Admini mohou zalohovat vybrane adresare z Docker hostitele vedle Docker svazku.',
    ],
    'host_path_safety_controls' => [
        'title' => 'Bezpecnostni kontroly cest hostitele',
        'description' => 'Cesty hostitele jsou pripojeny pouze pro cteni a lze je omezit pomoci VOLUMEVAULT_HOST_PATH_ALLOWLIST.',
    ],
    'stack_backup_coverage' => [
        'title' => 'Pokryti zaloh podle stacku',
        'description' => 'Docker svazky jsou seskupeny podle Compose nebo Swarm stacku se stavy pokryti zaloh.',
    ],
    'backup_archive_metadata' => [
        'title' => 'Metadata archivu zalohy',
        'description' => 'Uspesne behy ted mohou zobrazit klice a velikosti archivu, pokud jsou metadata cile dostupna.',
    ],
    'trusted_proxy_support' => [
        'title' => 'Podpora duveryhodnych proxy',
        'description' => 'VolumeVault muze duverovat nastavenym reverznim proxy, aby generovane URL pouzivaly verejne HTTPS schema.',
    ],
    'cleaner_docker_volume_sync' => [
        'title' => 'Cistsi synchronizace Docker svazku',
        'description' => 'Synchronizace ted odstranuje zastarale chybejici zaznamy svazku, ktere uz nejsou odkazovane zaloznimi ulohami.',
    ],
    'list_search_and_filters' => [
        'title' => 'Vyhledavani a filtry v seznamech',
        'description' => 'Svazky a zalozni ulohy ziskaly vyhledavani, filtry a prohledavatelny vyber svazku.',
    ],
    'php_85_container_runtime' => [
        'title' => 'Runtime kontejneru PHP 8.5',
        'description' => 'Kontejner presel na runtime ServerSideUp PHP 8.5 se spravovanou frontou a planovacem.',
    ],
    'first_stable_release' => [
        'title' => 'Prvni stabilni vydani',
        'description' => 'VolumeVault byl spusten s planovanymi zalohami, bezpecnymi obnovami, sifrovanymi cili, oznamenimi, uzivateli, API tokeny a instalacnimi zalohami.',
    ],
    'pagination_with_user_preference' => [
        'title' => 'Strankovane seznamy s preferencemi na stranku',
        'description' => 'Vsechny pohledy seznamu nyni podporuji strankovani s konfigurovatelnym poctem polozek na stranku (10, 20, 50, 100 nebo Vse). Vychozi hodnotu nastavite v nastaveni profilu.',
    ],
    'dark_pagination_menu' => [
        'title' => 'Tmave menu strankovani',
        'description' => 'Vyber poctu polozek na stranku si po otevreni nyni zachova tmavy vzhled a lepsi kontrast ve strankovanych seznamech.',
    ],
    'filter_toolbar_action_buttons' => [
        'title' => 'Obnovena primarni tlacitka',
        'description' => 'Primarni akcni tlacitka ted sdileji stejny modre oramovany styl v cele aplikaci ve svetlem i tmavem rezimu.',
    ],
    'shareable_filter_urls' => [
        'title' => 'Sditelne URL s filtry',
        'description' => 'Filtry seznamu Svazku, Stacku, Zaloznich uloh a Upozorneni se nyni promitaji v URL, coz umoznuje primo kopirovat a sdilet filtrovane pohledy.',
    ],
    'safer_default_environment_settings' => [
        'title' => 'Bezpecnejsi vychozi nastaveni prostredi',
        'description' => '.env.example ted pro nova nasazeni standardne nastavuje APP_ENV=production a APP_DEBUG=false. Zaroven pridava pokyny pro SESSION_SECURE_COOKIE, aby bylo mozne u HTTPS nasazeni zapnout zabezpecene cookies bez nechteneho rozbiti ciste HTTP instalaci.',
    ],
];
