<?php
declare(strict_types=1);

/**
 * lib/settings_actions.php — POST handlers for public/settings.php.
 *
 * Returns a result array instead of emitting output so the page keeps full
 * control over redirects, flash messages and rendering:
 *
 *   ['errors' => [key => code], 'saved' => bool, 'redirect' => ?string,
 *    'new_token' => ?string, 'log' => list<string>, 'test' => ?array]
 */

/**
 * @return array{errors: array<string, string>, saved: bool, redirect: ?string,
 *               new_token: ?string, log: list<string>, test: ?array}
 */
function settings_handle_post(PDO $pdo, string $tab, string $actor): array
{
    $result = ['errors' => [], 'saved' => false, 'redirect' => null, 'new_token' => null, 'log' => [], 'test' => null];
    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'save_settings':
            $group = (string) ($_POST['group'] ?? '');
            if (!in_array($group, ['general', 'assets', 'api', 'storage'], true)) {
                $result['errors']['_'] = 'unknown_group';
                return $result;
            }
            foreach (settings_definitions() as $key => $def) {
                if (($def['group'] ?? '') !== $group) {
                    continue;
                }
                if (!empty($def['secret'])) {
                    if (!empty($_POST['clear'][$key])) {
                        settings_set($key, '', $actor);
                        continue;
                    }
                    $new = trim((string) ($_POST['setting'][$key] ?? ''));
                    if ($new !== '') {
                        settings_set($key, $new, $actor);
                    }
                    continue;
                }
                if (!array_key_exists($key, (array) ($_POST['setting'] ?? []))) {
                    continue;
                }
                $raw = $_POST['setting'][$key];
                $type = (string) ($def['type'] ?? 'text');

                if ($type === 'bool') {
                    settings_set($key, !empty($raw) ? '1' : '0', $actor);
                    continue;
                }
                if ($type === 'locale') {
                    $locale = trim((string) $raw);
                    if ($locale !== '' && !in_array($locale, lang_supported(), true)) {
                        $result['errors'][$key] = 'invalid_locale';
                        continue;
                    }
                    settings_set($key, $locale, $actor);
                    continue;
                }
                if ($type === 'source') {
                    $slug = trim((string) $raw);
                    if ($slug !== '' && !source_exists($pdo, $slug)) {
                        $result['errors'][$key] = 'unknown_source';
                        continue;
                    }
                    settings_set($key, $slug, $actor);
                    continue;
                }
                if ($key === 'api.ip_allowlist' && !ip_allowlist_validate((string) $raw)['ok']) {
                    $result['errors'][$key] = 'invalid_ip';
                    continue;
                }
                $normalized = settings_normalize($key, $raw);
                if (!$normalized['ok']) {
                    $result['errors'][$key] = (string) $normalized['error'];
                    continue;
                }
                settings_set($key, $normalized['value'], $actor);
            }
            $result['saved'] = $result['errors'] === [];
            $result['redirect'] = 'settings.php?tab=' . urlencode($tab);
            return $result;

        case 'reset_setting':
            settings_forget((string) ($_POST['reset_key'] ?? ''));
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=' . urlencode($tab);
            return $result;

        case 'source_save':
            $original = trim((string) ($_POST['original_slug'] ?? ''));
            $payload = [
                'slug'       => $original !== '' ? $original : source_slugify((string) ($_POST['slug'] ?? '')),
                'label'      => (string) ($_POST['label'] ?? ''),
                'color'      => (string) ($_POST['color'] ?? ''),
                'is_default' => !empty($_POST['is_default']),
            ];
            $errors = source_validate($pdo, $payload, $original !== '' ? $original : null);
            if ($errors !== []) {
                $result['errors'] = $errors;
                return $result;
            }
            if ($original !== '') {
                source_update($pdo, $original, $payload);
            } else {
                source_create($pdo, $payload, $actor);
            }
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=sources';
            return $result;

        case 'source_delete':
            $outcome = source_delete($pdo, (string) ($_POST['slug'] ?? ''));
            if (!$outcome['ok']) {
                $result['errors']['_'] = (string) $outcome['error'];
                return $result;
            }
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=sources';
            return $result;
    }

    return settings_handle_post_extra($pdo, $action, $result, $tab, $actor);
}

/**
 * Remaining actions (fields, keys, migrations, S3 test) — split out only to keep
 * each function readable.
 */
