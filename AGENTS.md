# AGENTS.md

## Project

VolumeVault is a self-hosted Laravel application for managing Docker volume backups and safe restores through `offen/docker-volume-backup`.

The app is aimed at homelab users and must remain production-minded while keeping implementation pragmatic and maintainable.

## Stack

- Laravel 13, PHP 8.5+
- Vue 3, Inertia.js, TypeScript where practical
- Tailwind CSS
- SQLite by default
- Laravel database queue driver
- Laravel Scheduler
- Docker CLI orchestration through Symfony Process
- `offen/docker-volume-backup` is the backup/restore engine

## Architecture

- Keep Docker operations isolated in `app/Actions/Docker`.
- Keep backup and restore business logic in `app/Actions/Backup` and `app/Actions/Restore`.
- Controllers should stay thin.
- Models should not expose decrypted secrets to frontend/API responses.
- Use queue jobs for backup, restore, and Docker volume sync work.
- Keep frontend pages under `resources/js/Pages` and shared UI under `resources/js/Components`.

## Security

This app can mount `/var/run/docker.sock`, which gives high privileges on the Docker host.

Rules:

- Never log plaintext secrets.
- Never return decrypted credentials to the frontend or API.
- Destination credentials must stay encrypted at rest.
- Do not prefill secret fields when editing destinations.
- Preserve the warning that losing `APP_KEY` makes encrypted data unrecoverable.
- Be conservative with restore workflows; restore-to-new-volume is the safe default.

## Product Rules

Core resources:

- Docker volumes
- Backup destinations
- Backup jobs
- Backup runs
- Restore runs
- Notification channels
- Installation saves
- API tokens
- Users

Backup jobs must not run concurrently for the same job. Missing Docker volumes should be detected and surfaced clearly.

## Frontend

Preserve the existing visual language and layout patterns.

- Use Vue 3 composition style.
- Use Inertia forms and props consistently with existing pages.
- Keep translations in `resources/js/i18n/locales`.
- Avoid introducing new UI abstractions unless they are reused.

## Verification

Run relevant checks after changes:

```bash
php artisan test
npm run build
./vendor/bin/pint
```

For frontend-only changes, at minimum run:

```bash
npm run build
```

## Documentation

Update `README.md` or `docs/` when behavior, deployment, security assumptions, API usage, or user workflows change.

## Editing Guidelines

- Prefer the smallest correct change.
- Do not reimplement low-level backup logic handled by `offen/docker-volume-backup`.
- Do not add backward compatibility unless persisted data, public APIs, or user-facing behavior require it.
- Avoid touching unrelated files.
