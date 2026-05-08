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
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please upload a CSV, XLS, or XLSX file.',
            'file.mimes' => 'Only .xlsx, .xls, and .csv files are supported.',
            'file.max' => 'Employee imports must be 10 MB or smaller.',
        ];
    }
}
