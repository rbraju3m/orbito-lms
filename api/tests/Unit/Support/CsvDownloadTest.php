<?php

declare(strict_types=1);

use App\Support\Http\CsvDownload;

/*
 * Every string in an export was typed by somebody — a lead's name by a
 * stranger, a course title by an instructor — and a spreadsheet runs a cell
 * that starts like a formula (OWASP, "CSV Injection").
 */

it('defuses a cell a spreadsheet would run as a formula', function (string $typed, string $written): void {
    expect(CsvDownload::cell($typed))->toBe($written);
})->with([
    'equals' => ['=1+1', "'=1+1"],
    'plus' => ['+44 20 7946 0000', "'+44 20 7946 0000"],
    'minus' => ['-intro to drawing', "'-intro to drawing"],
    'at' => ['@SUM(A1:A9)', "'@SUM(A1:A9)"],
    'tab' => ["\t=cmd", "'\t=cmd"],
    'carriage return' => ["\r=cmd", "'\r=cmd"],
    'ordinary text' => ['Ada Lovelace', 'Ada Lovelace'],
    'a formula later in the cell' => ['Total =1+1', 'Total =1+1'],
    'empty' => ['', ''],
]);

it('leaves numbers as numbers a spreadsheet can sum, negative ones included', function (): void {
    expect(CsvDownload::cell(-1200))->toBe(-1200)
        ->and(CsvDownload::cell(3.5))->toBe(3.5)
        ->and(CsvDownload::cell(null))->toBeNull();
});
