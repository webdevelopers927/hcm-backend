<?php

namespace App\Enums;

/**
 * Permission names the frontend can gate UI on.
 *
 * Roles/permission storage (spatie/laravel-permission) is intentionally not wired up yet:
 * every authenticated user is granted all of these, and EmployeePolicy is the single
 * place where real checks will be added.
 */
enum Permission: string
{
    case EmployeesView = 'employees.view';
    case EmployeesCreate = 'employees.create';
    case EmployeesImport = 'employees.import';
    case EmployeesUpdate = 'employees.update';
    case EmployeesDelete = 'employees.delete';
    case EmployeesExport = 'employees.export';
    case EmployeesViewSensitive = 'employees.view-sensitive';
    case EmployeesReset2fa = 'employees.reset-2fa';
    case EmployeesUnbindDevice = 'employees.unbind-device';
    case DivisionsManage = 'divisions.manage';
    case DegreeProgramsManage = 'degree-programs.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
