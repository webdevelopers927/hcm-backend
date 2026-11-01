<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\BulkDeleteEmployees;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\BulkDeleteEmployeesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class EmployeeBulkDeleteController extends Controller
{
    public function __invoke(BulkDeleteEmployeesRequest $request, BulkDeleteEmployees $bulkDelete): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($bulkDelete->handle($actor, $request->ids(), $request->ip()));
    }
}
