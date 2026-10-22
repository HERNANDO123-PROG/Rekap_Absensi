<?php

namespace App\Http\Requests;

use App\Models\WorkScheduleCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkScheduleCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('work_schedule_categories', 'name')
                    ->ignore($category instanceof WorkScheduleCategory ? $category->id : null),
            ],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'work_schedule_profile_id' => ['nullable', 'integer', 'exists:work_schedule_profiles,id'],
        ];
    }
}