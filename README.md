<p align="center">
  <img src="public/logo.png" alt="VolumeVault" width="420">
</p>

# VolumeVault

<!-- Badges will go here. -->
<!-- Example: [![Build](...)](...) [![License](...)](...) -->

VolumeVault is a self-hosted Laravel application for managing Docker volume backups and safe restores through [`offen/docker-volume-backup`](https://github.com/offen/docker-volume-backup).

It provides a clear web UI for scheduled backups, restore runs, encrypted destinations, notifications, run history, onboarding, and API-driven automation.

<p align="center">
  <img src="public/previews/dashboard.png" alt="VolumeVault dashboard preview" width="45%">
  <img src="public/previews/jobs.png" alt="VolumeVault backup jobs preview" width="45%">
</p>

## Get Started

Generate an application key first:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 php artisan key:generate --show
```

Create a `docker-compose.yml` file and paste the generated key in `APP_KEY`:

```yaml
x-volumevault: &volumevault
  image: ghcr.io/darkdragon14/volumevault:latest
  environment:
    APP_NAME: VolumeVault
    APP_ENV: production
    APP_DEBUG: "false"
    APP_KEY: base64:paste-generated-key-here
    APP_URL: http://localhost:8080
    APP_TIMEZONE: UTC
    DB_CONNECTION: sqlite
    DB_DATABASE: /app/storage/database/database.sqlite
    QUEUE_CONNECTION: database
    CACHE_STORE: database
    SESSION_DRIVER: database
    LOG_CHANNEL: stack
    LOG_LEVEL: info
    MAIL_MAILER: log
  volumes:
    - volumevault_data:/app/storage
    - /var/run/docker.sock:/var/run/docker.sock

services:
  migrate:
    <<: *volumevault
    command: sh -lc "mkdir -p /app/storage/database /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs && touch /app/storage/database/database.sqlite && php artisan migrate --force"
    restart: "no"

  app:
    <<: *volumevault
    ports:
      - "8080:8000"
    command: php artisan serve --host=0.0.0.0 --port=8000
    depends_on:
      migrate:
        condition: service_completed_successfully
    restart: unless-stopped

  queue:
    <<: *volumevault
    command: php artisan queue:work --tries=1 --timeout=0
    depends_on:
      migrate:
        condition: service_completed_successfully
    restart: unless-stopped

  scheduler:
    <<: *volumevault
    command: php artisan schedule:work
    depends_on:
      migrate:
        condition: service_completed_successfully
    restart: unless-stopped

volumes:
  volumevault_data:
```

Start VolumeVault:

```bash
docker compose up -d
```

Open `http://localhost:8080` and create the first administrator account from the onboarding screen.

Keep your `APP_KEY` safe: it is required to decrypt destinations, notifications, and installation saves.

## Documentation

The full documentation is published with GitHub Pages and built from the [`docs`](docs) directory.

- Documentation URL: `https://volumevault.darkdragon.fr`
- Documentation source: [`docs/index.md`](docs/index.md)

## Credits

VolumeVault relies on [`offen/docker-volume-backup`](https://github.com/offen/docker-volume-backup) for the actual Docker volume backup engine and destination support.

Huge thanks to Offen and the maintainers of `offen/docker-volume-backup` for their work.

## Contributing

All contributions are welcome.
