<?php

namespace App\Services\Docker;

use RuntimeException;

/**
 * Validation helpers for the "Docker volume" backup destination.
 *
 * A Docker volume destination is mounted into the Offen backup container and
 * into throwaway helper containers as `<name>:/archive`. Because Docker itself
 * parses that spec, an unconstrained name, sub-path or object key could change
 * what actually gets mounted or read/written:
 *   - a `/` in the name turns the source into a host bind mount (e.g. `/etc`),
 *   - a `:` injects a third `src:dst:opts` field (extra mount options),
 *   - a `..` in a sub-path or key escapes `/archive` to the container root.
 *
 * Every value that reaches a `-v` spec or an in-container path is funnelled
 * through here, at both validation time and run time (fail-closed / TOCTOU),
 * mirroring how the local provider re-checks its host paths in localRuntime().
 */
class DockerVolumeName
{
    /** Where the volume is mounted inside the backup/helper containers. */
    public const MOUNT_POINT = '/archive';

    /** Docker's own named-volume grammar: first char alphanumeric, then [a-zA-Z0-9_.-]. */
    private const NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/';

    public static function isValidName(string $name): bool
    {
        return $name !== '' && strlen($name) <= 255 && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public static function assertName(string $name): string
    {
        if (! self::isValidName($name)) {
            throw new RuntimeException('Invalid Docker volume name.');
        }

        return $name;
    }

    /**
     * A sub-path is empty or `seg/seg`, each segment limited to a safe
     * character set; never absolute, never containing `..` or a colon.
     */
    public static function isValidSubpath(string $subpath): bool
    {
        $subpath = trim($subpath, '/');

        if ($subpath === '') {
            return true;
        }

        if (str_contains($subpath, ':') || strlen($subpath) > 255) {
            return false;
        }

        foreach (explode('/', $subpath) as $segment) {
            if ($segment === '' || $segment === '..' || preg_match('/^[a-zA-Z0-9_.-]+$/', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    public static function assertSubpath(string $subpath): string
    {
        if (! self::isValidSubpath($subpath)) {
            throw new RuntimeException('Invalid Docker volume sub-path.');
        }

        return trim($subpath, '/');
    }

    /** The archive directory inside the backup/helper container: /archive[/sub]. */
    public static function archiveDir(string $subpath): string
    {
        $subpath = self::assertSubpath($subpath);

        return self::MOUNT_POINT.($subpath !== '' ? '/'.$subpath : '');
    }

    /**
     * A relative object key (download/upload), confined under the archive
     * directory: never absolute, no `..` segment, no colon.
     */
    public static function assertKey(string $key): string
    {
        $key = ltrim($key, '/');

        if ($key === '' || str_contains($key, ':') || strlen($key) > 1024) {
            throw new RuntimeException('Invalid Docker volume object key.');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '..') {
                throw new RuntimeException('Invalid Docker volume object key.');
            }
        }

        return $key;
    }
}
