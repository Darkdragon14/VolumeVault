---
title: Security
icon: fas fa-shield-halved
order: 4
---

## Docker Socket Warning

Mounting `/var/run/docker.sock` gives this application high privileges on the Docker host. Only run VolumeVault in a trusted environment.

VolumeVault can start privileged Docker operations through the Docker socket. Treat access to the web UI and write-capable API tokens like access to the Docker host.

On first launch, VolumeVault requires onboarding and creates the first account as an administrator. Admins can manage users, encrypted destinations, notification channels, restores, and active Docker operations such as volume sync and manual backup runs. Regular users have read-only access to operational screens.

## HTTPS And Session Cookie

When VolumeVault is served over HTTPS (directly or behind a TLS-terminating reverse proxy), set `SESSION_SECURE_COOKIE=true`. This marks the session cookie with the `Secure` flag so the browser only ever sends it over HTTPS, which protects it from being leaked over an accidental plain-HTTP request.

Keep it **off** for plain-HTTP or LAN-only deployments: a `Secure` cookie is never sent over plain HTTP, so enabling it without TLS means the browser drops the session cookie and login fails. Behind a reverse proxy, the request is recognised as secure once `TRUSTED_PROXIES` is set and the proxy forwards `X-Forwarded-Proto: https` (see [Installation]({{ '/installation/' | relative_url }})).

VolumeVault also sends defense-in-depth response headers (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`), and adds `Strict-Transport-Security` (HSTS) automatically when a request is served over HTTPS. No request is ever redirected from HTTP to HTTPS, so plain-HTTP deployments are unaffected.

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
docker compose exec volumevault php artisan volumevault:reset-password admin@example.com
```

Both reset methods invalidate existing browser sessions for the user. Existing API tokens are kept so integrations are not interrupted.

## Two-Factor Authentication

Each user can optionally protect their account with a second factor based on a time-based one-time password (TOTP). It is disabled by default and configured per user — it never blocks accounts that have not enabled it.

Enable it from **Profile → Two-factor authentication**: scan the QR code with an authenticator app (Google Authenticator, Authy, 1Password, …) or enter the setup key manually, then confirm with a generated code to activate it. Because TOTP is computed locally by the app, two-factor authentication works without any mail or SMS configuration. After it is enabled, signing in asks for a six-digit code right after the password; API tokens are unaffected.

When you enable it, VolumeVault shows a set of single-use **recovery codes**. Store them somewhere safe — each one logs you in once if you lose access to your authenticator app, and you can regenerate the set at any time from the same screen. Disabling two-factor authentication requires confirming your current password.

On the code screen you can tick **Trust this device for 30 days**. A trusted browser skips the 6-digit code on its next logins — but it never skips the password, which is still required every session — so a stolen device cookie is useless on its own. The cookie stores only a random token whose hash is kept server-side. You can review the list of trusted devices and revoke any of them (or all at once) from **Profile → Two-factor authentication**, and they are also revoked automatically when the password changes or two-factor authentication is disabled or reset. Regular "remember me" login persistence is intentionally turned off for accounts with two-factor authentication, so the password is always re-entered on a new session.

The TOTP secret and recovery codes are encrypted at rest with `APP_KEY`. If a user loses both their authenticator app and their recovery codes, an administrator can clear their second factor from the **Users** page (the reset action appears only for users who have it enabled); the action is recorded in the activity log.

## SFTP Host Key Pinning

SSH/SFTP destinations accept an optional pinned host key. When set, VolumeVault verifies the server key before sending any credentials and refuses the connection on mismatch, blocking man-in-the-middle attacks.

The simplest way to set it is the **Fetch key from server** button on the destination form: VolumeVault connects to the host (key exchange only, no login), pins the key it presents, and shows its `SHA256:` fingerprint. This is trust-on-first-use — it protects against any later man-in-the-middle. If you want to also rule out a first-contact attack, compare the displayed fingerprint with the server's own (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`) before saving. You can also paste a key manually: the server's public host key (for example from `ssh-keyscan -t ed25519 server.local`) or its `SHA256:` fingerprint (from `ssh-keygen -lf`).

This pin protects VolumeVault's own SFTP operations — destination testing, listing, and restore downloads. The actual backup upload runs in the temporary `offen/docker-volume-backup` container, which does not verify SSH host keys (an upstream limitation), so that leg of the transfer cannot be pinned from here.

## Secure Installation Saves

Admins can create a secure `.vvsave` from the `Installation save` screen. The save archives useful files from `/app/storage`, including the SQLite database, then encrypts the archive with a key derived from the current `APP_KEY`.

The save intentionally does not include `APP_KEY`. Keep `APP_KEY` outside the file: it is required to unlock the save during onboarding import and protects the archive if the `.vvsave` is exposed.

Secure saves exclude runtime-only data such as sessions, cache, queued jobs, temporary restore downloads, and logs. They can be downloaded locally or uploaded to an active backup destination under `installation-saves/` when the provider supports paths.

To migrate an installation, start a fresh VolumeVault instance, choose `Import existing installation` during onboarding, upload the `.vvsave`, and provide the previous installation `APP_KEY`. Imported destination, notification, and user two-factor secrets are re-encrypted with the new instance key after restore.

## Safety Notes

- Always test restore before trusting backups.
- Restore-to-new-volume is safest because it does not overwrite the source volume.
- In-place restore overwrites the source Docker volume and requires typed confirmation of that volume name. Use safe in-place restore when containers using the volume should be stopped during the overwrite and restarted afterward.
- For databases, application-consistent backups may require stopping containers or using database-native dumps.
- Optional job setting `Stop containers before backup` stops containers using the volume before backup and restarts them afterward.
- Local backup destinations require a filesystem path shared by VolumeVault and the temporary Offen container.
- Host path backup sources are mounted read-only into the temporary Offen container. `VOLUMEVAULT_HOST_PATH_ALLOWLIST` restricts which host directories admins can select and is fail-closed (empty = nothing allowed). The same allowlist gates local backup destinations, which are bind-mounted read-write, and both are re-validated at run time to defend against a symlink swap (TOCTOU).
