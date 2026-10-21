<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Loads every US ZIP centre (Census ZCTA Gazetteer, public domain, ~33,800
 * rows) into `zip_centroids`. A drawn service area takes the ZIPs whose centre
 * falls inside it. Run once per box; re-running replaces the rows.
 */
class SchedulingLoadZipCentroids extends Command
{
    protected $signature = 'scheduling:load-zip-centroids
        {--file= : A Gazetteer .txt (tab-separated) instead of downloading}';

    protected $description = 'Load US ZIP centres from the Census Gazetteer';

    private const URL = 'https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2024_Gazetteer/2024_Gaz_zcta_national.zip';

    public function handle(): int
    {
        $text = $this->option('file') ? file_get_contents($this->option('file')) : $this->download();

        $rows = [];
        foreach (preg_split('/\r?\n/', trim($text)) as $i => $line) {
            $cols = array_map('trim', explode("\t", $line));
            if ($i === 0 || count($cols) < 7 || ! preg_match('/^\d{5}$/', $cols[0])) {
                continue;
            }
            $rows[] = ['zip' => $cols[0], 'latitude' => (float) $cols[5], 'longitude' => (float) $cols[6]];
        }

        if (count($rows) < 1) {
            $this->error('No ZIP rows found; nothing changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($rows) {
            DB::table('zip_centroids')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('zip_centroids')->insert($chunk);
            }
        });
        $this->info(count($rows).' ZIP centres loaded.');

        return self::SUCCESS;
    }

    private function download(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'gaz');
        Http::timeout(120)->sink($zipPath)->get(self::URL)->throw();
        $zip = new \ZipArchive;
        abort_unless($zip->open($zipPath) === true, 500, 'The Gazetteer download is not a zip.');
        $text = $zip->getFromIndex(0);
        $zip->close();
        @unlink($zipPath);

        return (string) $text;
    }
}
