---
title: Security
icon: fas fa-shield-halved
order: 4
---

## Docker Socket Warning

Mounting `/var/run/docker.sock` gives this application high privileges on the Docker host. Only run VolumeVault in a trusted environment.

VolumeVault can start privileged Docker operations through the Docker socket. Treat access to the web UI and write-capable API tokens like access to the Docker host.

The same warning applies when `DOCKER_HOST` points to a TCP endpoint: Docker API access is effectively root access to the Docker host. Never expose an unencrypted endpoint to the internet or an untrusted network. Keep it behind a private network, VPN, firewall, or tightly controlled socket proxy. Filtering Docker API routes reduces exposure but does not remove the risk, because VolumeVault must create containers and mount host filesystems. VolumeVault does not currently manage Docker TLS client certificates or direct remote-daemon connections. Remote Docker hosts use dedicated agents instead.

VolumeVault canonicalizes paths visible in its own filesystem regardless of whether Docker uses a Unix socket or TCP endpoint. Paths unavailable to VolumeVault can only receive lexical allowlist validation before Docker tests the bind mount. Keep `VOLUMEVAULT_HOST_PATH_ALLOWLIST` narrow, protect allowlisted directories from untrusted symlink replacement, and treat changes to it as privileged configuration.

On first launch, VolumeVault requires onboarding and creates the first account as an administrator. Admins can manage users, encrypted destinations, notification channels, restores, and active Docker operations such as volume sync and manual backup runs. Regular users have read-only access to operational screens.

## HTTPS And Session Cookie

### Agent Transport

Orchestrator-only mode (`VOLUMEVAULT_MODE=orchestrator`) requires no Docker socket or remote daemon. Local execution entrypoints are blocked even if `DOCKER_HOST` is accidentally configured. Remote-only backup groups are supported; mixed groups require local execution for local members. Notifications use the bundled Shoutrrr binary. The dedicated agent retains Docker-host privileges locally and does not receive the central database or `APP_KEY`.

Remote/mixed groups use durable central coordination over the existing `backup-v1` operations. Each member keeps its own host-scoped source, destination and execution privileges. Membership, source identity and failure policy are snapshotted for the run. Assigned work may drain during host maintenance; an unassigned next member waits for its host to resume. Container stop, backup and restart happen separately for each member, in sequence. Groups do not stop every application's containers together and do not guarantee a consistent cross-host snapshot. Cross-host relaying of host-local archives, remote Docker-label reconciliation and agent-side destination browsing/testing remain unsupported.

Maintenance and run admission serialize on the same Docker host rows. New runs cannot be claimed after maintenance wins that lock; operations accepted beforehand continue to completion and remain counted until cleanup finishes. Remote readiness additionally requires a fresh acknowledgment of the current maintenance nonce and zero reported operations. Version diagnostics are recorded only after agent authentication. Manual update guides contain no enrollment token or agent credential and do not replace containers automatically.

The optional agent endpoint uses HTTPS with a persisted private certificate authority, verified hostnames, and TLS 1.2 or newer on the agent client. Agents do not accept incoming connections and do not expose the Docker API. Obtain the generated installation command through a trusted administrator session: its bundled public CA establishes the initial trust relationship.

Enrollment tokens expire after 15 minutes and can establish only one agent identity. Retrying the same enrollment with the same locally persisted credential is idempotent. The orchestrator stores SHA-256 hashes of high-entropy enrollment and agent credentials; agent credentials cannot authenticate as users or access the public API. Re-enrollment invalidates the previous credential, and revocation blocks both ordinary requests and enrollment retries.

Agent private state uses a dedicated persistent directory with mode `0700` and files with mode `0600`. Protect the Docker host and storage volume as you would the Docker socket. The orchestrator's CA private key is retained in its protected persistent storage; the generated command contains only the public CA certificate. The agent does not receive `APP_KEY` or central database access.

Execution specifications are encrypted at rest centrally and delivered in an authenticated encrypted envelope over TLS. The envelope key is derived with HKDF from the authenticated agent credential and host UUID; the API response does not expose destination credentials as plaintext fields. The agent journals the decrypted specification using its own local key before execution, then revalidates paths and destination egress against local policy. No arbitrary shell command, image override or central policy override is accepted in a specification.

For S3-compatible destinations, an explicitly duplicated `settings.endpoint` must match the primary `endpoint`, including null/empty values. Contradictory representations are rejected rather than allowing the upload and download clients to resolve different endpoints. The agent checks the primary endpoint consumed by the uploader against its local egress policy.

Every backup helper creation, including `docker run --rm`, is recorded as cleanup pending before launch. A failed attached Docker client does not prove that its helper stopped. Applications remain stopped until idempotent helper removal and secret-file cleanup are confirmed; recovery completes that sequence after an interruption.

One durable assignment per host prevents overlapping backup/restore commands. Assignments are not reassigned merely because a heartbeat expires. Result callbacks require both agent authentication and the specific operation token, and duplicate completion cannot rewrite history or duplicate notification finalizations. Results are accepted only after cleanup; safety-backup metadata is preserved centrally before local receipts are compacted.

Inventory updates are scoped to the authenticated host, limited in size, and ordered by a persisted sequence number for that agent identity. Revocation is rechecked inside the inventory transaction. Loss of connectivity or a failed Docker listing preserves the last known inventory rather than reporting every volume as missing.

Enrollment is limited to ten requests per minute per source IP using its own counter. Authenticated heartbeats and inventories share a separate quota of 180 requests per minute per host UUID. Agent authentication runs before that quota is charged, so another host cannot consume it with invalid credentials.

### Browser Sessions

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
- Host path backup sources are mounted read-only into the temporary Offen container. `VOLUMEVAULT_HOST_PATH_ALLOWLIST` restricts which host directories admins can select and is fail-closed (empty = nothing allowed). The same allowlist gates local backup destinations, which are bind-mounted read-write. At run time, every locally visible path is canonicalized regardless of Docker transport to reject resolvable symlink escapes. Paths unavailable to VolumeVault receive lexical-only validation; protect allowlisted directories from untrusted symlink replacement.
