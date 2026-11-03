<?php

namespace App\Console\Commands;

use App\Actions\Contracts\ContractLifecycle;
use Illuminate\Console\Command;

class SyncContracts extends Command
{
    protected $signature = 'contracts:sync';

    protected $description = 'Block sign-in for employees whose contract has ended, and restore those with a contract in force again';

    public function handle(ContractLifecycle $lifecycle): int
    {
        $result = $lifecycle->syncAll();

        $this->info("Contracts synced: {$result['expired']} expired, {$result['restored']} restored.");

        return self::SUCCESS;
    }
}
