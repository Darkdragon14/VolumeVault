You are building a self-hosted Laravel application named VolumeVault.

VolumeVault is a noob-friendly web UI to manage Docker volume backups and restores to storage supported by `offen/docker-volume-backup`.

The goal is to create a reliable self-hosted tool for homelab users who want to:
- discover Docker volumes
- configure scheduled backups
- send backups to S3 / Cloudflare R2 / custom S3-compatible storage and the other storage backends supported by `offen/docker-volume-backup`
- pause/resume backup jobs
- detect missing volumes automatically
- run manual backups
- view logs and run history
- restore backups safely, with restore-to-new-volume as the default mode

The application must be production-minded, but the first implementation should remain a realistic MVP.

Do not build only a design document.
Generate a working initial implementation.

---

# Tech stack

Use:

- Laravel 11 or latest stable Laravel version available
- PHP 8.3+
- Vue 3
- Inertia.js
- TypeScript for frontend if possible
- Tailwind CSS
- SQLite for MVP
- Laravel database queue driver
- Laravel Scheduler
- Symfony Process for Docker CLI orchestration
- AWS SDK for PHP for S3-compatible object listing/testing if needed
- Docker Compose for self-hosted deployment

Backup engine:

- Use `offen/docker-volume-backup` as the backup/restore engine.
- Do not reimplement low-level backup logic.
- VolumeVault should orchestrate Docker containers running `offen/docker-volume-backup`.

Docker access:

- The app container will mount `/var/run/docker.sock`.
- Use the Docker CLI through Symfony Process for the MVP.
- Keep Docker operations isolated behind action/service classes so they can later be replaced by Docker Engine API calls.

---

# Important security model

This app has high privileges because it can access the Docker socket.

The README must clearly warn:

> Mounting `/var/run/docker.sock` gives this application high privileges on the Docker host. Only run VolumeVault in a trusted environment.

Secrets:

- Destination credentials must never be stored in plaintext.
- Use Laravel `Crypt` to encrypt credentials at rest.
- Never log plaintext secrets.
- Never return decrypted secrets to the frontend.
- When editing a destination, do not prefill secret fields.
- Allow updating secret fields only when the user explicitly provides new values.
- Document that losing `APP_KEY` means encrypted credentials can no longer be decrypted.

---

# Main concepts

The app should manage these resources:

1. Docker volumes
2. Backup destinations
3. Backup jobs
4. Backup runs
5. Restore runs
6. Audit events or activity logs if simple enough

---

# Database models and tables

Create migrations and Eloquent models for the following.

## docker_volumes

Fields:

- id
- name unique
- driver nullable
- mountpoint nullable
- labels json nullable
- options json nullable
- exists boolean default true
- last_seen_at nullable timestamp
- created_at
- updated_at

Purpose:

- Store discovered Docker volumes.
- Track missing volumes.
- Allow UI to show known volumes even after they disappear.

---

## backup_destinations

Fields:

- id
- name
- provider enum/string:
  - aws_s3
  - cloudflare_r2
  - custom_s3
- endpoint nullable
- region nullable
- bucket
- path_prefix nullable
- access_key_id encrypted text
- secret_access_key encrypted text
- use_path_style_endpoint boolean default false
- is_active boolean default true
- last_tested_at nullable timestamp
- last_test_status nullable string:
  - success
  - failed
- last_test_error nullable text
- created_at
- updated_at

Security:

- access_key_id and secret_access_key must be encrypted using Laravel Crypt.
- Do not expose decrypted values in API/Inertia props.
- Provide masked display values if needed, such as `********`.

Provider notes:

- AWS S3 should usually use AWS endpoint defaults.
- Cloudflare R2 requires a custom endpoint.
- Custom S3 allows endpoint + region + path-style toggle.

---

## backup_jobs

Fields:

- id
- name
- volume_name
- backup_destination_id foreign key
- schedule_type string:
  - hourly
  - daily
  - weekly
  - cron
- schedule_config json nullable

Examples:

hourly:
```json
{
  "everyHours": 6
}
```
daily:
```json
{
  "time": "02:00"
}
```
weekly:
```json
{
  "dayOfWeek": "sunday",
  "time": "03:00"
}
```
cron:
```json
{
  "expression": "0 2 * * *"
}
```
Additional fields:

