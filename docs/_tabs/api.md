---
title: API
icon: fas fa-code
order: 5
---

## Automation And AI-Friendly API

VolumeVault exposes a versioned HTTP API secured with Laravel Sanctum tokens and documented through an OpenAPI schema at `/api/v1/openapi.json`.

This makes the project friendly to automation tools, monitoring scripts, dashboards, and AI agents that need to inspect backup state or trigger explicit operations without scraping the web UI.

Volumes, jobs and backup runs include `docker_host_id`; restore runs include `source_docker_host_id` and `target_docker_host_id`. Backup job creation accepts `docker_host_id` (default `1`, the built-in local host); updates preserve the current host when omitted. Changing a source or host is refused while a run or cleanup remains outstanding. Inventory validation always uses the selected host. Remote jobs can be standalone or grouped, including groups spanning multiple agents or local and remote members. Remote Docker-label reconciliation requires agents advertising `docker-labels-v1`.

### Docker-label backup settings

Administrators can read `GET /api/v1/settings/docker-label-backups?docker_host_id=2` with the `read` token ability and update `PUT /api/v1/settings/docker-label-backups` with the `write` ability. Include `docker_host_id` in the update body; omitting the selector defaults to the built-in local host (`1`) for both endpoints. Send the full settings fields when updating, including `enabled`, `backup_destination_id` and schedule/retention/filter/notification defaults.

Responses include `settings` (with `docker_host_id`, `last_sync_error` and `last_synced_at`), safe `hosts` summaries with `supports_docker_labels`, active shared or same-host `destinations`, `notificationChannels` and `timezones`. Remote changes reconcile on the next complete compatible agent inventory. Older or incomplete inventories preserve existing label-managed jobs and surface synchronization errors. The web settings page uses the same selector on `GET` and `PUT /settings/docker-label-backups`.

Agents use a separate, versioned HTTPS protocol under `/agent/v1`, with agent-specific credentials that cannot authenticate to the public API or administrator routes. Enrollment and revocation are managed through the administrator-only Docker hosts page. Remote work requires a registered, non-revoked protocol-v1 agent advertising `backup-v1` or `restore-v1` for the requested operation. Configuration and queuing do not require the agent to be online; execution is claimed when it polls. Public API user tokens cannot authenticate as agents.

Agent execution uses POST `/agent/v1/operations/pull`, `/agent/v1/operations/{uuid}/progress` and `/agent/v1/operations/{uuid}/complete`. Pull responses carry credential-bound encrypted envelopes, not plaintext destination configurations. Assignments remain bound to their host until a cleanup-complete result is acknowledged. Repeated pull/result delivery is idempotent; these endpoints are not a generic Docker or shell proxy.

Agent heartbeats additionally report protocol/capabilities and active operation counts, and acknowledge a server-issued maintenance nonce. Maintenance and manual update guides are administrator-only web endpoints. In orchestrator-only mode, local execution mutations return validation errors while remote jobs and groups remain configurable and scheduled. Maintenance admission is checked on the execution host (the target for restores). An assigned group member may drain during maintenance, but the next member waits when its host is in maintenance.

Groups may contain local, remote or mixed-host members. Each member retains its own host-scoped source and destination: local filesystem and Docker-volume destinations must belong to that member's host. Remote members reuse `backup-v1`; there is no group-specific agent protocol. Group detail members and group-run member records expose `docker_host_id` and a safe `docker_host` identity (`id`, `name`, `is_local`).

Remote/mixed runs snapshot membership, sources and the `continue`/`stop` failure policy and use a durable central coordinator. Execution is sequential: each member completes its configured stop/backup/restart cycle before the next member is dispatched. A failed member makes the aggregate outcome fail; `continue` processes remaining members and `stop` skips them. Notifications remain one group start and one aggregate success/failure notification, not per-member notifications. This is not a consistent cross-host snapshot and does not stop all containers together. Purely local groups retain their synchronous execution path. Cross-host relaying of host-local archives remains unavailable; destination browsing/testing uses the separate `destination-v1` capability below.

Host-local destinations (`local` and `docker_volume`) accept `docker_host_id`, defaulting to `1` on creation and preserving the owner on update. A backup job cannot use another host's local destination. Network destinations have no host owner. Remote bind paths are checked lexically against the agent's reported allowlist without mounting or resolving them centrally; the agent revalidates its authoritative policy at execution. Destination secrets remain encrypted and are never returned or prefilled.

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

When restoring, `selected_backup_key` must be an exact provider key from a verified listing or a known historical record. A destination operation listing can provide a host-bound receipt as described below. Keys are opaque: do not normalize case, trim them, replace them with display names, or reconstruct Dropbox IDs. Volume names (`volume_name`, `target_volume_name`) must match `^[A-Za-z0-9_.-]+$`.

