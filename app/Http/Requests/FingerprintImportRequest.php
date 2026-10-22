<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FingerprintImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'extensions:xls,xlsx', 'max:20480'],
        ];
    }
}
