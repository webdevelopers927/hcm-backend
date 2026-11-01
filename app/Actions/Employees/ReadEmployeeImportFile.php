<?php

namespace App\Actions\Employees;

use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Reads the first sheet of a CSV/XLSX upload into rows keyed by normalised header.
 */
class ReadEmployeeImportFile
{
    /**
     * @return array<int, array<string, string>> spreadsheet line number => [column => value]
     */
    public function handle(string $path, string $extension, int $maxRows): array
    {
        $reader = $extension === 'xlsx' ? new XlsxReader : new CsvReader;

        try {
            $reader->open($path);
            $headers = null;
            $rows = [];
            $line = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = array_map(self::cellToString(...), $row->toArray());

                    if ($headers === null) {
                        $headers = array_map(self::normaliseHeader(...), $cells);

                        continue;
                    }
                    if (implode('', $cells) === '') {
                        continue; // skip blank lines
                    }
                    if (count($rows) >= $maxRows) {
                        throw ValidationException::withMessages(['file' => "The file may not contain more than {$maxRows} employee rows."]);
                    }

                    $values = [];
                    foreach ($headers as $i => $header) {
                        if ($header !== '') {
                            $values[$header] = $cells[$i] ?? '';
                        }
                    }
                    $rows[$line] = $values;
                }
                break; // first sheet only
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'The file could not be read. Upload a valid CSV or XLSX file.']);
        } finally {
            $reader->close();
        }

        if ($headers === null) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        return $rows;
    }

    /** "Join Date*" -> "join_date" */
    private static function normaliseHeader(string $header): string
    {
        $header = strtolower(trim(str_replace(['*', "\u{FEFF}"], '', $header)));

        return (string) preg_replace('/[^a-z0-9]+/', '_', $header);
    }

    private static function cellToString(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value => (string) (int) $value,
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }
}
