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

## Docker TCP Endpoint

VolumeVault normally connects through `unix:///var/run/docker.sock`. To use a TCP endpoint such as a socket proxy in front of the same Docker engine, remove the socket mount and set the container's `DOCKER_HOST`.

The repository includes a standalone TCP Compose file that does both safely. It uses `VOLUMEVAULT_DOCKER_HOST` for interpolation so the host Docker CLI is not redirected through the proxy:

```bash
VOLUMEVAULT_DOCKER_HOST=tcp://docker-proxy.example.internal:2375 docker compose -f docker-compose.tcp.yml up -d
```

Because this is a standalone file rather than a Compose merge override, it does not require a recent Compose version. The base `docker-compose.yml` fixes the container endpoint to the local Unix socket so an ambient `DOCKER_HOST` cannot accidentally select a proxy while the unrestricted socket remains mounted. For a custom Compose file, use the equivalent TCP configuration with no Docker socket mount:

```yaml
services:
  volumevault:
    image: ghcr.io/darkdragon14/volumevault:latest
    volumes:
      - volumevault_data:/app/storage
    environment:
      APP_KEY: base64:paste-generated-key-here
      DOCKER_HOST: tcp://docker-proxy.example.internal:2375
```

This is an instance-wide setting: volume discovery, backups, restores, container stop/start operations, and Docker-volume destinations all use that endpoint. The same endpoint is passed to the temporary Offen backup container. Its hostname or IP must therefore be reachable both from VolumeVault and from containers launched by Docker.

When the endpoint uses a Compose service name such as `socket-proxy`, set `VOLUMEVAULT_DOCKER_NETWORK` to the engine-visible user-defined network containing that service. VolumeVault passes the network to `docker run`, allowing the temporary Offen container to resolve and reach the proxy. Compose normally prefixes network names with the project name; assigning an explicit `name` avoids that ambiguity:

```yaml
services:
  socket-proxy:
    networks:
      - proxy-net

  volumevault:
    environment:
      DOCKER_HOST: tcp://socket-proxy:2375
      VOLUMEVAULT_DOCKER_NETWORK: volumevault-proxy
    networks:
      - proxy-net

networks:
  proxy-net:
    name: volumevault-proxy
```

Leave `VOLUMEVAULT_DOCKER_NETWORK` empty when the endpoint is already reachable without a specific Docker network. VolumeVault does not guess a network because its container may be attached to more than one.

This setting does **not** add support for managing a Docker engine on another host. Bind mounts are resolved by the daemon, while VolumeVault also needs direct access to some local files. In particular, local destinations may be unavailable when the endpoint controls another machine. Uploaded SSH private keys do not rely on a bind mount: VolumeVault copies them into the temporary backup container through the Docker API. Use a TCP socket proxy for the same Docker engine VolumeVault normally accesses, not a remote-host deployment.

Host-path backup sources refer to paths on the Docker host because bind mounts are resolved by the daemon. `VOLUMEVAULT_HOST_PATH_ALLOWLIST` must contain the permitted paths. VolumeVault canonicalizes paths that are visible in its own filesystem and validates the bind by launching a temporary container. Paths that are not visible to VolumeVault can only be checked lexically, which is another reason remote-host deployments are unsupported.

### TCP access security

Docker API access is effectively root access to the Docker host. Anyone who can reach a write-capable endpoint can create containers and mount host filesystems.

- Never publish an unencrypted Docker TCP endpoint on the internet or an untrusted LAN.
- Restrict access with a private network, VPN, firewall, or a dedicated Docker socket proxy.
- A proxy API allowlist reduces unrelated exposure, but VolumeVault legitimately creates containers with bind mounts, so its access remains highly privileged.
- This version accepts a `tcp://` endpoint through `DOCKER_HOST` but does not manage Docker TLS client certificates or remote Docker hosts. Protect the connection at the network or proxy layer.

The conventional unencrypted Docker port is `2375`. Do not expose it publicly. Modern Docker versions also restrict starting an unauthenticated remotely reachable daemon, so a secured proxy or private tunnel is preferable to binding the daemon directly.

### Docker socket proxy access

VolumeVault directly needs Docker API access to inspect and list containers and volumes, create and remove volumes, create/start/stop temporary containers, attach to or wait for those containers, and inspect or pull their images. A socket proxy must therefore allow at least the equivalent of `INFO`, `CONTAINERS`, `VOLUMES`, `IMAGES`, and write requests for those operations. Restrict the proxy to VolumeVault's network peers even when these routes are filtered.

Offen also connects to the endpoint from inside the backup container. It always reads Docker info and the container list. Additional exec, service, node, task, stop, and start operations depend on Offen labels and Swarm usage. VolumeVault performs its own stop/start orchestration, but proxy permissions should still be reviewed whenever the Offen image or its labels change.

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
    command: ["mkdir -p /app/storage/database /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache && touch /app/storage/database/database.sqlite && chown -R www-data:www-data /app/storage /app/bootstrap/cache && /command/s6-setuidgid www-data php artisan migrate --force"]
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
    healthcheck:
      disable: true

  # Dedicated worker for the "metadata" queue. Completed backups defer their
  # archive-metadata listing (and, for standalone backups, their finish
  # notification) to this queue so a slow destination listing never blocks the
  # main worker. It MUST be running, or those metadata and notifications never
  # send. The packaged all-in-one image runs this automatically.
  queue-metadata:
    <<: *volumevault-runtime-service
    command: ["/command/s6-setuidgid", "www-data", "php", "artisan", "queue:work", "--queue=metadata", "--tries=1", "--timeout=0"]
    healthcheck:
      disable: true

  scheduler:
    <<: *volumevault-runtime-service
    command: ["/command/s6-setuidgid", "www-data", "php", "artisan", "schedule:work"]
    healthcheck:
      disable: true

volumes:
  volumevault_data:
```

This layout is useful when you want separate container lifecycle, logs, and resource limits for runtime concerns. The `app` service keeps the image entrypoint so nginx and PHP-FPM are prepared correctly, but disables migrations because the separate `migrate` service already handles them. The runner services disable the image's HTTP healthcheck because they do not run nginx.

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
- `DOCKER_HOST`: Docker endpoint used by the entire instance. Defaults to `unix:///var/run/docker.sock`; set a private `tcp://host:port` endpoint to use a socket proxy for the same Docker engine. Remote Docker hosts are not supported.
- `VOLUMEVAULT_DOCKER_NETWORK`: optional engine-visible user-defined network attached to temporary Offen backup containers. Set it when a TCP socket proxy hostname is only reachable on that network. Compose-generated network names are commonly prefixed with the project name unless the network has an explicit `name`.
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

VolumeVault prevents deleting your own account and prevents deleting or demoting the last admin.

During onboarding, you can either create the first administrator or import a `.vvsave` from a previous VolumeVault installation.
