---
title: Destinations
icon: fas fa-database
order: 2
---

## Backup Destinations

VolumeVault is not S3-only. It supports the destination families exposed by the current destination layer and mapped to `offen/docker-volume-backup` when running backups:

- S3-compatible storage: AWS S3, Cloudflare R2, and custom S3-compatible endpoints.
- WebDAV: URL, optional path, optional basic auth, and optional insecure TLS mode.
- SSH/SFTP: host, port, remote path, username, password or private key.
- Azure Blob Storage: container plus account key or connection string.
- Dropbox: remote path, app key, app secret, refresh token, and concurrency level.
- Google Drive: folder ID and service account JSON, with optional domain-wide delegation subject.
- Local filesystem: archive path shared between VolumeVault and the temporary Offen container.

Each destination can be tested from the UI. Destination testing, listing, upload, download, and restore download behavior is centralized in `app/Services/BackupDestinations/DestinationStorage.php`.

Local destinations require special care in Docker deployments. The configured archive path must be readable by VolumeVault for listing/restores and mounted into the temporary Offen backup container for writes.

## Cloudflare R2

Use provider `cloudflare_r2` and endpoint:

```text
https://<account_id>.r2.cloudflarestorage.com
```

Use your R2 bucket name, access key ID, and secret access key. Region can usually remain `auto` or `us-east-1` depending on your R2 credentials. If testing fails, verify the endpoint and credentials in Cloudflare.
