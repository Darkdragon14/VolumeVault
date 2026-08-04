---
title: Development & Roadmap
icon: fas fa-list-check
order: 6
---

## Local Development

Install dependencies if needed:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 install
npm install
```

Run migrations:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 php artisan migrate
```

Build frontend assets:

```bash
npm run build
```

## Tests

Run tests with Docker Composer:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 php artisan test
```

## Current Limitations

- No external identity provider support yet.
- A Docker TCP endpoint, such as a socket proxy for the local engine, can be configured through `DOCKER_HOST`; remote Docker hosts and Docker TLS client certificates are not supported.
- No Kubernetes support.
- Notifications require Docker to pull and run the Shoutrrr CLI image for test and delivery.
- Backup archive extraction assumes the archive layout produced by the configured `offen/docker-volume-backup` mount path.
- Local backup destinations require a filesystem path shared by VolumeVault and the temporary Offen container.

## Roadmap

- Add external identity provider support for shared environments.
- Add more guided Shoutrrr services.
- Add backup pruning visibility and destination browsing improvements.
