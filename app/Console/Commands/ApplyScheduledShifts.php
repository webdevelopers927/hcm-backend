<?php

namespace App\Console\Commands;

use App\Actions\Shifts\AssignShift;
use Illuminate\Console\Command;

class ApplyScheduledShifts extends Command
{
    protected $signature = 'shifts:apply-scheduled';

    protected $description = 'Move employees to shift changes that take effect today (or were missed)';

    public function handle(AssignShift $assignShift): int
    {
        $moved = $assignShift->applyDue();

        $this->info("Applied scheduled shift changes; {$moved} employee(s) moved.");

        return self::SUCCESS;
    }
}
