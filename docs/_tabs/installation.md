---
title: Installation
icon: fas fa-download
order: 1
---

## Recommended Self-Hosted Setup

1. Generate an app key:

```bash
docker run --rm ghcr.io/darkdragon14/volumevault:latest php artisan key:generate --show
```

2. Create a `docker-compose.yml` file and paste the generated value in `APP_KEY`:

```yaml
services:
  volumevault:
    image: ghcr.io/darkdragon14/volumevault:latest
    ports:
      - "8080:8080"
    volumes:
      - volumevault_data:/app/storage
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      APP_KEY: base64:paste-generated-key-here
    restart: unless-stopped

volumes:
  volumevault_data:
```

3. Start VolumeVault:

```bash
docker compose up -d
```

4. Open `http://localhost:8080`.
5. Create the first administrator account from the onboarding screen, or import an existing installation save.

The recommended setup runs one container. At startup it prepares storage, runs database migrations, then starts nginx, PHP-FPM, the queue worker, and the scheduler under process supervision.

Production defaults are built into VolumeVault. Add environment variables only when you need to override them, for example `APP_URL`, `APP_TIMEZONE`, or SMTP settings.

## Reverse Proxy And HTTPS Termination

When VolumeVault runs behind a reverse proxy such as Pangolin, Caddy, Traefik, or nginx, TLS is usually terminated by the proxy and the container receives plain HTTP traffic on port `8080`. In that setup, configure Laravel to trust your proxy so generated URLs, redirects, and Vite assets use the original HTTPS scheme.

Use the reverse proxy container IP or Docker network CIDR for `TRUSTED_PROXIES`:

Replace `proxy_network` with the Docker network name shared by VolumeVault and your reverse proxy container.

```yaml
services:
  volumevault:
    image: ghcr.io/darkdragon14/volumevault:latest
    networks:
      - proxy_network
    volumes:
      - volumevault_data:/app/storage
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      APP_KEY: base64:paste-generated-key-here
      APP_URL: https://volumevault.example.com
      TRUSTED_PROXIES: 172.18.0.0/16
    restart: unless-stopped
```

You can inspect the Docker network subnet with:

```bash
docker network inspect proxy_network
```

`TRUSTED_PROXIES="*"` is also supported for simple homelab setups where proxy IPs change, but using the proxy IP or network CIDR is stricter. Only use `*` when the VolumeVault container is not directly reachable by clients and your reverse proxy overwrites forwarded headers from clients. If `TRUSTED_PROXIES` is empty, VolumeVault does not trust forwarded proxy headers.

### Forwarded headers

Trusting the proxy is only half of the setup: the proxy must also send the forwarded headers VolumeVault reads. The important one is the scheme:

```text
X-Forwarded-Proto: https
```

Without it, Laravel still generates `http://` URLs. The visible symptom is that the login page loads over HTTPS, authentication succeeds, but the app stays on `/login` (or you see a mixed-content / network error in the browser console) — manually removing `login` from the URL then loads the dashboard. Setting `X-Forwarded-Proto: https` on the proxy resolves this.

Optionally also forward:

```text
X-Forwarded-Port: 443
X-Forwarded-For: <client-ip>
```

VolumeVault intentionally ignores forwarded host and prefix headers (`X-Forwarded-Host`, `X-Forwarded-Prefix`). The request host comes from the `Host` header instead, so your reverse proxy must forward the original public `Host` to the container. Most proxies (HAProxy, Traefik, Caddy) preserve it by default; nginx needs `proxy_set_header Host $host`. Keep `APP_URL` set to the public URL you use — password reset links are always built from `APP_URL` — and configure your reverse proxy to overwrite forwarded headers instead of passing through client-supplied values.

This applies to any HTTPS-terminating proxy (HAProxy/OPNsense, Pangolin, Caddy, Traefik, nginx). The recommended path keeps HTTPS at the proxy and plain HTTP to the container on port `8080`:

```text
Client -> HTTPS -> reverse proxy -> HTTP -> VolumeVault (port 8080)
```

Once HTTPS is in place, set `SESSION_SECURE_COOKIE=true` so the session cookie is only sent over HTTPS. With `TRUSTED_PROXIES` set and `X-Forwarded-Proto: https` forwarded, VolumeVault sees the request as secure, so this works behind the proxy. Keep it off for plain-HTTP or LAN-only access, otherwise the `Secure` cookie is never sent and login fails.

