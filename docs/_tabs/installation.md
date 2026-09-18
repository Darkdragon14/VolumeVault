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

## Deployment Modes and Images

The web application supports `VOLUMEVAULT_MODE=hybrid` (default) and `VOLUMEVAULT_MODE=orchestrator`.

- **Hybrid:** central UI, API, scheduling and local Docker execution, with optional agents.
- **Orchestrator only:** central UI, API, agent transport, alerts and network-backed finalization, without Docker daemon access. Local discovery, scheduling/execution, stale-run recovery and host-path audits are disabled. Local resources and history remain in the database; their jobs are not silently failed or deleted. Local destination metadata finalizations are held until local execution is available again. Notifications use the bundled Shoutrrr binary instead of starting Docker containers.
- **Agent:** the separate `ghcr.io/darkdragon14/volumevault-agent` image runs PHP CLI only, without nginx, PHP-FPM, frontend assets, the central scheduler or queue workers. It retains the shared PHP dependencies and execution code. Its default command is `php artisan volumevault:agent`, and its persistent storage remains `/app/storage`.

For orchestrator-only deployment, use the standalone file, not an overlay on the socket-mounted base file:

```bash
VOLUMEVAULT_AGENT_URL=https://192.168.1.10:8443 \
  docker compose -f docker-compose.orchestrator.yml up -d
```

Set `APP_KEY` as for any central installation. `VOLUMEVAULT_VERSION` can pin the application's image tag. Before changing an existing hybrid installation to orchestrator-only, enter maintenance for its local host and wait for active work and cleanup to finish. Keep the same central storage volume. To re-enable local work, return to hybrid with the original Docker endpoint and end maintenance explicitly.

Both container images are built from the same source revision and published with corresponding version tags. Local builds use `docker build --target deploy -t volumevault:local .` and `docker build --target agent -t volumevault-agent:local .`. The default Dockerfile target remains the web application. Building the agent does not include the frontend build artifacts in its runtime image.

## Agent Enrollment and Inventory

Compatible agents provide inventory discovery and execute backups and restores, including members of remote or mixed-host backup groups. Backup forms select a source host; restore forms select a target host. Shared network destinations support A-to-B restore into a new volume. A host-local destination belongs to its owner and can currently be restored only on that same host. Remote Docker-label reconciliation, archive relaying and agent-side destination browsing/testing operations remain unavailable. The existing Volumes, Stacks and Dashboard inventory pages remain local-only; job/restore forms and Docker hosts expose agent inventories.

Groups containing remote members use a durable central coordinator and the existing `backup-v1` agent capability; no new agent protocol is needed. Remote-only groups work in orchestrator mode; mixed groups require local execution for their local members. Keep the central scheduler, execution queue and metadata worker running. Membership, failure policy and member sources are snapshotted when the remote/mixed run is queued. Members execute sequentially, each completing its configured stop/backup/restart cycle before the next starts. This does not stop all applications together or provide a consistent cross-host snapshot. Purely local groups retain their synchronous member execution within the queued group job.

The local card shows the Docker engine's server version and total container count (running and stopped), refreshed by the existing volume synchronization. **Last volume sync** is the completion time of the last successful volume discovery; it is not a heartbeat. Only agents show **Last contact**, and their **Last inventory sync** covers the received volume/container snapshot. Failed discovery retains the last successful timestamp; failed metadata probes do not replace known counts with zero. VolumeVault's software version is displayed separately from Docker's version. `main`, `dev` and `development` denote development builds rather than numbered releases. Orchestrator-only cards omit Docker-specific metrics.

Enable the orchestrator's built-in TLS endpoint with an HTTPS origin that every agent can reach. A private LAN IP or DNS name works; a public domain and manually issued certificate are not required. For the repository's Compose setup:

```bash
VOLUMEVAULT_AGENT_URL=https://192.168.1.10:8443 \
  docker compose -f docker-compose.yml -f docker-compose.agents.yml up -d
```

For a custom deployment, set `VOLUMEVAULT_AGENTS_ENABLED=true`, set `VOLUMEVAULT_AGENT_URL` to that HTTPS origin, and publish container port `8443`. If a different host port is published, include it in the origin. The ordinary web interface remains available on port `8080`. Use the built-in TLS endpoint directly or through TCP passthrough: a reverse proxy terminating TLS with a different certificate authority will not match the trust configuration generated for the agent.