- cron_expression nullable string
- status string:
- active
- paused
- error
- running
- pause_reason nullable text
- last_run_at nullable timestamp
- next_run_at nullable timestamp
- last_success_at nullable timestamp
- last_error nullable text
- retention_days nullable integer
- retention_count nullable integer
- stop_containers_before_backup boolean default false
- created_at
- updated_at

Rules:

- A job references a Docker volume by volume name.
- If the volume disappears, mark the job as error or paused with a clear reason.
- Do not run jobs whose status is paused, error, or running.
- Prevent concurrent runs for the same job.
- Keep next_run_at updated after each scheduler tick and after each run.

---

## backup_runs

Fields:

- id
- backup_job_id foreign key
- status string:
- queued
- running
- success
- failed
- cancelled
- trigger string:
- scheduled
- manual
- started_at nullable timestamp
- finished_at nullable timestamp
- duration_seconds nullable integer
- logs long text nullable
- error_message nullable text
- docker_container_id nullable string
- created_at
- updated_at

Purpose:

- Store each backup attempt.
- Display history and logs.
- Allow debugging from UI.

---

## restore_runs

Fields:

- id
- backup_job_id foreign key
- backup_destination_id foreign key nullable
- selected_backup_key string
- source_volume_name string
- target_volume_name string
- mode string:
- new_volume
- inplace
- safe_inplace
- status string:
- queued
- running
- success
- failed
- cancelled
- affected_containers json nullable
- confirmation_text nullable string
- started_at nullable timestamp
- finished_at nullable timestamp
- duration_seconds nullable integer
- logs long text nullable
- error_message nullable text
- docker_container_id nullable string
- created_at
- updated_at

MVP restore requirement:

- Fully implement new_volume.
- Structure code for inplace and safe_inplace.
- It is okay if inplace and safe_inplace are disabled in the UI at first, but the model and service architecture should support them later.

Safety:

- Default restore mode must be new_volume.
- Never overwrite an existing volume by default.
- In-place restore must require typing the target volume name before enabling the restore action.
- Safe in-place restore must detect containers using the volume, show them to the user, stop them, restore, then restart them.

---

## activity_logs or audit_events

If simple enough, create a small activity table:

Fields:

- id
- event_type string
- subject_type nullable string
- subject_id nullable integer
- message text
- context json nullable
- created_at

Use it for:

- backup job created
- backup run started
- backup run failed
- restore run started
- missing volume detected
- destination test failed

This can be simple.

---

## Backend architecture

Use a modular Laravel structure.

Suggested folders:
```text
app/
  Actions/
    Docker/
      ListDockerVolumes.php
      InspectDockerVolume.php
      CreateDockerVolume.php
      RemoveDockerVolume.php
      FindContainersUsingVolume.php
      StopDockerContainers.php
      StartDockerContainers.php
      RunBackupContainer.php
      RunRestoreContainer.php

    Backup/
      CreateBackupRun.php
      RunBackup.php
      MarkMissingVolumeJobs.php

    Restore/
      GenerateRestoreVolumeName.php
      CreateRestoreRun.php
      RunRestore.php

  Jobs/
    SyncDockerVolumesJob.php
    RunBackupJob.php
    RunRestoreJob.php
    DispatchDueBackupJobsJob.php

  Models/
    DockerVolume.php
    BackupDestination.php
    BackupJob.php
    BackupRun.php
    RestoreRun.php
    ActivityLog.php

  Services/
    Docker/
      DockerProcess.php

    Scheduling/
      BackupScheduleCalculator.php
      CronExpressionValidator.php

    S3/
      S3ClientFactory.php
      TestS3Destination.php
      ListBackupObjects.php

    Logging/
      AppendRunLog.php
```

Keep code clean and testable.

---

## Docker process abstraction

Create a DockerProcess service that wraps Symfony Process.

Requirements:

- Run Docker CLI commands safely.
- Use array arguments, not shell strings.
- Capture stdout and stderr.
- Provide timeout handling.
- Never log environment variables containing secrets.
- Return structured results.

Example interface concept:
```PHP
$result = $dockerProcess->run([
    'docker',
    'volume',
    'ls',
    '--format',
    '{{json .}}',
]);
```

Do not concatenate shell commands.

