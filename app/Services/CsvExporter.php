<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Builds CSV files for download (financial reports, Phase 5).
 *
 *  - fputcsv() does the quoting, so commas, quotes and line breaks inside a
 *    value cannot break the column layout.
 *  - UTF-8 with a BOM, and ";" as the separator: that is what Excel expects in a
 *    pt-BR locale, so accents and columns open correctly with a double click.
 *  - CSV/formula injection: a cell that starts with =, +, -, @ (or a tab / CR,
 *    which some spreadsheet apps strip before evaluating) would run as a formula
 *    when opened, e.g. a unit named "=HYPERLINK(...)". Such cells are prefixed
 *    with a single quote, which makes the spreadsheet treat them as text.
 */
final class CsvExporter
{
    private const BOM = "\u{FEFF}";
    private const DANGEROUS_FIRST_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<list<string|int|null>> $rows The first row is usually the header.
     */
    public function build(array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the CSV.');
        }

        fwrite($handle, self::BOM);
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'sanitize'], $row), ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** "1234.50" → "1234,50": DECIMAL strings in the pt-BR format Excel parses as numbers. */
    public static function decimal(string $amount): string
    {
        return str_replace('.', ',', $amount);
    }

    /** Neutralises spreadsheet formulas (see class comment). */
    public static function sanitize(string|int|null $value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], self::DANGEROUS_FIRST_CHARS, true)) {
            return "'" . $value;
        }

        return $value;
    }
}
