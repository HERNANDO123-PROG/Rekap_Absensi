<?php

namespace App\Http\Requests;

use App\Enums\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'request_date' => ['required', 'date_format:Y-m-d'],
            'leave_type' => [
                'required',
                Rule::in([
                    LeaveType::Absent->value,
                    LeaveType::OfficialDuty->value,
                    LeaveType::TemporaryExit->value,
                ]),
            ],
        ];
    }
}