---

## Docker volume discovery

Implement ListDockerVolumes.

It should run:
```Bash
docker volume ls --format '{{json .}}'
```
Then optionally inspect each volume using:
```Bash
docker volume inspect <volume_name>
```
Parse:

- name
- driver
- mountpoint
- labels
- options

Sync behavior:

- Mark currently found volumes as exists = true.
- Update last_seen_at.
- Mark previously known but now missing volumes as exists = false.
- For configured backup jobs referencing missing volumes:
  - set status to error
  - set pause_reason or last_error to Docker volume not found
  - do not delete the job.

Add a manual “Sync volumes” button in UI.

Also schedule volume sync periodically, e.g. every 5 minutes.

---

## Finding containers using a volume

Implement FindContainersUsingVolume.

For MVP, use Docker CLI.

Possible approach:
```Bash
docker ps -a --filter volume=<volume_name> --format '{{json .}}'
```
Return:

- container id
- names
- image
- state/status

Use this for future safe restore and optional backup with container stop.

---

## Backup destination handling

Create CRUD for destinations.

Validation:

- name required
- provider required
- bucket required
- endpoint required for cloudflare_r2 and custom_s3
- access_key_id required on create
- secret_access_key required on create
- on update, credentials are optional; keep old encrypted values if empty

Test connection:

- Add a “Test connection” action.
- Use AWS SDK S3 client.
- Attempt to list objects with prefix or call HeadBucket where supported.
- For Cloudflare R2, use endpoint + credentials.
- Store last_tested_at, last_test_status, last_test_error.
- Return user-friendly errors.

Frontend:

- When editing a destination, do not display the existing secret.
- Show "Credentials are already saved. Leave blank to keep existing values."

---

## Schedule handling

Implement BackupScheduleCalculator.

It must support:

hourly:
- every X hours
- X must be between 1 and 24
daily:
- at HH:mm
weekly:
- selected day of week
- at HH:mm
cron:
- advanced expression
- validate expression

The calculator should:

- normalize schedule_config
- compute cron_expression when possible
- compute next_run_at from now
- provide a human-readable schedule summary for the UI

Examples:

- "Every 6 hours"
- "Every day at 02:00"
- "Every Sunday at 03:00"
- "Cron: 0 2 * * *"

Use a cron expression library if needed.

Scheduler design:

Do not create one Laravel schedule entry per backup job.

Instead:

- Laravel Scheduler runs one task every minute.
- That task dispatches DispatchDueBackupJobsJob.
- It queries backup_jobs where:
  - status = active
  - next_run_at <= now
- It dispatches RunBackupJob for each due job.
- It should skip jobs already running.
- After dispatch or completion, update next_run_at.

This avoids complex dynamic scheduler registration.

---

## Backup run behavior

When a backup job is due or manually triggered:

1. Verify job is active unless manual run explicitly supports running paused jobs. For MVP, only active jobs should run.
2. Verify the volume exists.
3. Verify destination is active.
4. Prevent concurrent runs for the same job.
5. Create a backup_runs row with status queued.
6. Dispatch RunBackupJob.
7. Set run status to running.
8. Launch offen/docker-volume-backup as a temporary Docker container.
9. Capture logs.
10. Mark success or failed.
11. Update backup job:
  - last_run_at
  - last_success_at if success
  - last_error if failed
  - next_run_at

Important:

- Always remove the temporary container after completion.
- Ensure failed containers are cleaned up.
- Append logs progressively if simple.
- For MVP, it is acceptable to store logs after the process finishes.

---

## offen/docker-volume-backup orchestration

Use offen/docker-volume-backup as the container image.

Create a dedicated action:
```Text
app/Actions/Docker/RunBackupContainer.php
```
It should receive:

- volume name
- destination config
- job options
- backup run id

It should build a Docker command using Symfony Process array arguments.

It must:

- mount Docker volume into the path expected by offen/docker-volume-backup
- pass provider credentials and endpoint/path config via env vars
- pass target and prefix config when the provider supports it
- pass any required archive naming config
- not log secrets

Because exact offen/docker-volume-backup environment variables may change, structure the code so env var mapping is centralized in one class/method and easy to adjust.

Add clear comments saying:

> Check offen/docker-volume-backup documentation if an environment variable changes.

