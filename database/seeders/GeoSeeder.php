<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Countries, states and cities from database/data/geo (trimmed from the dr5hn
 * countries-states-cities-database, ODbL-1.0). Idempotent: rows are upserted by the dataset id.
 */
class GeoSeeder extends Seeder
{
    private const CHUNK = 2000;

    public function run(): void
    {
        $this->load('countries', ['name', 'iso2', 'iso3', 'phone_code', 'nationality', 'emoji']);
        $this->load('states', ['country_id', 'name', 'code']);
        $this->load('cities', ['state_id', 'country_id', 'name']);
    }

    /**
     * @param  list<string>  $update  columns refreshed when the id already exists
     */
    private function load(string $table, array $update): void
    {
        $path = database_path("data/geo/{$table}.csv.gz");
        $fh = gzopen($path, 'r');
        if ($fh === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $header = fgetcsv($fh, escape: '\\');
        if ($header === false) {
            throw new RuntimeException("{$path} is empty.");
        }

        $count = 0;
        $batch = [];
        while (($row = fgetcsv($fh, escape: '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $batch[] = array_map(fn (?string $v) => $v === '' ? null : $v, array_combine($header, $row));

            if (count($batch) === self::CHUNK) {
                DB::table($table)->upsert($batch, ['id'], $update);
                $count += count($batch);
                $batch = [];
            }
        }
        gzclose($fh);

        if ($batch !== []) {
            DB::table($table)->upsert($batch, ['id'], $update);
            $count += count($batch);
        }

        $this->command->info("{$table}: {$count}");
    }
}
