<?php
declare(strict_types=1);

/**
 * lib/user_preferences.php — per-user UI preferences (locale, theme, page size
 * and the persisted grid layout).
 *
 * Stored in `user_preferences` (email is the primary key, matching `users`), not
 * in `app_settings`, because two people must be able to use different languages
 * and column orders at the same time. Missing rows simply fall back to the
 * Settings defaults, so the table can be introduced without a backfill.
 */

/** Defaults derived from the global settings (never throws when the DB is down). */
function user_preferences_defaults(): array
{
    $theme = 'system';
    $perPage = 25;
    try {
        $theme = (string) settings_get('general.default_theme', 'system');
        $perPage = (int) settings_get('general.per_page', 25);
    } catch (Throwable) {
        // Settings unavailable (not migrated yet) → built-in defaults.
    }
    return [
        'locale'     => null,
        'theme'      => user_preferences_theme_normalize($theme) ?? 'system',
        'per_page'   => $perPage >= 5 && $perPage <= 500 ? $perPage : 25,
        'grid_state' => null,
    ];
}

/** Supported theme modes. */
function user_preferences_themes(): array
{
    return ['system', 'light', 'dark'];
}

/** Page sizes offered by the grid. */
function user_preferences_page_sizes(): array
{
    return [10, 25, 50, 100];
}

function user_preferences_theme_normalize(?string $theme): ?string
{
    $theme = strtolower(trim((string) $theme));
    return in_array($theme, user_preferences_themes(), true) ? $theme : null;
}
/**
 * Normalise a grid layout coming from the SPA.
 *
 * Only whitelisted shapes survive: no arbitrary SQL identifiers, no unbounded
 * arrays, no nested objects. Everything else falls back to `null` (= defaults).
 */
function user_preferences_grid_state(mixed $raw): ?array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($raw)) {
        return null;
    }

    $list = static function (mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '' && preg_match('/^[A-Za-z0-9_:.\-]{1,64}$/', $item) === 1) {
                $out[] = $item;
            }
            if (count($out) >= 100) {
                break;
            }
        }
        return array_values(array_unique($out));
    };

    $sort = trim((string) ($raw['sort'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_:.\-]{1,64}$/', $sort) !== 1) {
        $sort = '';
    }
    $dir = strtolower(trim((string) ($raw['dir'] ?? '')));
    $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : '';

    $state = [
        'order'   => $list($raw['order'] ?? []),
        'visible' => $list($raw['visible'] ?? []),
        'sort'    => $sort,
        'dir'     => $dir,
    ];

    if ($state['order'] === [] && $state['visible'] === [] && $sort === '') {
        return null;
    }
    return $state;
}

/**
 * Effective preferences for a user (defaults merged with the stored row).
 *
 * @return array{locale: ?string, theme: string, per_page: int, grid_state: ?array}
 */
function user_preferences_get(?string $email = null): array
{
    $prefs = user_preferences_defaults();
    $email = trim((string) $email);
    $pdo = db_try();
    if ($email === '' || $pdo === null || !db_table_exists($pdo, 'user_preferences')) {
        return $prefs;
    }

    $stmt = $pdo->prepare('SELECT locale, theme, per_page, grid_state FROM user_preferences WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row === false) {
        return $prefs;
    }

    $prefs['locale'] = locale_normalize(is_string($row['locale']) ? $row['locale'] : null);
    $prefs['theme'] = user_preferences_theme_normalize(is_string($row['theme']) ? $row['theme'] : null)
        ?? $prefs['theme'];

    $perPage = (int) ($row['per_page'] ?? 0);
    if ($perPage >= 5 && $perPage <= 500) {
        $prefs['per_page'] = $perPage;
    }
    $prefs['grid_state'] = user_preferences_grid_state($row['grid_state'] ?? null);

    return $prefs;
}

/**
 * Apply a partial update and return the merged preferences.
 *
 * @param array<string, mixed> $input   keys: locale, theme, per_page, grid_state
 * @param array<string, mixed> $current result of user_preferences_get()
 * @return array{locale: ?string, theme: string, per_page: int, grid_state: ?array}
 *
 * @throws ApiError('validation_failed', …, 422) when a value is invalid
 */
function user_preferences_apply(array $input, array $current): array
{
    $errors = [];
    $next = $current;

    if (array_key_exists('locale', $input)) {
        $raw = $input['locale'];
        if ($raw === null || $raw === '') {
            $next['locale'] = null;
        } else {
            $locale = locale_normalize(is_string($raw) ? $raw : null);
            if ($locale === null) {
                $errors['locale'] = 'unsupported_locale';
            } else {
                $next['locale'] = $locale;
            }
        }
    }

    if (array_key_exists('theme', $input)) {
        $theme = user_preferences_theme_normalize(is_string($input['theme']) ? $input['theme'] : null);
        if ($theme === null) {
            $errors['theme'] = 'invalid_option';
        } else {
            $next['theme'] = $theme;
        }
    }

    if (array_key_exists('per_page', $input)) {
        $rawPage = $input['per_page'];
        if (!is_int($rawPage) && !(is_string($rawPage) && ctype_digit($rawPage))) {
            $errors['per_page'] = 'invalid_number';
        } else {
            $perPage = (int) $rawPage;
            if ($perPage < 5 || $perPage > 500) {
                $errors['per_page'] = 'out_of_range';
            } else {
                $next['per_page'] = $perPage;
            }
        }
    }

    if (array_key_exists('grid_state', $input)) {
        $rawState = $input['grid_state'];
        $state = user_preferences_grid_state($rawState);
        $isEmpty = $rawState === null || $rawState === [] || $rawState === '';
        if ($state === null && !$isEmpty) {
            $errors['grid_state'] = 'invalid_grid_state';
        } else {
            $next['grid_state'] = $state;
        }
    }

    if ($errors !== []) {
        throw new ApiError('validation_failed', 'One or more preferences are invalid.', 422, $errors);
    }

    return $next;
}

/**
 * Persist preferences for a user (no-op when the table is missing).
 *
 * @param array{locale: ?string, theme: string, per_page: int, grid_state: ?array} $prefs
 */
function user_preferences_save(array $prefs, string $email): void
{
    $email = trim($email);
    $pdo = db_try();
    if ($email === '' || $pdo === null || !db_table_exists($pdo, 'user_preferences')) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO user_preferences (email, locale, theme, per_page, grid_state)
              VALUES (:email, :locale, :theme, :per_page, :grid_state)
         ON DUPLICATE KEY UPDATE locale = VALUES(locale), theme = VALUES(theme),
              per_page = VALUES(per_page), grid_state = VALUES(grid_state)'
    );
    $stmt->execute([
        ':email'      => $email,
        ':locale'     => $prefs['locale'] ?? null,
        ':theme'      => (string) ($prefs['theme'] ?? 'system'),
        ':per_page'   => (int) ($prefs['per_page'] ?? 25),
        ':grid_state' => $prefs['grid_state'] === null
            ? null
            : json_encode($prefs['grid_state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

