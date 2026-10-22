<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'is_working_day' => ['required', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ];
    }
}