The implementation should be practical and easy to update.

Required support:

AWS S3
Cloudflare R2 via custom S3 endpoint
Custom S3-compatible endpoint

For Cloudflare R2, support endpoint like:
```Text
https://<account_id>.r2.cloudflarestorage.com
```

---

## Restore support

Restore is mandatory.

Implement a restore wizard.

MVP must fully support:

### Restore to new volume

Flow:

1. User opens restore page for a backup job.
2. Backend lists available backup objects/snapshots from destination.
3. User selects backup object/key.
4. User chooses restore mode.
5. Default mode is new_volume.
6. Backend generates target volume name:
```
<source_volume>_restored_<YYYYMMDD_HHmmss>
```
7. Ensure target volume does not already exist.
8. Create the Docker volume.
9. Run offen/docker-volume-backup restore container.
10. Mount target volume.
11. Restore selected backup into target volume.
12. Store restore logs.
13. Show result in UI.

Generate restore volume names safely:

- replace invalid characters with _
- avoid overly long names if needed
- ensure uniqueness by appending suffix if collision exists

### In-place restore

Structure the code and UI for it, but it can be disabled for MVP.

Safety requirements when enabled later:

- Show strong warning.
- Require typing the target volume name.
- Never run without confirmation.
- Detect containers using the volume and warn the user.

### Safe in-place restore

Structure code for later.

Expected behavior when implemented later:

- Detect containers using target volume.
- Show affected containers.
- Stop containers.
- Restore.
- Restart containers.
- If restart fails, show clear error.

---

## Backup archive listing

Implement endpoint/service:

ListBackupObjects

For MVP:

- list objects under destination path prefix
- return:
  - key
  - size
  - last_modified
- sort newest first
- filter only plausible backup files if possible

UI should show:

- date
- key/name
- size
- select button

If no backup is found, show a helpful empty state.


---

## HTTP routes / controllers

Use Laravel controllers and Inertia pages.

Suggested routes:
```Text
GET /                      -> redirect dashboard
GET /dashboard             -> DashboardController

GET /volumes               -> VolumeController@index
POST /volumes/sync         -> VolumeController@sync

GET /destinations          -> DestinationController@index
GET /destinations/create   -> DestinationController@create
POST /destinations         -> DestinationController@store
GET /destinations/{id}/edit -> DestinationController@edit
PUT /destinations/{id}     -> DestinationController@update
DELETE /destinations/{id}  -> DestinationController@destroy
POST /destinations/{id}/test -> DestinationController@test

GET /backup-jobs          -> BackupJobController@index
GET /backup-jobs/create   -> BackupJobController@create
POST /backup-jobs         -> BackupJobController@store
GET /backup-jobs/{id}     -> BackupJobController@show
GET /backup-jobs/{id}/edit -> BackupJobController@edit
PUT /backup-jobs/{id}     -> BackupJobController@update
DELETE /backup-jobs/{id}  -> BackupJobController@destroy
POST /backup-jobs/{id}/run -> BackupJobController@runNow
POST /backup-jobs/{id}/pause -> BackupJobController@pause
POST /backup-jobs/{id}/resume -> BackupJobController@resume

GET /backup-runs/{id}     -> BackupRunController@show

GET /backup-jobs/{id}/restore -> RestoreController@create
GET /backup-jobs/{id}/backups -> RestoreController@listBackups
POST /backup-jobs/{id}/restore -> RestoreController@store
GET /restore-runs/{id} -> RestoreRunController@show
```
Use Form Request classes for validation.

---

## API/Inertia data safety

When sending backup destinations to frontend:

Do not include:

- decrypted access_key_id
- decrypted secret_access_key

Use only:

- id
- name
- provider
- endpoint
- region
- bucket
- path_prefix
- use_path_style_endpoint
- is_active
- last_tested_at
- last_test_status
- last_test_error
- masked credential indicators

---

## Frontend pages

Use Vue 3 + Inertia + Tailwind.

Create a clean dashboard-style layout.

### Pages:

#### Dashboard

Cards:

- Total Docker volumes
- Existing volumes
- Missing volumes
- Active backup jobs
- Paused jobs
- Jobs in error
- Last backup run status
- Next scheduled backup

Sections:

