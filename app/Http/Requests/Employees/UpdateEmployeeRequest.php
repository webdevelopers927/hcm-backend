<?php

namespace App\Http\Requests\Employees;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    use EmployeeRules;

    public function authorize(): bool
    {
        return true; // Policy check happens in the controller.
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseEmployeeInput();
    }

    /**
     * Same rules as create, but every field is optional (PATCH). Fields that are required on
     * create stay non-empty when sent.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return array_map(
            fn (array $fieldRules) => ['sometimes', ...$fieldRules],
            $this->employeeRules($employee),
        );
    }
}
