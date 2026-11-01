<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\Shift;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportEmployees
{
    /** Deliberately no PII: no CNIC, IBAN, salary, phone or birth date. */
    public const HEADINGS = ['Code', 'Name', 'Email', 'Designation', 'Department', 'Status', 'Work mode', 'Shift', 'Location', 'Join date'];

    public function __construct(private readonly BuildEmployeeQuery $builder) {}

    /**
     * @param  array<string, mixed>  $filters  list filters + optional ids[]
     */
    public function handle(array $filters, string $format): StreamedResponse
    {
        $query = $this->builder->handle($filters)
            ->select(BuildEmployeeQuery::LIST_COLUMNS)
            ->with(['designation:id,name', 'department:id,name', 'shift:id,name,start_time,end_time', 'location:id,name']);
        $query = $this->builder->sort($query, $filters['sort'] ?? null);

        $filename = 'employees-'.now()->format('Y-m-d-His').'.'.$format;

        return response()->streamDownload(function () use ($query, $format) {
            $rows = $query->lazy(500)->map(fn (Employee $e) => $this->row($e));

            $format === 'xlsx' ? $this->writeXlsx($rows) : $this->writeCsv($rows);
        }, $filename, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return list<string>
     */
    private function row(Employee $e): array
    {
        $shift = $e->shift
            ? sprintf('%s (%s-%s)', $e->shift->name, Shift::hhmm($e->shift->start_time), Shift::hhmm($e->shift->end_time))
            : '';

        return [
            (string) $e->code,
            $e->full_name,
            $e->email,
            (string) $e->designation?->name,
            (string) $e->department?->name,
            $e->status->label(),
            $e->work_mode->label(),
            $shift,
            (string) $e->location?->name,
            $e->join_date->toDateString(),
        ];
    }

    /**
     * @param  iterable<list<string>>  $rows
     */
    private function writeCsv(iterable $rows): void
    {
        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly.
        fputcsv($out, self::HEADINGS);
        foreach ($rows as $row) {
            fputcsv($out, array_map(self::neutraliseFormula(...), $row));
        }
        fclose($out);
    }

    /**
     * @param  iterable<list<string>>  $rows
     */
    private function writeXlsx(iterable $rows): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile('php://output');
        $writer->addRow(Row::fromValues(self::HEADINGS));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
    }

    /** CSV injection guard: cells starting with = + - @ are prefixed with a quote. */
    private static function neutraliseFormula(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
