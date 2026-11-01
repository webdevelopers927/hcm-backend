<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class ImportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy check happens in the controller.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Extension, not MIME: CSV is often detected as text/plain and XLSX as application/zip.
            // The reader rejects files it cannot parse.
            'file' => ['required', 'file', 'extensions:csv,xlsx', 'max:5120'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    public function extension(): string
    {
        return strtolower((string) $this->file('file')?->getClientOriginalExtension());
    }
}
