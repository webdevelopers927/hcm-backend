<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\RevealSensitiveData;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeSensitiveResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeSensitiveController extends Controller
{
    public function __invoke(Request $request, Employee $employee, RevealSensitiveData $reveal): JsonResponse
    {
        Gate::authorize('viewSensitive', $employee);

        /** @var User $actor */
        $actor = $request->user();

        return (new EmployeeSensitiveResource($reveal->handle($actor, $employee, $request->ip())))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }
}
