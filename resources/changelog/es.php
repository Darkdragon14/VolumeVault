<?php

return [
    'restore_volume_ownership' => [
        'title' => 'Verificar la propiedad de los nuevos volúmenes de restauración',
        'description' => 'Las restauraciones en volúmenes nuevos fijan el destino mediante un contenedor auxiliar y verifican su etiqueta de propiedad aleatoria antes de iniciar ese mismo contenedor. Los volúmenes de destino tras un fallo nunca se eliminan automáticamente. Inspecciónelos y elimínelos manualmente si procede, o reintente con otro nombre; los registros explican los pasos siguientes.',
    ],
    'cross_host_archive_relay' => [
        'title' => 'Restaurar archivos locales en otro host',
        'description' => 'El relevo central cifrado transfiere archivos a un volumen nuevo en otro host, incluido el modo híbrido local/remoto. Los lados remotos requieren archive-relay-v1. Las listas y los recibos vigentes siguen vinculados al propietario; los detalles muestran progreso, caducidad, errores y limpieza. Se conserva el original. Valores predeterminados: 10 GiB por archivo, 50 GiB de almacenamiento y 24 horas. Quedan pendientes las vistas unificadas y la auditoría de flujos secundarios.',
    ],
    'agent_destination_operations' => [
        'title' => 'Probar y explorar destinos mediante agentes',
        'description' => 'Elige un host para probar destinos guardados, medir almacenamiento y explorar archivos paginados con progreso, errores y vigencia visibles. Los agentes requieren destination-v1; el almacenamiento compartido sigue usando el host central por defecto y el local permanece con su propietario. Las restauraciones vinculan las claves a comprobantes recientes del host de destino, mientras los agentes antiguos conservan la restauración desde el historial.',
    ],
    'remote_docker_label_backups' => [
        'title' => 'Copias por etiquetas Docker en hosts remotos',
        'description' => 'Configura los valores predeterminados por host desde los ajustes o la API, también en modo solo orquestador. Los agentes docker-labels-v1 procesan las declaraciones con inventarios completos y destinos activos compartidos o del mismo host. Los inventarios antiguos o incompletos conservan los trabajos existentes y muestran errores de sincronización.',
    ],
    'remote_backup_groups' => [
        'title' => 'Grupos de copias en hosts locales y remotos',
        'description' => 'Los grupos coordinan miembros locales, remotos y mixtos de forma secuencial con un resultado global. La coordinación duradera conserva miembros, fuentes y política de fallos; el trabajo asignado termina durante el mantenimiento mientras el siguiente miembro espera. Los controles funcionan en modo orquestador y las vistas identifican los hosts. Cada miembro completa su propio ciclo de parada, copia y reinicio; no es una instantánea coherente entre hosts. Se admiten los agentes backup-v1 existentes.',
    ],
    'agent_execution_safety_and_identity' => [
        'title' => 'Ejecución segura de agentes y selección precisa de recursos',
        'description' => 'Las comprobaciones S3 de los agentes rechazan endpoints contradictorios. Los contenedores auxiliares deben eliminarse antes de reiniciar aplicaciones, incluso tras perder la conexión Docker. Los accesos a volúmenes conservan su host, la selección del archivo transmite su contexto histórico y las restauraciones no heredan cambios posteriores del tipo de origen del trabajo.',
    ],
    'remote_agent_execution' => [
        'title' => 'Copias y restauraciones mediante agentes Docker',
        'description' => 'Los agentes compatibles ejecutan copias y restauraciones con comandos cifrados persistentes y diarios privados de recuperación. Un destino de red compartido permite restaurar en otro host sin socket Docker en el orquestador. Los resultados y metadatos de copias de seguridad previas sobreviven a las reconexiones sin repetir trabajo completado.',
    ],
    'remote_host_workflows' => [
        'title' => 'Configuración de copias y restauraciones por host',
        'description' => 'Los trabajos pueden usar agentes registrados, incluso sin conexión y en modo solo orquestador. El almacenamiento de red compartido permite restaurar en otro host. El inventario, las rutas y los destinos locales se limitan a su host.',
    ],
    'docker_host_metrics' => [
        'title' => 'Información útil de hosts locales y agentes',
        'description' => 'Las tarjetas muestran la versión del motor Docker y el número de contenedores locales sincronizado. La tarjeta local oculta el último contacto reservado a agentes y aclara la última sincronización de volúmenes. Las versiones de VolumeVault, incluidas las de desarrollo, se identifican claramente. El modo solo orquestador oculta las métricas Docker. Los roles aparecen en las tarjetas de hosts, no bajo el logotipo de la aplicación.',
    ],
    'maintenance_dispatch_recovery' => [
        'title' => 'Reanudar el trabajo en cola tras el mantenimiento',
        'description' => 'Los grupos en cola siguen siendo publicables tras un mantenimiento prolongado, aunque la reconciliación preceda al envío. Al reanudar un host se renuevan los intentos de entrega de sus ejecuciones principales pendientes, sin modificar el historial ni las operaciones internas.',
    ],
    'agent_deployment_lifecycle' => [
        'title' => 'Agentes dedicados, modo orquestador y actualizaciones manuales',
        'description' => 'Los agentes disponen de una imagen PHP CLI dedicada. El modo solo orquestador funciona sin daemon Docker, incluidas las notificaciones. La vista de hosts muestra roles, versiones y compatibilidad, con mantenimiento persistente y una guía de actualización manual que conserva la identidad. La actualización remota automática no está habilitada.',
    ],
    'restore_target_host_recovery' => [
        'title' => 'Recuperar restauraciones según su host de destino',
        'description' => 'Las restauraciones interrumpidas hacia el host local ahora se recuperan aunque su archivo proceda de otro host. La recuperación también gestiona los intentos agotados de publicación en cola y los contenedores que quedaron detenidos, sin modificar destinos remotos.',
    ],
    'agent_runtime_resilience' => [
        'title' => 'Heartbeats y recuperación fiables de los agentes',
        'description' => 'Los agentes detrás del mismo NAT ya no comparten cuotas ni bloquean el registro. Las recopilaciones largas del inventario Docker siguen enviando heartbeats y tienen un límite de duración. Un fallo al guardar el estado detiene el agente para que Docker lo reinicie con su identidad persistida.',
    ],
    'agent_enrollment_inventory' => [
        'title' => 'Registro seguro de agentes e inventario Docker',
        'description' => 'Los administradores pueden registrar hosts con un comando Docker generado, consultar su conexión y los recuentos de inventario, renovar el registro y revocar el acceso. Los agentes PHP CLI usan TLS verificado, identidades persistentes y conexiones salientes. Los certificados integrados se renuevan automáticamente.',
    ],
    'docker_host_attribution' => [
        'title' => 'Asignación explícita al host Docker local',
        'description' => 'La migración asigna automáticamente los volúmenes, tareas, historiales y destinos locales existentes al host Docker local. No es necesario cambiar la configuración. Las identidades por host preparan la ejecución multihost.',
    ],
    'dropbox_safety_backup_validation' => [
        'title' => 'Rechazo anticipado de copias de seguridad previas en Dropbox',
        'description' => 'Las restauraciones en el mismo volumen rechazan la copia de seguridad previa solicitada antes de entrar en cola si el destino actual es Dropbox. El formulario explica la limitación y conserva tu elección hasta que la cambies explícitamente.',
    ],
    'dropbox_restore_identity_and_archive_ordering' => [
        'title' => 'Restauraciones de Dropbox más seguras y orden de archivos corregido',
        'description' => 'Las subidas de Offen no proporcionan a VolumeVault un ID exacto y verificable del archivo de Dropbox. El procesamiento asíncrono de metadatos nunca determina la identidad por el nombre del archivo, ya que este podría haber sido reemplazado. Incluso las nuevas copias de seguridad de Dropbox completadas correctamente sin un ID de archivo demostrado siguen sin poder verificarse para su restauración desde el historial de ejecuciones. Los archivos en destinos de tipo volumen Docker ahora se muestran correctamente del más reciente al más antiguo.',
    ],
    'secure_local_archive_reads' => [
        'title' => 'Lectura más segura de archivos locales',
        'description' => 'Las descargas de archivos locales para restauraciones ahora fijan la raíz del archivo, rechazan el recorrido mediante enlaces simbólicos y publican los archivos completos de forma atómica sin eliminar los destinos existentes si se produce un fallo.',
    ],
    'durable_queued_run_dispatch' => [
        'title' => 'Despacho fiable de ejecuciones en cola',
        'description' => 'Las copias de seguridad, restauraciones y grupos en cola ahora usan una concesión de despacho persistente y se vuelven a despachar automáticamente si se pierde la entrega inicial, sin duplicar una ejecución ya reclamada por un trabajador. Se requiere una conexión de cola asíncrona; el controlador sync se rechaza porque no puede volver a poner en cola de forma duradera los trabajos que esperan un bloqueo.',
    ],
    'durable_terminal_notifications' => [
        'title' => 'Notificaciones de finalizacion duraderas',
        'description' => 'Las notificaciones finales de copias, restauraciones y grupos ahora se reintentan por canal, evitando perder resultados por fallos temporales.',
    ],
    'backup_run_snapshots' => [
        'title' => 'Historial fiable de ejecuciones de copia',
        'description' => 'Las ejecuciones conservan ahora su origen, destino y nombre de archivo originales, para que la ejecucion, los metadatos, el historial y las restauraciones desde ejecuciones anteriores sigan siendo correctos tras editar la tarea.',
    ],
    'backup_job_sorting' => [
        'title' => 'Tareas de copia ordenables',
        'description' => 'Las tareas de copia de seguridad ahora se pueden ordenar en ambos sentidos por nombre, próxima ejecución programada o última ejecución. El orden se aplica a toda la lista paginada, permanece en la URL y también está disponible mediante la API.',
    ],
    'backup_run_live_updates' => [
        'title' => 'Actualizaciones en directo de las copias',
        'description' => 'Las páginas de detalle de las ejecuciones de copia ahora se actualizan automáticamente mientras permanecen abiertas, manteniendo al día el estado, los tiempos, los errores, los registros y los datos del archivo sin recargar la página manualmente.',
    ],
    'ssh_private_key_backup_fix' => [
        'title' => 'Copias SFTP con claves privadas',
        'description' => 'Las copias SFTP ahora pueden autenticarse sin contrasena mediante una clave privada SSH cargada. VolumeVault copia la clave de forma segura en el contenedor temporal de Offen y la elimina despues de la ejecucion.',
    ],
    'ssh_destination_update_fix' => [
        'title' => 'Actualizaciones fiables de destinos SFTP',
        'description' => 'Ahora se pueden actualizar los destinos SFTP existentes cuando su host SSH es un nombre de host o una dirección IP. Los errores de validación de la configuración y las credenciales SFTP también se muestran junto a los campos afectados.',
    ],
    'docker_tcp_backup_network' => [
        'title' => 'Red del proxy Docker TCP para copias de seguridad',
        'description' => 'Las copias de seguridad ahora pueden acceder a un proxy de socket Docker TCP mediante su nombre de servicio en la red Docker. Define VOLUMEVAULT_DOCKER_NETWORK con la red personalizada visible para el motor y VolumeVault conectará a ella los contenedores temporales de Offen.',
    ],
    'docker_label_backups' => [
        'title' => 'Docker label managed backups',
        'description' => 'Running containers can now declare scheduled volume backups through Docker labels. Administrators configure trusted defaults in VolumeVault, while generated jobs stay read-only and are safely disabled when their declaration disappears or conflicts.',
    ],
    'docker_tcp_endpoint' => [
        'title' => 'Compatibilidad con endpoints Docker TCP',
        'description' => 'VolumeVault ahora puede usar un endpoint Docker TCP, como un socket proxy delante del mismo motor Docker, mediante DOCKER_HOST=tcp://host:port. Los comandos Docker y los contenedores temporales de copia Offen usan el endpoint configurado. Los hosts Docker remotos no son compatibles; el acceso TCP equivale a root y debe limitarse a una red privada de confianza.',
    ],
    'orphaned_backup_recovery' => [
        'title' => 'Recuperación fiable de copias interrumpidas',
        'description' => 'VolumeVault ahora detecta contenedores de copia huérfanos tras un reinicio, los detiene de forma segura, marca sus ejecuciones y grupos como fallidos, libera sus bloqueos y reinicia los contenedores de aplicaciones que quedaron detenidos. Los trabajos y grupos de copia ya no quedan bloqueados indefinidamente después de este fallo.',
    ],
    'backup_group_detail_page' => [
        'title' => 'Página de detalle del grupo de copia de seguridad',
        'description' => 'Los grupos de copia de seguridad ahora tienen una página de detalle de solo lectura, disponible para todos los usuarios, que muestra la programación del grupo, sus miembros, el historial agregado de ejecuciones y el tamaño de la última copia de seguridad correcta. Abrir un grupo desde la lista, desde el widget de grupos con errores del panel o desde el enlace de volver al grupo de una ejecución lleva ahora a esta página en lugar del formulario de edición reservado a administradores. Los administradores conservan ahí las acciones ejecutar, pausar, reanudar, editar y eliminar, y el historial de ejecuciones del grupo se trasladó a esta página desde el formulario de edición, que ahora es un formulario puro.',
    ],
    'mobile_header_actions' => [
        'title' => 'Acciones de cabecera móvil más limpias',
        'description' => 'La personalización del panel y las acciones de creación en las páginas de lista ahora usan botones compactos con icono junto al título en móvil, manteniendo los botones de texto completos en pantallas más grandes.',
    ],
    'group_backup_size_reporting' => [
        'title' => 'Informe de tamaño de las copias de grupo',
        'description' => 'Las ejecuciones de copia de grupo ahora informan del tamaño total de los archivos de sus miembros. Un nuevo widget opcional del panel, «Tamaño de la última copia de grupo exitosa», se puede activar desde la opción Personalizar del panel, y el tamaño agregado también aparece en las ejecuciones de grupo recientes y en el historial de ejecuciones de cada grupo. A través de la API, las ejecuciones de grupo exponen total_backup_size_bytes y el panel añade una estadística last_successful_group_backup_size. Los tamaños pueden aparecer con un breve retraso tras finalizar una ejecución, porque el tamaño de cada archivo de miembro se registra de forma asíncrona.',
    ],
    'inclusive_backup_filter' => [
        'title' => 'Filtrado de copia por inclusión',
        'description' => 'Las tareas de copia ahora pueden conservar solo las carpetas o archivos que indiques, en lugar de solo excluir algunos. Elige «Incluir solo» en el formulario de la tarea e introduce una lista de rutas separadas por comas, relativas a la raíz de la fuente de copia (por ejemplo «Backups, config/app.conf»); todo lo demás se omite, manteniendo los archivos pequeños. El modo avanzado de exclusión por regex sigue disponible, y las tareas de solo inclusión también se pueden crear a través de la API.',
    ],
    'grouped_backup_jobs' => [
        'title' => 'Trabajos de copia de seguridad agrupados',
        'description' => 'Haz una copia de seguridad de varios volúmenes como una única operación programada: un grupo de copia de seguridad posee la programación y las notificaciones, y los trabajos se adjuntan a él desde el formulario de trabajo de copia de seguridad. El grupo envía una única notificación de inicio y una de éxito/fallo para todos sus volúmenes —ideal para un único monitor de tipo dead man\'s switch— y puedes elegir si un volumen fallido detiene la ejecución o si el grupo continúa e informa igualmente del fallo. Los grupos de copia de seguridad también están disponibles a través de la API.',
    ],
    'user_date_format_preference' => [
        'title' => 'Preferencia de formato de fecha por usuario',
        'description' => 'Cada usuario puede elegir desde su perfil el formato regional usado para mostrar fechas. Por ejemplo, la interfaz puede seguir en ingles mientras las fechas cambian del orden estadounidense mes/dia al orden australiano o britanico dia/mes. La zona horaria de la aplicacion sigue controlando que hora local se muestra.',
    ],
    'trusted_2fa_device_password_revocation' => [
        'title' => 'Dispositivos 2FA de confianza revocados al cambiar contrasenas',
        'description' => 'Cambiar o restablecer la contrasena de un usuario ahora revoca sus dispositivos 2FA de confianza. Los registros existentes de dispositivos de confianza se eliminan durante la actualizacion, para que los navegadores deban superar de nuevo el desafio 2FA antes de volver a ser confiables.',
    ],
    'installation_save_two_factor_reencryption' => [
        'title' => 'Secretos 2FA recifrados durante la importacion de instalacion',
        'description' => 'Las importaciones de guardados de instalacion ahora recifran los secretos TOTP y codigos de recuperacion de los usuarios con la APP_KEY de la nueva instancia, igual que destinos y notificaciones, evitando bloqueos 2FA tras la migracion.',
    ],
    'docker_volume_destinations' => [
        'title' => 'Destinos de volumen de Docker',
        'description' => 'Un nuevo destino «Volumen de Docker» realiza copias de seguridad en un volumen de Docker con nombre —cualquier controlador, incluido NFS u otros recursos de red—. Declara el volumen en tu archivo Compose y VolumeVault lo monta por su nombre en el contenedor de copia temporal, de modo que las copias, las restauraciones, el listado y el uso de almacenamiento funcionan sin compartir ninguna ruta del host con VolumeVault. Si el volumen ya no existe, el destino falla con un error claro en lugar de escribir en silencio en un volumen vacío recién creado.',
    ],
    'webhook_notifications' => [
        'title' => 'Notificaciones por webhook',
        'description' => 'Un nuevo canal de notificación «Webhook» llama a tus propias URL en los eventos de copia de seguridad y restauración. Define una URL distinta para cada acción —inicio, éxito y error— y VolumeVault llama a la correspondiente cuando una copia o restauración empieza, termina con éxito o falla. Rellena los que quieras; establece el nivel del canal en «Cada copia de seguridad y restauración» para enviar también las URL de inicio y de éxito. Facilita la integración con servicios de monitorización y de tipo «interruptor de hombre muerto». Los canales de notificación también pueden actualizarse ahora mediante la API.',
    ],
    'backup_start_notifications' => [
        'title' => 'Notificaciones de inicio de copia de seguridad',
        'description' => 'Las copias de seguridad ahora envían una notificación cuando empieza una ejecución, no solo al terminar, igual que ya hacen las restauraciones. Los mensajes de inicio se envían a los canales configurados para recibir cada ejecución; los canales de solo errores no se ven afectados. Los servicios de monitorización pueden así medir cuánto tarda una copia.',
    ],
    'unencrypted_smtp_notifications' => [
        'title' => 'Compatibilidad con servidores SMTP sin cifrar',
        'description' => 'Los canales de notificación SMTP ahora pueden entregar a servidores que no usan cifrado. Una nueva opción «Servidor SMTP sin cifrar» en el formulario de notificación desactiva TLS y STARTTLS para que VolumeVault pueda alcanzar un relé SMTP local de confianza que de otro modo rechazaría la conexión con un error «unencrypted connection». La entrega cifrada sigue siendo la opción predeterminada y no cambia.',
    ],
    'optional_two_factor_auth' => [
        'title' => 'Autenticación de dos factores opcional',
        'description' => 'Ahora puede proteger su cuenta con una autenticación de dos factores opcional basada en una contraseña de un solo uso y limitada en el tiempo (TOTP). Actívela desde su perfil escaneando un código QR con una aplicación de autenticación como Google Authenticator o Authy y confirmando con un código generado. Una vez activada, al iniciar sesión se solicita un código de seis dígitos justo después de la contraseña. Se proporciona un conjunto de códigos de recuperación de un solo uso por si pierde el acceso a su aplicación de autenticación, y los administradores pueden restablecer la autenticación de dos factores de cualquier usuario desde la página Usuarios. En la pantalla del código también puede marcar un navegador como de confianza para omitir el código —nunca la contraseña— durante 30 días.',
    ],
    'backup_initiator_tracking' => [
        'title' => 'Seguimiento de quién inició cada copia de seguridad',
        'description' => 'Las copias de seguridad ahora registran qué usuario las inició. Las ejecuciones manuales (desde la interfaz o la API) y las copias de seguridad de toda la pila se atribuyen al usuario conectado, la copia de seguridad de protección realizada antes de una restauración in situ hereda el usuario que inició la restauración, y las ejecuciones programadas quedan sin atribuir. El iniciador aparece en el historial de ejecuciones del trabajo y en los detalles de la copia de seguridad, se incluye en las notificaciones de copia de seguridad y está disponible como un nuevo token {{ user }} para las plantillas de notificación personalizadas.',
    ],
    'restore_history_on_job' => [
        'title' => 'Historial de restauraciones en los trabajos de copia',
        'description' => 'La página de cada trabajo de copia de seguridad divide ahora su historial en dos pestañas: «Historial» enumera las copias del trabajo y una nueva pestaña «Historial de restauraciones» enumera todas las restauraciones realizadas para ese trabajo, con estado, modo, volúmenes de origen y destino, hora de inicio, duración y un enlace a los detalles completos de la restauración. Ambas pestañas están ahora paginadas, por lo que los historiales largos ya no se limitan a 50 filas.',
    ],
    'restore_in_place_modes' => [
        'title' => 'Modos de restauración en sitio',
        'description' => 'El asistente de restauración ahora puede restaurar una copia directamente en su volumen Docker de origen. «Restaurar en sitio» vacía y reemplaza el volumen tras volver a escribir su nombre para confirmar; «Restauración en sitio segura» además detiene los contenedores que usan el volumen durante la restauración y los reinicia después. El selector de copias se limita por defecto a los archivos de la tarea seleccionada, añade filtros por nombre y fecha, marca la copia más reciente, y un botón «Restaurar esta copia» abre el asistente desde una ejecución de copia. Ambos modos en sitio pueden, opcionalmente, hacer una copia de seguridad del contenido actual del volumen antes de sobrescribirlo; la restauración se cancela si esa copia falla.',
    ],
    'restore_notifications' => [
        'title' => 'Notificaciones de restauración',
        'description' => 'VolumeVault ahora te avisa cuando una restauración comienza, se completa o falla, reutilizando los canales de notificación ya configurados en la tarea de copia de seguridad. Los mensajes de inicio y de éxito se envían a los canales configurados para recibir cada ejecución, mientras que los fallos llegan a todos los canales. Un problema de notificación nunca interrumpe la propia restauración.',
    ],
    'stack_bulk_backup' => [
        'title' => 'Copia de seguridad de todo un stack a la vez',
        'description' => 'La página de stacks ahora permite hacer una copia de seguridad de un stack completo con un clic. Los stacks totalmente configurados muestran un botón "Ejecutar todas las tareas" que pone en cola una ejecución para cada tarea; los stacks con volúmenes sin cubrir muestran un cuadro "Copia de seguridad del stack" que crea una tarea de copia (diaria o personalizada) para cada volumen que no tiene una y, después, pone en cola una copia de todo el stack. La misma operación está disponible a través de la API (POST /stacks/backup).',
    ],
    'busybox_restore_tar_compat' => [
        'title' => 'Extracción de restauración compatible',
        'description' => 'Las restauraciones a un nuevo volumen Docker ya no pasan opciones de tar exclusivas de GNU y ahora envían el archivo al contenedor de restauración por streaming, por lo que la extracción funciona con BusyBox tar y con despliegues en contenedor cuyo almacenamiento vive en un volumen Docker.',
    ],
    'stable_stack_volume_search' => [
        'title' => 'Búsqueda estable de stacks y volúmenes',
        'description' => 'Al escribir en la búsqueda de stacks o volúmenes, el filtro ahora permanece activo en lugar de restablecerse tras sincronizar la URL.',
    ],
    'backup_archive_name_templates' => [
        'title' => 'Nombres personalizados para archivos de copia',
        'description' => 'Las tareas de copia ahora pueden definir una plantilla de nombre de archivo con tokens como {name}, {source}, {id}, {year}, {month}, {day} y {time}. Las tareas existentes conservan el nombre anterior volumevault-source-run-id hasta que se configure una plantilla, y el formulario avisa cuando una plantilla puede sobrescribir archivos anteriores.',
    ],
    'russian_translation_revisions' => [
        'title' => 'Texto de interfaz en ruso refinado',
        'description' => 'Las traducciones de la interfaz en ruso recibieron ajustes adicionales de redaccion para mejorar la coherencia y la legibilidad. Gracias a @artyomboyko por esta contribucion de traduccion.',
    ],
    'complete_i18n_coverage' => [
        'title' => 'Traducciones de la interfaz más completas',
        'description' => 'Muchos textos de la interfaz que aún aparecían en inglés —incluidas las páginas de tokens de API y de guardado de la instalación— ahora están totalmente traducidos. Los nueve idiomas se sincronizaron y se completaron las traducciones que faltaban, de modo que los usuarios que no hablan inglés ya no ven etiquetas, botones ni mensajes sin traducir.',
    ],
    'reliable_run_logs' => [
        'title' => 'Registros de ejecución más fiables',
        'description' => 'Los registros de copia de seguridad y restauración ahora se añaden de forma atómica, por lo que las escrituras simultáneas (por ejemplo, el manejador de fallos de un trabajo que se activa mientras termina una ejecución) ya no pueden sobrescribirse entre sí. El truncado de registros también respeta UTF-8, manteniendo válidos los registros recortados y evitando que rompan la vista de detalles de la ejecución.',
    ],
    'stale_run_liveness_reconcile' => [
        'title' => 'Recuperación más rápida de copias interrumpidas',
        'description' => 'Las ejecuciones bloqueadas tras un fallo, tiempo de espera o reinicio del worker ahora se recuperan mucho más rápido. El reconciliador comprueba si el contenedor de copia sigue activo en lugar de esperar un retardo fijo: las ejecuciones muertas fallan en minutos, mientras que las copias realmente largas se mantienen intactas. La recuperación también se ejecuta automáticamente al iniciar el contenedor y reinicia los contenedores de aplicación que quedaron detenidos.',
    ],
    'local_destination_listing_cap' => [
        'title' => 'Listados acotados de destinos locales',
        'description' => 'El listado de copias de un destino de sistema de archivos local ahora está limitado a 1000 entradas, igual que los demás proveedores de almacenamiento, de modo que un destino con un directorio de archivos muy grande ya no carga todo su árbol en una sola respuesta.',
    ],
    'per_job_schedule_timezone' => [
        'title' => 'Zona horaria por tarea',
        'description' => 'Cada tarea de copia de seguridad ahora puede definir su propia zona horaria, de modo que una programación como «cada día a las 02:00» se ejecuta a las 02:00 hora local en lugar de en la zona horaria global de la aplicación. Déjelo en «Predeterminado de la aplicación» para mantener el comportamiento anterior.',
    ],
    'http_security_headers' => [
        'title' => 'Cabeceras HTTP de seguridad',
        'description' => 'Las respuestas ahora incluyen cabeceras de seguridad de defensa en profundidad (X-Frame-Options, X-Content-Type-Options y Referrer-Policy), además de HSTS cuando se sirve por HTTPS. Las implementaciones en HTTP simple y en red local no se ven afectadas: ninguna petición se fuerza nunca de HTTP a HTTPS.',
    ],
    'local_destination_path_error_feedback' => [
        'title' => 'Errores de ruta más claros para destinos locales',
        'description' => 'Al crear un destino de sistema de archivos local, los errores de validación de la ruta —como una ruta bloqueada por la lista de permitidos de rutas del host— ahora se muestran directamente en el formulario, en lugar de volver silenciosamente a la página de creación.',
    ],
    'russian_translation_consistency' => [
        'title' => 'Traducciones al ruso refinadas',
        'description' => 'El texto de la interfaz en ruso se actualizó para mejorar la coherencia, y el glosario para traductores de ruso se movió fuera de los archivos de idioma incluidos hacia una documentación dedicada del proyecto. Esto mantiene más limpios los recursos de idioma incluidos sin perder el glosario para quienes contribuyen. Gracias a @artyomboyko por esta contribución de traducción.',
    ],
    'customizable_dashboard' => [
        'title' => 'Panel personalizable',
        'description' => 'Ahora puedes elegir que widgets mostrar en el panel y en que orden. Haz clic en "Personalizar" para ocultar o mostrar cualquier tarjeta de estadistica o seccion, arrastralas para reordenarlas y luego haz clic en "Listo" para guardar. Cada usuario conserva su propia disposicion, y "Restablecer valores predeterminados" restaura la disposicion original.',
    ],
    'self_container_backup_guard' => [
        'title' => 'VolumeVault ya no detiene su propio contenedor durante una copia de seguridad',
        'description' => 'Cuando una tarea de copia de seguridad tiene activado "detener contenedores antes de la copia" y apunta a un volumen que el propio contenedor de VolumeVault tambien monta, VolumeVault ya no detiene su propio contenedor, lo que habria interrumpido la copia en curso. El contenedor se detecta automaticamente a partir de su nombre de host y su cgroup; define VOLUMEVAULT_CONTAINER_ID o VOLUMEVAULT_CONTAINER_NAME si la deteccion automatica no es fiable (nombre de host personalizado o red de host).',
    ],
    'host_path_stop_containers' => [
        'title' => 'Detener contenedores seleccionados en copias de ruta de host',
        'description' => 'Las tareas de copia de tipo ruta de host ahora pueden detener contenedores antes de la copia y reiniciarlos despues, como ya hacian las tareas de volumen Docker. Como una ruta de host no se puede asociar automaticamente a contenedores, los eliges por nombre en el formulario de la tarea. La seleccion se guarda por nombre, asi sobrevive a la recreacion de contenedores; los contenedores que ya no existen o ya estan detenidos se omiten, y VolumeVault nunca detiene su propio contenedor.',
    ],
    'ssrf_destination_guard' => [
        'title' => 'Los destinos de copia de seguridad con IP privada ahora estan protegidos (SSRF)',
        'description' => 'VolumeVault ahora se niega por defecto a conectarse a un destino de copia de seguridad cuyo host se resuelve en una direccion privada, de bucle local (loopback) o de enlace local (incluido el punto de metadatos de la nube 169.254.169.254). Esto solo afecta a los destinos con IP privada, como un NAS en la LAN o un S3/MinIO autoalojado; los destinos en la nube accesibles por una URL publica no se ven afectados. Las copias programadas siguen ejecutandose, pero la prueba de destino, la restauracion (listado y descarga) y la alerta de cuota de almacenamiento quedan bloqueadas hasta que indique el rango del destino en VOLUMEVAULT_SSRF_ALLOWED_IPS (CIDR separados por comas, p. ej. 192.168.1.0/24). Los canales de notificacion no se ven afectados.',
    ],
    'host_path_allowlist_fail_closed' => [
        'title' => 'La lista de permitidos de rutas del host ahora es fail-closed',
        'description' => 'VOLUMEVAULT_HOST_PATH_ALLOWLIST ahora deniega de forma predeterminada: cuando esta vacia, las fuentes de copia por ruta del host y los destinos locales se rechazan en lugar de permitir cualquier ruta. La misma lista ahora tambien protege los destinos locales, y las rutas se vuelven a comprobar en tiempo de ejecucion para bloquear el cambio de enlaces simbolicos. Las instalaciones existentes que dependian del comportamiento abierto anterior deben enumerar sus rutas: ejecuta "php artisan volumevault:host-path-allowlist:audit" para obtener el valor exacto que debes definir.',
    ],
    'auth_rate_limiting' => [
        'title' => 'Inicio de sesion y restablecimiento de contrasena con limite de velocidad',
        'description' => 'Las solicitudes de inicio de sesion y de restablecimiento de contrasena ahora estan limitadas a 5 intentos por minuto, lo que ralentiza los ataques de fuerza bruta contra la contrasena del administrador. Al superar el limite se devuelve una respuesta temporal de "demasiadas solicitudes" que se restablece despues de un minuto.',
    ],
    'restore_input_hardening' => [
        'title' => 'Validacion mas estricta de las entradas de restauracion y copia',
        'description' => 'La copia seleccionada para una restauracion ahora debe coincidir con el listado del destino, lo que bloquea claves de salto de ruta como "../../etc/passwd". Los nombres de volumenes Docker se limitan a caracteres seguros, y la extraccion de restauracion se confina para que un archivo manipulado no pueda escribir fuera del volumen de destino.',
    ],
    'sftp_host_key_pinning' => [
        'title' => 'Fijacion de la clave de host SSH para destinos SFTP',
        'description' => 'Los destinos SSH/SFTP ahora pueden fijar la clave de host del servidor para bloquear los ataques de intermediario. Use el boton "Obtener clave del servidor" - o el nuevo endpoint POST /api/v1/destinations/host-key - para confiar en la clave presentada, o pegue una clave de host o una huella SHA256. La clave se verifica antes de enviar cualquier credencial, para las operaciones SFTP propias de VolumeVault (prueba, listado, restauracion). Dejarla vacia mantiene el comportamiento anterior.',
    ],
    'api_token_expiration' => [
        'title' => 'Los tokens de API ahora caducan por defecto',
        'description' => 'Los tokens de API ahora caducan 60 dias despues de su creacion por defecto, lo que limita el impacto de un token filtrado. Los tokens existentes mas antiguos dejan de funcionar tras la actualizacion y deben recrearse. Defina SANCTUM_TOKEN_EXPIRATION (en minutos) para cambiar el periodo, o null para mantener tokens sin caducidad. Una caducidad por token solo puede acortar este periodo, nunca ampliarlo.',
    ],
    'alert_check_isolation' => [
        'title' => 'Comprobaciones de alerta mas robustas',
        'description' => 'Una regla de alerta que falla ya no impide que se comprueben las demas reglas. Cada regla se evalua ahora de forma independiente y los fallos se registran, de modo que una sola comprobacion defectuosa ya no puede desactivar silenciosamente tus demas alertas.',
    ],
    'restore_volume_cleanup' => [
        'title' => 'Reintentos mas limpios tras una restauracion fallida',
        'description' => 'Cuando una restauracion falla despues de crear su volumen de destino, VolumeVault ahora elimina el volumen creado parcialmente para que el siguiente reintento empiece limpio en lugar de quedar bloqueado por un error de "ya existe".',
    ],
    'schedule_drift_prevention' => [
        'title' => 'Programacion de copias de seguridad mas fiable',
        'description' => 'Las copias de seguridad programadas ya no se saltan una ejecucion cuando un worker se retrasa. La proxima ejecucion ahora se ancla en la franja prevista en lugar de la hora de finalizacion de la ejecucion anterior, de modo que una ejecucion lenta o retrasada ya no puede desviar la programacion.',
    ],
    'destination_usage_efficiency' => [
        'title' => 'Calculo mas eficiente del uso de almacenamiento del destino',
        'description' => 'El uso de almacenamiento de los destinos de copia de seguridad ahora se calcula recorriendo los objetos en flujo en lugar de cargar toda la lista en memoria, y las conexiones SFTP siempre se cierran despues. Los destinos con muchas copias de seguridad se miden de forma mas fiable, sin agotar la memoria ni dejar conexiones abiertas.',
    ],
    'run_log_integrity' => [
        'title' => 'Registros de ejecucion mas fiables',
        'description' => 'Los registros de las ejecuciones de copia de seguridad y restauracion ahora se anaden de forma atomica, de modo que las actualizaciones simultaneas - como un mensaje de error y un aviso de reinicio de contenedor - ya no se sobrescriben entre si. Ademas su tamano esta limitado, conservando la salida mas reciente en lugar de crecer sin limite.',
    ],
    'stale_run_reconciliation' => [
        'title' => 'Recuperacion automatica de ejecuciones interrumpidas',
        'description' => 'Las ejecuciones de copia y restauracion interrumpidas por un fallo del worker, un timeout o un reinicio ahora se marcan automaticamente como fallidas en lugar de quedarse bloqueadas, para que las copias programadas sigan ejecutandose. Los contenedores de aplicacion detenidos para una copia tambien se reinician automaticamente si un fallo los dejo apagados.',
    ],
    'advanced_alerting' => [
        'title' => 'Alertas avanzadas',
        'description' => 'VolumeVault puede supervisar las tareas de copia para detectar copias obsoletas, fallos repetidos, estados de error prolongados y tamanos de archivo inusuales.',
    ],
    'destination_storage_limit_alerts' => [
        'title' => 'Alertas de limite de almacenamiento',
        'description' => 'Los destinos de copia ahora pueden definir umbrales absolutos de advertencia y criticos con canales de notificacion de alertas dedicados.',
    ],
    'mobile_navigation_redesign' => [
        'title' => 'Navegacion movil mejorada',
        'description' => 'El encabezado movil ahora usa un boton de menu compacto y un panel de navegacion estructurado en lugar de apilar todos los enlaces en el encabezado.',
    ],
    'keyboard_shortcuts' => [
        'title' => 'Atajos de teclado',
        'description' => 'En escritorio, use Ctrl+K para navegacion rapida, atajos con prefijo g para las vistas y / para enfocar la busqueda de listas.',
    ],
    'in_app_update_summaries' => [
        'title' => 'Resumenes de actualizacion integrados',
        'description' => 'VolumeVault ahora puede mostrar a los usuarios lo que cambio despues de una actualizacion de la aplicacion.',
    ],
    'available_update_checks' => [
        'title' => 'Deteccion de actualizaciones disponibles',
        'description' => 'VolumeVault ahora puede indicar cuando hay una nueva version de GitHub disponible.',
    ],
    'backup_job_detail_deletion' => [
        'title' => 'Eliminacion desde el detalle de la tarea',
        'description' => 'Las tareas de copia ahora pueden eliminarse directamente desde su pagina de detalle.',
    ],
    'per_job_notification_channels' => [
        'title' => 'Canales de notificacion por tarea',
        'description' => 'Las tareas de copia ahora pueden elegir que canales de notificacion activos reciben sus resultados.',
    ],
    'notification_defaults_migration' => [
        'title' => 'Migracion de notificaciones predeterminadas',
        'description' => 'Esta version agrega ajustes de notificacion a las tareas de copia y el seguimiento del canal predeterminado a los canales de notificacion.',
    ],
    'host_path_backup_sources' => [
        'title' => 'Fuentes de ruta del host',
        'description' => 'Los administradores pueden respaldar directorios seleccionados del host Docker junto con los volumenes Docker.',
    ],
    'host_path_safety_controls' => [
        'title' => 'Controles de seguridad de rutas del host',
        'description' => 'Las rutas del host se montan en modo solo lectura y pueden restringirse con VOLUMEVAULT_HOST_PATH_ALLOWLIST.',
    ],
    'stack_backup_coverage' => [
        'title' => 'Cobertura de copia por stack',
        'description' => 'Los volumenes Docker se agrupan por stack Compose o Swarm con estados de cobertura de copia.',
    ],
    'backup_archive_metadata' => [
        'title' => 'Metadatos del archivo de copia',
        'description' => 'Las ejecuciones exitosas ahora pueden mostrar las claves y los tamanos de archivo cuando hay metadatos del destino disponibles.',
    ],
    'trusted_proxy_support' => [
        'title' => 'Soporte de proxies de confianza',
        'description' => 'VolumeVault puede confiar en los proxies inversos configurados para que las URL generadas usen el esquema HTTPS publico.',
    ],
    'cleaner_docker_volume_sync' => [
        'title' => 'Sincronizacion de volumenes Docker mas limpia',
        'description' => 'La sincronizacion ahora elimina los registros de volumenes ausentes obsoletos que ya no estan referenciados por tareas de copia.',
    ],
    'list_search_and_filters' => [
        'title' => 'Busqueda y filtros en las listas',
        'description' => 'Los volumenes y las tareas de copia ahora tienen busqueda, filtros y un selector de volumen con busqueda.',
    ],
    'php_85_container_runtime' => [
        'title' => 'Runtime de contenedor PHP 8.5',
        'description' => 'El contenedor paso al runtime ServerSideUp PHP 8.5 con servicios supervisados de cola y planificador.',
    ],
    'first_stable_release' => [
        'title' => 'Primera version estable',
        'description' => 'VolumeVault se lanzo con copias programadas, restauraciones seguras, destinos cifrados, notificaciones, usuarios, tokens API y copias de la instalacion.',
    ],
    'pagination_with_user_preference' => [
        'title' => 'Listas paginadas con preferencia por pagina',
        'description' => 'Todas las vistas de lista ahora admiten paginacion con un numero de elementos por pagina configurable (10, 20, 50, 100 o Todos). Puede establecer su valor predeterminado en los ajustes del perfil.',
    ],
    'dark_pagination_menu' => [
        'title' => 'Menu de paginacion en tema oscuro',
        'description' => 'El menu desplegable de elementos por pagina ahora conserva una paleta de tema oscuro cuando esta abierto, mejorando el contraste en las vistas de lista paginadas.',
    ],
    'filter_toolbar_action_buttons' => [
        'title' => 'Botones primarios renovados',
        'description' => 'Los botones de accion primarios ahora comparten el mismo estilo azul delineado en toda la aplicacion, tanto en modo claro como en modo oscuro.',
    ],
    'shareable_filter_urls' => [
        'title' => 'URL de filtros compartibles',
        'description' => 'Los filtros de las listas de Volumenes, Stacks, Tareas de copia y Alertas ahora se reflejan en la URL, para que pueda copiar y compartir vistas filtradas directamente.',
    ],
    'safer_default_environment_settings' => [
        'title' => 'Configuracion de entorno predeterminada mas segura',
        'description' => '.env.example ahora configura las nuevas instalaciones con APP_ENV=production y APP_DEBUG=false. Tambien agrega una guia para SESSION_SECURE_COOKIE, de modo que los despliegues con HTTPS puedan activar cookies seguras sin romper por accidente instalaciones solo HTTP.',
    ],
    'trusted_proxy_host_hardening' => [
        'title' => 'Refuerzo del host de proxy confiable',
        'description' => 'El manejo de proxies confiables ahora ignora las cabeceras reenviadas de host y prefijo, y los enlaces de restablecimiento de contrasena se generan desde APP_URL para evitar enlaces envenenados.',
    ],
];