1. Open **Docker hosts** as an administrator and add a named host.
2. Copy the generated `docker run` command and run it on that Docker machine within 15 minutes.
3. The PHP CLI agent enrolls and sends heartbeats on a 30-second cadence. It collects a complete volume/container inventory at startup, then waits five minutes between collection attempts. Intermediate cycles use only a lightweight Docker availability probe, capped at ten seconds. Heartbeats continue during long Docker inspections; collection has a five-minute total budget and incomplete results are never published. The agent starts no web server, needs no incoming port, and does not need the orchestrator's database or `APP_KEY`.

The command contains the orchestrator's public CA certificate and a one-use enrollment token. Obtain it through a trusted administrator session. The agent validates the TLS certificate and hostname before transmitting credentials. Its unique credential is generated locally and stored under `/app/storage/app/agent` in the named volume included in the command. The central database stores credential hashes only. Retain the named volume when recreating the agent container.

The orchestrator generates its own CA and server certificate in its persistent storage. Server certificates renew automatically before expiry; the CA remains stable across restarts. Keep `/app/storage` persistent and protected. The CA expires after ten years; replacing that trust identity requires new agent trust configuration. Changing the configured origin also requires explicit agent reconfiguration rather than silently trusting another endpoint.

Use **Revoke** to stop accepting an agent's credentials immediately. **Renew enrollment** invalidates its old credentials and generates a new installation command. Stop and remove the old agent container, then run the new command with its existing named volume. A lost enrollment response can be retried by the same persisted agent identity without creating a second registration.

An unreachable agent becomes offline without its inventory being marked missing. Docker failures preserve its last inventory and report Docker as unavailable. Missing volumes are detected only from a newer, successfully collected complete inventory.

If the agent cannot persist its identity or inventory sequence, it exits with a failure code instead of retrying with unusable state. The generated Docker restart policy restarts it so it can reload the same persisted identity after the storage problem is resolved. Enrollment limits are separate from normal traffic limits, and each authenticated agent has its own traffic quota even when several hosts share a NAT address.

Across separate networks, provide connectivity with your existing VPN, Tailscale, or other routing. VolumeVault does not provide NAT traversal or a hosted relay. No Docker daemon needs to be exposed over the network.

To test a locally built image, set `VOLUMEVAULT_AGENT_IMAGE` to the agent image tag on the orchestrator; that image must also be available on the agent machine. By default, installation commands use the agent image tagged with the orchestrator's application version (`latest` for development builds). An empty override retains that default. Execution-capable agents advertise `backup-v1` and `restore-v1` alongside `inventory-v1` and `maintenance-v1`. Older discovery-only agents must be updated before selecting them for execution.

### Running Backups and Restores on Agents

1. Register an execution-capable agent and wait for its source inventory.
2. Create a backup job, select its Docker host, then select a volume or allowed host path on that host. Choose standalone scheduling or attach it to an existing or new group. Network destinations are shared; filesystem/Docker-volume destinations must be owned by the same host as the job.
3. Trigger the job (or its group) or let the central scheduler queue it. Offline hosts may retain queued work until the agent returns. The agent launches Offen against its own configured Docker daemon and reports the sanitized result after container cleanup.
4. Restore a successful run using the target host selector. Another host can restore from a shared network destination into a new volume; the original host's volume does not need to exist there. Existing target names are rechecked by the target agent before any modification.

Only one operation is assigned at a time to each agent. Maintenance prevents new assignments; an accepted operation continues, including cleanup and any safety backup. Completed results are retried until acknowledged, without rerunning the operation. The same persisted identity volume is required after an agent restart.

The agent creates an encrypted operation journal and a private SQLite runtime for each accepted operation under `/app/storage/app/agent`. Its encryption key is local to that agent; it never receives the central `APP_KEY` or database access. The existing backup/restore actions run against that isolated runtime, preserving archive validation and container restart logic. After central acknowledgement, private runtime data is removed and a compact anti-replay receipt is retained. Logs and metadata are returned at completion; active operations send progress heartbeats.

Set `VOLUMEVAULT_HOST_PATH_ALLOWLIST` and, for private network destinations, `VOLUMEVAULT_SSRF_ALLOWED_IPS` on the agent itself. These are authoritative local policies and cannot be overridden by a command from the orchestrator. Local filesystem destinations also require the corresponding mount in the agent container, as in a local VolumeVault installation. Network destination browsing/validation on the orchestrator still requires central connectivity and its own egress policy. Existing provider-specific limitations, including Dropbox archive identity restrictions, continue to apply.

