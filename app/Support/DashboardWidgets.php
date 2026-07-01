<?php

namespace App\Support;

class DashboardWidgets
{
    /**
     * Canonical stat cards in their default order.
     *
     * @var list<string>
     */
    public const STAT_KEYS = [
        'total_volumes',
        'existing_volumes',
        'missing_volumes',
        'backed_up_volumes',
        'configured_volumes',
        'unprotected_volumes',
        'total_jobs',
        'active_jobs',
        'paused_jobs',
        'error_jobs',
        'total_groups',
        'active_groups',
        'paused_groups',
        'error_groups',
        'last_backup_run_status',
        'last_successful_backup_size',
        'next_scheduled_backup',
    ];

    /**
     * Canonical content sections in their default order.
     *
     * @var list<string>
     */
    public const SECTION_KEYS = [
        'recent_backups',
        'recent_group_runs',
        'recent_restores',
        'jobs_with_errors',
        'groups_with_errors',
    ];

    /**
     * Widgets hidden by default (visible only if the user opts in).
     *
     * @var list<string>
     */
    public const HIDDEN_BY_DEFAULT = [
        'last_successful_backup_size',
    ];

    /**
     * Merge the user's stored preferences with the canonical widget lists.
     *
     * Unknown keys are dropped, missing keys are appended in their canonical
     * order so new widgets shipped in a future version always show up.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array{stats: list<array{key: string, visible: bool}>, sections: list<array{key: string, visible: bool}>}
     */
    public static function normalize(?array $stored): array
    {
        return [
            'stats' => self::normalizeGroup($stored['stats'] ?? null, self::STAT_KEYS),
            'sections' => self::normalizeGroup($stored['sections'] ?? null, self::SECTION_KEYS),
        ];
    }

    /**
     * @param  mixed  $storedGroup
     * @param  list<string>  $canonicalKeys
     * @return list<array{key: string, visible: bool}>
     */
    private static function normalizeGroup($storedGroup, array $canonicalKeys): array
    {
        $result = [];
        $seen = [];

        if (is_array($storedGroup)) {
            foreach ($storedGroup as $item) {
                if (! is_array($item) || ! isset($item['key']) || ! is_string($item['key'])) {
                    continue;
                }

                $key = $item['key'];

                if (! in_array($key, $canonicalKeys, true) || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $result[] = [
                    'key' => $key,
                    'visible' => (bool) ($item['visible'] ?? true),
                ];
            }
        }

        foreach ($canonicalKeys as $key) {
            if (isset($seen[$key])) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'visible' => ! in_array($key, self::HIDDEN_BY_DEFAULT, true),
            ];
        }

        return $result;
    }

    /**
     * Whether a given section key is currently visible in the preferences.
     *
     * @param  array{stats: list<array{key: string, visible: bool}>, sections: list<array{key: string, visible: bool}>}  $preferences
     */
    public static function isSectionVisible(array $preferences, string $key): bool
    {
        foreach ($preferences['sections'] as $section) {
            if ($section['key'] === $key) {
                return $section['visible'];
            }
        }

        return false;
    }
}
