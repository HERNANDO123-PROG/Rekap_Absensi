<?php

namespace App\Http\Requests;

use App\Enums\WorkCalendarType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkWorkCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['required', Rule::in(array_column(WorkCalendarType::cases(), 'value'))],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_working_day' => ['required', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ];
    }
}
