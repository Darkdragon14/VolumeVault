---
title: Backup & Restore
icon: fas fa-rotate
order: 3
---

## How It Works

VolumeVault does not reimplement backup archive creation. Backup runs launch a temporary `offen/docker-volume-backup:latest` container with the selected Docker volume or host path mounted read-only under `/backup`. VolumeVault maps each configured destination to the environment variables expected by `offen/docker-volume-backup`.

Restore runs download and verify the selected archive through VolumeVault's destination layer, then extract it using the `offen/docker-volume-backup` image with `tar` as entrypoint. Restores can target a new Docker volume or, for Docker-volume backup jobs, overwrite the original volume with explicit confirmation.

Docker commands are built with array arguments through Symfony Process. Secrets are passed as process environment variables or temporary mounted secret files and are not logged by VolumeVault.

## Backup Jobs

To create a backup job:

1. Make sure Docker volumes have been synced from the Volumes screen.
2. Create and test at least one active destination.
3. Open `Backup jobs` and create a job for a Docker volume or an absolute host path.
4. Choose a schedule: hourly, daily, weekly, or cron.
5. Optionally set retention days, retention count, archive name template, file exclusion regexp, and container stop behavior.
6. Save the job and run it manually once to validate the destination and logs.

Backup times are interpreted in `APP_TIMEZONE`. For example, set `APP_TIMEZONE=Europe/Paris` if a daily schedule at `02:00` should run at 02:00 Paris time instead of 02:00 UTC.

Backup jobs can optionally exclude files from the archive with `BACKUP_EXCLUDE_REGEXP`. The value is a Go regular expression matched against each file's full path inside `BACKUP_SOURCES`. For example, `\.log$` excludes log files, `(^|/)cache(/|$)` excludes folders named `cache`, and `(^|/)node_modules(/|$)` excludes `node_modules` folders. Leave the field empty to include everything.

Backup jobs can also define an archive name template without the extension. Supported tokens are `{name}`, `{source}`, `{id}`, `{run}`, `{year}`, `{month}`, `{day}`, `{time}`, `{hour}`, `{minute}`, and `{second}`. `{name}` is the job name sanitized for filenames, `{source}` is the Docker volume or host path source, and `{id}` / `{run}` is the backup run ID. VolumeVault appends `.tar.gz` automatically. Include a uniqueness token such as `{id}` or `{time}` to avoid overwriting earlier archives with the same generated name.

## Host Path Sources

Host path backup jobs mount an existing directory from the Docker host into the temporary Offen container with a read-only Docker bind mount. The path is passed to Docker, while Offen only sees the mounted directory under `/backup`.

Host path rules:

- The path must be absolute, must already exist on the Docker host, and must be a directory.
- The filesystem root `/` is rejected.
- Paths containing `.` or `..` segments are rejected.
- `Stop containers before backup` is only available for Docker volume sources.
- The host path must match one of the comma-separated prefixes in `VOLUMEVAULT_HOST_PATH_ALLOWLIST`. This is fail-closed: when the allowlist is empty, host path sources (and local destinations) are refused.

Example allowlist:

```env
VOLUMEVAULT_HOST_PATH_ALLOWLIST=/srv,/mnt/data,/opt/stacks
```

Jobs outside those prefixes fail validation when saved (the error is shown on the host path field) and are re-checked at run time, so a path that is later swapped for a symlink pointing outside the allowlist is still refused.

> **Upgrading from a version without the fail-closed allowlist?** Earlier releases allowed any host path when `VOLUMEVAULT_HOST_PATH_ALLOWLIST` was empty. After upgrading, existing host-path sources and local destinations are refused until their paths are allowlisted. Run the audit command to get the exact value to add to your `.env`:
>
> ```bash
> php artisan volumevault:host-path-allowlist:audit
> ```
>
> It prints the `VOLUMEVAULT_HOST_PATH_ALLOWLIST=…` line that keeps your existing jobs and destinations working (nothing more). The command also runs hourly on the scheduler and records a warning in the activity log if any in-use path is blocked, so the breakage is never silent.
{: .prompt-warning }

