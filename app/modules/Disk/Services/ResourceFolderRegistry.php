<?php

namespace App\Modules\Disk\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The read-only "Zasoby" (resources) tree: files that belong to another resource (task
 * attachments, report outputs) don't live in the user's folder tree, but they still show up on
 * the disk — under synthetic, read-only folders shaped as
 *
 *     Zasoby → <resource type> → <created_at bucket> → files.
 *
 * This registry is the single source of truth for WHICH resource types appear and how their
 * files bucket by time. A type is only listed if it actually has files (enumerated elsewhere),
 * so an entry here is a capability, not a promise of content.
 *
 * Bucketing is computed in PHP, never with database date functions: the codebase deliberately
 * avoids driver-specific SQL (to_char/strftime), and buckets must work identically on the
 * shared Postgres connection and an own-DB tenant. created_at is stored UTC, so buckets are
 * UTC too; the labels are localized on the frontend from the raw key.
 */
class ResourceFolderRegistry
{
    public const GRANULARITY_YEAR = 'year';

    public const GRANULARITY_MONTH = 'month';

    public const GRANULARITY_DAY = 'day';

    /**
     * Registered resource types, keyed by their morph alias (= File.fileable_type).
     *
     * Only types with a real File writer are here: `task` (attachments + the bot's
     * generate_file tool) and `form_report` (the generated report file). User avatars and
     * other morphs join when they start producing files.
     *
     * @var array<string, array{label_key: string, icon: string, granularity: string}>
     */
    public const TYPES = [
        'task' => [
            'label_key' => 'disk.resources.task',
            'icon' => 'list-checks',
            'granularity' => self::GRANULARITY_MONTH,
        ],
        'form_report' => [
            'label_key' => 'disk.resources.form_report',
            'icon' => 'file-text',
            'granularity' => self::GRANULARITY_MONTH,
        ],
        // Files uploaded through a form's file input; they belong to the submission that
        // carried them (FileService::bindTempTo), so they surface here read-only.
        'form_submission' => [
            'label_key' => 'disk.resources.form_submission',
            'icon' => 'inbox',
            'granularity' => self::GRANULARITY_MONTH,
        ],
    ];

    public static function isRegistered(string $alias): bool
    {
        return array_key_exists($alias, self::TYPES);
    }

    public static function granularityOf(string $alias): string
    {
        return self::TYPES[$alias]['granularity'] ?? self::GRANULARITY_MONTH;
    }

    /** The bucket key a timestamp falls into, for a type's granularity: 2026, 2026-07, 2026-07-17. */
    public static function bucketKey(string $alias, CarbonInterface $at): string
    {
        return match (self::granularityOf($alias)) {
            self::GRANULARITY_YEAR => $at->format('Y'),
            self::GRANULARITY_DAY => $at->format('Y-m-d'),
            default => $at->format('Y-m'),
        };
    }

    /**
     * The half-open [start, end) instant range a bucket key covers, or null if the key does not
     * match the type's granularity. Half-open on purpose: it includes the whole final day
     * without the off-by-one that a `<=` end-of-day comparison invites.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function bucketRange(string $alias, string $key): ?array
    {
        $granularity = self::granularityOf($alias);

        return match ($granularity) {
            self::GRANULARITY_YEAR => self::rangeFrom($key, '/^\d{4}$/', 'Y', fn (CarbonImmutable $s) => $s->addYear()),
            self::GRANULARITY_DAY => self::rangeFrom($key, '/^\d{4}-\d{2}-\d{2}$/', 'Y-m-d', fn (CarbonImmutable $s) => $s->addDay()),
            default => self::rangeFrom($key, '/^\d{4}-\d{2}$/', 'Y-m', fn (CarbonImmutable $s) => $s->addMonth()),
        };
    }

    /**
     * @param  callable(CarbonImmutable): CarbonImmutable  $advance
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private static function rangeFrom(string $key, string $pattern, string $format, callable $advance): ?array
    {
        if (preg_match($pattern, $key) !== 1) {
            return null;
        }

        // Anchor to the start of the period, in UTC to match how created_at is stored.
        $start = CarbonImmutable::createFromFormat('!' . $format, $key, 'UTC');

        if ($start === false) {
            return null;
        }

        return [$start, $advance($start)];
    }
}
