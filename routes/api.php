<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DegreeProgramController;
use App\Http\Controllers\Api\V1\DivisionController;
use App\Http\Controllers\Api\V1\EmployeeBulkDeleteController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDetailController;
use App\Http\Controllers\Api\V1\EmployeeDeviceController;
use App\Http\Controllers\Api\V1\EmployeeDraftController;
use App\Http\Controllers\Api\V1\EmployeeExportController;
use App\Http\Controllers\Api\V1\EmployeeFileController;
use App\Http\Controllers\Api\V1\EmployeeFilterController;
use App\Http\Controllers\Api\V1\EmployeeFormOptionsController;
use App\Http\Controllers\Api\V1\EmployeeImportController;
use App\Http\Controllers\Api\V1\EmployeeSensitiveController;
use App\Http\Controllers\Api\V1\EmployeeShiftController;
use App\Http\Controllers\Api\V1\EmployeeTwoFactorController;
use App\Http\Controllers\Api\V1\EmploymentStatusController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\OffboardingController;
use App\Http\Controllers\Api\V1\UploadController;
use Illuminate\Support\Facades\Route;

// Prefix "api/v1" is set in bootstrap/app.php.

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // Reference data
    Route::get('countries', [GeoController::class, 'countries']);
    Route::get('countries/{country}/states', [GeoController::class, 'states']);
    Route::get('states/{state}/cities', [GeoController::class, 'cities']);
    Route::get('nationalities', [GeoController::class, 'nationalities']);
    Route::apiResource('divisions', DivisionController::class);
    Route::apiResource('degree-programs', DegreeProgramController::class);
    Route::apiResource('employment-statuses', EmploymentStatusController::class);

    Route::post('uploads', [UploadController::class, 'store'])->middleware('throttle:uploads');

    // Off-boarding
    Route::apiResource('offboardings', OffboardingController::class);
    Route::post('offboardings/{offboarding}/complete', [OffboardingController::class, 'complete']);
    Route::post('offboardings/{offboarding}/checklist', [OffboardingController::class, 'addItem']);
    Route::scopeBindings()->group(function () {
        Route::patch('offboardings/{offboarding}/checklist/{item}', [OffboardingController::class, 'updateItem']);
        Route::delete('offboardings/{offboarding}/checklist/{item}', [OffboardingController::class, 'removeItem']);
    });

    // Shift records
    Route::get('employee-shifts', [EmployeeShiftController::class, 'index']);
    Route::post('employee-shifts/assign', [EmployeeShiftController::class, 'assign']);
    Route::delete('employee-shifts/assignments/{assignment}', [EmployeeShiftController::class, 'cancel']);

    // Static paths first so they are not captured by {employee}.
    Route::get('employees/filters', EmployeeFilterController::class);
    Route::get('employees/form-options', EmployeeFormOptionsController::class);
    Route::get('employees/drafts', [EmployeeDraftController::class, 'index']);
    Route::post('employees/drafts', [EmployeeDraftController::class, 'store']);
    Route::post('employees/bulk-delete', EmployeeBulkDeleteController::class);
    Route::post('employees/export', EmployeeExportController::class);
    Route::get('employees/import/template', [EmployeeImportController::class, 'template']);
    Route::post('employees/import', [EmployeeImportController::class, 'store']);

    Route::apiResource('employees', EmployeeController::class);

    Route::get('employees/{employee}/details', EmployeeDetailController::class);
    Route::get('employees/{employee}/shift-history', [EmployeeShiftController::class, 'history']);
    Route::post('employees/{employee}/submit', [EmployeeDraftController::class, 'submit']);
    Route::get('employees/{employee}/sensitive', EmployeeSensitiveController::class);
    Route::post('employees/{employee}/two-factor/reset', EmployeeTwoFactorController::class);
    Route::get('employees/{employee}/device', [EmployeeDeviceController::class, 'show']);
    Route::post('employees/{employee}/device/unbind', [EmployeeDeviceController::class, 'unbind']);

    Route::scopeBindings()->group(function () {
        Route::get('employees/{employee}/documents/{document}/download', [EmployeeFileController::class, 'document'])
            ->name('employees.documents.download');
        Route::get('employees/{employee}/work-experiences/{workExperience}/letter', [EmployeeFileController::class, 'experienceLetter'])
            ->name('employees.work-experiences.letter');
    });
});
