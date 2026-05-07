<p align="center">
  <img src="public/logo.png" alt="VolumeVault" width="420">
</p>

# VolumeVault

[![tests](https://github.com/Darkdragon14/VolumeVault/actions/workflows/tests.yml/badge.svg)](https://github.com/Darkdragon14/VolumeVault/actions/workflows/tests.yml)
[![Container image](https://github.com/darkdragon14/VolumeVault/actions/workflows/ghcr.yml/badge.svg?branch=main)](https://github.com/darkdragon14/VolumeVault/actions/workflows/ghcr.yml)
[![Latest release](https://img.shields.io/github/v/release/darkdragon14/VolumeVault?display_name=tag&sort=semver&label=release)](https://github.com/darkdragon14/VolumeVault/releases)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](composer.json)

VolumeVault is a self-hosted Laravel application for managing Docker volume backups and safe restores through [`offen/docker-volume-backup`](https://github.com/offen/docker-volume-backup).

It provides a clear web UI for scheduled backups, restore runs, encrypted destinations, notifications, run history, onboarding, and API-driven automation.

<table>
  <tr>
    <td align="center" width="50%">
      <img src="public/previews/dashboard.png" alt="VolumeVault dashboard preview">
    </td>
    <td align="center" width="50%">
      <img src="public/previews/jobs.png" alt="VolumeVault backup jobs preview">
    </td>
  </tr>
</table>

## Get Started

Generate an application key first:

```bash
docker run --rm ghcr.io/darkdragon14/volumevault:latest php artisan key:generate --show
```

Create a `docker-compose.yml` file and paste the generated key in `APP_KEY`:

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

Start VolumeVault:

```bash
docker compose up -d
```

Open `http://localhost:8080` and create the first administrator account from the onboarding screen.

The single container runs the web app, database migrations, queue worker, and scheduler.

Defaults are built into the application for a production SQLite setup. Add environment variables only when you need to override them, for example `APP_URL`, `APP_TIMEZONE`, or SMTP settings.

You can also use `env_file: .env` for overrides, but do not reuse a development `.env` in production without review. Values such as `APP_ENV=local` or `APP_DEBUG=true` override the safe production defaults.

Keep your `APP_KEY` safe: it is required to decrypt destinations, notifications, and installation saves.

## Documentation

The full documentation is published with GitHub Pages and built from the [`docs`](docs) directory.

- Documentation URL: [https://darkdragon14.github.io/VolumeVault/](https://darkdragon14.github.io/VolumeVault/)
- Documentation source: [`docs/index.md`](docs/index.md)

## Credits

VolumeVault relies on [`offen/docker-volume-backup`](https://github.com/offen/docker-volume-backup) for the actual Docker volume backup engine and destination support.

Huge thanks to Offen and the maintainers of `offen/docker-volume-backup` for their work.

## Contributing

All contributions are welcome.
