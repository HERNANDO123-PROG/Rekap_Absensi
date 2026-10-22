<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $active = $this->input('status') === EmployeeStatus::Active->value;

        return [
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('work_schedule_categories', 'id')->where('is_active', true),
            ],
            'work_schedule_profile_id' => ['nullable', 'integer'],
            'fingerprint_id' => [
                'nullable', 'string', 'max:64',
                ...($active ? [Rule::unique('employees', 'fingerprint_id')
                    ->where('unit_id', $this->input('unit_id'))
                    ->where('status', EmployeeStatus::Active->value)] : []),
            ],
            'nip' => ['nullable', 'string', 'max:255'],
            'namafinger' => ['nullable', 'string', 'max:255'],
            'nama_lengkap' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_column(EmployeeStatus::cases(), 'value'))],
        ];
    }
}
