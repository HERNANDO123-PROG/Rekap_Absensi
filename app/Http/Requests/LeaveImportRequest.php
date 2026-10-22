<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'file' => ['required', 'file', 'mimes:xls,xlsx,csv,txt', 'extensions:xls,xlsx,csv', 'max:20480'],
        ];
    }
}
