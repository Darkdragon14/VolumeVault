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

Each saved destination offers connection testing, storage measurements and paginated archive browsing from its edit page or the Test action on the destination list. Select **Execute on host** to run through a compatible `destination-v1` agent. Shared network destinations default to central host `1`, including deployments without local Docker execution; host-local destinations are fixed to their owner. Unavailable hosts show a capability/ownership/maintenance explanation. Operations use the saved configuration, so save edits before testing them.

Requests run asynchronously: the UI shows pending/running status, errors, storage bytes and object count, and the result freshness deadline. Offline agents leave work pending until they can claim it. Changing the selected host or destination clears previous results and cancels browser polling. Browsing displays exact provider keys alongside names and loads additional pages on demand. Results used as restore receipts are fresh for 30 minutes from operation claim (or creation if unclaimed), not from when the browser retrieves them.

### Automated usage and threshold measurements

Under **Destination storage limits**, save an **Automated storage measurement executor** for scheduled checks. This is separate from the manual **Execute on host** control. Network destinations default to the central network; select a compatible `destination-v1` agent when the endpoint is private to that host. This setting selects both network reachability and the executor's egress policy. There is no central fallback when the chosen agent cannot perform the measurement. Pending, stale and failed measurements are not treated as zero usage.

The saved `storage_measurement_host_id` is nullable: `null` or `1` means central execution for network destinations. API updates preserve the saved choice when omitted and reset it when explicitly set to `null`. Local filesystem and Docker-volume destinations always measure on their owner; the form fixes the selector accordingly. Changing the provider resets a network choice or selects the local owner, and changing a local owner updates the measurement host. A host's maintenance state does not erase this saved configuration.

If a saved network measurement host becomes unavailable, the form keeps its ID and shows a disabled selected option with its name and ID (or just the ID if the host is no longer in the supplied host lists). It does not silently switch to central execution. If the backend rejects saving that host, select a compatible agent or the central network, or restore the agent's enrollment and upgrade it for `destination-v1`. Basic editing of a host-local destination keeps its explicit owner even when that host lacks destination-operation support.

### SFTP host-key discovery

An unsaved SFTP form can discover the server key from the **central network** or an agent with both `destination-v1` and `sftp-host-key-v1`. Choose the discovery executor explicitly; it is independent of automated measurements and manual saved-destination operations. Discovery sends only host, port and executor, never endpoint credentials. Agent discovery queues an operation and polls for its result. Changing the host, port, executor, provider or manually entered key invalidates the in-flight result; leaving the page stops polling.

You may still paste a public host key or SHA256 fingerprint manually. Compare a discovered fingerprint with the server through a trusted channel before saving: discovery is trust on first use, not independent identity verification. Pinning protects SFTP operations performed by VolumeVault. **The Offen backup container cannot verify host keys**, so discovery does not fix this backup-upload limitation.

Destination testing, listing, upload, download, and restore download behavior is centralized in `app/Services/BackupDestinations/DestinationStorage.php`. Backup runs upload through Offen, which does not expose a verifiable exact Dropbox file ID to VolumeVault. Asynchronous metadata processing never infers that ID from a filename, since the file may have been replaced. Even a newly successful Dropbox backup without a proven file ID remains unverifiable for restoration from run history.

Uploaded SSH private keys are written to a temporary file with restricted permissions and copied through the Docker API into the temporary Offen backup container before it starts. The local key file and temporary container are removed after the backup attempt, including when setup or execution fails.

Local destinations require special care in Docker deployments. The configured archive path must be readable by VolumeVault for listing/restores and mounted into the temporary Offen backup container for writes.

The official image bundles `/usr/local/bin/volumevault-local-archive-reader`. Custom or non-official runtime images must compile and install the repository's `docker/local-archive-reader.c`; local restore downloads fail closed when this reader is unavailable.

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

For remote destinations, configure this variable on the **owning agent**. Policy reports come from accepted inventory and include a timestamp and freshness. Known-empty means no paths are allowed; stale, missing or offline-agent reports mean unknown and do not establish that a path is centrally blocked or safe. The agent applies its authoritative policy at execution. Use `php artisan volumevault:host-path-allowlist:audit --host=2` for one host or `--all` for all hosts; these remote audits use stored inventory without Docker access.