## Large Installation Compose

For larger installations, you can split the migration, web app, queue worker, and scheduler into separate services while keeping the same image and storage volume:

```yaml
x-volumevault-environment: &volumevault-environment
  APP_KEY: ${APP_KEY:?Set APP_KEY before starting VolumeVault}

x-volumevault-service: &volumevault-service
  image: ghcr.io/darkdragon14/volumevault:latest
  volumes:
    - volumevault_data:/app/storage
    - /var/run/docker.sock:/var/run/docker.sock
  environment:
    <<: *volumevault-environment

x-volumevault-runtime-service: &volumevault-runtime-service
  <<: *volumevault-service
  depends_on:
    migrate:
      condition: service_completed_successfully
  restart: unless-stopped

services:
  migrate:
    <<: *volumevault-service
    entrypoint: ["sh", "-lc"]
    command: "mkdir -p /app/storage/database /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache && touch /app/storage/database/database.sqlite && chown -R www-data:www-data /app/storage /app/bootstrap/cache && /command/s6-setuidgid www-data php artisan migrate --force"
    restart: "no"

  app:
    <<: *volumevault-runtime-service
    ports:
      - "8080:8080"
    environment:
      <<: *volumevault-environment
      VOLUMEVAULT_MIGRATIONS_ENABLED: "false"
      VOLUMEVAULT_QUEUE_ENABLED: "false"
      VOLUMEVAULT_SCHEDULER_ENABLED: "false"
    command: ["/init"]

  queue:
    <<: *volumevault-runtime-service
    command: ["/command/s6-setuidgid", "www-data", "php", "artisan", "queue:work", "--tries=1", "--timeout=0"]

  # Dedicated worker for the "metadata" queue. Completed backups defer their
  # archive-metadata listing (and, for standalone backups, their finish
  # notification) to this queue so a slow destination listing never blocks the
  # main worker. It MUST be running, or those metadata and notifications never
  # send. The packaged all-in-one image runs this automatically.
  queue-metadata:
    <<: *volumevault-runtime-service
    command: ["/command/s6-setuidgid", "www-data", "php", "artisan", "queue:work", "--queue=metadata", "--tries=1", "--timeout=0"]

  scheduler:
    <<: *volumevault-runtime-service
    command: ["/command/s6-setuidgid", "www-data", "php", "artisan", "schedule:work"]

volumes:
  volumevault_data:
```

This layout is useful when you want separate container lifecycle, logs, and resource limits for runtime concerns. The `app` service keeps the image entrypoint so nginx and PHP-FPM are prepared correctly, but disables migrations because the separate `migrate` service already handles them.

The container listens on port `8080`. You can expose any host port by changing the value on the left, for example `9090:8080`, and should set `APP_URL` to the public URL you use.

## Update Summaries

After an application update, VolumeVault can show each signed-in user a short in-app summary of the changes they have not seen yet. The summary uses the version embedded in the container image and the local changelog shipped with the application, so it does not require GitHub access from your server.

Users can dismiss the update summary after reading it, and can reopen the full changelog from the user menu or the version link in the footer. Images built from `main` show unreleased entries when they are available.

When `APP_VERSION` is a tagged release, VolumeVault can also check GitHub for a newer release and show a discreet footer notice plus a card on the changelog page. This check is cached and can be disabled with `VOLUMEVAULT_UPDATE_CHECK_ENABLED=false` for offline or restricted installations.

## Environment Variables

