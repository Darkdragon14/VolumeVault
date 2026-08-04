# Security Policy

## Reporting a vulnerability

If you discover a security vulnerability in VolumeVault, please do not open a public GitHub issue.

Instead, please report it privately using GitHub Private Vulnerability Reporting if available on this repository.

If private reporting is not available, you can contact the maintainer at:

me@darkdragon14.dev

Please include as much detail as possible, including:

- A clear description of the vulnerability
- Steps to reproduce the issue
- The affected version or commit
- Any potential impact
- Suggested fixes, if any

I will try to acknowledge the report as soon as possible and coordinate a fix before public disclosure.

## Trust boundary: Docker access

VolumeVault is built for a **single-tenant, admin-trusted** deployment: everyone
who can sign in is assumed to be a full administrator of the host.

To create and run backup/restore containers, VolumeVault either bind-mounts the
host Docker socket (`/var/run/docker.sock`) read-write into its own container and
the backup container it spawns, or connects both containers through a configured
TCP socket proxy. The standalone TCP Compose file omits the Unix socket mount.
**Either form of Docker API access is equivalent to root on the host** because it
allows creating containers with arbitrary bind mounts. This is inherent to what
the tool does, not a bug.

Some consequences worth understanding before exposing VolumeVault:

- A Docker **socket proxy does not meaningfully reduce this risk**: VolumeVault
  legitimately needs `POST /containers/create` with arbitrary bind mounts, which
  is already root-equivalent.
- The only effective container→host containment is running Docker with
  **user-namespace remapping (`userns-remap`) or rootless Docker**.
- Treat the VolumeVault web interface as a **host root console**. Keep it on a
  trusted network, behind authentication, and ideally behind a reverse proxy
  with TLS. Do not expose it directly to untrusted networks.

## Supported versions

Security fixes are generally provided for the latest released version of VolumeVault.

## Disclosure

Please avoid publicly disclosing the vulnerability until a fix has been released or a coordinated disclosure timeline has been agreed.