### Asynchronous destination operations

Administrators with the `write` token ability can submit `POST /api/v1/destinations/{id}/operations`:

```json
{ "action": "list", "docker_host_id": 2, "limit": 100, "cursor": null }
```

- `action` is required: `test`, `stats`, or `list`.
- `backup_run_id` is optional and allowed only for `list`. It requests an exact historical archive lookup using the run's server-resolved destination snapshot and provider identity, including a Dropbox stable file ID even if the archive moved outside the configured folder. The result contains zero or one object and `next_cursor: null`; do not combine this lookup with a pagination cursor. The operation response includes `backup_run_id` (or `null` for generic listings). Clients must match this run context when resuming or polling a listing receipt and use the resolved run ID after historical-context navigation. Arbitrary `selected_backup_key` or `selected_backup` request parameters are prohibited.
- `docker_host_id` is optional. Shared network destinations default to central host `1`; host-local destinations default to their owner and reject any other host. Remote execution requires a registered compatible agent advertising `destination-v1` and accepting work outside maintenance.
- `limit` is an integer from **1–1000**, default **1000**, for each list page.
- `cursor` is optional and only meaningful for `list`. Pass `result.data.next_cursor` unchanged when requesting the next page with the **same destination, locator, host and limit**. Cursors expire one hour after the listing operation completes; invalid, changed-context or expired cursors return validation errors. Restart listing without a cursor to recover.

The response is **202**, with `{ "data": operation }`. Poll `GET /api/v1/destinations/{id}/operations/{operation}` using the `read` ability. Its `data` includes:

```json
{
  "id": "operation-uuid",
  "destination_id": 9,
  "docker_host_id": 2,
  "action": "list",
  "status": "pending",
  "result": null,
  "locator_current": true,
  "fresh_until": null
}
```

Status progresses through `pending`, `running`, and `completed`. Offline agents may leave work pending. Completion does **not** imply success: inspect `result.status` (`success` or `failed`) and any `result.error_message`. Successful `result.data` is action-specific:

| Action | Data |
| --- | --- |
| `test` | `{ "ok": true }` |
| `stats` | `{ "used_bytes": 1234, "object_count": 10 }` |
| `list` | `{ "objects": [{ "key": "opaque-key", "display_name": "backup.tar.gz", "size": 1234, "last_modified": null }], "next_cursor": null }` |

`last_modified` may instead contain a date string. Failed results have `data: null`. Results are limited to **2 MiB**, and lists to the requested limit (at most **1000 objects per page**). Use smaller pages if necessary. Offset-based pagination over changing directories is **not a snapshot**: concurrent additions/removals can cause skipped or repeated objects. Clients should deduplicate by exact key, not display name.

`locator_current: false` means the destination location changed since the operation was created. `fresh_until` is the claim time (or creation time for unclaimed operations) plus **30 minutes**, exposed after completion. Polling or reusing a cursor does not extend freshness. For a restore chosen from a listing, send `destination_operation_id` with `selected_backup_key` and `target_docker_host_id` to the restore endpoint. The receipt must be a successful fresh **list** operation matching the destination's current locator, target host and exact key on that receipt's page. Keep the original page receipt when selecting an archive after fetching later pages. Refresh and reselect if expired; a stats/test operation is not a restore receipt.

The authenticated web UI uses the same JSON contract at `POST /destinations/{id}/operations` and `GET /destinations/{id}/operations/{operation}` with session authentication and CSRF protection. These JSON calls do not use Inertia page navigation.

`POST /api/v1/backup-jobs/{id}/restore` accepts these restore modes:

The optional `target_docker_host_id` defaults to the job's current host. With a shared network destination, `new_volume` can restore an archive from host A onto host B, including the same volume name when absent on B. The source host and source name come from the selected historical `backup_run_id`, not the job's current configuration. In-place restores require an available volume on the target and exact typed confirmation. A safety backup additionally requires the job and its current destination to be usable on that target host.

Without a destination-operation receipt, network archive keys retain central verification. For an agent-owned local destination, a successful historical `backup_run_id` with its exact `selected_backup_key` and unchanged destination locator remains supported without a receipt, including older agents. The backup-list endpoint can return known matching runs with `backup_run_id`, `verification_deferred: true` and `listing_supported: false` for the older-agent fallback; actual archive existence is checked by the agent before modifying the target. When selecting a compatible remote host via `docker_host_id`, listing can instead return a `202` operation to poll. Host-local archives cannot be restored onto another host: archive transfer between host-local destinations is not yet supported.

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
POST   /api/v1/destinations/{id}/operations
GET    /api/v1/destinations/{id}/operations/{operation}
GET    /api/v1/notifications
GET    /api/v1/notifications/{id}
POST   /api/v1/notifications/{id}/test
```
