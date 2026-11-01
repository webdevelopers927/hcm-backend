<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\ExportEmployees;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\ExportEmployeesRequest;
use App\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeExportController extends Controller
{
    public function __invoke(ExportEmployeesRequest $request, ExportEmployees $export): StreamedResponse
    {
        Gate::authorize('export', Employee::class);

        return $export->handle($request->filters(), (string) $request->validated('format'));
    }
}
