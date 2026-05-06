---
title: Installation
icon: fas fa-download
order: 1
---

## Recommended Self-Hosted Setup

1. Generate an app key:

```bash
docker run --rm ghcr.io/darkdragon14/volumevault:latest php artisan key:generate --show
```

2. Create a `docker-compose.yml` file and paste the generated value in `APP_KEY`:

```yaml
services:
  volumevault:
    image: ghcr.io/darkdragon14/volumevault:latest
    ports:
      - "8080:8000"
    volumes:
      - volumevault_data:/app/storage
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      APP_KEY: base64:paste-generated-key-here
    restart: unless-stopped

volumes:
  volumevault_data:
```

3. Start VolumeVault:

```bash
docker compose up -d
```

4. Open `http://localhost:8080`.
5. Create the first administrator account from the onboarding screen, or import an existing installation save.

The recommended setup runs one container. At startup it prepares storage, runs database migrations, then starts the web app, queue worker, and scheduler under process supervision.

Production defaults are built into VolumeVault. Add environment variables only when you need to override them, for example `APP_URL`, `APP_TIMEZONE`, or SMTP settings.

## Large Installation Compose

For larger installations, you can split the migration, web app, queue worker, and scheduler into separate services while keeping the same image and storage volume:

```yaml
x-volumevault-environment: &volumevault-environment
  APP_KEY: ${APP_KEY:?Set APP_KEY before starting VolumeVault}

x-volumevault-service: &volumevault-service
  image: ghcr.io/darkdragon14/volumevault:latest
  volumes:
    - volumevault_data:/app/storage
    - /var/run/docker.sock:/var/run/docker.sock
  environment:
    <<: *volumevault-environment

x-volumevault-runtime-service: &volumevault-runtime-service
  <<: *volumevault-service
  entrypoint: []
  depends_on:
    migrate:
      condition: service_completed_successfully
  restart: unless-stopped

services:
  migrate:
    <<: *volumevault-service
    entrypoint: ["sh", "-lc"]
    command: "mkdir -p /app/storage/database /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs && touch /app/storage/database/database.sqlite && php artisan migrate --force"
    restart: "no"

  app:
    <<: *volumevault-runtime-service
    ports:
      - "8080:8000"
    command: ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

  queue:
    <<: *volumevault-runtime-service
    command: ["php", "artisan", "queue:work", "--tries=1", "--timeout=0"]

  scheduler:
    <<: *volumevault-runtime-service
    command: ["php", "artisan", "schedule:work"]

volumes:
  volumevault_data:
```

This layout is useful when you want separate container lifecycle, logs, and resource limits for runtime concerns. Runtime services use `entrypoint: []` to disable the image's all-in-one startup entrypoint so each service can run only its assigned command.

## Environment Variables

- `APP_KEY`: required for encrypted destination credentials, notification URLs, and installation saves.
- `APP_ENV`: defaults to `production`.
- `APP_DEBUG`: defaults to `false`.
- `APP_TIMEZONE`: timezone used to interpret backup schedules and display backup job dates, defaults to `UTC`. Use an IANA timezone such as `Europe/Paris`.
- `APP_URL`: public URL, defaults to `http://localhost:8080`.
- `DB_CONNECTION`: defaults to `sqlite`.
- `DB_DATABASE`: defaults to `/app/storage/database/database.sqlite` inside the Docker image.
- `QUEUE_CONNECTION`: defaults to `database`.
- `CACHE_STORE`: defaults to `database`.
- `SESSION_DRIVER`: defaults to `database`.
- `MAIL_MAILER`: use `smtp` or another real mail transport to enable email password reset links. The default `log` mode hides email reset in the UI.

You can override values directly in Compose:

```yaml
environment:
  APP_KEY: base64:paste-generated-key-here
  APP_URL: https://volumevault.example.com
  APP_TIMEZONE: Europe/Paris
```

Or load an environment file:

```yaml
env_file: .env
environment:
  APP_KEY: ${APP_KEY:?Set APP_KEY before starting VolumeVault}
```

Do not reuse a local development `.env` in production without review. Values such as `APP_ENV=local` or `APP_DEBUG=true` override the safe production defaults.

## Secrets And APP_KEY

Destination credentials and notification URLs are encrypted using Laravel's encrypted casts. Plaintext secrets are never sent back to the frontend or API, and edit forms intentionally leave secret fields blank.

If you lose `APP_KEY`, encrypted credentials and secure installation saves can no longer be decrypted. Back up `APP_KEY` securely before trusting scheduled backups.

## Onboarding And Users

The first account created through `/onboarding` is always an admin. After that, admins can create more admins or regular users from the Users screen.

Roles:

- `admin`: full access, including users, destinations, notification channels, restore flows, API tokens, installation saves, and Docker actions.
- `user`: read-only access to dashboard, volumes, jobs, runs, and logs.

VolumeVault prevents deleting your own account and prevents deleting or demoting the last admin.

During onboarding, you can either create the first administrator or import a `.vvsave` from a previous VolumeVault installation.
