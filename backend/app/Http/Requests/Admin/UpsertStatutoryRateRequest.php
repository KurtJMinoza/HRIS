<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertStatutoryRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'employer_rate' => ['required', 'numeric', 'min:0'],
            'employee_rate' => ['required', 'numeric', 'min:0'],
            'min_salary' => ['nullable', 'numeric', 'min:0'],
            'max_salary' => ['nullable', 'numeric', 'min:0'],
            'msc' => ['nullable', 'numeric', 'min:0'],
            'salary_floor' => ['nullable', 'numeric', 'min:0'],
            'salary_ceiling' => ['nullable', 'numeric', 'min:0'],
            'tier_threshold' => ['nullable', 'numeric', 'min:0'],
            'monthly_cap' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'is_active' => ['nullable', 'boolean'],
            'brackets' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'compliance_reference' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $code = strtoupper((string) $this->route('code'));
            $er = (float) $this->input('employer_rate', 0);
            $ee = (float) $this->input('employee_rate', 0);

            if ($code === 'SSS' && (abs($ee - 0.05) > 0.0001 || abs($er - 0.10) > 0.0001)) {
                $validator->errors()->add('employee_rate', 'SSS rates must be 5% employee and 10% employer (RA 11199).');
            }
            if ($code === 'PHILHEALTH' && (abs($ee - 0.025) > 0.0001 || abs($er - 0.025) > 0.0001)) {
                $validator->errors()->add('employee_rate', 'PhilHealth rates must be 2.5% employee and 2.5% employer (RA 11223).');
            }
            if ($code === 'PAGIBIG' && (abs($er - 0.02) > 0.0001 || $ee < 0.01 || $ee > 0.02)) {
                $validator->errors()->add('employee_rate', 'Pag-IBIG must use employee 1%-2% and employer 2% (RA 9679).');
            }

            $floor = $this->input('salary_floor');
            $ceiling = $this->input('salary_ceiling');
            if ($floor !== null && $ceiling !== null && (float) $ceiling < (float) $floor) {
                $validator->errors()->add('salary_ceiling', 'Salary ceiling must be greater than or equal to salary floor.');
            }
        });
    }
}
