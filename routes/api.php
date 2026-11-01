<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmployeeBulkDeleteController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDeviceController;
use App\Http\Controllers\Api\V1\EmployeeExportController;
use App\Http\Controllers\Api\V1\EmployeeFilterController;
use App\Http\Controllers\Api\V1\EmployeeImportController;
use App\Http\Controllers\Api\V1\EmployeeSensitiveController;
use App\Http\Controllers\Api\V1\EmployeeTwoFactorController;
use Illuminate\Support\Facades\Route;

// Prefix "api/v1" is set in bootstrap/app.php.

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // Static paths first so they are not captured by {employee}.
    Route::get('employees/filters', EmployeeFilterController::class);
    Route::post('employees/bulk-delete', EmployeeBulkDeleteController::class);
    Route::post('employees/export', EmployeeExportController::class);
    Route::get('employees/import/template', [EmployeeImportController::class, 'template']);
    Route::post('employees/import', [EmployeeImportController::class, 'store']);

    Route::apiResource('employees', EmployeeController::class);

    Route::get('employees/{employee}/sensitive', EmployeeSensitiveController::class);
    Route::post('employees/{employee}/two-factor/reset', EmployeeTwoFactorController::class);
    Route::get('employees/{employee}/device', [EmployeeDeviceController::class, 'show']);
    Route::post('employees/{employee}/device/unbind', [EmployeeDeviceController::class, 'unbind']);
});
