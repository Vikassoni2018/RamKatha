<?php

declare(strict_types=1);

namespace App\Service\Export;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams CSV downloads row by row (constant memory for large exports). Values starting with
 * =, +, -, @ are prefixed to prevent spreadsheet formula injection.
 */
final class CsvExporter
{
    /**
     * @param list<string>             $header
     * @param iterable<list<mixed>>    $rows
     */
    public function stream(string $filename, array $header, iterable $rows): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($header, $rows): void {
            $out = fopen('php://output', 'wb');
            if (false === $out) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Devanagari correctly
            fputcsv($out, $header, ',', '"', '\\');
            $i = 0;
            foreach ($rows as $row) {
                fputcsv($out, array_map($this->cell(...), $row), ',', '"', '\\');
                if (0 === ++$i % 500) {
                    flush();
                }
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'export.csv'));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function cell(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if (\is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        $string = (string) $value;
        if ('' !== $string && \in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $string = "'".$string;
        }

        return $string;
    }
}