- Recent backup runs
- Recent restore runs
- Jobs with errors


#### Volumes page

Table:

-Name
-Driver
-Exists / Missing badge
-Last seen
-Actions:
  -Create backup job
  -View related jobs

Button:

- Sync volumes

Empty state:

- "No Docker volumes found. Make sure VolumeVault can access the Docker socket."


#### Destinations page

Table:

- Name
- Provider
- Bucket
- Endpoint
- Last test status
- Actions:
  - Test
  - Edit
  - Delete

Create/edit form:

- Name
- Provider
- Endpoint
- Region
- Bucket
- Path prefix
- Access key ID
- Secret access key
- Path-style endpoint checkbox

UX:

- For Cloudflare R2, show helper text explaining endpoint format.
- On edit, show "Leave credentials empty to keep existing credentials."


#### Backup jobs page

Table:

- Name
- Volume
- Destination
- Schedule summary
- Status badge
- Last run
- Next run
- Actions:
  - Run now
  - Pause/Resume
  - Restore
  - View
  - Edit
  - Delete

Status badges:

- Active
- Paused
- Error
- Running

#### Backup job create/edit page

Fields:

- Name
- Volume select
- Destination select
- Schedule mode
- Schedule config
- Retention days
- Retention count
- Stop containers before backup checkbox

Schedule UI:

Simple modes:

- Every X hours
- Daily at time
- Weekly on day + time

Advanced mode:

- Cron expression input

Show schedule summary before saving.

#### Job detail page

Show:

- Job info
- Status
- Last run
- Next run
- Last error if any
- Run history table
- Buttons:
  - Run now
  - Pause/Resume
  - Restore

Run history:

- status
- trigger
- started_at
- duration
- link to logs

#### Backup run detail page

Show:

- Job name
- Status
- Trigger
- Started/finished/duration
- Logs in monospace block
- Error message if any

#### Restore wizard

Steps:

1. Select backup
2. Select restore mode
3. Confirm
4. Run / result

Step 1:

- Fetch available backup objects.
- Show newest first.
- Show size and last modified.

Step 2:

Restore mode cards:

- Restore to new volume — recommended/default
- Restore in place — dangerous, disabled for MVP or marked "coming later"
- Safe in-place restore — disabled for MVP or marked "coming later"

For new volume:

- Show generated target volume name.
- Allow editing target volume name if safe.
- Validate it does not already exist.

Step 3:

Confirmation:

- Show source volume
- selected backup key
- target volume
- destination
- warning that restore can take time

Step 4:

Result:

- link to restore run detail
- show logs/status

#### Restore run detail page

Show:

- Status
- Source volume
- Target volume
- Mode
- Backup key
- Started/finished/duration
- Logs
- Error message if any

---

##Jobs and queue

Use Laravel database queue.

Create jobs:

- SyncDockerVolumesJob
  - Calls Docker volume sync
  - Updates DB
  - Marks missing volume jobs
- DispatchDueBackupJobsJob
  - Finds active due jobs
  - Prevents dispatching jobs already running
  - Creates backup run rows
  - Dispatches RunBackupJob
- RunBackupJob
  - Runs one backup
  - Updates backup_runs
  - Updates backup_jobs
  - Captures logs
  - Handles errors
- RunRestoreJob
  - Runs one restore
  - Updates restore_runs
  - Captures logs
  - Handles errors

---

## Scheduler

Configure Laravel Scheduler:

Every minute:

- dispatch due backup jobs

Every 5 minutes:

- sync Docker volumes

Example conceptual behavior:
```PHP
$schedule->job(new DispatchDueBackupJobsJob)->everyMinute()->withoutOverlapping();
$schedule->job(new SyncDockerVolumesJob)->everyFiveMinutes()->withoutOverlapping();
```
Also provide README instructions for running:
```Bash
php artisan schedule:work
php artisan queue:work
```
In Docker Compose, either:

- use separate containers for app, queue, scheduler
- or use a supervisor process

Preferred Compose services:

- app
- queue
- scheduler

---

##Docker Compose deployment

Provide:

- Dockerfile
- docker-compose.yml
- .env.example

