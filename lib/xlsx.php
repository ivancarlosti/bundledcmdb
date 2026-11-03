<?php
declare(strict_types=1);

/**
 * lib/xlsx.php — minimal, dependency-free `.xlsx` writer.
 *
 * The Docker image and the PHP CLI have no `ext-zip` (see `Dockerfile`), and
 * `ext-zlib` is not guaranteed either, so `PhpSpreadsheet` (which needs both) is
 * not an option. An `.xlsx` is an OPC package: a ZIP archive of a handful of XML
 * parts. This file writes that archive itself:
 *
 *   - stored (method 0) or raw-deflate (method 8, only when `deflate_init()`
 *     exists and the result is actually smaller),
 *   - entries streamed through temp files, so a 50k-row export never has to fit
 *     in memory,
 *   - inline strings only, which keeps the writer at one XML parser rule (no
 *     shared-strings table to deduplicate and no `styles` bookkeeping),
 *   - one worksheet, a bold header row, frozen header and auto-sized columns.
 *
 * Everything Excel/LibreOffice needs is present: `[Content_Types].xml`, the
 * package relationships, `workbook.xml`, `styles.xml` and the sheet. Nothing
 * else (no theme, no calc chain) — those are optional and only add bytes.
 */

/** Zip entry names inside the package. */
const XLSX_PART_CONTENT_TYPES = '[Content_Types].xml';
const XLSX_PART_RELS = '_rels/.rels';
const XLSX_PART_WORKBOOK = 'xl/workbook.xml';
const XLSX_PART_WORKBOOK_RELS = 'xl/_rels/workbook.xml.rels';
const XLSX_PART_STYLES = 'xl/styles.xml';
const XLSX_PART_SHEET = 'xl/worksheets/sheet1.xml';

/**
 * Column letter for a one-based index (1 => A, 27 => AA).
 */
function xlsx_column(int $index): string
{
    $letters = '';
    $index = max(1, $index);
    while ($index > 0) {
        $remainder = ($index - 1) % 26;
        $letters = chr(65 + $remainder) . $letters;
        $index = intdiv($index - 1, 26);
    }
    return $letters;
}

/**
 * Text that is safe inside XML 1.0.
 *
 * Excel rejects most C0 controls, and `htmlspecialchars()` alone would leave
 * them in place (a single stray \x01 in a note is enough for Excel to call the
 * whole file corrupt).
 */
