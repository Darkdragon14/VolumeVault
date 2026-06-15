---
title: API
icon: fas fa-code
order: 5
---

## Automation And AI-Friendly API

VolumeVault exposes a versioned HTTP API secured with Laravel Sanctum tokens and documented through an OpenAPI schema at `/api/v1/openapi.json`.

This makes the project friendly to automation tools, monitoring scripts, dashboards, and AI agents that need to inspect backup state or trigger explicit operations without scraping the web UI.

API tokens are created by admins from the `API tokens` screen. Tokens are displayed only once at creation and stored hashed after that.

Tokens expire by default to limit the blast radius of a leaked token. The default lifetime is 60 days and is configurable with `SANCTUM_TOKEN_EXPIRATION` (in minutes); set it to `null` to allow non-expiring tokens. A per-token expiry chosen at creation can only shorten this window, never extend it.

Use API tokens with the Bearer scheme:

```bash
curl -H "Authorization: Bearer <token>" \
  -H "Accept: application/json" \
  http://localhost:8080/api/v1/volumes
```

Token abilities:

- `read`: inspect dashboard data, volumes, jobs, runs, logs, and admin-only safe destination/notification metadata.
- `write`: create or update resources and trigger operations such as volume sync, backup runs, restore runs, destination tests, and notification tests.

Write operations still require an admin user, and secrets are never returned in plaintext by the API. Responses only include masked indicators such as `has_access_key_id`, `has_secret_access_key`, and `masked_*` fields.

When restoring, `selected_backup_key` must be one of the keys returned by `GET /api/v1/backup-jobs/{id}/backups` - it is checked against the destination listing, so arbitrary or path-traversal keys are rejected. Volume names (`volume_name`, `target_volume_name`) must match `^[A-Za-z0-9_.-]+$`.

`POST /api/v1/stacks/backup` backs up a whole Compose or Swarm stack in one call. Pass `{ "stack": "<name>" }` (use `null` for volumes that carry no stack label). For every Docker volume in the stack that has no backup job yet, a job is created from `backup_destination_id` and the `schedule_type` / `schedule_config` / `timezone` you provide, then a manual run is queued for every Docker-volume job in the stack. When the stack is already fully configured, those fields can be omitted to simply queue a run for each existing job. The response (`202`) is a `{ "data": { "created", "queued", "skipped" } }` summary; jobs that cannot run right now (inactive, already running, missing volume) are counted in `skipped` instead of aborting the batch.

Host-path backup sources and local destinations (`settings.archive_path` / `archive_mount_source`) must match the fail-closed `VOLUMEVAULT_HOST_PATH_ALLOWLIST`. `GET /api/v1/host-path-allowlist` returns the allowed prefixes (`configured: false` means host paths are refused), so an integration can validate paths before creating a job or destination instead of relying on `422` errors.

For SSH/SFTP destinations, set `settings.host_key` (an OpenSSH public host key line or a `SHA256:` fingerprint) on create/update to pin the server and block man-in-the-middle attacks. `POST /api/v1/destinations/host-key` (`{ "host": "...", "port": 22 }`) connects without authenticating and returns the key and fingerprint a server currently presents, so an integration can pin it (trust on first use).

Useful API calls:

```text
GET    /api/v1/openapi.json
GET    /api/v1/me
GET    /api/v1/dashboard
GET    /api/v1/volumes
POST   /api/v1/volumes/sync
POST   /api/v1/stacks/backup
GET    /api/v1/host-path-allowlist
GET    /api/v1/backup-jobs
POST   /api/v1/backup-jobs
GET    /api/v1/backup-jobs/{id}
PUT    /api/v1/backup-jobs/{id}
DELETE /api/v1/backup-jobs/{id}
POST   /api/v1/backup-jobs/{id}/run
POST   /api/v1/backup-jobs/{id}/pause
POST   /api/v1/backup-jobs/{id}/resume
GET    /api/v1/backup-jobs/{id}/backups
POST   /api/v1/backup-jobs/{id}/restore
GET    /api/v1/backup-runs
GET    /api/v1/backup-runs/{id}
GET    /api/v1/restore-runs
GET    /api/v1/restore-runs/{id}
GET    /api/v1/destinations
POST   /api/v1/destinations
POST   /api/v1/destinations/host-key
GET    /api/v1/destinations/{id}
PUT    /api/v1/destinations/{id}
DELETE /api/v1/destinations/{id}
POST   /api/v1/destinations/{id}/test
GET    /api/v1/notifications
GET    /api/v1/notifications/{id}
POST   /api/v1/notifications/{id}/test
```