Compose should include:
```YAML
services:
  app:
    build: .
    ports:
      - "8080:8000"
    volumes:
      - volumevault_data:/app/storage
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      APP_ENV: production
      APP_DEBUG: false
      APP_KEY: ...
      DB_CONNECTION: sqlite
      DB_DATABASE: /app/storage/database/database.sqlite
      QUEUE_CONNECTION: database

  queue:
    build: .
    command: php artisan queue:work --tries=1 --timeout=0
    volumes:
      - volumevault_data:/app/storage
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      ...

  scheduler:
    build: .
    command: php artisan schedule:work
    volumes:
      - volumevault_data:/app/storage
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      ...
```
Make sure SQLite database directory exists.

Volume:
```YAML
volumes:
  volumevault_data:
```

---

## README requirements

Create a clear README with:

1. What VolumeVault is
2. Current MVP features
3. Safety warning about Docker socket
4. Backup/restore safety notes
5. Environment variables
6. Docker Compose setup
7. First run instructions
8. How to generate APP_KEY
9. How to configure Cloudflare R2
10. How scheduling works
11. How restore works
12. Limitations
13. Roadmap

Safety notes:

- Always test restore before trusting backups.
- Restore-to-new-volume is safest.
- In-place restore can overwrite data and should be used carefully.
- For databases, application-consistent backups may require stopping containers or using database-native dumps.

---

## Testing requirements

Add tests for:

- Schedule calculator
  - hourly every 6 hours
  - daily at 02:00
  - weekly Sunday at 03:00
  - cron expression validation
  - next_run_at calculation
- Restore volume name generation
  - source volume my-app_data
  - generated name starts with my-app_data_restored_
  - invalid chars are sanitized
  - collisions generate unique names
- Destination secrets
  - encrypted credentials are not equal to plaintext
  - decrypted credentials match original
  - frontend serialization does not expose secrets
- Missing volume detection
  - job referencing missing volume is marked error
  - error message is clear

---

## UX quality requirements

The UI should be simple and friendly.

Use:

- clear status badges
- helpful empty states
- confirmation dialogs for destructive actions
- warnings for restore
- readable logs
- schedule summaries
- no raw cron requirement unless advanced mode is selected

Avoid:

- exposing raw stack traces to users
- exposing secrets
- making restore too easy to trigger accidentally
- deleting backup jobs without confirmation

---

## Non-goals for MVP

Do not implement in the first version:

- authentication / multi-user
- SaaS billing
- team management
- remote Docker hosts
- Kubernetes
- file-level restore
- backup diff viewer
- notifications
- webhook integrations
- OAuth
- complex RBAC
- advanced restic repository browsing unless required by offen/docker-volume-backup

However, keep the architecture clean enough so these can be added later.

---

## Implementation order

Start implementation in this order:

1. Create Laravel + Inertia + Vue project structure.
2. Add migrations and models.
3. Add Docker volume discovery and sync.
4. Add destinations CRUD with encrypted credentials.
5. Add destination test action.
6. Add schedule calculator.
7. Add backup jobs CRUD.
8. Add scheduler dispatch logic.
9. Add backup run model and queue job.
10. Add Docker backup container orchestration.
11. Add backup run logs.
12. Add restore-to-new-volume flow.
13. Add restore run logs.
14. Add dashboard.
15. Add Dockerfile and docker-compose.yml.
16. Add README.
17. Add tests.

---

## Code style
- Keep controllers thin.
- Use Form Requests for validation.
- Use Actions/Services for business logic.
- Use enums or constants for statuses where appropriate.
- Add clear comments around Docker/offen environment variable mapping.
- Prefer explicit error handling.
- Return user-friendly errors.
- Avoid hardcoding provider-specific values except defaults.
- Never build shell commands using string concatenation.

---

## Final expected result

Generate the initial working MVP implementation of VolumeVault.

The result should include:

- Laravel app
- Vue/Inertia frontend
- migrations
- models
- controllers
- jobs
- actions/services
- tests
- Dockerfile
- docker-compose.yml
- .env.example
- README

The app should be runnable locally with Docker Compose and should allow a user to:

1. open the UI
2. sync Docker volumes
3. create an S3/R2 destination
4. create a backup job
5. run a manual backup
6. see backup logs
7.list available backup objects
8. restore a selected backup into a new Docker volume

Prioritize backend correctness and backup/restore safety over visual polish. The UI should be clean but simple.
