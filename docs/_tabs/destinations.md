---
title: Destinations
icon: fas fa-database
order: 2
---

## Backup Destinations

VolumeVault is not S3-only. It supports the destination families exposed by the current destination layer and mapped to `offen/docker-volume-backup` when running backups:

- S3-compatible storage: AWS S3, Cloudflare R2, and custom S3-compatible endpoints.
- WebDAV: URL, optional path, optional basic auth, and optional insecure TLS mode.
- SSH/SFTP: host, port, remote path, username, password or private key, and an optional pinned host key.
- Azure Blob Storage: container plus account key or connection string.
- Dropbox: remote path, app key, app secret, refresh token, and concurrency level.
- Google Drive: folder ID and service account JSON, with optional domain-wide delegation subject.
- Local filesystem: archive path shared between VolumeVault and the temporary Offen container.
- Docker volume: a named Docker volume (any driver — `local`, NFS, CIFS, …) mounted **by name** into the temporary Offen container. No host path needs to be shared with VolumeVault.

Each destination can be tested from the UI. Destination testing, listing, upload, download, and restore download behavior is centralized in `app/Services/BackupDestinations/DestinationStorage.php`.

Uploaded SSH private keys are written to a temporary file with restricted permissions and copied through the Docker API into the temporary Offen backup container before it starts. The local key file and temporary container are removed after the backup attempt, including when setup or execution fails.

Local destinations require special care in Docker deployments. The configured archive path must be readable by VolumeVault for listing/restores and mounted into the temporary Offen backup container for writes.

### Docker volume destinations (NFS and other drivers)

A **Docker volume** destination writes backups to a named Docker volume instead of a host path. This is the recommended way to back up to an NFS share (or any volume driver): you declare the volume in your Compose file, and VolumeVault mounts that same volume **by name** into the temporary Offen container at backup and restore time.

Unlike a local destination, the volume does **not** have to be mounted into the VolumeVault container itself: listing, testing, restore downloads, and storage metrics all run in short-lived helper containers that mount the volume on demand. There is no host path allowlist to configure.

Configuration fields:

- **Docker volume name** (required): the name of a Docker volume that exists on the same Docker engine VolumeVault talks to.
- **Subpath** (optional): a sub-directory inside the volume where archives are stored. Leave empty to use the volume root. Run **Test** once to provision the sub-directory before scheduling backups.

Example `docker-compose.yml` backing up to an NFS share:

```yaml
volumes:
  volumevault_data:
  barril-backups:
    driver_opts:
      type: "nfs"
      o: "addr=192.168.1.10,nolock,soft,rw"
      device: ":/volume1/backups"

services:
  volumevault:
    image: ghcr.io/darkdragon14/volumevault:latest
    volumes:
      - volumevault_data:/app/storage
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      APP_KEY: xxx
    restart: unless-stopped
```

Then create a **Docker volume** destination with **Docker volume name** set to `barril-backups`. Note that the NFS volume no longer needs to be mounted into the `volumevault` service — VolumeVault mounts it into the Offen container by name when a backup runs.

> The volume name is restricted to Docker's named-volume grammar (letters, digits, `_`, `.`, `-`). A slash or colon is rejected so the mount cannot be turned into a host bind mount or carry extra mount options. The subpath cannot be absolute or contain `..`.
{: .prompt-info }

### Host path allowlist

The **archive path** (and the optional **Docker mount source**) are held to the same fail-closed host path allowlist as host-path backup sources. They must sit under a prefix listed in the `VOLUMEVAULT_HOST_PATH_ALLOWLIST` environment variable (comma-separated), and they cannot contain a colon (`:`), commas, or `.`/`..` segments.

The allowlist is empty by default, so **no local path is accepted out of the box**. If you try to create a local destination without configuring it, the form rejects the path with an error such as *"Host path access is disabled…"* or *"Host path is outside VOLUMEVAULT_HOST_PATH_ALLOWLIST…"* and stays on the create page. Set the variable, then clear the config cache:

```bash
# .env
VOLUMEVAULT_HOST_PATH_ALLOWLIST=/archive,/mnt/backups

php artisan config:clear
```