A lost connection does not expire an assignment or move it to another agent. If a worker disappears, recovery observes its helper and persisted state instead of replaying a potentially destructive operation. Missing/corrupt journal or runtime data requires intervention; do not delete it to force a retry. Revocation stops control-plane access, but already accepted local work can finish. Recover with the same persistent state and a valid enrollment to report its result.

## Compatibility, Maintenance and Manual Updates

The Docker hosts page distinguishes software version, protocol version and capabilities. Protocol **1** with `inventory-v1` is currently compatible; equality of software versions is not required. Agent software is compared with the installed orchestrator release to show current, update available, or ahead. Development/non-release versions are shown as unknown. This is not a claim that an arbitrary newer protocol is supported. Authenticated requests using an unsupported protocol are rejected and their diagnostic version is recorded for administrators.

1. Update the orchestrator using its existing deployment and retain its storage, `APP_KEY` and TLS identity. Follow release-specific migration and compatibility notes.
2. Enter maintenance for the agent. This persists a new maintenance nonce and blocks new work admission. Already assigned group members and safety-backup children may complete; the next group member waits if its host is in maintenance. Stale-work recovery and cleanup remain available.
3. Wait for a fresh agent heartbeat acknowledging that nonce and reporting no active operations. The central ledger must also contain no running operations or pending container cleanup for the host. Waiting, unassigned group members do not prevent that host from becoming maintenance-ready. Queued work remains queued and does not prevent readiness.
4. Open the manual guide and download the target image using its `docker pull` command. **Pulling alone does not update the container.** Change the image in your existing Docker or Compose deployment and recreate that container, preserving all mounts, networks, environment settings and the existing named volume mounted at `/app/storage`. The guide deliberately does not reconstruct or delete a potentially customized deployment.
5. Wait for reconnection and compatible protocol/capabilities, then end maintenance. No new enrollment token is required; the stored identity is reused. Keep the orchestrator URL and public CA configuration unchanged.

Queued groups are not considered abandoned merely because their creation time is old: publication recovery handles them until execution starts. On an actual maintenance-to-active transition, the host's waiting top-level backup, restore and group runs receive fresh publication leases on their next dispatch. This prevents stale delivery deadlines accumulated during maintenance from failing them immediately after resume, even if reconciliation runs first. Execution timestamps, internal group/safety-backup children and unrelated hosts are preserved; an already-active host is not reset by repeated resume requests.

The local hybrid host also supports maintenance; update it through its existing central deployment, not through the agent guide. The pure orchestrator card is not a local execution target.

An offline, incompatible, or older agent without maintenance acknowledgment cannot be declared ready automatically. For such agents, verify their state on the host and use the existing deployment to recover or update them manually; do not renew enrollment simply to change an image. Manual updates are the initial workflow. Remote self-update, container replacement and rollback supervision are a priority follow-up, not implemented by this page.

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

Host-path backup sources refer to paths on the Docker host because bind mounts are resolved by the daemon. `VOLUMEVAULT_HOST_PATH_ALLOWLIST` must contain the permitted paths. VolumeVault canonicalizes paths that are visible in its own filesystem and validates the bind by launching a temporary container. Paths that are not visible to VolumeVault can only be checked lexically, which is another reason direct remote-daemon deployments are unsupported. Use an agent with its own host-path allowlist for a remote host.

### TCP access security

Docker API access is effectively root access to the Docker host. Anyone who can reach a write-capable endpoint can create containers and mount host filesystems.

- Never publish an unencrypted Docker TCP endpoint on the internet or an untrusted LAN.
- Restrict access with a private network, VPN, firewall, or a dedicated Docker socket proxy.
- A proxy API allowlist reduces unrelated exposure, but VolumeVault legitimately creates containers with bind mounts, so its access remains highly privileged.
- This version accepts a `tcp://` endpoint through `DOCKER_HOST` but does not manage Docker TLS client certificates or direct remote-daemon connections. Agent discovery uses the separate TLS transport described above. Protect Docker TCP connections at the network or proxy layer.

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
- `DB_QUEUE_RETRY_AFTER`, `BEANSTALKD_QUEUE_RETRY_AFTER`, and `REDIS_QUEUE_RETRY_AFTER`: default to `360` seconds and must remain greater than the 300-second Docker volume synchronization timeout. Any other queue driver that reserves jobs must use a reservation or retry delay greater than 300 seconds.
- Amazon SQS does not use a Laravel `retry_after` setting. Configure the queue's visibility timeout in AWS to greater than 300 seconds.
- `QUEUE_CONNECTION`: defaults to `database`. Backup and restore runs require an asynchronous queue; the `sync` driver is not supported because lock-contending jobs must be released back onto a durable queue.
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
