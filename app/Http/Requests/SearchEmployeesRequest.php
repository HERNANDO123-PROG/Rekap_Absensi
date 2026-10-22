<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'search' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