function xlsx_xml_text(string $value): string
{
    $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
    return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * One worksheet row as `<row>` XML (header cells use style 1 = bold).
 *
 * @param list<string> $cells
 */
function xlsx_row_xml(int $rowNumber, array $cells, bool $header = false): string
{
    $xml = '<row r="' . $rowNumber . '">';
    $column = 1;
    foreach ($cells as $cell) {
        $ref = xlsx_column($column++) . $rowNumber;
        $style = $header ? ' s="1"' : '';
        // Everything is an inline string on purpose: a serial number, an asset
        // tag or a phone-like value must survive a round-trip unchanged, which
        // is not true once Excel decides something is a number or a date.
        $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
            . xlsx_xml_text($cell)
            . '</t></is></c>';
    }
    return $xml . '</row>';
}

/**
 * Worksheet XML, one chunk at a time.
 *
 * @param list<string>        $header
 * @param iterable<list<string>> $rows
 * @return Generator<int, string>
 */
function xlsx_sheet_chunks(array $header, iterable $rows): Generator
{
    $widths = [];
    foreach ($header as $index => $title) {
        // `cols` has to appear before `sheetData`; widths are derived from the
        // header text and widened later rows are already wide enough in practice.
        $length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        $widths[$index] = max(10, min(60, $length + 4));
    }

    yield '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    yield '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>';

    $cols = '<cols>';
    foreach ($widths as $index => $width) {
        $cols .= '<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    yield $cols . '</cols><sheetData>';

    if ($header !== []) {
        yield xlsx_row_xml(1, $header, true);
    }

    $rowNumber = $header === [] ? 1 : 2;
    foreach ($rows as $cells) {
        yield xlsx_row_xml($rowNumber++, $cells);
    }

    yield '</sheetData></worksheet>';
}


/**
 * Build the `.xlsx` archive.
 *
 * Entries are written with a proper local header (CRC and sizes computed before
 * the header is emitted) so no data descriptor or seek-back is needed; each part
 * is first assembled in a temp stream, which is what keeps the memory footprint
 * flat for a large sheet.
 */
final class XlsxArchive
{
    /** @var resource */
    private $archive;

    /** @var list<array{name: string, method: int, crc: int, size: int, comp: int, offset: int}> */
    private array $entries = [];

    private int $offset = 0;

    public function __construct()
    {
        $handle = tmpfile();
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the workbook.');
        }
        $this->archive = $handle;
    }

    public function addString(string $name, string $data): void
    {
        $this->addChunks($name, [$data]);
    }

    /**
     * @param iterable<string> $chunks
     */
    public function addChunks(string $name, iterable $chunks, bool $compress = true): void
    {
        $raw = $this->collect($chunks);
        $size = (int) (fstat($raw)['size'] ?? 0);
        $crc = $this->crcOf($raw);

        $method = 0;
        $source = $raw;
        $comp = $size;
        $deflated = null;

        if ($compress && $size > 256 && function_exists('deflate_init')) {
            $deflated = $this->deflate($raw);
            if ($deflated !== null) {
                $compressedSize = (int) (fstat($deflated)['size'] ?? 0);
                if ($compressedSize > 0 && $compressedSize < $size) {
                    $method = 8;
                    $source = $deflated;
                    $comp = $compressedSize;
                }
            }
        }

        $nameLength = strlen($name);
        $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $this->dosTime(), $this->dosDate(), $crc, $comp, $size, $nameLength, 0);
        $this->write($header . $name);
        $this->copy($source);

        $this->entries[] = [
            'name'   => $name,
            'method' => $method,
            'crc'    => $crc,
            'size'   => $size,
            'comp'   => $comp,
            'offset' => $this->offset - $comp - 30 - $nameLength,
        ];

        fclose($raw);
        if ($deflated !== null) {
            fclose($deflated);
        }
    }


    /**
     * Close the archive and return the whole `.xlsx` as bytes.
     */
    public function finish(): string
    {
        $directory = '';
        foreach ($this->entries as $entry) {
            $directory .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                $entry['method'],
                $this->dosTime(),
                $this->dosDate(),
                $entry['crc'],
                $entry['comp'],
                $entry['size'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                0,
                $entry['offset']
            ) . $entry['name'];
        }

        $directoryOffset = $this->offset;
        $this->write($directory);
        $this->write(pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($this->entries),
            count($this->entries),
            strlen($directory),
            $directoryOffset,
            0
        ));

        rewind($this->archive);
        $bytes = stream_get_contents($this->archive);
        fclose($this->archive);

        return $bytes === false ? '' : $bytes;
    }

    /**
     * @param iterable<string> $chunks
     * @return resource
     */
    private function collect(iterable $chunks)
    {
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream for a workbook part.');
        }
        foreach ($chunks as $chunk) {
            if ($chunk !== '') {
                fwrite($stream, $chunk);
            }
        }
        rewind($stream);
        return $stream;
    }

    /** @param resource $stream */
    private function crcOf($stream): int
    {
        $hash = hash_init('crc32b');
        while (!feof($stream)) {
            $block = fread($stream, 1 << 16);
            if ($block === false || $block === '') {
                break;
            }
            hash_update($hash, $block);
        }
        rewind($stream);
        return (int) unpack('N', hash_final($hash, true))[1];
    }

    /**
     * Raw-deflate a stream into another stream; null when zlib is unusable.
     *
     * @param resource $source
     * @return resource|null
     */
    private function deflate($source)
    {
        $context = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
        if ($context === false) {
            return null;
        }
        $out = tmpfile();
        if ($out === false) {
            return null;
        }

        rewind($source);
        while (!feof($source)) {
            $block = fread($source, 1 << 16);
            if ($block === false || $block === '') {
                break;
            }
            $deflated = deflate_add($context, $block, ZLIB_NO_FLUSH);
            if ($deflated !== false && $deflated !== '') {
                fwrite($out, $deflated);
            }
        }
        $tail = deflate_add($context, '', ZLIB_FINISH);
        if ($tail !== false && $tail !== '') {
            fwrite($out, $tail);
        }

        rewind($out);
        return $out;
    }

    /** @param resource $source */
    private function copy($source): void
    {
        rewind($source);
        while (!feof($source)) {
            $block = fread($source, 1 << 16);
            if ($block === false || $block === '') {
                break;
            }
            $this->write($block);
        }
    }

    private function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        fwrite($this->archive, $bytes);
        $this->offset += strlen($bytes);
    }

    /** DOS time, little-endian: seconds/2, minutes, hours. */
    private function dosTime(): int
    {
        $now = getdate();
        return (($now['hours'] & 0x1F) << 11) | (($now['minutes'] & 0x3F) << 5) | (($now['seconds'] >> 1) & 0x1F);
    }

    /** DOS date: day, month, years since 1980. */
    private function dosDate(): int
    {
        $now = getdate();
        return ((($now['year'] - 1980) & 0x7F) << 9) | (($now['mon'] & 0x0F) << 5) | ($now['mday'] & 0x1F);
    }
}

/**
 * Sheet name Excel accepts: 31 characters and none of `[]:*?/\`.
 */
function xlsx_sheet_name(string $name): string
{
    $name = trim($name);
    $name = $name === '' ? 'Assets' : $name;
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 31) : substr($name, 0, 31);
    return str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $name);
}

/**
 * Assemble the package parts and return the `.xlsx` bytes.
 *
 * @param list<string>           $header first row (bold)
 * @param iterable<list<string>> $rows
 */
function xlsx_build(array $header, iterable $rows, string $sheetName = 'Assets'): string
{
    $sheetName = xlsx_sheet_name($sheetName);

    $archive = new XlsxArchive();
    $archive->addString(XLSX_PART_CONTENT_TYPES, xlsx_content_types_xml());
    $archive->addString(XLSX_PART_RELS, xlsx_rels_xml());
    $archive->addString(XLSX_PART_WORKBOOK, xlsx_workbook_xml($sheetName));
    $archive->addString(XLSX_PART_WORKBOOK_RELS, xlsx_workbook_rels_xml());
    $archive->addString(XLSX_PART_STYLES, xlsx_styles_xml());
    $archive->addChunks(XLSX_PART_SHEET, xlsx_sheet_chunks($header, $rows));

    return $archive->finish();
}

function xlsx_content_types_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
}

function xlsx_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function xlsx_workbook_xml(string $sheetName): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_xml_text($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
}

function xlsx_workbook_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
}

/**
 * Two cell formats: the default one and a bold one for the header row.
 *
 * `cellStyleXfs` is required even though nothing uses it — Excel refuses the
 * package when the collection is missing.
 */
function xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}