- `APP_KEY`: required for encrypted destination credentials, notification URLs, and installation saves.
- `APP_ENV`: defaults to `production`.
- `APP_DEBUG`: defaults to `false`.
- `APP_TIMEZONE`: timezone used to interpret backup schedules and display date/times, defaults to `UTC`. Use an IANA timezone such as `Europe/Paris`. Each user can choose their regional date format from their profile; this changes the displayed order such as month/day vs day/month without changing the timezone.
- `APP_URL`: public URL, defaults to `http://localhost:8080`.
- `TRUSTED_PROXIES`: reverse proxy IP, CIDR, comma-separated list, or `*` when running behind HTTPS termination. Leave empty when exposing VolumeVault directly. If you use `*`, ensure the backend is only reachable through a proxy that overwrites forwarded headers.
- `VOLUMEVAULT_HOST_PATH_ALLOWLIST`: comma-separated list of Docker host path prefixes allowed for host-path backup sources **and local backup destinations**, for example `/srv,/mnt/data`. Fail-closed: when empty, host-path sources and local destinations are refused. Set the prefixes you intend to back up to/from.
- `DB_CONNECTION`: defaults to `sqlite`.
- `DB_DATABASE`: defaults to `/app/storage/database/database.sqlite` inside the Docker image.
- `QUEUE_CONNECTION`: defaults to `database`.
- `CACHE_STORE`: defaults to `database`.
- `SESSION_DRIVER`: defaults to `database`.
- `SESSION_SECURE_COOKIE`: defaults to off. Set to `true` when serving over HTTPS so the session cookie carries the `Secure` flag and is only sent over HTTPS. Leave it off for plain-HTTP or LAN-only access — a `Secure` cookie is never sent over plain HTTP, so enabling it without TLS prevents login.
- `VOLUMEVAULT_MIGRATIONS_ENABLED`: set to `false` only when running migrations in a separate container.
- `VOLUMEVAULT_QUEUE_ENABLED`: set to `false` only when splitting queue workers into separate containers.
- `VOLUMEVAULT_SCHEDULER_ENABLED`: set to `false` only when splitting the scheduler into a separate container.
- `VOLUMEVAULT_UPDATE_CHECK_ENABLED`: set to `false` to disable the cached GitHub latest-release check shown in the footer and changelog page.
- `MAIL_MAILER`: use `smtp` or another real mail transport to enable email password reset links. The default `log` mode hides email reset in the UI.

You can override values directly in Compose:

```yaml
environment:
  APP_KEY: base64:paste-generated-key-here
  APP_URL: https://volumevault.example.com
  APP_TIMEZONE: Europe/Paris
  VOLUMEVAULT_HOST_PATH_ALLOWLIST: /srv,/mnt/data
```

Or load an environment file:

```yaml
env_file: .env
environment:
  APP_KEY: ${APP_KEY:?Set APP_KEY before starting VolumeVault}
```

Do not reuse a local development `.env` in production without review. Values such as `APP_ENV=local` or `APP_DEBUG=true` override the safe production defaults.

## Secrets And APP_KEY

Destination credentials and notification URLs are encrypted using Laravel's encrypted casts. Plaintext secrets are never sent back to the frontend or API, and edit forms intentionally leave secret fields blank.

If you lose `APP_KEY`, encrypted credentials and secure installation saves can no longer be decrypted. Back up `APP_KEY` securely before trusting scheduled backups.

## Onboarding And Users

The first account created through `/onboarding` is always an admin. After that, admins can create more admins or regular users from the Users screen.

Roles:

- `admin`: full access, including users, destinations, notification channels, restore flows, API tokens, installation saves, and Docker actions.
- `user`: read-only access to dashboard, volumes, jobs, runs, and logs.

Admins can restrict regular users to selected hosts. API tokens inherit the host access of the user who created them.

VolumeVault prevents deleting your own account and prevents deleting or demoting the last admin.

During onboarding, you can either create the first administrator or import a `.vvsave` from a previous VolumeVault installation.

## Hosts And Agents

VolumeVault creates a `Local Docker Host` automatically for the Docker socket mounted into the central app. This host is active by default and counts toward the free active-host limit.

Admins can add agent hosts from the `Hosts` screen. Creating or regenerating an agent enrollment token shows the token once; only a hash is stored. Agent endpoints use that token separately from user API tokens.

The dedicated agent image is `ghcr.io/darkdragon14/volumevault-agent`. Configure it with `VOLUMEVAULT_CENTRAL_URL` and the one-time enrollment token shown by the central app as `VOLUMEVAULT_AGENT_TOKEN`. The first agent runtime is intentionally narrow: it heartbeats, leases commands, and executes remote volume sync. Backup and restore commands are created by the central app but require the agent executor to support those command types before they will run remotely.

Remote agent hosts execute Docker work on their own Docker host. Local filesystem and Docker-volume destinations are therefore host-local when executed by an agent, while cloud, SFTP, and WebDAV destinations are reached by the agent directly.
