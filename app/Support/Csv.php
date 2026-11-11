<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class Csv
{
    /**
     * Stream a CSV download. Rows may be a lazy iterable (large exports do not load into memory).
     * Text cells that a spreadsheet could execute as a formula (=, +, -, @, tab, CR) are neutralised.
     *
     * @param  array<int,string>  $headers
     * @param  iterable<array<int,mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");                        // UTF-8 BOM so Excel shows names correctly
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && ! is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
