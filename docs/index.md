---
layout: page
title: VolumeVault
permalink: /
toc: false
---

<p align="center">
  <img src="/assets/img/volumevault/logo.png" alt="VolumeVault" width="420">
</p>

# Docker Volume Backups With Safer Restores

VolumeVault is a self-hosted Laravel application for managing Docker volume backups and safe restores to storage backends supported by [`offen/docker-volume-backup`](https://github.com/offen/docker-volume-backup).

It provides a guided web UI around scheduled backups, encrypted destinations, notifications, restore runs, run history, onboarding, and API-driven automation while keeping operational risks explicit.

<p align="center">
  <img src="/assets/img/volumevault/dashboard.png" alt="VolumeVault dashboard preview" width="48%">
  <img src="/assets/img/volumevault/jobs.png" alt="VolumeVault backup jobs preview" width="48%">
</p>

## Highlights

- Discover Docker volumes through the Docker CLI and keep track of volumes that disappear later.
- Configure AWS S3, Cloudflare R2, custom S3-compatible storage, WebDAV, SSH/SFTP, Azure Blob Storage, Dropbox, Google Drive, and local filesystem destinations.
- Store destination credentials and notification URLs encrypted at rest with Laravel `Crypt`.
- Create hourly, daily, weekly, or cron-based backup schedules.
- Run manual backups, pause or resume jobs, inspect logs, and view backup and restore history.
- Restore selected archives into new Docker volumes by default.
- Configure Shoutrrr notification channels globally or per backup job.
- Create API tokens for integrations, automation scripts, dashboards, and AI agents.
- Export encrypted installation saves and import them during onboarding.

## Quick Start

Generate an application key first:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 php artisan key:generate --show
```

Start the provided Compose stack:

```bash
docker compose up -d --build
```

Open `http://localhost:8080`, then create the first administrator account from onboarding or import an existing installation save.

## Security Warning

Mounting `/var/run/docker.sock` gives VolumeVault high privileges on the Docker host. Only run it in a trusted environment, and treat access to the web UI and write-capable API tokens like access to the Docker host.

## Documentation

- [Installation]({{ '/installation/' | relative_url }}): Docker Compose setup, environment variables, `APP_KEY`, onboarding, and users.
- [Destinations]({{ '/destinations/' | relative_url }}): supported storage providers and destination behavior.
- [Backup & Restore]({{ '/backup-restore/' | relative_url }}): jobs, scheduling, backup engine details, restore behavior, and notifications.
- [Security]({{ '/security/' | relative_url }}): Docker socket risk, encrypted secrets, installation saves, password recovery, and safety notes.
- [API]({{ '/api/' | relative_url }}): Sanctum tokens, API abilities, and useful endpoints.
- [Development & Roadmap]({{ '/development-roadmap/' | relative_url }}): local development, tests, limitations, and roadmap.

## Credits

VolumeVault relies on [`offen/docker-volume-backup`](https://github.com/offen/docker-volume-backup) for the actual Docker volume backup engine and destination support.

Huge thanks to Offen and the maintainers of `offen/docker-volume-backup` for their work. VolumeVault exists as an orchestration and management UI around that project, not as a replacement for it.
