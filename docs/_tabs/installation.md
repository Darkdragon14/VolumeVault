---
title: Installation
icon: fas fa-download
order: 1
---

## Docker Compose Setup

1. Copy `.env.example` to `.env`.
2. Generate an app key:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 php artisan key:generate --show
```

3. Put the generated value in `.env` as `APP_KEY=base64:...`.
4. Start VolumeVault:

```bash
docker compose up -d --build
```

5. Open `http://localhost:8080`.
6. Create the first administrator account from the onboarding screen, or import an existing installation save.

The Compose stack builds one shared `volumevault:local` image and runs four services from it: `migrate`, `app`, `queue`, and `scheduler`. Runtime services share the `volumevault_data` named volume for SQLite and Laravel storage.

## Environment Variables

- `APP_KEY`: required for encrypted destination credentials, notification URLs, and installation saves.
- `APP_TIMEZONE`: timezone used to interpret backup schedules and display backup job dates, defaults to `UTC`. Use an IANA timezone such as `Europe/Paris`.
- `APP_URL`: public URL, defaults to `http://localhost:8080` in Compose.
- `DB_CONNECTION`: defaults to `sqlite` in the provided Compose setup.
- `DB_DATABASE`: use `/app/storage/database/database.sqlite` in Compose.
- `QUEUE_CONNECTION`: use `database`.
- `MAIL_MAILER`: use `smtp` or another real mail transport to enable email password reset links. The default `log` mode hides email reset in the UI.

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
