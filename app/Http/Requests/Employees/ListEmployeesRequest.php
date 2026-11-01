<?php

namespace App\Http\Requests\Employees;

use App\Actions\Employees\BuildEmployeeQuery;
use App\Enums\EmployeeStatus;
use App\Enums\WorkMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEmployeesRequest extends FormRequest
{
    /** Array filters; a scalar (?status=active) is accepted and wrapped. */
    protected const ARRAY_FILTERS = [
        'status', 'department_id', 'designation_id', 'division_id', 'campaign_id', 'shift_id', 'location_id', 'work_mode',
    ];

    public function authorize(): bool
    {
        return true; // Policy check happens in the controller.
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];
        foreach (static::ARRAY_FILTERS as $key) {
            if ($this->has($key) && ! is_array($this->input($key))) {
                $normalised[$key] = array_filter(explode(',', (string) $this->input($key)), fn ($v) => $v !== '');
            }
        }
        $this->merge($normalised);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::in(EmployeeStatus::values())],
            'work_mode' => ['sometimes', 'array'],
            'work_mode.*' => [Rule::in(WorkMode::values())],
            'department_id' => ['sometimes', 'array'],
            'department_id.*' => ['integer'],
            'designation_id' => ['sometimes', 'array'],
            'designation_id.*' => ['integer'],
            'division_id' => ['sometimes', 'array'],
            'division_id.*' => ['integer'],
            'campaign_id' => ['sometimes', 'array'],
            'campaign_id.*' => ['integer'],
            'shift_id' => ['sometimes', 'array'],
            'shift_id.*' => ['integer'],
            'location_id' => ['sometimes', 'array'],
            'location_id.*' => ['integer'],
            'sort' => ['nullable', Rule::in(BuildEmployeeQuery::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return collect($this->validated())->except(['page', 'per_page'])->all();
    }

    /** Values above the cap are clamped rather than rejected. */
    public function perPage(): int
    {
        $max = (int) config('hcm.employees.per_page_max');

        return min($max, (int) ($this->validated('per_page') ?? config('hcm.employees.per_page_default')));
    }
}
