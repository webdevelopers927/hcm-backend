<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Checked per id in BulkDeleteEmployees.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_map('intval', array_values((array) $this->validated('ids')));
    }
}
