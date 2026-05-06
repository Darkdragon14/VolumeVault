---
title: API
icon: fas fa-code
order: 5
---

## Automation And AI-Friendly API

VolumeVault exposes a versioned HTTP API secured with Laravel Sanctum tokens and documented through an OpenAPI schema at `/api/v1/openapi.json`.

This makes the project friendly to automation tools, monitoring scripts, dashboards, and AI agents that need to inspect backup state or trigger explicit operations without scraping the web UI.

API tokens are created by admins from the `API tokens` screen. Tokens are displayed only once at creation and stored hashed after that.

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

Useful API calls:

```text
GET    /api/v1/openapi.json
GET    /api/v1/me
GET    /api/v1/dashboard
GET    /api/v1/volumes
POST   /api/v1/volumes/sync
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
GET    /api/v1/destinations/{id}
PUT    /api/v1/destinations/{id}
DELETE /api/v1/destinations/{id}
POST   /api/v1/destinations/{id}/test
GET    /api/v1/notifications
GET    /api/v1/notifications/{id}
POST   /api/v1/notifications/{id}/test
```
