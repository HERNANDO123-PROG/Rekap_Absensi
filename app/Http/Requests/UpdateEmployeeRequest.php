<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $categoryRule = Rule::exists('work_schedule_categories', 'id')
            ->where(function ($query) use ($employee): void {
                $query->where('is_active', true);

                if ($employee instanceof Employee && $employee->category_id !== null) {
                    $query->orWhere('id', $employee->category_id);
                }
            });

        return [
            'unit_id' => ['sometimes', 'required', 'integer', 'exists:units,id'],
            'category_id' => ['sometimes', 'nullable', 'integer', $categoryRule],
            'work_schedule_profile_id' => ['sometimes', 'nullable', 'integer'],
            'nip' => ['nullable', 'string', 'max:255'],
            'nama_lengkap' => ['sometimes', 'nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = [
                '_token',
                '_method',
                'nama_lengkap',
                'position',
                'nip',
                'unit_id',
                'category_id',
                'work_schedule_profile_id',
            ];

            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Field ini tidak dapat diubah melalui pembaruan profil pegawai.');
            }

            if (array_intersect(array_keys($this->all()), [
                'nama_lengkap',
                'position',
                'nip',
                'unit_id',
                'category_id',
                'work_schedule_profile_id',
            ]) === []) {
                $validator->errors()->add('profile', 'Pilih data pegawai yang akan disimpan.');
            }
        });
    }
}
