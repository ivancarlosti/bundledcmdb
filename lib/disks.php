<?php
declare(strict_types=1);

/**
 * lib/disks.php — structured physical disk inventory (`assets.PhysicalDisks`).
 *
 * Inventory agents report one JSON array per device instead of the three flat
 * text columns (`HDs`, `HDsTypes`, `HDsSpacesGB`). The canonical shape is:
 *
 *   [{
 *     "number": 0, "friendly_name": "WD Green ...", "media_type": "SSD",
 *     "bus_type": "NVMe", "size_gb": 476.94, "free_gb": 210.5, "used_gb": 266.44,
 *     "volumes": [{"drive": "C:", "label": "Windows", "total_gb": 476.94,
 *                  "free_gb": 210.5, "used_gb": 266.44}]
 *   }]
 *
 * Input is forgiving: keys are matched after lower-casing and dropping
 * non-alphanumerics, so `friendlyName`, `friendly_name` and `Friendly Name` all
 * land on `friendly_name`. Output is always the snake_case shape above.
 * `used_gb` is optional on input — when it is missing the value is derived as
 * `size_gb - free_gb` (and `total_gb - free_gb` for volumes), so an agent never
 * has to compute it.
 */

/** Hard limits of one payload; a workstation never reaches them. */
const DISKS_MAX_DISKS = 32;
const DISKS_MAX_VOLUMES = 64;
const DISKS_MAX_TEXT = 191;
const DISKS_MAX_GB = 10000000.0;

/** Lower-cased, alphanumerics-only form of a payload key. */
function disks_key(string $key): string
{
    return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));
}

/**
 * First matching value in `$row` for one of the accepted spellings.
 *
 * @param array<string, mixed> $row
 * @param list<string> $aliases already-normalised spellings
 */
function disks_pick(array $row, array $aliases): mixed
{
    foreach ($row as $key => $value) {
        if (in_array(disks_key((string) $key), $aliases, true)) {
            return $value;
        }
    }
    return null;
}

/** Trimmed single-line text, length-capped; empty becomes null. */
function disks_text(mixed $value): ?string
{
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    if ($text === '') {
        return null;
    }
    return mb_substr($text, 0, DISKS_MAX_TEXT);
}

/**
 * Non-negative GB amount rounded to two decimals, or null when the value is
 * absent. A present-but-unusable value sets `$invalid` (the caller turns that
 * into a validation error).
 */
function disks_gb(mixed $value, bool &$invalid): ?float
{
    if ($value === null || $value === '' || $value === []) {
        return null;
    }
    if (is_bool($value) || is_array($value) || is_object($value)) {
        $invalid = true;
        return null;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    // A lone comma is a decimal separator ("476,94"); a thousands separator
    // ("1,024") is not, so only the short form is rewritten.
    if (preg_match('/^\d+,\d{1,2}$/', $text) === 1) {
        $text = str_replace(',', '.', $text);
    }
    if (!is_numeric($text)) {
        $invalid = true;
        return null;
    }
    $number = (float) $text;
    if (!is_finite($number) || $number < 0 || $number > DISKS_MAX_GB) {
        $invalid = true;
        return null;
    }
    return round($number, 2);
}

/** Integer disk slot number; falls back to the array position. */
function disks_number(mixed $value, int $position): int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (is_string($value) && ctype_digit(trim($value))) {
        return (int) trim($value);
    }
    if (is_float($value) && $value >= 0 && $value <= 1024) {
        return (int) $value;
    }
    return $position;
}

/**
 * Decoded disk list from a column value, a JSON string or an array.
 *
 * Returns `[]` for anything unusable (empty column, invalid JSON, a JSON
 * object), which is what the API and the device page render as "no data".
 *
 * @return list<array<string, mixed>>
 */
function disks_decode(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $raw = $decoded;
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $disk) {
        if (is_array($disk)) {
            $out[] = $disk;
        }
    }
    return $out;
}

/**
 * Validate and canonicalise a `physical_disks` payload.
 *
 * @return array{ok: bool, error: ?string, disks: list<array<string, mixed>>}
 */
