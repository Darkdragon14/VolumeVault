---
title: Development & Roadmap
icon: fas fa-list-check
order: 6
---

## Local Development

Install dependencies if needed:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 install
npm install
```

Run migrations:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 php artisan migrate
```

Build frontend assets:

```bash
npm run build
```

## Tests

Run tests with Docker Composer:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 php artisan test
```

## Current Limitations

- No external identity provider support yet.
- A Docker TCP endpoint, such as a socket proxy for the local engine, can be configured through `DOCKER_HOST`; multi-host execution uses agents rather than a central list of remote Docker endpoints. Remote backups, restores, remote/mixed backup groups, host-scoped label reconciliation and `destination-v1` testing/browsing/storage measurements are available. Host-local archive relaying remains pending.
- No Kubernetes support.
- Hybrid notifications use the Shoutrrr Docker image; orchestrator-only notifications use the bundled native CLI without a daemon.
- Backup archive extraction assumes the archive layout produced by the configured `offen/docker-volume-backup` mount path.
- Local backup destinations require a filesystem path shared by VolumeVault and the temporary Offen container.

## Roadmap

- Multi-host agents: the database now attributes volumes, jobs and execution history to a Docker host. Existing data is migrated to the built-in local host, and local destinations retain their existing archive identity. Inventory, coverage and volume locks are scoped by host. Local execution refuses remote-owned runs and destinations.
- Restore recovery follows the execution target, not the archive's origin: a restore targeting the local host remains eligible for queue recovery, stale-run reconciliation and container restart even when its source history belongs to another host. Restores targeting remote hosts remain excluded from local recovery.
- The backup container engine is reusable and agents run the existing backup/restore safety pipeline in isolated private runtimes. Remote/mixed groups now use durable central coordination with snapshotted membership, sources and failure policy over existing `backup-v1` operations. Members run sequentially with per-member stop/backup/restart and one aggregate outcome; this is not a consistent cross-host snapshot or a stop-all workflow. Pure-local groups retain their synchronous execution path. Archive relaying is still pending.
- PHP CLI agents can now be enrolled from the Docker hosts page using a generated Docker command. They initiate verified TLS connections and persist their identity across restarts. The orchestrator receives heartbeats and host-scoped volume/container inventories. Users provide connectivity between separate networks.
- Commands are persisted centrally, delivered as authenticated encrypted envelopes and journaled on the agent before execution. Scheduling stays central; accepted execution and recovery continue during control-plane outages.
- Dedicated CLI agent and orchestrator-only deployments, compatibility diagnostics and maintenance admission are available. Updates are manual and retain agent identity. Remote container replacement and rollback supervision are a priority follow-up.

### Agent Transport Integration Test

Build the web image with `docker build --target deploy -t volumevault:agent-test .` and the CLI image with `docker build --target agent -t volumevault-agent:test .`. Run `VOLUMEVAULT_AGENT_TEST_IMAGE=volumevault:agent-test VOLUMEVAULT_AGENT_CLI_TEST_IMAGE=volumevault-agent:test php artisan test --compact tests/Feature/AgentDockerIntegrationTest.php tests/Feature/AgentImageTest.php` where PHP 8.5 and Docker are available. Tests use isolated resources, real nginx TLS, both central deployment modes and a deterministic Docker fixture. They verify wrong-CA rejection, replacement of the full-image CLI with the dedicated image using the existing identity volume and no enrollment token, restart and revocation. Neither tested application container receives the host Docker socket. Resources are removed on completion.

### Real Agent Execution Integration Test

`tests/Feature/AgentExecutionDockerTest.php` is opt-in because it starts two privileged Docker-in-Docker daemons on a disposable internal network. It never mounts production volumes or the host socket into the application/agent containers. Preload `docker:27-dind`, `offen/docker-volume-backup:latest` and the test MinIO image (`pgsty/minio@sha256:b6bfe7239bfc83fb90d31612d9704d86039dd714f7904b3f1ad68f211e602372`, or override `TEST_MINIO_IMAGE`). Set `VOLUMEVAULT_AGENT_EXECUTION_DOCKER_TEST=1`, `VOLUMEVAULT_AGENT_TEST_IMAGE` and `VOLUMEVAULT_AGENT_CLI_TEST_IMAGE` to the current built images, then run that test with a Docker-capable PHP test runner.

The test performs an actual Offen upload from engine A to S3, restores the bytes onto engine B and proves B's homonymous source volume is untouched. It suspends the orchestrator after each assignment, waits for the agent's durable result, restarts the agent before acknowledgement, then verifies completion without duplicate archives or runs. All owned containers, networks and volumes are cleaned up.

### Other Planned Improvements
- Add agent-side destination operations and relay for archives held on another host.
- Add external identity provider support for shared environments.
- Add more guided Shoutrrr services.
- Add backup pruning visibility and destination browsing improvements.
