<?php

return [
    'ssh_private_key_backup_fix' => [
        'title' => 'SFTP mentesek privat kulccsal',
        'description' => 'Az SFTP mentesek mostantol jelszo nelkul, feltoltott SSH privat kulccsal is hitelesithetok. A VolumeVault biztonsagosan az ideiglenes Offen mentest vegzo kontenerbe masolja a kulcsot, majd a futas utan eltavolitja.',
    ],
    'ssh_destination_update_fix' => [
        'title' => 'SFTP-célok megbízható frissítése',
        'description' => 'A meglévő SFTP-célok mostantól akkor is frissíthetők, ha az SSH-gazdagép állomásnévvel vagy IP-címmel van megadva. Az SFTP-beállítások és hitelesítő adatok ellenőrzési hibái az érintett mezők mellett is megjelennek.',
    ],
    'docker_tcp_backup_network' => [
        'title' => 'Docker TCP proxyhálózat a mentésekhez',
        'description' => 'A mentések mostantól a Docker-hálózati szolgáltatásnevén keresztül is elérhetik a Docker TCP socket proxyt. Állítsa a VOLUMEVAULT_DOCKER_NETWORK értékét az engine számára látható egyéni hálózatra; a VolumeVault ehhez csatlakoztatja az ideiglenes Offen mentési konténereket.',
    ],
    'docker_tcp_endpoint' => [
        'title' => 'Docker TCP-végpont támogatása',
        'description' => 'A VolumeVault mostantól Docker TCP-végpontot használhat a DOCKER_HOST=tcp://host:port beállítással, például ugyanazon Docker-motor előtt működő socket proxyt. A Docker-parancsok és az ideiglenes Offen mentési konténerek a beállított végpontot használják. Távoli Docker-hosztok nem támogatottak; a TCP-hozzáférés root jogosultsággal egyenértékű, ezért megbízható privát hálózatra kell korlátozni.',
    ],
    'orphaned_backup_recovery' => [
        'title' => 'Megbízható helyreállítás megszakadt mentések után',
        'description' => 'A VolumeVault mostantól felismeri az újraindítás után árván maradt mentési konténereket, biztonságosan leállítja őket, sikertelennek jelöli a futásokat és csoportokat, feloldja a zárolásokat, majd újraindítja a leállítva maradt alkalmazáskonténereket. A mentési feladatok és csoportok többé nem maradnak végleg blokkolva ilyen hiba után.',
    ],
    'backup_group_detail_page' => [
        'title' => 'Biztonsági mentési csoport részletező oldala',
        'description' => 'A biztonsági mentési csoportoknak mostantól van egy csak olvasható részletező oldaluk, amely minden felhasználó számára elérhető, és megjeleníti a csoport ütemezését, tagjait, az összesített futási előzményeket és az utolsó sikeres mentés méretét. Egy csoport megnyitása a listából, az irányítópult hibás csoportok widgetjéből vagy egy csoportfuttatás vissza a csoporthoz hivatkozásából mostantól erre az oldalra vezet a csak rendszergazdáknak fenntartott szerkesztőűrlap helyett. A rendszergazdák továbbra is elérik itt a futtatás, szüneteltetés, folytatás, szerkesztés és törlés műveleteket, és a csoport futási előzményei erről a szerkesztőűrlapról erre az oldalra kerültek át, így az űrlap immár tisztán űrlap.',
    ],
    'mobile_header_actions' => [
        'title' => 'Letisztultabb mobil fejlécműveletek',
        'description' => 'Az irányítópult testreszabása és a listanézetek létrehozási műveletei mobilon most kompakt ikon gombokként jelennek meg az oldal címe mellett, míg nagyobb képernyőkön megmaradnak a teljes szöveges gombok.',
    ],
    'group_backup_size_reporting' => [
        'title' => 'Csoportos mentések méretének jelentése',
        'description' => 'A csoportos mentési futtatások mostantól jelentik tagjaik archívumainak teljes méretét. Egy új, választható irányítópult-widget, „Utolsó sikeres csoportos mentés mérete“, bekapcsolható az irányítópult Testreszabás paneljén, és az összesített méret megjelenik a legutóbbi csoportos futtatásoknál és az egyes csoportok futtatási előzményeiben is. Az API-n keresztül a csoportos futtatások közzéteszik a total_backup_size_bytes értéket, az irányítópult pedig egy last_successful_group_backup_size statisztikával bővül. A méretek egy futtatás befejezése után rövid késéssel jelenhetnek meg, mivel az egyes tagarchívumok méretét a rendszer aszinkron módon rögzíti.',
    ],
    'inclusive_backup_filter' => [
        'title' => 'Mentés szűrése belefoglalással',
        'description' => 'A mentési feladatok mostantól csak a felsorolt mappákat vagy fájlokat tarthatják meg, ahelyett, hogy csak néhányat zárnának ki. Válassza a „Csak belefoglalás“ lehetőséget a feladat űrlapján, és adjon meg egy vesszővel elválasztott, a mentési forrás gyökeréhez viszonyított útvonallistát (például „Backups, config/app.conf“); minden mást kihagy, így az archívumok kicsik maradnak. A haladó, regex alapú kizárási mód továbbra is elérhető, és a csak belefoglalást használó feladatok az API-n keresztül is létrehozhatók.',
    ],
    'grouped_backup_jobs' => [
        'title' => 'Csoportosított biztonsági mentési feladatok',
        'description' => 'Több kötetet menthet egyetlen ütemezett műveletként: egy biztonsági mentési csoport birtokolja az ütemezést és az értesítéseket, a feladatokat pedig a biztonsági mentési feladat űrlapjáról csatolja hozzá. A csoport egyetlen indítási és egyetlen sikeres/hibás értesítést küld az összes kötetéről — ideális egyetlen dead man\'s switch típusú figyelőhöz —, és megválaszthatja, hogy egy hibás kötet leállítja-e a futást, vagy a csoport folytatja és mégis jelzi a hibát. A biztonsági mentési csoportok API-n keresztül is elérhetők.',
    ],
    'user_date_format_preference' => [
        'title' => 'Felhasznalonkenti datumformatum',
        'description' => 'Mostantol minden felhasznalo kivalaszthatja a profiljaban, milyen regionalis formatum jelenjen meg a datumoknal. Peldaul az interfesz maradhat angol, mikozben a datumok az amerikai honap/nap sorrendrol az ausztral vagy brit nap/honap sorrendre valtananak. Az alkalmazas idozonaja tovabbra is azt hatarozza meg, melyik helyi ido jelenik meg.',
    ],
    'trusted_2fa_device_password_revocation' => [
        'title' => 'Megbizhato 2FA-eszkozok visszavonasa jelszovaltozaskor',
        'description' => 'Egy felhasznalo jelszavanak modositasa vagy visszaallitasa most visszavonja a felhasznalo megbizhato 2FA-eszkozeit. A meglevo megbizhatoeszkoz-rekordok a frissites soran torlodnek, igy a bongeszoknek ujra teljesiteniuk kell a 2FA-kihivast, mielott ismet megbizhatova valhatnak.',
    ],
    'installation_save_two_factor_reencryption' => [
        'title' => '2FA-titkok ujratitkositasa telepitesimportkor',
        'description' => 'A telepitesi mentesek importja mostantol az uj peldany APP_KEY ertekevel ujratitkositja a felhasznaloi TOTP-titkokat es helyreallitokodokat, a celokhoz es ertesitesekhez hasonloan, igy migracio utan elkerulhetok a 2FA-zarolasok.',
    ],
    'docker_volume_destinations' => [
        'title' => 'Docker-kötet célok',
        'description' => 'Egy új „Docker-kötet“ célállomás egy névvel ellátott Docker-kötetre készít biztonsági mentést — bármilyen illesztőprogram, beleértve az NFS-t vagy más hálózati megosztásokat. Deklarálja a kötetet a Compose-fájlban, és a VolumeVault név szerint csatolja az ideiglenes mentési konténerbe, így a mentések, a visszaállítások, a listázás és a tárhelyhasználat anélkül működik, hogy gazdagép-elérési utat kellene megosztani a VolumeVault-tal. Ha a kötet már nem létezik, a célállomás egyértelmű hibával hiúsul meg, ahelyett hogy észrevétlenül egy üres, újonnan létrehozott kötetbe írna.',
    ],
    'webhook_notifications' => [
        'title' => 'Webhook-értesítések',
        'description' => 'Egy új „Webhook“ értesítési csatorna a saját URL-jeit hívja meg a mentési és visszaállítási eseményeknél. Állítson be külön URL-t minden művelethez — indítás, siker és hiba —, és a VolumeVault a megfelelőt hívja meg, amikor egy mentés vagy visszaállítás elindul, sikerül vagy meghiúsul. Töltse ki tetszés szerint; állítsa a csatorna szintjét „Minden mentés és visszaállítás“ értékre, hogy az indítási és sikeres URL-ek is elküldésre kerüljenek. Ez megkönnyíti a felügyeleti és „holtember-kapcsoló“ típusú szolgáltatásokkal való integrációt. Az értesítési csatornák mostantól az API-n keresztül is frissíthetők.',
    ],
    'backup_start_notifications' => [
        'title' => 'Mentésindítási értesítések',
        'description' => 'A mentések mostantól a futás indulásakor is küldenek értesítést, nemcsak a végén — ahogy a visszaállítások már teszik. Az indítási üzenetek a minden futást fogadó csatornákra mennek; a csak hibákat fogadó csatornákat nem érinti. A felügyeleti szolgáltatások így mérni tudják, mennyi ideig tart egy mentés.',
    ],
    'unencrypted_smtp_notifications' => [
        'title' => 'Titkosítatlan SMTP-kiszolgálók támogatása',
        'description' => 'Az SMTP értesítési csatornák mostantól olyan kiszolgálókra is kézbesíthetnek, amelyek nem használnak titkosítást. Az értesítési űrlapon egy új „Az SMTP-kiszolgáló titkosítatlan" beállítás kikapcsolja a TLS-t és a STARTTLS-t, így a VolumeVault elérhet egy megbízható helyi SMTP-továbbítót, amely egyébként „unencrypted connection" hibával utasítaná el a kapcsolatot. A titkosított kézbesítés marad az alapértelmezett, és változatlan.',
    ],
    'optional_two_factor_auth' => [
        'title' => 'Választható kétfaktoros hitelesítés',
        'description' => 'Mostantól védheti fiókját választható, időalapú egyszer használatos jelszón (TOTP) alapuló kétfaktoros hitelesítéssel. Kapcsolja be a profiljában egy QR-kód beolvasásával egy hitelesítő alkalmazással, például a Google Authenticatorral vagy az Authyval, majd erősítse meg a generált kóddal. Bekapcsolás után a bejelentkezés a jelszó után közvetlenül egy hatjegyű kódot kér. Egyszer használatos helyreállítási kódok készlete áll rendelkezésre arra az esetre, ha elveszítené a hozzáférést a hitelesítő alkalmazáshoz, az adminisztrátorok pedig bármely felhasználó kétfaktoros hitelesítését visszaállíthatják a Felhasználók oldalon. A kód képernyőjén egy böngészőt megbízhatóként is megjelölhet, hogy 30 napig kihagyja a kódot – de a jelszót soha.',
    ],
    'backup_initiator_tracking' => [
        'title' => 'Annak nyomon követése, ki indította az egyes mentéseket',
        'description' => 'A mentések mostantól rögzítik, melyik felhasználó indította őket. A kézi futtatások (a felületről vagy az API-ból) és a teljes verem mentései a bejelentkezett felhasználóhoz vannak rendelve, a helyben történő visszaállítás előtt készített biztonsági mentés a visszaállítást indító felhasználót örökli, az ütemezett futtatások pedig hozzárendelés nélkül maradnak. A kezdeményező megjelenik a feladat futtatási előzményeiben és a mentés részleteiben, szerepel a mentési értesítésekben, és új {{ user }} tokenként elérhető az egyéni értesítési sablonokhoz.',
    ],
    'restore_history_on_job' => [
        'title' => 'Visszaállítási előzmények a biztonsági mentési feladatoknál',
        'description' => 'Minden biztonsági mentési feladat oldala mostantól két fülre osztja az előzményeket: a „Futási előzmények" a feladat biztonsági mentéseit sorolja fel, egy új „Visszaállítási előzmények" fül pedig az adott feladathoz végrehajtott összes visszaállítást – állapottal, móddal, forrás- és célkötettel, kezdési idővel, időtartammal és a teljes visszaállítási részletekre mutató hivatkozással. Mindkét fül mostantól lapozható, így a hosszú előzmények már nem korlátozódnak 50 sorra.',
    ],
    'restore_in_place_modes' => [
        'title' => 'Helyben visszaállítási módok',
        'description' => 'A visszaállítási varázsló mostantól közvetlenül a forrás Docker kötetébe tud visszaállítani egy mentést. A „Helyben visszaállítás" a név újragépelésével történő megerősítés után kiüríti és lecseréli a kötetet; a „Biztonságos helyben visszaállítás" a visszaállítás alatt ezenfelül leállítja a kötetet használó konténereket, majd a végén újraindítja őket. A mentésválasztó mostantól alapértelmezetten a kiválasztott feladat archívumait mutatja, név és dátum szerinti szűrőket ad, kiemeli a legújabb archívumot, és a „Mentés visszaállítása" gomb közvetlenül egy mentési futásból nyitja meg a varázslót. Mindkét helyben visszaállítási mód opcionálisan elmentheti a kötet jelenlegi tartalmát a felülírás előtt; ha ez a biztonsági mentés sikertelen, a visszaállítás megszakad.',
    ],
    'restore_notifications' => [
        'title' => 'Visszaállítási értesítések',
        'description' => 'A VolumeVault mostantól értesít, amikor egy visszaállítás elindul, sikerül vagy meghiúsul, és ehhez a mentési feladathoz már beállított értesítési csatornákat használja. Az indítási és sikerüzenetek a minden futtatást fogadó csatornákra kerülnek, míg a hibák minden csatornát elérnek. Egy értesítési hiba soha nem szakítja meg magát a visszaállítást.',
    ],
    'stack_bulk_backup' => [
        'title' => 'Egy teljes stack mentése egyszerre',
        'description' => 'A Stackek oldal mostantól egyetlen kattintással menthet egy teljes stacket. A teljesen beállított stackeknél megjelenik az "Összes feladat futtatása" gomb, amely minden feladathoz sorba állít egy futtatást; a nem lefedett kötetekkel rendelkező stackeknél a "Stack mentése" ablak minden feladat nélküli kötethez létrehoz egy napi (vagy egyéni) mentési feladatot, majd sorba állít egy mentést a teljes stackhez. Ugyanez a művelet elérhető az API-n keresztül is (POST /stacks/backup).',
    ],
    'busybox_restore_tar_compat' => [
        'title' => 'Kompatibilis visszaallitasi kicsomagolas',
        'description' => 'Az uj Docker volume-ra torteno visszaallitasok mar nem adnak at csak GNU tar altal tamogatott opciokat, es az archivumot most streamelve kuldik a visszaallitasi kontenerbe, igy a kicsomagolas mukodik BusyBox tar mellett es olyan konteneres telepiteseken is, ahol a tarolo Docker volume-ban van.',
    ],
    'stable_stack_volume_search' => [
        'title' => 'Stabil stack- es volume-kereses',
        'description' => 'A stackek vagy volume-ok keresojebe irt szoveg most aktivan tartja a szurot, ahelyett hogy az URL szinkronizalasa utan visszaallna alapallapotba.',
    ],
    'backup_archive_name_templates' => [
        'title' => 'Egyedi mentesarchivum-nevek',
        'description' => 'A mentési feladatok mostantol archivumnev-sablont hasznalhatnak olyan tokenekkel, mint {name}, {source}, {id}, {year}, {month}, {day} es {time}. A meglevo feladatok megtartjak a korabbi volumevault-source-run-id nevezest, amig nincs sablon beallitva, es az urlap figyelmeztet, ha egy sablon felulirhat korabbi archivumokat.',
    ],
    'russian_translation_revisions' => [
        'title' => 'Finomitott orosz feluleti szovegek',
        'description' => 'Az orosz feluleti forditasok tovabbi megfogalmazasi javitasokat kaptak a jobb kovetkezetesseg es olvashatosag erdekeben. Koszonet @artyomboyko reszere a forditasi hozzajarulasert.',
    ],
    'complete_i18n_coverage' => [
        'title' => 'Teljesebb felületi fordítások',
        'description' => 'Számos felületi szöveg, amely még angolul jelent meg – köztük az API-tokenek és a telepítésmentések oldalai –, mostantól teljesen le van fordítva. Mind a kilenc nyelv szinkronizálva lett, és a hiányzó fordítások pótlásra kerültek, így a nem angol nyelvű felhasználók többé nem látnak lefordítatlan címkéket, gombokat és üzeneteket.',
    ],
    'reliable_run_logs' => [
        'title' => 'Megbízhatóbb futtatási naplók',
        'description' => 'A biztonsági mentések és visszaállítások naplóbejegyzései mostantól atomi módon kerülnek hozzáfűzésre, így az egyidejű írások (például egy feladat hibakezelője, amely egy futtatás befejeződésekor indul el) nem írják felül egymást. A naplók csonkolása UTF-8-tudatos, így a rövidített naplók érvényesek maradnak, és nem törik el a futtatás részleteinek nézetét.',
    ],
    'stale_run_liveness_reconcile' => [
        'title' => 'Megszakadt biztonsági mentések gyorsabb helyreállítása',
        'description' => 'A worker összeomlása, időtúllépése vagy újraindulása után elakadt futtatások mostantól sokkal gyorsabban helyreállnak. Az egyeztető azt ellenőrzi, hogy a biztonsági mentés tárolója még aktív-e, ahelyett, hogy fix késleltetést várna: a halott futtatások perceken belül meghiúsulnak, míg a valóban hosszú mentések érintetlenek maradnak. A helyreállítás a tároló indításakor is automatikusan lefut, és újraindítja a leállítva hagyott alkalmazástárolókat.',
    ],
    'local_destination_listing_cap' => [
        'title' => 'Korlátozott helyi célok listázása',
        'description' => 'A helyi fájlrendszeren lévő cél biztonsági mentéseinek listázása mostantól 1000 bejegyzésre van korlátozva, akárcsak a többi tárolószolgáltatónál, így egy nagyon nagy archívumkönyvtárral rendelkező cél már nem tölti be a teljes fát egyetlen válaszba.',
    ],
    'per_job_schedule_timezone' => [
        'title' => 'Feladatonkénti időzóna',
        'description' => 'Minden biztonsági mentési feladat mostantól megadhatja saját időzónáját, így egy olyan ütemezés, mint a „naponta 02:00-kor”, helyi idő szerint 02:00-kor fut, nem pedig a globális alkalmazás-időzónában. Hagyja „Alkalmazás alapértelmezett” értéken a korábbi viselkedés megtartásához.',
    ],
    'http_security_headers' => [
        'title' => 'HTTP biztonsági fejlécek',
        'description' => 'A válaszok mostantól mélységi védelmet biztosító biztonsági fejléceket tartalmaznak (X-Frame-Options, X-Content-Type-Options és Referrer-Policy), valamint HSTS-t, ha HTTPS-en keresztül szolgálják ki. A sima HTTP és a helyi hálózati telepítéseket ez nem érinti — egyetlen kérés sem kényszerül soha HTTP-ről HTTPS-re.',
    ],
    'local_destination_path_error_feedback' => [
        'title' => 'Érthetőbb útvonalhibák a helyi céloknál',
        'description' => 'Helyi fájlrendszer-cél létrehozásakor az útvonal-ellenőrzési hibák — például a gazdagép útvonal-engedélylistája által letiltott útvonal — mostantól közvetlenül az űrlapon jelennek meg, ahelyett, hogy némán visszatérnének a létrehozási oldalra.',
    ],
    'russian_translation_consistency' => [
        'title' => 'Finomított orosz fordítások',
        'description' => 'Az orosz felületi szövegek egységesebbek lettek, az orosz fordítói glosszárium pedig kikerült a szállított nyelvi fájlokból a projekt külön dokumentációjába. Így a csomagolt nyelvi erőforrások tisztábbak maradnak, miközben a glosszárium továbbra is elérhető a közreműködőknek. Köszönet @artyomboyko részére ezért a fordítási hozzájárulásért.',
    ],
    'customizable_dashboard' => [
        'title' => 'Testreszabhato iranyitopult',
        'description' => 'Most mar kivalaszthatja, mely iranyitopult-widgetek jelenjenek meg es milyen sorrendben. Kattintson a "Testreszabas" gombra barmely statisztikai kartya vagy szakasz elrejtesehez vagy megjelenitesehez, huzassal rendezze at oket, majd kattintson a "Kesz" gombra a menteshez. Minden felhasznalo sajat elrendezest tart meg, az "Alapertekek visszaallitasa" pedig visszaallitja az eredeti elrendezest.',
    ],
    'self_container_backup_guard' => [
        'title' => 'A VolumeVault mar nem allitja le a sajat konteneret a mentes alatt',
        'description' => 'Ha egy mentesi feladatnal be van kapcsolva a "konterek leallitasa mentes elott", es olyan kotetet celoz, amelyet maga a VolumeVault konteneren is csatol, a VolumeVault mar nem allitja le a sajat konteneret - ami megszakitotta volna a folyamatban levo mentest. A konteneren automatikusan felismerheto a gepnev (hostname) es a cgroup alapjan; allitsa be a VOLUMEVAULT_CONTAINER_ID vagy VOLUMEVAULT_CONTAINER_NAME erteket, ha az automatikus felismeres nem megbizhato (egyedi gepnev vagy host-halozat).',
    ],
    'host_path_stop_containers' => [
        'title' => 'Kivalasztott kontenerek leallitasa host utvonal mentesekhez',
        'description' => 'A host utvonal tipusu mentesi feladatok mostantol leallithatjak a kontenereket a mentes elott, majd utana ujrainditjak oket, ahogy a Docker kotet feladatok mar korabban is. Mivel egy host utvonal nem rendelheto automatikusan kontenerekhez, a feladat urlapjan nev szerint valasztod ki oket. A kivalasztas nev szerint tarolodik, igy megmarad a kontenerek ujraletrehozasa utan is; a mar nem letezo vagy mar leallitott kontenereket kihagyja, es a VolumeVault soha nem allitja le a sajat konteneret.',
    ],
    'ssrf_destination_guard' => [
        'title' => 'A privat IP-cimu mentesi celok mostantol vedettek (SSRF)',
        'description' => 'A VolumeVault mostantol alapertelmezetten megtagadja a kapcsolodast olyan mentesi celhoz, amelynek a gazdaneve privat, loopback vagy link-local cimre oldodik fel (beleertve a 169.254.169.254 felho-metaadat vegpontot). Ez csak a privat IP-cimu celokat erinti, peldaul egy LAN-on levo NAS-t vagy egy sajat uzemeltetesu S3/MinIO-t - a nyilvanos URL-en elerheto felho celok nem erintettek. Az utemezett mentesek tovabbra is futnak, de a celteszt, a visszaallitas (listazas es letoltes) es a tarhelykvota-riasztas blokkolva van, amig fel nem veszi a cel tartomanyat a VOLUMEVAULT_SSRF_ALLOWED_IPS valtozoba (vesszovel elvalasztott CIDR-ek, pl. 192.168.1.0/24). Az ertesitesi csatornak nincsenek vedve.',
    ],
    'host_path_allowlist_fail_closed' => [
        'title' => 'A hoteleresi utak engedelyezesi listaja mostantol fail-closed',
        'description' => 'A VOLUMEVAULT_HOST_PATH_ALLOWLIST mostantol alapertelmezetten elutasit: ha ures, a hoteleresi uton alapulo mentesi forrasokat es a helyi celokat elutasitja ahelyett, hogy barmely utat engedelyezne. Ugyanez a lista mostantol a helyi celokat is vedi, es az utak futasidoben ujra ellenorzesre kerulnek a szimbolikus linkek lecserelesenek megakadalyozasara. A korabbi nyitott alapertelmezett viselkedesre tamaszkodo meglevo telepiteseknek fel kell sorolniuk az utjaikat - futtassa a "php artisan volumevault:host-path-allowlist:audit" parancsot a pontosan beallitando ertek megszerzesehez.',
    ],
    'auth_rate_limiting' => [
        'title' => 'Bejelentkezes es jelszo-visszaallitas sebessegkorlatozassal',
        'description' => 'A bejelentkezesi es jelszo-visszaallitasi kereseket mostantol percenkent 5 probalkozasra korlatozzuk, ami lassitja az adminisztratori jelszo elleni nyers ero alapu tamadasokat. A korlat tullepesekor ideiglenes "tul sok keres" valasz erkezik, amely egy perc utan visszaallodik.',
    ],
    'restore_input_hardening' => [
        'title' => 'Szigorubb visszaallitasi es mentesi bemenet-ellenorzes',
        'description' => 'A visszaallitashoz kivalasztott mentesnek mostantol egyeznie kell a cel listajaval, ami blokkolja az olyan utvonalbejaro kulcsokat, mint a "../../etc/passwd". A Docker-kotetnevek biztonsagos karakterekre vannak korlatozva, es a visszaallitasi kicsomagolas korlatozott, igy egy hamisitott archivum nem irhat a celkoteten kivulre.',
    ],
    'sftp_host_key_pinning' => [
        'title' => 'SSH gazdakulcs rogzitese az SFTP celokhoz',
        'description' => 'Az SSH/SFTP celok mostantol rogzithetik a szerver gazdakulcsat a kozbeekelodeses (man-in-the-middle) tamadasok blokkolasahoz. Hasznalja a "Kulcs lekerese a szerverrol" gombot - vagy az uj POST /api/v1/destinations/host-key vegpontot -, hogy megbizzon a bemutatott kulcsban, vagy illesszen be egy gazdakulcsot vagy SHA256 ujjlenyomatot. A kulcs ellenorzese a hitelesito adatok elkuldese elott tortenik, a VolumeVault altal vegzett SFTP-muveletekhez (teszt, listazas, visszaallitas). Uresen hagyva a korabbi viselkedes marad.',
    ],
    'api_token_expiration' => [
        'title' => 'Az API-tokenek mostantol alapertelmezetten lejarnak',
        'description' => 'Az API-tokenek mostantol alapertelmezetten a letrehozasuk utan 60 nappal lejarnak, ami korlatozza egy kiszivargott token hatasat. A meglevo, ennel regebbi tokenek a frissites utan nem mukodnek tovabb, es ujra letre kell hozni oket. Allitsa be a SANCTUM_TOKEN_EXPIRATION erteket (percben) az idoszak modositasahoz, vagy null erteket a lejarat nelkuli tokenek megtartasahoz. A tokenenkenti lejarat csak roviditheti ezt az idoszakot, soha nem hosszabbithatja meg.',
    ],
    'alert_check_isolation' => [
        'title' => 'Ellenallobb riasztasellenorzesek',
        'description' => 'Egy hibara futo riasztasi szabaly mar nem akadalyozza meg a tobbi szabaly ellenorzeset. Minden szabaly mostantol fuggetlenul ertekelodik ki, es a hibak naplozasra kerulnek, igy egyetlen hibas ellenorzes mar nem tudja csendben kikapcsolni a tobbi riasztast.',
    ],
    'restore_volume_cleanup' => [
        'title' => 'Tisztabb ujraprobalkozasok sikertelen visszaallitas utan',
        'description' => 'Ha egy visszaallitas a celkotet letrehozasa utan meghiusul, a VolumeVault mostantol torli a reszben letrehozott kotetet, igy a kovetkezo probalkozas tisztan indul, ahelyett hogy egy "mar letezik" hiba blokkolna.',
    ],
    'schedule_drift_prevention' => [
        'title' => 'Megbizhatobb biztonsagi mentes utemezes',
        'description' => 'Az utemezett biztonsagi mentesek mar nem hagynak ki futtatast, amikor egy worker lemarad. A kovetkezo futtatas mostantol a tervezett idosavhoz igazodik a korabbi futtatas befejezesi ideje helyett, igy a lassu vagy kesleltetett futtatas mar nem csusztathatja el az utemezest.',
    ],
    'destination_usage_efficiency' => [
        'title' => 'Hatekonyabb celhely-tarhasznalat szamitas',
        'description' => 'A biztonsagi mentes celhelyek tarhasznalatat mostantol az objektumok folyamatos bejarasaval szamitja a rendszer, ahelyett hogy a teljes listat betoltene a memoriaba, es az SFTP-kapcsolatokat ezt kovetoen mindig lezarja. A sok mentest tartalmazo celhelyek megbizhatobban merhetok, a memoria kimeritese vagy nyitva hagyott kapcsolatok nelkul.',
    ],
    'run_log_integrity' => [
        'title' => 'Megbizhatobb futtatasi naplok',
        'description' => 'A biztonsagi mentesi es visszaallitasi futtatasok naploi mostantol atomi modon bovulnek, igy az egyidejuleg torteno frissitesek - peldaul egy hibauzenet es egy konteneer-ujrainditasi ertesites - mar nem irjak felul egymast. A naplok merete is korlatozott, a legfrissebb kimenetet megtartva ahelyett, hogy korlatlanul novekedne.',
    ],
    'stale_run_reconciliation' => [
        'title' => 'Megszakitott futasok automatikus helyreallitasa',
        'description' => 'A worker osszeomlasa, idotullepes vagy ujrainditas miatt megszakitott mentesi es visszaallitasi futasok mostantol automatikusan sikertelenkent jelolodnek meg, ahelyett hogy elakadva maradnanak, igy az utemezett mentesek tovabb futnak. A mentes miatt leallitott alkalmazaskontenerek is automatikusan ujraindulnak, ha egy osszeomlas leallitva hagyta oket.',
    ],
    'advanced_alerting' => [
        'title' => 'Fejlett riasztas',
        'description' => 'A VolumeVault figyelheti a mentesi feladatokat elavult mentesek, ismetlodo hibak, hosszan tarto hibaallapotok es szokatlan archivum meretek szempontjabol.',
    ],
    'destination_storage_limit_alerts' => [
        'title' => 'Cel tarhelykorlat riasztasok',
        'description' => 'A mentesi celok most abszolut figyelmeztetesi es kritikus tarhelykuszoboket allithatnak be dedikalt ertesitesi csatornakkal.',
    ],
    'mobile_navigation_redesign' => [
        'title' => 'Tovabbfejlesztett mobil navigacio',
        'description' => 'A mobil fejlec most kompakt menugombot es strukturalt navigacios panelt hasznal, ahelyett hogy minden hivatkozast a fejlecbe zsufolna.',
    ],
    'keyboard_shortcuts' => [
        'title' => 'Billentyuparancsok',
        'description' => 'Desktopon hasznalja a Ctrl+K-t gyors navigaciohoz, a g elotagu parancsokat a nezetekhez es a / jelet a listakereses fokuszalasahoz.',
    ],
    'in_app_update_summaries' => [
        'title' => 'Alkalmazason beluli frissitesi osszegzesek',
        'description' => 'A VolumeVault mostantol meg tudja mutatni a felhasznaloknak, mi valtozott egy alkalmazasfrissites utan.',
    ],
    'available_update_checks' => [
        'title' => 'Elerheto frissitesek ellenorzese',
        'description' => 'A VolumeVault mostantol jelezni tudja, ha ujabb GitHub kiadas erheto el.',
    ],
    'backup_job_detail_deletion' => [
        'title' => 'Torles a feladat reszleteibol',
        'description' => 'A mentesi feladatok mostantol kozvetlenul a reszletek oldalarol torolhetok.',
    ],
    'per_job_notification_channels' => [
        'title' => 'Ertesitesi csatornak feladatonkent',
        'description' => 'A mentesi feladatok mostantol kivalaszthatjak, mely aktiv ertesitesi csatornak kapjak meg az eredmenyeiket.',
    ],
    'notification_defaults_migration' => [
        'title' => 'Alapertelmezett ertesitesek migracioja',
        'description' => 'Ez a kiadas ertesitesi beallitasokat ad a mentesi feladatokhoz es alapertelmezett csatorna kovetest az ertesitesi csatornakhoz.',
    ],
    'host_path_backup_sources' => [
        'title' => 'Host utvonal mentesi forrasok',
        'description' => 'Az adminok kijelolt konyvtarakat menthetnek a Docker hostrol a Docker kotetek mellett.',
    ],
    'host_path_safety_controls' => [
        'title' => 'Host utvonal biztonsagi kontrollok',
        'description' => 'A host utvonalak csak olvashato modon csatolodnak, es korlatozhatok a VOLUMEVAULT_HOST_PATH_ALLOWLIST beallitassal.',
    ],
    'stack_backup_coverage' => [
        'title' => 'Stack mentesi lefedettseg',
        'description' => 'A Docker kotetek Compose vagy Swarm stack szerint csoportosulnak mentesi lefedettsegi allapotokkal.',
    ],
    'backup_archive_metadata' => [
        'title' => 'Mentesi archivum metaadatok',
        'description' => 'A sikeres futasok mostantol megjelenithetik az archivum kulcsokat es mereteket, ha a cel metaadatai elerhetok.',
    ],
    'trusted_proxy_support' => [
        'title' => 'Megbizhato proxy tamogatas',
        'description' => 'A VolumeVault megbizhat a beallitott reverse proxykban, igy a generalt URL-ek a nyilvanos HTTPS semat hasznaljak.',
    ],
    'cleaner_docker_volume_sync' => [
        'title' => 'Tisztabb Docker kotet szinkronizalas',
        'description' => 'A szinkronizalas most eltavolitja az elavult hianyzo kotet rekordokat, amelyekre mar nem hivatkoznak mentesi feladatok.',
    ],
    'list_search_and_filters' => [
        'title' => 'Lista kereses es szurok',
        'description' => 'A kotetek es mentesi feladatok keresest, szuroket es keresheto kotetvalasztot kaptak.',
    ],
    'php_85_container_runtime' => [
        'title' => 'PHP 8.5 kontener runtime',
        'description' => 'A kontener atkerult a ServerSideUp PHP 8.5 runtime-ra felugyelt sor es utemezo szolgaltatasokkal.',
    ],
    'first_stable_release' => [
        'title' => 'Elso stabil kiadas',
        'description' => 'A VolumeVault utemezett mentesekkel, biztonsagos visszaallitasokkal, titkositott celokkal, ertesitesekkel, felhasznalokkal, API tokenekkel es telepitesi mentesekkel indult.',
    ],
    'pagination_with_user_preference' => [
        'title' => 'Lapozott listak oldalankenti beallitassal',
        'description' => 'Az osszes listanezet mostmar tamogatja a lapozast konfiguralhato oldalankenti elemszammal (10, 20, 50, 100, vagy Osszes). Alapertelmezett beallitasat a profil beallitasokban adhatja meg.',
    ],
    'dark_pagination_menu' => [
        'title' => 'Sotet lapozasi menu',
        'description' => 'Az oldalankenti elemszam-valaszto megnyitva is megorzi a sotet megjelenest, jobb kontraszttal a lapozott listakban.',
    ],
    'filter_toolbar_action_buttons' => [
        'title' => 'Megujult elsoleges gombok',
        'description' => 'Az elsoleges muveletgombok most az alkalmazas egeszeben ugyanazt a keretes kek stilust kapjak vilagos es sotet temaban is.',
    ],
    'shareable_filter_urls' => [
        'title' => 'Megoszthato szuro URL-ek',
        'description' => 'A Kotetek, Stackek, Mentesi feladatok es Riasztasok listaszuroi mostmar megjelennek az URL-ben, igy a szurt nezeteket kozvetlenul masolhatja es megoszthatja.',
    ],
    'safer_default_environment_settings' => [
        'title' => 'Biztonsagosabb alapertelmezett kornyezeti beallitasok',
        'description' => 'A .env.example mostantol alapertelmezetten APP_ENV=production es APP_DEBUG=false ertekkel indul az uj telepiteseknel. Emellett utmutatast ad a SESSION_SECURE_COOKIE beallitasahoz is, hogy a HTTPS telepitesek biztonsagos cookie-kat kapcsolhassanak be anelkul, hogy veletlenul elrontanak a csak HTTP-s telepiteseket.',
    ],
    'trusted_proxy_host_hardening' => [
        'title' => 'Megbizhato proxy host kemenyites',
        'description' => 'A megbizhato proxy kezeles most figyelmen kivul hagyja a tovabbitott host es prefix fejleceket, a jelszo-visszaallitasi linkek pedig az APP_URL alapjan keszulnek a mergezett linkek megelozesere.',
    ],
];
