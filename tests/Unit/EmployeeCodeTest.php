<?php

use App\Enums\EmployeeStatus;
use App\Models\Employee;

it('formats employee codes from the id', function (int $id, string $code) {
    expect(Employee::formatCode($id))->toBe($code);
})->with([
    [1, 'EMP-0001'],
    [42, 'EMP-0042'],
    [12345, 'EMP-12345'],
]);

it('exposes the status values in the agreed order', function () {
    expect(EmployeeStatus::values())->toBe(['active', 'probation', 'on_leave', 'notice_period', 'inactive']);
});
