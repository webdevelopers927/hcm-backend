<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeWorkExperience;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private employee files. Only reachable through these authenticated, audit-logged endpoints.
 */
class EmployeeFileController extends Controller
{
    public function document(Request $request, Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        return $this->download($request, $employee, $document->path, $document->original_name, 'Employee document downloaded');
    }

    public function experienceLetter(Request $request, Employee $employee, EmployeeWorkExperience $workExperience): StreamedResponse
    {
        abort_if($workExperience->experience_letter_path === null, 404);

        return $this->download(
            $request, $employee, $workExperience->experience_letter_path,
            (string) $workExperience->experience_letter_name, 'Experience letter downloaded',
        );
    }

    private function download(Request $request, Employee $employee, string $path, string $name, string $log): StreamedResponse
    {
        Gate::authorize('viewSensitive', $employee);
        abort_unless(Storage::disk('local')->exists($path), 404);

        /** @var User $actor */
        $actor = $request->user();
        activity('employees')
            ->causedBy($actor)
            ->performedOn($employee)
            ->event('file_downloaded')
            ->withProperties(['ip' => $request->ip(), 'file' => $name])
            ->log($log);

        return Storage::disk('local')->download($path, $name, ['Cache-Control' => 'no-store, private']);
    }
}