function settings_handle_post_extra(PDO $pdo, string $action, array $result, string $tab, string $actor): array
{
    switch ($action) {
        case 'field_save':
            $id = (int) ($_POST['field_id'] ?? 0);
            $payload = [
                'field_key'      => (string) ($_POST['field_key'] ?? ''),
                'label'          => (string) ($_POST['label'] ?? ''),
                'label_i18n'     => (array) ($_POST['label_i18n'] ?? []),
                'data_type'      => (string) ($_POST['data_type'] ?? 'text'),
                'options_json'   => (string) ($_POST['options_json'] ?? ''),
                'default_value'  => (string) ($_POST['default_value'] ?? ''),
                'is_required'    => !empty($_POST['is_required']),
                'is_unique'      => !empty($_POST['is_unique']),
                'is_searchable'  => !empty($_POST['is_searchable']),
                'show_in_grid'   => !empty($_POST['show_in_grid']),
                'editable_roles' => (array) ($_POST['editable_roles'] ?? []),
                'sort_order'     => (int) ($_POST['sort_order'] ?? 0),
                'is_active'      => !empty($_POST['is_active']),
                'validation'     => [
                    'min'       => (string) ($_POST['min'] ?? ''),
                    'max'       => (string) ($_POST['max'] ?? ''),
                    'maxlength' => (string) ($_POST['maxlength'] ?? ''),
                    'pattern'   => (string) ($_POST['pattern'] ?? ''),
                ],
            ];
            $errors = field_validate($pdo, field_normalize_payload($payload), $id > 0 ? $id : null);
            if ($errors !== []) {
                $result['errors'] = $errors;
                return $result;
            }
            if ($id > 0) {
                field_update($pdo, $id, $payload, $actor);
            } else {
                field_create($pdo, $payload, $actor);
            }
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=fields';
            return $result;

        case 'field_toggle':
            field_set_active($pdo, (int) ($_POST['field_id'] ?? 0), !empty($_POST['active']), $actor);
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=fields';
            return $result;

        case 'field_delete':
            field_delete($pdo, (int) ($_POST['field_id'] ?? 0));
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=fields';
            return $result;

        case 'key_create':
        case 'key_update':
            $id = (int) ($_POST['key_id'] ?? 0);
            $payload = api_key_normalize_payload([
                'label'           => (string) ($_POST['label'] ?? ''),
                'company'         => (string) ($_POST['company'] ?? ''),
                'source_slug'     => (string) ($_POST['source_slug'] ?? ''),
                'allowed_sources' => (array) ($_POST['allowed_sources'] ?? []),
                'ip_allowlist'    => (string) ($_POST['ip_allowlist'] ?? ''),
                'can_read'        => !empty($_POST['can_read']),
                'can_write'       => !empty($_POST['can_write']),
                'can_delete'      => !empty($_POST['can_delete']),
                'is_active'       => !empty($_POST['is_active']),
            ]);
            $errors = api_key_validate($pdo, $payload);
            if ($errors !== []) {
                $result['errors'] = $errors;
                return $result;
            }
            if ($action === 'key_update' && $id > 0) {
                api_key_update($pdo, $id, $payload);
            } else {
                $created = api_key_create($pdo, $payload, $actor);
                $result['new_token'] = $created['token'];
                $_SESSION['new_api_token'] = $created['token'];
            }
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=api';
            return $result;

        case 'key_rotate':
            $token = api_key_rotate($pdo, (int) ($_POST['key_id'] ?? 0));
            if ($token !== null) {
                $_SESSION['new_api_token'] = $token;
                $result['new_token'] = $token;
            }
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=api';
            return $result;

        case 'key_toggle':
            api_key_set_active($pdo, (int) ($_POST['key_id'] ?? 0), !empty($_POST['active']));
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=api';
            return $result;

        case 'key_delete':
            api_key_delete($pdo, (int) ($_POST['key_id'] ?? 0));
            $result['saved'] = true;
            $result['redirect'] = 'settings.php?tab=api';
            return $result;

        case 'migrate':
            $dryRun = !empty($_POST['dry_run']);
            $run = migrate_run($pdo, $dryRun);
            $result['log'] = $run['log'];
            $result['saved'] = !$dryRun && migrate_status($pdo)['ready'];
            return $result;
    }

    $result['errors']['_'] = 'unknown_action';
    return $result;
}
