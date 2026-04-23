<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImportEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please upload a CSV or XLSX file.',
            'file.mimes' => 'Only .xlsx and .csv files are supported.',
        ];
    }
}
