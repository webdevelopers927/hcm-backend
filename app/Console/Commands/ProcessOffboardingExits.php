<?php

namespace App\Console\Commands;

use App\Actions\Offboarding\OffboardingWorkflow;
use Illuminate\Console\Command;

class ProcessOffboardingExits extends Command
{
    protected $signature = 'offboarding:process-exits';

    protected $description = 'Move employees whose last working day has passed to their post-exit employment status';

    public function handle(OffboardingWorkflow $workflow): int
    {
        $count = $workflow->processDue();

        $this->info("Processed {$count} exit(s).");

        return self::SUCCESS;
    }
}
