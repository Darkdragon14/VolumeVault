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

`GET /api/v1/backup-jobs` accepts `sort=created_at|name|next_run_at|last_run_at` and `direction=asc|desc`. Date sorts always place jobs without a date last and use the job name as a stable tie-breaker.

When restoring, `selected_backup_key` must be one of the keys returned by `GET /api/v1/backup-jobs/{id}/backups` - it is checked against the destination listing, so arbitrary or path-traversal keys are rejected. Volume names (`volume_name`, `target_volume_name`) must match `^[A-Za-z0-9_.-]+$`.

`POST /api/v1/backup-jobs/{id}/restore` accepts these restore modes:

- `new_volume`: restore into a fresh Docker volume. Provide `target_volume_name`; this is the safest mode and is also the only mode available for host-path backup jobs.
- `inplace`: overwrite the source Docker volume. This is available only for Docker-volume backup jobs and requires `confirmation_text` to exactly match the source volume name.
- `safe_inplace`: overwrite the source Docker volume after stopping containers that currently use it, then restart those containers after the restore finishes or fails. This has the same Docker-volume-only and `confirmation_text` requirements as `inplace`.

For `inplace` and `safe_inplace`, set `backup_before_overwrite` to `true` to take a safety backup before clearing the source volume. That safety backup appears as a backup run with trigger `pre_restore`; scheduled and manual backup runs continue to use `scheduled` and `manual`.

`POST /api/v1/stacks/backup` backs up a whole Compose or Swarm stack in one call. Pass `{ "stack": "<name>" }` (use `null` for volumes that carry no stack label). For every Docker volume in the stack that has no backup job yet, a job is created from `backup_destination_id` and the `schedule_type` / `schedule_config` / `timezone` you provide, then a manual run is queued for every Docker-volume job in the stack. When the stack is already fully configured, those fields can be omitted to simply queue a run for each existing job. The response (`202`) is a `{ "data": { "created", "queued", "skipped", "grouped" } }` summary; jobs that cannot run right now (inactive, already running, missing volume) are counted in `skipped` instead of aborting the batch. Volumes whose job belongs to a backup group are counted in `grouped` and are not run by the stack backup — they back up on their group's own schedule (trigger the group with `POST /api/v1/backup-groups/{id}/run` if you need them now).

Host-path backup sources and local destinations (`settings.archive_path` / `archive_mount_source`) must match the fail-closed `VOLUMEVAULT_HOST_PATH_ALLOWLIST`. `GET /api/v1/host-path-allowlist` returns the allowed prefixes (`configured: false` means host paths are refused), so an integration can validate paths before creating a job or destination instead of relying on `422` errors.

For SSH/SFTP destinations, set `settings.host_key` (an OpenSSH public host key line or a `SHA256:` fingerprint) on create/update to pin the server and block man-in-the-middle attacks. `POST /api/v1/destinations/host-key` (`{ "host": "...", "port": 22 }`) connects without authenticating and returns the key and fingerprint a server currently presents, so an integration can pin it (trust on first use).

`POST /api/v1/backup-groups` creates a backup group that owns the schedule, notifications and failure policy (`continue` or `stop`) for a set of member jobs. Attach a job to a group by creating or updating a backup job with `planning_mode: "group"` and either `backup_job_group_id` (an existing group) or `group_selection: "new"` plus a `new_group` object. `POST /api/v1/backup-groups/{id}/run` queues one group run that backs up every active member volume and emits a single start and success/fail notification; `GET /api/v1/backup-group-runs/{id}` returns the aggregated outcome with its per-volume member runs. A group cannot be deleted while it still has members. Group run payloads (`GET /api/v1/backup-group-runs`, the `recent_group_runs` lists and the dashboard) include `total_backup_size_bytes`, the sum of the member archive sizes; it stays `null` until at least one member size has been recorded, because sizes are written asynchronously shortly after each volume finishes. The dashboard stats also expose `last_successful_group_backup_size`, the aggregated size of the most recent successful group run.

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
GET    /api/v1/backup-groups
POST   /api/v1/backup-groups
GET    /api/v1/backup-groups/{id}
PUT    /api/v1/backup-groups/{id}
DELETE /api/v1/backup-groups/{id}
POST   /api/v1/backup-groups/{id}/run
POST   /api/v1/backup-groups/{id}/pause
POST   /api/v1/backup-groups/{id}/resume
PATCH  /api/v1/backup-groups/{id}/notifications
GET    /api/v1/backup-group-runs
GET    /api/v1/backup-group-runs/{id}
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
