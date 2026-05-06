---
title: Security
icon: fas fa-shield-halved
order: 4
---

## Docker Socket Warning

Mounting `/var/run/docker.sock` gives this application high privileges on the Docker host. Only run VolumeVault in a trusted environment.

VolumeVault can start privileged Docker operations through the Docker socket. Treat access to the web UI and write-capable API tokens like access to the Docker host.

On first launch, VolumeVault requires onboarding and creates the first account as an administrator. Admins can manage users, encrypted destinations, notification channels, restores, and active Docker operations such as volume sync and manual backup runs. Regular users have read-only access to operational screens.

## Password Recovery

If outbound mail is configured, the login screen shows a password reset link. Configure the container with a real mail transport, for example SMTP:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=volumevault@example.com
MAIL_PASSWORD=change-me
MAIL_FROM_ADDRESS=volumevault@example.com
MAIL_FROM_NAME=VolumeVault
```

When `MAIL_MAILER` is `log` or `array`, email password reset is hidden because no message will leave the container.

Password reset is always available from the container CLI:

```bash
docker compose exec app php artisan volumevault:reset-password admin@example.com
```

Both reset methods invalidate existing browser sessions for the user. Existing API tokens are kept so integrations are not interrupted.

## Secure Installation Saves

Admins can create a secure `.vvsave` from the `Installation save` screen. The save archives useful files from `/app/storage`, including the SQLite database, then encrypts the archive with a key derived from the current `APP_KEY`.

The save intentionally does not include `APP_KEY`. Keep `APP_KEY` outside the file: it is required to unlock the save during onboarding import and protects the archive if the `.vvsave` is exposed.

Secure saves exclude runtime-only data such as sessions, cache, queued jobs, temporary restore downloads, and logs. They can be downloaded locally or uploaded to an active backup destination under `installation-saves/` when the provider supports paths.

To migrate an installation, start a fresh VolumeVault instance, choose `Import existing installation` during onboarding, upload the `.vvsave`, and provide the previous installation `APP_KEY`. Imported destination and notification secrets are re-encrypted with the new instance key after restore.

## Safety Notes

- Always test restore before trusting backups.
- Restore-to-new-volume is safest because it does not overwrite the source volume.
- In-place restore can overwrite data and should be used carefully when implemented later.
- For databases, application-consistent backups may require stopping containers or using database-native dumps.
- Optional job setting `Stop containers before backup` stops containers using the volume before backup and restarts them afterward.
- Local backup destinations require a filesystem path shared by VolumeVault and the temporary Offen container.
