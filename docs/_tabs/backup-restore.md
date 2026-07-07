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
5. Optionally set retention days, retention count, archive name template, file filtering (include or exclude), and container stop behavior.
6. Save the job and run it manually once to validate the destination and logs.

Backup times are interpreted in `APP_TIMEZONE`. For example, set `APP_TIMEZONE=Europe/Paris` if a daily schedule at `02:00` should run at 02:00 Paris time instead of 02:00 UTC.

Backup jobs can optionally filter which files end up in the archive. Two modes are available:

- **Include only (simple)** — keep only the folders or files you list. Enter a comma-separated list of paths relative to the volume root (for example `Backups, config/app.conf`); everything else is skipped. This is the simplest way to back up just one or two folders. Paths are relative to the volume root as Offen sees it under `/backup/<mount>`, so use `Backups`, not the Docker host path `/_data/Backups`. Segments `.` and `..` are rejected, and an empty list backs up everything. VolumeVault generates the matching `BACKUP_EXCLUDE_REGEXP` automatically (`offen/docker-volume-backup` only supports exclusion, and Go's RE2 engine has no negative lookahead, so the include list is compiled into the equivalent exclusion regexp).
- **Exclude with regex (advanced)** — exclude files with `BACKUP_EXCLUDE_REGEXP`. The value is a Go regular expression matched against each file's full path inside `BACKUP_SOURCES`. For example, `\.log$` excludes log files, `(^|/)cache(/|$)` excludes folders named `cache`, and `(^|/)node_modules(/|$)` excludes `node_modules` folders. Leave the field empty to include everything.

In the web form, creating a job defaults to the simple include mode, while existing jobs keep their stored mode. Through the API the behavior differs for backward compatibility: `backup_filter_mode` is optional and defaults to `exclude` when omitted, so an API client that wants include mode must set `backup_filter_mode` to `include` explicitly. The related API fields are `backup_include_paths` (used in include mode) and `backup_exclude_regexp` (used in exclude mode).

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

## Backup Groups

A backup group backs up several volumes as a single scheduled operation, with **one** start notification and **one** success/failure notification for the whole set. This suits monitors that treat a check like a dead man's switch (for example Healthchecks.io), where a single endpoint should cover many volumes without one endpoint per volume.

The group owns the schedule, the notification channels, and the failure policy. Each volume is still an ordinary backup job with its own destination, retention, and archive, so restores stay per-volume and unchanged.

To create a group:

1. Open `Backup groups` and create a group, or select `Part of a group` while creating a backup job and choose `Create a new group`.
2. Set the group schedule (hourly, daily, weekly, or cron), timezone, notification channels, and failure policy.
3. Attach jobs from the backup job form: switch a job to group mode and pick the group. The job's own schedule and notifications are then managed by the group and its next run is driven by the group.

When a group runs:

- It sends one start notification, then backs up each active member volume in turn (one archive per volume).
- It sends one success notification if every volume succeeded, or one failure notification if any volume failed.
- The failure policy decides what happens after a volume fails: `Continue, report failure` backs up the remaining volumes and still reports failure, while `Stop at first failure` halts the run immediately. Either way the group reports failure when any volume fails.

Disable (pause) a member to skip its volume without removing it, and edit a member to switch it back to individual scheduling to detach it from the group. A group cannot be deleted while it still has members.

The dashboard shows groups as their own widgets (group counts, recent group runs, and groups in error), separate from standalone jobs, so a member failure surfaces as one group in error rather than many individual jobs. Each member volume keeps its own run history and restore, reachable from the job.

Proactive alerts still evaluate each member volume individually (backup too old, job in error too long, and so on); because the group owns notifications, a member's alert is delivered through the group's notification channels.

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