## Scheduling

VolumeVault uses Laravel Scheduler and the database queue:

- The scheduler runs `DispatchDueBackupJobsJob` every minute.
- It finds active jobs whose `next_run_at` is due.
- It creates a queued backup run and dispatches `RunBackupJob`.
- A separate scheduled job syncs Docker volumes every five minutes.

For non-Docker local development, run:

```bash
php artisan queue:work --tries=1 --timeout=0
php artisan schedule:work
```

## Backup Engine Details

Backups are run by launching a temporary `offen/docker-volume-backup:latest` container.

The environment variable mapping for `offen/docker-volume-backup` is centralized in `app/Actions/Docker/RunBackupContainer.php`. Check the upstream `offen/docker-volume-backup` documentation if an environment variable changes.

By default, generated archive names follow this pattern:

```text
volumevault-<safe-source-name>-run-<backup-run-id>.tar.gz
```

Existing jobs with no archive name template keep that legacy pattern.

## Restore Behavior

Restore-to-new-volume remains the default and safest mode because it never overwrites the source. Host path backups are always restored into a new Docker volume.

Available restore modes:

- `Restore to new volume`: creates a new Docker volume, downloads and verifies the selected archive, then extracts into the new volume.
- `Restore in place`: available only for Docker volume sources. It downloads and verifies the selected archive, requires you to retype the exact source volume name, clears the source volume, then extracts the archive back into that same volume.
- `Safe in-place restore`: available only for Docker volume sources. It performs the same destructive overwrite as restore-in-place, but first stops running containers that use the volume and restarts them after the restore finishes or fails.

In-place modes are destructive. They are restricted to Docker volume backup jobs, ignore custom target volume names, and require typed confirmation of the source volume name before the restore can be queued.

For both in-place modes, you can optionally back up the current contents of the source volume before it is overwritten. This pre-restore safety backup uses the backup job's configured destination, is linked from the restore details, and aborts the restore before any wipe if it fails.

The restore wizard lists backup objects from the selected job's destination, lets you filter by archive name or displayed date, marks the latest archive, and can be opened directly from a backup run through `Restore this backup`.

## Notifications

VolumeVault sends backup and restore notifications through Shoutrrr. Each backup job has its own notification toggle and selected notification channels; restore runs reuse the channels configured on their backup job.

Supported guided setup modes:

- Discord webhook.
- Telegram bot.
- Ntfy topic.
- Gotify application token.
- SMTP email.
- Advanced mode with any complete Shoutrrr URL for other supported services.

Notification levels:

- `Errors only`: sends notifications only for failed backup and restore runs.
- `Every backup and restore run`: sends notifications for successful backup runs and restore start/success events, in addition to failures.

Restore failures are sent to every selected channel, including channels configured as `Errors only`. Restore start and success messages are sent only to channels configured for every run. Notification delivery errors are logged but never interrupt the backup or restore itself.

Per-job notification configuration:

- `Enable notifications for this job`: controls whether that backup job sends notifications.
- Selected channels: one or more configured channels used by that job.
- Inactive channels stay selectable but do not send until reactivated.

One notification channel can be marked as the default channel. It is preselected when creating new backup jobs, but users can change the selection and choose any combination of channels.

Notification URLs are encrypted at rest and never returned to the frontend or API after saving. Use the channel test button after setup to verify the target service.

Channels can optionally override backup and restore notification titles and bodies with templates.

Backup template tokens: `{{ job }}`, `{{ source }}`, `{{ volume }}`, `{{ destination }}`, `{{ status }}`, `{{ trigger }}`, `{{ user }}`, `{{ duration }}`, `{{ backup_size }}`, and `{{ error }}`.

Restore template tokens: `{{ job }}`, `{{ source }}`, `{{ target }}`, `{{ mode }}`, `{{ status }}`, `{{ user }}`, `{{ duration }}`, and `{{ error }}`.

Notification tests and delivery run the Shoutrrr CLI image through Docker. Only admins can create, edit, delete, or test notification channels.
