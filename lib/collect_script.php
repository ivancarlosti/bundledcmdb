<?php
declare(strict_types=1);

/**
 * lib/collect_script.php — renders the downloadable collector.
 *
 * Two shapes, one inventory: a `.bat` a non-technical user can double-click and
 * a `.ps1` for administrators who deploy scripts themselves. Both carry the same
 * ASCII-only body (`lib/collect_script.ps1`, a template with three placeholders)
 * and talk to `public/collect.php`.
 *
 * Why the `.bat` embeds the script instead of shipping two files: a colleague
 * who receives one attachment can run it, and there is no second file to lose or
 * mismatch. The batch part is a launcher only — it finds the marker line, copies
 * everything after it to a temp `.ps1`, runs it with `-ExecutionPolicy Bypass`
 * and deletes it again. The body never goes through cmd's parser, so quotes,
 * carets and parentheses in PowerShell stay intact.
 *
 * The file name and the body are ASCII on purpose: cmd.exe mangles a UTF-8 batch
 * file, and `Set-Content -Encoding ASCII` would turn a typographic dash into a
 * question mark inside a string literal.
 */

/** Bumped when the payload changes; travels as `X-Collector-Version`. */
const COLLECT_SCRIPT_VERSION = '1.0.0';

/** Marker line that separates the batch launcher from the PowerShell body. */
const COLLECT_SCRIPT_MARKER = '#__BCMDB_PS1__';

/** Endpoint the script posts to (`app_base_url()` already carries the scheme). */
function collect_script_endpoint(): string
{
    return rtrim(app_base_url(), '/') . '/collect';
}

/**
 * The PowerShell body with the placeholders replaced.
 *
 * `strtr()` and not `sprintf()`: the body legitimately contains `%` (WMI
 * filters, `-like` patterns) and `%s` would eat them.
 */
function collect_script_body(string $endpoint, string $token): string
{
    $template = (string) file_get_contents(__DIR__ . '/collect_script.ps1');
    return strtr($template, [
        '__ENDPOINT__' => $endpoint,
        '__TOKEN__'    => $token,
        '__VERSION__'  => COLLECT_SCRIPT_VERSION,
    ]);
}

/**
 * Launcher + body as one double-clickable batch file.
 *
 * The launcher never sees the body: cmd executes line by line and `exit /b` is
 * the last statement it reaches, so the marker and everything after it are dead
 * text for cmd and data for PowerShell.
 */
function collect_script_bat(string $endpoint, string $token): string
{
    $tag = 'bcmdb-collect-' . bin2hex(random_bytes(4));
    $command = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "'
        . '$l=Get-Content -LiteralPath $env:BCMDB_SELF; '
        . '$m=' . "'" . COLLECT_SCRIPT_MARKER . "'" . '; '
        . '$i=[Array]::IndexOf($l,$m); '
        . 'if($i -lt 0 -or $i -ge ($l.Count-1)){Write-Host ' . "'This file is incomplete - download it again.'" . '; exit 1}; '
        . '$l[($i+1)..($l.Count-1)] | Set-Content -LiteralPath $env:BCMDB_PS1 -Encoding ASCII; '
        . '& $env:BCMDB_PS1; exit $LASTEXITCODE"';

    $lines = [
        '@echo off',
        'setlocal',
        'title BundledCMDB - collect device data',
        'set "BCMDB_SELF=%~f0"',
        'set "BCMDB_PS1=%TEMP%\\' . $tag . '.ps1"',
        $command,
        'set "RC=%ERRORLEVEL%"',
        'del "%BCMDB_PS1%" >nul 2>&1',
        'echo.',
        'pause',
        'exit /b %RC%',
        COLLECT_SCRIPT_MARKER,
    ];

    $body = collect_script_body($endpoint, $token);
    // cmd.exe wants CRLF; PowerShell accepts either, so one ending is enough.
    return implode("\r\n", $lines) . "\r\n" . collect_crlf($body);
}

/** The same inventory as a plain `.ps1`, for administrators who deploy scripts. */
function collect_script_ps1(string $endpoint, string $token): string
{
    return collect_crlf(collect_script_body($endpoint, $token));
}

/** Normalise any line endings to CRLF, without doubling an existing `\r`. */
function collect_crlf(string $text): string
{
    return implode("\r\n", explode("\n", str_replace(["\r\n", "\r"], "\n", $text)));
}
