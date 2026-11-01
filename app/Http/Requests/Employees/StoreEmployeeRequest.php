<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
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
        return $this->employeeRules(null);
    }
}
