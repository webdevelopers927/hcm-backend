<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /employees (complete hire) and POST /employees/drafts. Same field rules; the controller decides
 * whether completeness is enforced.
 */
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

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->employeeAfterValidation($validator, null)];
    }
}