function disks_normalize(mixed $raw): array
{
    $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'disks' => []];

    if ($raw === null || $raw === '' || $raw === []) {
        return ['ok' => true, 'error' => null, 'disks' => []];
    }
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => true, 'error' => null, 'disks' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            // A JSON object is not a disk list: accepting it silently would lose
            // the payload and look like "the agent reported nothing".
            return $fail('invalid_disks');
        }
        $raw = $decoded;
    }
    if (!is_array($raw) || !array_is_list($raw)) {
        return $fail('invalid_disks');
    }
    if (count($raw) > DISKS_MAX_DISKS) {
        return $fail('too_many_disks');
    }

    $disks = [];
    foreach ($raw as $position => $row) {
        if (!is_array($row)) {
            return $fail('invalid_disks');
        }
        $invalid = false;

        $volumes = disks_pick($row, ['volumes', 'partitions']);
        $volumes = $volumes === null ? [] : $volumes;
        if (!is_array($volumes) || !array_is_list($volumes)) {
            return $fail('invalid_disks');
        }
        if (count($volumes) > DISKS_MAX_VOLUMES) {
            return $fail('too_many_volumes');
        }

        $size = disks_gb(disks_pick($row, ['sizegb', 'size', 'capacitygb', 'capacity', 'totalgb', 'total']), $invalid);
        $free = disks_gb(disks_pick($row, ['freegb', 'free', 'freespacegb', 'availablespacegb', 'available']), $invalid);
        $used = disks_gb(disks_pick($row, ['usedgb', 'used', 'usedspacegb', 'occupiedgb']), $invalid);

        $cleanVolumes = [];
        foreach ($volumes as $volume) {
            if (!is_array($volume)) {
                return $fail('invalid_disks');
            }
            $vTotal = disks_gb(disks_pick($volume, ['totalgb', 'total', 'sizegb', 'size', 'capacitygb', 'capacity']), $invalid);
            $vFree = disks_gb(disks_pick($volume, ['freegb', 'free', 'freespacegb', 'availablespacegb', 'available']), $invalid);
            $vUsed = disks_gb(disks_pick($volume, ['usedgb', 'used', 'usedspacegb', 'occupiedgb']), $invalid);

            $cleanVolumes[] = [
                'drive'    => disks_text(disks_pick($volume, ['drive', 'letter', 'mount', 'mountpoint', 'path'])),
                'label'    => disks_text(disks_pick($volume, ['label', 'name', 'volumename'])),
                'total_gb' => $vTotal,
                'free_gb'  => $vFree,
                'used_gb'  => $vUsed ?? disks_derive_used($vTotal, $vFree),
            ];
        }

        if ($invalid) {
            return $fail('invalid_number');
        }

        $disks[] = [
            'number'        => disks_number(disks_pick($row, ['number', 'index', 'slot', 'id']), (int) $position),
            'friendly_name' => disks_text(disks_pick($row, ['friendlyname', 'name', 'model', 'devicename'])),
            'media_type'    => disks_text(disks_pick($row, ['mediatype', 'disktype', 'type', 'kind'])),
            'bus_type'      => disks_text(disks_pick($row, ['bustype', 'bus', 'interface', 'connector'])),
            'size_gb'       => $size,
            'free_gb'       => $free,
            'used_gb'       => $used ?? disks_derive_used($size, $free),
            'volumes'       => $cleanVolumes,
        ];
    }

    usort($disks, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
    return ['ok' => true, 'error' => null, 'disks' => $disks];
}

/** `total - free`, clamped at zero; null when either side is unknown. */
function disks_derive_used(?float $total, ?float $free): ?float
{
    if ($total === null || $free === null) {
        return null;
    }
    return round(max(0.0, $total - $free), 2);
}

/** "476.94" / "931.51" — no trailing zeros, no thousands separator. */
function disks_gb_text(float $value): string
{
    $text = number_format($value, 2, '.', '');
    return rtrim(rtrim($text, '0'), '.') ?: '0';
}

/** Column value for a normalised disk list (`null` when there is none). */
function disks_encode(array $disks): ?string
{
    if ($disks === []) {
        return null;
    }
    $json = json_encode($disks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

/**
 * Legacy text columns derived from the disk list.
 *
 * Kept in sync on every write so the grid, the export and the clients that read
 * `HDs`/`HDsTypes`/`HDsSpacesGB` need no knowledge of the JSON. The numeric
 * roll-ups that used to live here were retired with migration 0019: `size_gb`,
 * `free_gb` and `used_gb` are in the JSON, so a client that wants a total adds
 * them up itself instead of trusting a second copy.
 *
 * @param list<array<string, mixed>> $disks normalised disks
 * @return array{HDs: ?string, HDsTypes: ?string, HDsSpacesGB: ?string}
 */
function disks_summary(array $disks): array
{
    $empty = ['HDs' => null, 'HDsTypes' => null, 'HDsSpacesGB' => null];
    if ($disks === []) {
        return $empty;
    }

    $names = [];
    $types = [];
    $spaces = [];
    foreach ($disks as $disk) {
        $number = (int) ($disk['number'] ?? 0);
        $name = trim((string) ($disk['friendly_name'] ?? ''));
        $names[] = $name !== '' ? $name : 'Disk ' . $number;

        $type = trim((string) ($disk['media_type'] ?? '') . ' ' . (string) ($disk['bus_type'] ?? ''));
        if ($type !== '') {
            $types[] = $type;
        }
        if (($disk['size_gb'] ?? null) !== null) {
            $spaces[] = disks_gb_text((float) $disk['size_gb']);
        }
    }

    $join = static fn (array $parts): ?string => $parts === []
        ? null
        : mb_substr(implode(', ', array_values(array_unique($parts))), 0, 255);

    return [
        'HDs'         => $join($names),
        'HDsTypes'    => $join($types),
        'HDsSpacesGB' => $join($spaces),
    ];
}
