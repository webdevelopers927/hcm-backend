<?php

namespace App\Http\Requests\Employees;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PATCH /employees/{id}, for drafts and real employees alike. Send only what changed: absent fields keep
 * their stored value. For a real employee the saved result must still be complete (EmployeeCompleteness).
 */
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->employeeRules($this->employee());
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->employeeAfterValidation($validator, $this->employee())];
    }

    private function employee(): Employee
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return $employee;
    }
}
