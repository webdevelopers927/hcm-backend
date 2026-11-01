<?php

namespace App\Console\Commands;

use App\Models\Upload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneUploads extends Command
{
    protected $signature = 'uploads:prune';

    protected $description = 'Delete uploads that were never attached to an employee';

    public function handle(): int
    {
        $count = 0;

        Upload::query()
            ->where('created_at', '<', now()->subHours(Upload::TTL_HOURS))
            ->chunkById(200, function ($uploads) use (&$count) {
                foreach ($uploads as $upload) {
                    Storage::disk('local')->delete($upload->path);
                    $upload->delete();
                    $count++;
                }
            });

        $this->info("Pruned {$count} upload(s).");

        return self::SUCCESS;
    }
}
