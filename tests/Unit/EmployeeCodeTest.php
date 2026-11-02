<?php

use App\Models\Employee;

it('formats employee codes from the id', function (int $id, string $code) {
    expect(Employee::formatCode($id))->toBe($code);
})->with([
    [1, 'EMP-0001'],
    [42, 'EMP-0042'],
    [12345, 'EMP-12345'],
]);
