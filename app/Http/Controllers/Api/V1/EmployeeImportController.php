<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\DownloadImportTemplate;
use App\Actions\Employees\ImportEmployees;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\ImportEmployeesRequest;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeImportController extends Controller
{
    public function store(ImportEmployeesRequest $request, ImportEmployees $import): JsonResponse
    {
        Gate::authorize('import', Employee::class);

        /** @var User $actor */
        $actor = $request->user();
        $dryRun = $request->boolean('dry_run');

        $result = $import->handle(
            $actor,
            (string) $request->file('file')?->getRealPath(),
            $request->extension(),
            $dryRun,
            $request->ip(),
        );

        if ($dryRun) {
            return response()->json([
                'message' => "All {$result['total_rows']} rows are valid. Nothing was imported (dry run).",
                'dry_run' => true,
                'total_rows' => $result['total_rows'],
            ]);
        }

        return response()->json([
            'message' => count($result['employee_ids']).' employees imported. Invitations are being sent.',
            'dry_run' => false,
            'imported' => count($result['employee_ids']),
            'employee_ids' => $result['employee_ids'],
        ], 201);
    }

    public function template(DownloadImportTemplate $template): StreamedResponse
    {
        Gate::authorize('import', Employee::class);

        return $template->handle();
    }
}
