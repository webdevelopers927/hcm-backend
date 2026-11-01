<?php

namespace App\Http\Requests\Employees;

class ExportEmployeesRequest extends ListEmployeesRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'ids' => ['sometimes', 'array', 'max:5000'],
            'ids.*' => ['integer'],
            'format' => ['required', 'in:csv,xlsx'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return collect($this->validated())->except(['page', 'per_page', 'format'])->all();
    }
}
