<?php

return [
    'backup_job_sorting' => [
        'title' => 'Tri des tâches de sauvegarde',
        'description' => 'Les tâches de sauvegarde peuvent désormais être triées par nom, prochaine exécution planifiée ou dernière exécution, dans les deux sens. Le tri couvre toute la liste paginée, reste dans l’URL et est également disponible via l’API.',
    ],
    'backup_run_live_updates' => [
        'title' => 'Suivi en direct des sauvegardes',
        'description' => 'Les pages de détail des sauvegardes s’actualisent désormais automatiquement tant qu’elles restent ouvertes, afin de maintenir à jour le statut, la durée, les erreurs, les journaux et les informations de l’archive sans rechargement manuel.',
    ],
    'ssh_private_key_backup_fix' => [
        'title' => 'Sauvegardes SFTP avec une cle privee',
        'description' => 'Les sauvegardes SFTP peuvent desormais utiliser une cle privee SSH televersee sans mot de passe. VolumeVault copie la cle de maniere securisee dans le conteneur de sauvegarde Offen temporaire, puis la supprime apres execution.',
    ],
    'ssh_destination_update_fix' => [
        'title' => 'Mise à jour fiable des destinations SFTP',
        'description' => 'Les destinations SFTP existantes peuvent désormais être mises à jour lorsque leur hôte SSH est un nom d’hôte ou une adresse IP. Les erreurs de validation des paramètres et identifiants SFTP sont aussi affichées à côté des champs concernés.',
    ],
    'docker_tcp_backup_network' => [
        'title' => 'Réseau du socket proxy Docker TCP pour les sauvegardes',
        'description' => 'Les sauvegardes peuvent désormais joindre un socket proxy Docker TCP désigné par son nom de service sur un réseau Docker. Définissez VOLUMEVAULT_DOCKER_NETWORK avec le nom du réseau utilisateur visible par le moteur, et VolumeVault y connectera les conteneurs de sauvegarde Offen temporaires.',
    ],
    'docker_tcp_endpoint' => [
        'title' => 'Prise en charge des endpoints Docker TCP',
        'description' => 'VolumeVault peut désormais utiliser un endpoint Docker TCP, comme un socket proxy devant le même moteur Docker, via DOCKER_HOST=tcp://hote:port. Les commandes Docker et les conteneurs de sauvegarde Offen temporaires utilisent cet endpoint. Les hôtes Docker distants ne sont pas pris en charge, et l’accès TCP reste équivalent à un accès root et doit être limité à un réseau privé de confiance.',
    ],
    'orphaned_backup_recovery' => [
        'title' => 'Récupération fiable des sauvegardes interrompues',
        'description' => 'VolumeVault détecte désormais les conteneurs de sauvegarde orphelins après un redémarrage de l’application, les arrête en toute sécurité, marque leurs exécutions et groupes parents en échec, libère leurs verrous et redémarre les conteneurs applicatifs laissés à l’arrêt. Les jobs et groupes de sauvegarde ne restent plus bloqués indéfiniment après cet incident.',
    ],
    'backup_group_detail_page' => [
        'title' => 'Page de détail d\'un groupe de sauvegarde',
        'description' => 'Les groupes de sauvegarde disposent désormais d\'une page de détail en lecture seule, accessible à tous les utilisateurs, qui affiche la planification du groupe, ses membres, l\'historique agrégé des exécutions et la taille de la dernière sauvegarde réussie. Ouvrir un groupe depuis la liste, depuis le widget des groupes en erreur du tableau de bord ou depuis le lien de retour au groupe d\'une exécution mène désormais à cette page plutôt qu\'au formulaire d\'édition réservé aux administrateurs. Les administrateurs y conservent les actions exécuter, mettre en pause, reprendre, modifier et supprimer, et l\'historique des exécutions du groupe a été déplacé du formulaire d\'édition — désormais un simple formulaire — vers cette page.',
    ],
    'mobile_header_actions' => [
        'title' => 'Actions d\'en-tête mobile plus propres',
        'description' => 'La personnalisation du tableau de bord et les actions de création des pages de liste utilisent désormais de petits boutons icônes à côté du titre sur mobile, tout en conservant les boutons texte sur les écrans plus larges.',
    ],
    'group_backup_size_reporting' => [
        'title' => 'Rapport de taille des sauvegardes groupées',
        'description' => 'Les exécutions de groupe de sauvegarde indiquent désormais la taille totale des archives de leurs membres. Un nouveau widget de tableau de bord optionnel, « Taille de la dernière sauvegarde groupée réussie », peut être activé depuis le panneau Personnaliser du tableau de bord, et la taille agrégée apparaît aussi sur les exécutions de groupe récentes et dans l\'historique des exécutions de chaque groupe. Via l\'API, les exécutions de groupe exposent total_backup_size_bytes et le tableau de bord ajoute une statistique last_successful_group_backup_size. Les tailles peuvent apparaître avec un léger délai après la fin d\'une exécution, car la taille de chaque archive membre est enregistrée de façon asynchrone.',
    ],
    'inclusive_backup_filter' => [
        'title' => 'Filtrage de sauvegarde par inclusion',
        'description' => 'Les tâches de sauvegarde peuvent désormais ne conserver que les dossiers ou fichiers que vous listez, au lieu d\'en exclure seulement certains. Choisissez « Inclure uniquement » dans le formulaire de la tâche et saisissez une liste de chemins séparés par des virgules, relatifs à la racine de la source de sauvegarde (par exemple « Backups, config/app.conf ») ; tout le reste est ignoré, ce qui garde des archives légères. Le mode d\'exclusion par regex avancée reste disponible, et les tâches en inclusion seule peuvent aussi être créées via l\'API.',
    ],
    'grouped_backup_jobs' => [
        'title' => 'Jobs de sauvegarde groupés',
        'description' => 'Sauvegardez plusieurs volumes en une seule opération planifiée : un groupe de sauvegarde possède le planning et les notifications, et les jobs y sont rattachés depuis le formulaire de job de sauvegarde. Le groupe envoie une seule notification de début et une seule notification de succès/échec pour tous ses volumes — idéal pour un unique moniteur de type dead man\'s switch — et vous pouvez choisir si un volume en échec arrête l\'exécution ou si le groupe continue en signalant tout de même l\'échec. Les groupes de sauvegarde sont aussi disponibles via l\'API.',
    ],
    'user_date_format_preference' => [
        'title' => 'Preference de format de date par utilisateur',
        'description' => 'Chaque utilisateur peut maintenant choisir depuis son profil le format regional utilise pour afficher les dates. Par exemple, l\'interface peut rester en anglais tout en passant les dates de l\'ordre americain mois/jour a l\'ordre australien ou britannique jour/mois. Le fuseau horaire de l\'application controle toujours l\'heure locale affichee.',
    ],
    'trusted_2fa_device_password_revocation' => [
        'title' => 'Appareils 2FA fiables revoques lors des changements de mot de passe',
        'description' => 'Modifier ou reinitialiser le mot de passe d\'un utilisateur revoque maintenant ses appareils 2FA fiables. Les enregistrements existants sont effaces pendant la mise a jour, afin que les navigateurs repassent le challenge 2FA avant de pouvoir etre marques comme fiables.',
    ],
    'installation_save_two_factor_reencryption' => [
        'title' => 'Secrets 2FA rechiffres pendant l\'import d\'installation',
        'description' => 'Les imports de sauvegarde d\'installation rechiffrent maintenant les secrets TOTP et les codes de recuperation des utilisateurs avec l\'APP_KEY de la nouvelle instance, comme les destinations et notifications, afin d\'eviter les blocages 2FA apres migration.',
    ],
    'docker_volume_destinations' => [
        'title' => 'Destinations volume Docker',
        'description' => 'Une nouvelle destination « Volume Docker » sauvegarde vers un volume Docker nommé — n\'importe quel pilote, y compris NFS ou d\'autres partages réseau. Déclarez le volume dans votre fichier Compose et VolumeVault le monte par son nom dans le conteneur de sauvegarde temporaire : les sauvegardes, les restaurations, le listing et l\'utilisation du stockage fonctionnent sans partager de chemin hôte avec VolumeVault. Si le volume n\'existe plus, la destination échoue avec une erreur claire au lieu d\'écrire silencieusement dans un volume vide recréé automatiquement.',
    ],
    'webhook_notifications' => [
        'title' => 'Notifications par webhook',
        'description' => 'Un nouveau canal de notification « Webhook » appelle vos propres URL lors des événements de sauvegarde et de restauration. Définissez une URL différente pour chaque action — démarrage, succès et échec — et VolumeVault appelle celle qui correspond lorsqu\'une sauvegarde ou une restauration démarre, réussit ou échoue. Remplissez les champs souhaités ; réglez le niveau du canal sur « Chaque sauvegarde et restauration » pour envoyer aussi les URL de démarrage et de succès. Cela facilite l\'intégration avec les services de supervision et de type « homme mort ». Les canaux de notification peuvent désormais aussi être modifiés via l\'API.',
    ],
    'backup_start_notifications' => [
        'title' => 'Notifications de démarrage des sauvegardes',
        'description' => 'Les sauvegardes envoient désormais une notification au démarrage d\'une exécution, et plus seulement à la fin, comme le font déjà les restaurations. Les messages de démarrage sont envoyés aux canaux réglés pour recevoir chaque exécution ; les canaux « erreurs uniquement » ne sont pas affectés. Les services de supervision peuvent ainsi mesurer la durée d\'une sauvegarde.',
    ],
    'unencrypted_smtp_notifications' => [
        'title' => 'Prise en charge des serveurs SMTP non chiffrés',
        'description' => 'Les canaux de notification SMTP peuvent désormais envoyer vers des serveurs qui n\'utilisent pas de chiffrement. Une nouvelle option « Serveur SMTP non chiffré » dans le formulaire de notification désactive TLS et STARTTLS afin que VolumeVault puisse atteindre un relais SMTP local de confiance qui refuserait sinon la connexion avec une erreur « unencrypted connection ». L\'envoi chiffré reste l\'option par défaut et est inchangé.',
    ],
    'optional_two_factor_auth' => [
        'title' => 'Authentification à deux facteurs facultative',
        'description' => 'Vous pouvez désormais protéger votre compte avec une authentification à deux facteurs facultative reposant sur un mot de passe à usage unique basé sur le temps (TOTP). Activez-la depuis votre profil en scannant un QR code avec une application d\'authentification comme Google Authenticator ou Authy, puis confirmez avec un code généré. Une fois activée, la connexion demande un code à six chiffres juste après le mot de passe. Une série de codes de récupération à usage unique est fournie au cas où vous perdriez l\'accès à votre application d\'authentification, et les administrateurs peuvent réinitialiser l\'authentification à deux facteurs de n\'importe quel utilisateur depuis la page Utilisateurs. Sur l\'écran du code, vous pouvez aussi marquer un navigateur comme fiable pour sauter le code — jamais le mot de passe — pendant 30 jours.',
    ],
    'backup_initiator_tracking' => [
        'title' => 'Suivi de l\'auteur de chaque sauvegarde',
        'description' => 'Les sauvegardes enregistrent désormais quel utilisateur les a lancées. Les exécutions manuelles (depuis l\'interface ou l\'API) et les sauvegardes de pile complète sont attribuées à l\'utilisateur connecté, la sauvegarde de sécurité réalisée avant une restauration sur place hérite de l\'utilisateur ayant lancé la restauration, et les exécutions planifiées restent sans auteur. L\'initiateur apparaît dans l\'historique des exécutions de la tâche et sur le détail de la sauvegarde, est inclus dans les notifications de sauvegarde et est disponible via un nouveau jeton {{ user }} pour les modèles de notification personnalisés.',
    ],
    'restore_history_on_job' => [
        'title' => 'Historique des restaurations par tâche',
        'description' => 'La page de chaque tâche de sauvegarde répartit désormais son historique en deux onglets : « Historique des sauvegardes » liste les sauvegardes de la tâche, et un nouvel onglet « Historique des restaurations » liste chaque restauration effectuée pour cette tâche — avec le statut, le mode, les volumes source et cible, la date de début, la durée et un lien vers les détails complets de la restauration. Les deux onglets sont désormais paginés, l\'historique n\'est donc plus limité à 50 lignes.',
    ],
    'restore_in_place_modes' => [
        'title' => 'Modes de restauration sur place',
        'description' => 'L\'assistant de restauration peut désormais restaurer une sauvegarde directement dans son volume Docker source. « Restaurer sur place » vide et remplace le volume après avoir retapé son nom pour confirmer ; « Restauration sur place sécurisée » arrête en plus les conteneurs utilisant le volume pendant la restauration et les redémarre ensuite. Le sélecteur de sauvegarde se limite par défaut aux archives de la tâche sélectionnée, ajoute des filtres par nom et par date, signale l\'archive la plus récente, et un bouton « Restaurer cette sauvegarde » ouvre l\'assistant directement depuis une exécution de sauvegarde. Les deux modes sur place peuvent aussi, en option, sauvegarder le contenu actuel du volume avant de l\'écraser ; la restauration est annulée si cette sauvegarde de sécurité échoue.',
    ],
    'restore_notifications' => [
        'title' => 'Notifications de restauration',
        'description' => 'VolumeVault vous prévient désormais lorsqu\'une restauration démarre, réussit ou échoue, en réutilisant les canaux de notification déjà configurés sur la tâche de sauvegarde. Les messages de démarrage et de réussite sont envoyés aux canaux réglés sur « chaque exécution », tandis que les échecs atteignent tous les canaux. Un problème de notification n\'interrompt jamais la restauration elle-même.',
    ],
    'stack_bulk_backup' => [
        'title' => 'Sauvegarder une stack entière en une fois',
        'description' => 'La page Stacks permet désormais de sauvegarder une stack entière en un clic. Les stacks entièrement configurées disposent d\'un bouton « Exécuter toutes les tâches » qui lance une sauvegarde pour chaque tâche ; les stacks avec des volumes non couverts proposent une fenêtre « Sauvegarder la stack » qui crée une tâche de sauvegarde (quotidienne ou personnalisée) pour chaque volume qui n\'en a pas, puis lance une sauvegarde pour toute la stack. La même opération est disponible via l\'API (POST /stacks/backup).',
    ],
    'busybox_restore_tar_compat' => [
        'title' => 'Extraction de restauration compatible',
        'description' => 'Les restaurations vers un nouveau volume Docker n\'envoient plus d\'options tar propres à GNU et streament maintenant l\'archive dans le conteneur de restauration, afin que l\'extraction fonctionne avec BusyBox tar et les déploiements conteneurisés dont le stockage vit dans un volume Docker.',
    ],
    'stable_stack_volume_search' => [
        'title' => 'Recherche stacks et volumes stabilisée',
        'description' => 'La saisie dans la recherche des stacks ou volumes garde maintenant le filtre actif au lieu de revenir à l\'état par défaut après la synchronisation de l\'URL.',
    ],
    'backup_archive_name_templates' => [
        'title' => 'Noms personnalisés pour les archives de sauvegarde',
        'description' => 'Les jobs de sauvegarde peuvent maintenant définir un modèle de nom d’archive avec des variables comme {name}, {source}, {id}, {year}, {month}, {day} et {time}. Les jobs existants gardent l’ancien format volumevault-source-run-id tant qu’aucun modèle n’est configuré, et le formulaire avertit lorsqu’un modèle risque d’écraser d’anciennes archives.',
    ],
    'russian_translation_revisions' => [
        'title' => 'Textes russes de l’interface affinés',
        'description' => 'Les traductions russes de l’interface ont reçu des corrections de formulation supplémentaires pour améliorer leur cohérence et leur lisibilité. Merci à @artyomboyko pour cette contribution de traduction.',
    ],
    'complete_i18n_coverage' => [
        'title' => 'Traductions de l\'interface plus complètes',
        'description' => 'De nombreux textes de l\'interface encore affichés en anglais — notamment les pages des jetons API et de sauvegarde de l\'installation — sont désormais entièrement traduits. Les neuf langues ont été synchronisées et les traductions manquantes complétées, afin que les utilisateurs non anglophones ne voient plus de libellés, boutons et messages non traduits.',
    ],
    'reliable_run_logs' => [
        'title' => 'Journaux d\'exécution plus fiables',
        'description' => 'Les journaux des sauvegardes et des restaurations sont désormais ajoutés de manière atomique : deux écritures simultanées (par exemple le gestionnaire d\'échec d\'un job qui se déclenche pendant qu\'une exécution se termine) ne peuvent plus s\'écraser mutuellement. La troncature des journaux respecte aussi l\'UTF-8, évitant des journaux corrompus dans l\'affichage des détails d\'exécution.',
    ],
    'stale_run_liveness_reconcile' => [
        'title' => 'Récupération plus rapide des sauvegardes interrompues',
        'description' => 'Les exécutions bloquées après un crash, un délai dépassé ou un redémarrage du worker sont désormais récupérées beaucoup plus vite. Le réconciliateur vérifie si le conteneur de sauvegarde est toujours actif au lieu d\'attendre un délai fixe : les exécutions mortes échouent en quelques minutes, tandis que les sauvegardes réellement longues sont préservées. La récupération s\'exécute aussi automatiquement au démarrage du conteneur et redémarre les conteneurs applicatifs laissés arrêtés.',
    ],
    'local_destination_listing_cap' => [
        'title' => 'Listing borné des destinations locales',
        'description' => 'Le listing des sauvegardes d\'une destination sur système de fichiers local est désormais limité à 1000 entrées, comme les autres fournisseurs de stockage, afin qu\'un répertoire d\'archives très volumineux ne charge plus toute son arborescence dans une seule réponse.',
    ],
    'per_job_schedule_timezone' => [
        'title' => 'Fuseau horaire par tâche',
        'description' => 'Chaque tâche de sauvegarde peut désormais définir son propre fuseau horaire : une planification comme « tous les jours à 02:00 » s\'exécute à 02:00 heure locale plutôt que dans le fuseau horaire global de l\'application. Laissez « Valeur par défaut de l\'application » pour conserver le comportement précédent.',
    ],
    'http_security_headers' => [
        'title' => 'En-têtes HTTP de sécurité',
        'description' => 'Les réponses incluent désormais des en-têtes de sécurité en défense en profondeur (X-Frame-Options, X-Content-Type-Options et Referrer-Policy), ainsi que HSTS lorsque le service est servi en HTTPS. Les déploiements en HTTP simple et sur réseau local ne sont pas affectés : aucune requête n\'est jamais forcée du HTTP vers le HTTPS.',
    ],
    'local_destination_path_error_feedback' => [
        'title' => 'Erreurs de chemin plus claires pour les destinations locales',
        'description' => 'La création d\'une destination de type système de fichiers local affiche désormais les erreurs de validation du chemin directement dans le formulaire — par exemple un chemin bloqué par la liste d\'autorisation des chemins hôtes — au lieu de revenir silencieusement sur la page de création.',
    ],
    'russian_translation_consistency' => [
        'title' => 'Traductions russes harmonisées',
        'description' => 'Les textes russes de l\'interface ont été mis à jour pour plus de cohérence, et le glossaire des traducteurs russes a été déplacé hors des fichiers de langue fournis vers une documentation dédiée du projet. Les ressources de langue embarquées restent ainsi plus propres tout en conservant le glossaire pour les contributeurs. Merci à @artyomboyko pour cette contribution de traduction.',
    ],
    'customizable_dashboard' => [
        'title' => 'Tableau de bord personnalisable',
        'description' => 'Vous pouvez desormais choisir quels widgets afficher sur le tableau de bord et dans quel ordre. Cliquez sur « Personnaliser » pour masquer ou afficher n\'importe quelle carte de statistique ou section, glissez-les pour les reordonner, puis cliquez sur « Terminer » pour enregistrer. Chaque utilisateur conserve sa propre disposition, et « Reinitialiser » restaure l\'agencement d\'origine.',
    ],
    'self_container_backup_guard' => [
        'title' => 'VolumeVault n\'arrete plus son propre conteneur pendant une sauvegarde',
        'description' => 'Lorsqu\'un job de sauvegarde a « arreter les conteneurs avant la sauvegarde » active et cible un volume que le conteneur VolumeVault monte lui aussi, VolumeVault n\'arrete plus son propre conteneur - ce qui aurait interrompu la sauvegarde en cours. Le conteneur est detecte automatiquement via son nom d\'hote et son cgroup ; definissez VOLUMEVAULT_CONTAINER_ID ou VOLUMEVAULT_CONTAINER_NAME si la detection automatique n\'est pas fiable (nom d\'hote personnalise ou reseau hote).',
    ],
    'host_path_stop_containers' => [
        'title' => 'Arret de conteneurs choisis pour les sauvegardes de chemin hote',
        'description' => 'Les jobs de sauvegarde de type chemin hote peuvent desormais arreter des conteneurs avant la sauvegarde puis les redemarrer, comme le faisaient deja les jobs de volume Docker. Comme un chemin hote ne peut pas etre relie automatiquement a des conteneurs, vous les choisissez par nom dans le formulaire du job. La selection est enregistree par nom, elle survit donc a la recreation des conteneurs ; les conteneurs qui n\'existent plus ou deja arretes sont ignores, et VolumeVault n\'arrete jamais son propre conteneur.',
    ],
    'ssrf_destination_guard' => [
        'title' => 'Les destinations de sauvegarde en IP privee sont desormais protegees (SSRF)',
        'description' => 'VolumeVault refuse desormais par defaut de se connecter a une destination de sauvegarde dont l\'hote se resout en une adresse privee, de bouclage (loopback) ou lien-local (y compris le point de terminaison de metadonnees cloud 169.254.169.254). Cela ne concerne que les destinations sur IP privee, comme un NAS local ou un S3/MinIO auto-heberge - les destinations cloud accessibles par une URL publique ne sont pas affectees. Les sauvegardes planifiees continuent de s\'executer, mais le test de destination, la restauration (listing et telechargement) et l\'alerte de quota de stockage sont bloques tant que vous n\'avez pas liste la plage de la destination dans VOLUMEVAULT_SSRF_ALLOWED_IPS (CIDR separes par des virgules, par ex. 192.168.1.0/24). Les canaux de notification ne sont pas concernes.',
    ],
    'host_path_allowlist_fail_closed' => [
        'title' => 'La liste d\'autorisation des chemins hote est desormais fail-closed',
        'description' => 'VOLUMEVAULT_HOST_PATH_ALLOWLIST refuse desormais par defaut : lorsqu\'elle est vide, les sources de sauvegarde par chemin hote et les destinations locales sont refusees au lieu d\'autoriser n\'importe quel chemin. La meme liste protege maintenant aussi les destinations locales, et les chemins sont reverifies a l\'execution pour bloquer les substitutions de liens symboliques. Les installations existantes qui s\'appuyaient sur l\'ancien comportement ouvert doivent lister leurs chemins - executez "php artisan volumevault:host-path-allowlist:audit" pour obtenir la valeur exacte a definir.',
    ],
    'auth_rate_limiting' => [
        'title' => 'Connexion et reinitialisation de mot de passe limitees',
        'description' => 'Les requetes de connexion et de reinitialisation de mot de passe sont desormais limitees a 5 tentatives par minute, ce qui ralentit les attaques par force brute sur le mot de passe administrateur. Au-dela de la limite, une reponse temporaire "trop de requetes" est renvoyee et se reinitialise au bout d\'une minute.',
    ],
    'restore_input_hardening' => [
        'title' => 'Validation renforcee des entrees de restauration et de sauvegarde',
        'description' => 'La sauvegarde selectionnee pour une restauration doit desormais correspondre au listing de la destination, ce qui bloque les cles de traversee de chemin comme "../../etc/passwd". Les noms de volumes Docker sont limites a des caracteres surs, et l\'extraction de restauration est confinee afin qu\'une archive falsifiee ne puisse pas ecrire en dehors du volume cible.',
    ],
    'sftp_host_key_pinning' => [
        'title' => 'Epinglage de la cle d\'hote SSH pour les destinations SFTP',
        'description' => 'Les destinations SSH/SFTP peuvent desormais epingler la cle d\'hote du serveur pour bloquer les attaques de l\'homme du milieu. Utilisez le bouton "Recuperer la cle du serveur" - ou le nouvel endpoint POST /api/v1/destinations/host-key - pour approuver la cle presentee, ou collez une cle d\'hote ou une empreinte SHA256. La cle est verifiee avant tout envoi d\'identifiants, pour les operations SFTP propres a VolumeVault (test, listing, restauration). La laisser vide conserve le comportement precedent.',
    ],
    'api_token_expiration' => [
        'title' => 'Les tokens API expirent desormais par defaut',
        'description' => 'Les tokens API expirent desormais 60 jours apres leur creation par defaut, ce qui limite l\'impact d\'un token divulgue. Les tokens existants plus anciens cessent de fonctionner apres la mise a jour et doivent etre recrees. Definissez SANCTUM_TOKEN_EXPIRATION (en minutes) pour modifier la duree, ou null pour conserver des tokens sans expiration. Une expiration definie par token ne peut que raccourcir cette duree, jamais l\'allonger.',
    ],
    'alert_check_isolation' => [
        'title' => 'Verifications d\'alerte plus robustes',
        'description' => 'Une regle d\'alerte qui echoue n\'empeche plus la verification des autres regles. Chaque regle est desormais evaluee independamment et les echecs sont journalises, de sorte qu\'une seule verification defaillante ne peut plus desactiver silencieusement vos autres alertes.',
    ],
    'restore_volume_cleanup' => [
        'title' => 'Reprises plus propres apres une restauration echouee',
        'description' => 'Lorsqu\'une restauration echoue apres avoir cree son volume cible, VolumeVault supprime desormais le volume partiellement cree afin que la nouvelle tentative reparte propre, au lieu d\'etre bloquee par une erreur "existe deja".',
    ],
    'schedule_drift_prevention' => [
        'title' => 'Planification des sauvegardes plus fiable',
        'description' => 'Les sauvegardes planifiees ne sautent plus d\'execution lorsqu\'un worker prend du retard. La prochaine execution est desormais ancree sur le creneau prevu plutot que sur l\'heure de fin du run precedent, ce qui evite toute derive du planning.',
    ],
    'destination_usage_efficiency' => [
        'title' => 'Calcul de l\'utilisation du stockage plus efficace',
        'description' => 'L\'utilisation du stockage des destinations de sauvegarde est desormais calculee en parcourant les objets en flux plutot qu\'en chargeant toute la liste en memoire, et les connexions SFTP sont toujours fermees ensuite. Les destinations contenant de nombreuses sauvegardes sont mesurees de maniere plus fiable, sans saturer la memoire ni laisser de connexions ouvertes.',
    ],
    'run_log_integrity' => [
        'title' => 'Journaux d\'execution plus fiables',
        'description' => 'Les journaux des executions de sauvegarde et de restauration sont desormais ajoutes de maniere atomique : les mises a jour concurrentes - par exemple un message d\'erreur et une notification de redemarrage de conteneur - ne s\'ecrasent plus mutuellement. Leur taille est aussi plafonnee, en conservant la sortie la plus recente plutot que de grossir sans limite.',
    ],
    'stale_run_reconciliation' => [
        'title' => 'Recuperation automatique des runs interrompus',
        'description' => 'Les sauvegardes et restaurations interrompues par un crash, un timeout ou un redemarrage du worker sont maintenant marquees en echec automatiquement au lieu de rester bloquees, pour que les sauvegardes planifiees continuent de tourner. Les conteneurs applicatifs arretes pour une sauvegarde sont aussi redemarres automatiquement si un crash les avait laisses eteints.',
    ],
    'advanced_alerting' => [
        'title' => 'Alerting avance',
        'description' => 'VolumeVault peut surveiller les jobs de backup pour detecter les sauvegardes trop anciennes, les echecs repetes, les erreurs prolongees et les tailles d archives inhabituelles.',
    ],
    'destination_storage_limit_alerts' => [
        'title' => 'Alertes de limite de stockage',
        'description' => 'Les destinations peuvent maintenant definir des seuils absolus warning et critiques avec des canaux de notification dedies.',
    ],
    'mobile_navigation_redesign' => [
        'title' => 'Navigation mobile amelioree',
        'description' => "L'en-tete mobile utilise maintenant un bouton de menu compact et un panneau de navigation structure au lieu d'empiler tous les liens dans l'en-tete.",
    ],
    'keyboard_shortcuts' => [
        'title' => 'Raccourcis clavier',
        'description' => 'Sur desktop, utilisez Ctrl+K pour la navigation rapide, les raccourcis commencant par g pour les vues et / pour cibler la recherche des listes.',
    ],
    'in_app_update_summaries' => [
        'title' => 'Resumes de mise a jour integres',
        'description' => "VolumeVault peut maintenant montrer aux utilisateurs ce qui a change apres une mise a jour de l'application.",
    ],
    'available_update_checks' => [
        'title' => 'Detection des mises a jour disponibles',
        'description' => 'VolumeVault peut maintenant indiquer quand une nouvelle version GitHub est disponible.',
    ],
    'backup_job_detail_deletion' => [
        'title' => 'Suppression depuis le detail de tache',
        'description' => 'Les taches de sauvegarde peuvent maintenant etre supprimees directement depuis leur page detail.',
    ],
    'per_job_notification_channels' => [
        'title' => 'Canaux de notification par tache',
        'description' => 'Les taches de sauvegarde peuvent maintenant choisir quels canaux actifs recoivent leurs resultats.',
    ],
    'notification_defaults_migration' => [
        'title' => 'Migration des notifications par defaut',
        'description' => 'Cette version ajoute des parametres de notification aux taches et le suivi du canal par defaut aux canaux de notification.',
    ],
    'host_path_backup_sources' => [
        'title' => 'Sources chemin hote',
        'description' => "Les admins peuvent sauvegarder des dossiers choisis de l'hote Docker en plus des volumes Docker.",
    ],
    'host_path_safety_controls' => [
        'title' => 'Controles de securite des chemins hote',
        'description' => 'Les chemins hote sont montes en lecture seule et peuvent etre limites avec VOLUMEVAULT_HOST_PATH_ALLOWLIST.',
    ],
    'stack_backup_coverage' => [
        'title' => 'Couverture de sauvegarde par stack',
        'description' => 'Les volumes Docker sont regroupes par stack Compose ou Swarm avec leur etat de couverture de sauvegarde.',
    ],
    'backup_archive_metadata' => [
        'title' => 'Metadonnees des archives',
        'description' => "Les executions reussies peuvent maintenant afficher les cles et tailles d'archive quand la destination fournit ces metadonnees.",
    ],
    'trusted_proxy_support' => [
        'title' => 'Support des proxys de confiance',
        'description' => 'VolumeVault peut faire confiance aux proxys inverses configures pour generer des URL avec le schema HTTPS public.',
    ],
    'cleaner_docker_volume_sync' => [
        'title' => 'Synchronisation des volumes plus propre',
        'description' => 'La synchronisation supprime maintenant les anciens volumes manquants qui ne sont plus references par des taches.',
    ],
    'list_search_and_filters' => [
        'title' => 'Recherche et filtres dans les listes',
        'description' => 'Les volumes et taches de sauvegarde ont maintenant une recherche, des filtres et un selecteur de volume recherchable.',
    ],
    'php_85_container_runtime' => [
        'title' => 'Runtime conteneur PHP 8.5',
        'description' => 'Le conteneur utilise maintenant le runtime ServerSideUp PHP 8.5 avec file et planificateur supervises.',
    ],
    'first_stable_release' => [
        'title' => 'Premiere version stable',
        'description' => "VolumeVault a ete lance avec sauvegardes planifiees, restaurations sures, destinations chiffrees, notifications, utilisateurs, jetons API et sauvegardes d'installation.",
    ],
    'pagination_with_user_preference' => [
        'title' => 'Listes paginees avec preference par page',
        'description' => "Toutes les vues listees supportent maintenant la pagination avec un nombre d'elements par page configurable (10, 20, 50, 100, ou Tous). Vous pouvez definir votre valeur par defaut dans les parametres du profil.",
    ],
    'dark_pagination_menu' => [
        'title' => 'Menu de pagination en theme sombre',
        'description' => "Le menu du nombre d'elements par page conserve maintenant une palette adaptee au theme sombre lorsqu'il est ouvert, avec un meilleur contraste dans les vues paginees.",
    ],
    'filter_toolbar_action_buttons' => [
        'title' => 'Boutons primaires harmonises',
        'description' => 'Les boutons d action principaux partagent maintenant le meme style souligne bleu dans toute l application, en theme clair comme en theme sombre.',
    ],
    'shareable_filter_urls' => [
        'title' => 'URLs de filtres partageables',
        'description' => 'Les filtres des listes Volumes, Stacks, Taches de sauvegarde et Alertes sont maintenant refletes dans l URL, permettant de copier et partager des vues filtrees directement.',
    ],
    'safer_default_environment_settings' => [
        'title' => 'Parametres d environnement par defaut plus surs',
        'description' => '.env.example utilise maintenant APP_ENV=production et APP_DEBUG=false pour les nouveaux deploiements. Une indication pour SESSION_SECURE_COOKIE est egalement ajoutee afin que les deploiements HTTPS puissent activer des cookies securises sans casser par inadvertance les installations en HTTP seul.',
    ],
    'trusted_proxy_host_hardening' => [
        'title' => "Durcissement de l'hote proxy de confiance",
        'description' => 'La gestion des proxys de confiance ignore maintenant les en-tetes host et prefix transmis, et les liens de reinitialisation de mot de passe sont generes depuis APP_URL pour eviter les liens empoisonnes.',
    ],
];
