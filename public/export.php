<?php
declare(strict_types=1);

/**
 * public/export.php — CSV export of the filtered asset list.
 *
 * Uses the same asset_list() query as the grid, so filters, sorting and the
 * visible custom fields match what the user sees.
 */

session_start();
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/lang.php';
lang_init();
app_boot();

if (empty($_SESSION['user_email'])) {
    redirect('index.php');
}
$role = (string) ($_SESSION['role'] ?? 'user');
$currentUser = (string) $_SESSION['user_email'];
$company = (string) ($_SESSION['company'] ?? '');
$pdo = db_try();
if ($pdo === null || $company === '') {
    http_response_code(503);
    exit(e(lang('db_unavailable')));
}

$searchField = trim((string) ($_GET['search_field'] ?? ''));
$searchText = trim((string) ($_GET['search_text'] ?? ''));
$sourceFilter = trim((string) ($_GET['source'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? ''));
$dir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

$gridFields = array_values(array_filter(
    field_defs($pdo),
    static fn (array $def): bool => (int) $def['show_in_grid'] === 1
));
$columns = asset_grid_columns();

$filename = 'assets-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $company) . '-' . gmdate('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly UTF-8 BOM

$header = [];
foreach ($columns as $column) {
    $header[] = asset_column_defs()[$column]['label'] ?? $column;
}
foreach ($gridFields as $def) {
    $header[] = field_label($def, lang_code());
}
fputcsv($out, $header, ';', '"', '');

$page = 1;
do {
    $result = asset_list($pdo, [
        'company'      => $company,
        'source'       => $sourceFilter,
        'search'       => $searchText,
        'search_field' => $searchField,
        'owner_email'  => $role === 'user' ? $currentUser : '',
        'sort'         => $sort,
        'dir'          => $dir,
        'page'         => $page,
        'per_page'     => 500,
    ]);

    $assetIds = array_map(static fn (array $row): int => (int) $row['Id'], $result['rows']);
    $customValues = custom_values_load($pdo, $assetIds, $gridFields);
    $statusOptions = lang_status_options();

    foreach ($result['rows'] as $row) {
        $line = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if ($column === 'Status') {
                $value = $statusOptions[(string) $value] ?? $value;
            } elseif ($column === 'BYOD' || $column === 'Mobile') {
                $value = asset_bool_parse($value) ? lang('true_label') : lang('false_label');
            } elseif ($column === 'LastSeen') {
                $value = app_date((string) $value);
            }
            $line[] = $value;
        }
        foreach ($gridFields as $def) {
            $line[] = field_display_value($def, $customValues[(int) $row['Id']][(string) $def['field_key']] ?? null);
        }
        fputcsv($out, $line, ';', '"', '');
    }

    $page++;
} while ($page <= $result['pages']);

fclose($out);
exit;
