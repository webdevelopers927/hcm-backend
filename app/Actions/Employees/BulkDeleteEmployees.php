<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BulkDeleteEmployees
{
    public function __construct(private readonly DeleteEmployee $deleteEmployee) {}

    /**
     * Checks the delete policy per id; ids that fail are reported, the rest are deleted.
     *
     * @param  list<int>  $ids
     * @return array{deleted: list<int>, failed: list<array{id: int, reason: string}>}
     */
    public function handle(User $actor, array $ids, ?string $ip): array
    {
        $ids = array_values(array_unique($ids));
        $employees = Employee::query()->finalized()->with('user:id')->whereIn('id', $ids)->get()->keyBy('id');

        $deleted = [];
        $failed = [];

        DB::transaction(function () use ($actor, $ids, $ip, $employees, &$deleted, &$failed) {
            foreach ($ids as $id) {
                $employee = $employees->get($id);

                if (! $employee) {
                    $failed[] = ['id' => $id, 'reason' => 'not_found'];

                    continue;
                }

                $response = Gate::forUser($actor)->inspect('delete', $employee);
                if ($response->denied()) {
                    $failed[] = ['id' => $id, 'reason' => (string) ($response->code() ?? 'forbidden')];

                    continue;
                }

                $this->deleteEmployee->handle($actor, $employee, $ip);
                $deleted[] = $id;
            }
        });

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
