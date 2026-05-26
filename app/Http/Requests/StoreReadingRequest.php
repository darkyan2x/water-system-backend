<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        /*
         * Temporary backward compatibility:
         * old frontend/offline queue may still send "value".
         * New frontend should send "current_reading".
         */
        if (!$this->has('current_reading') && $this->has('value')) {
            $this->merge([
                'current_reading' => $this->input('value'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:users,id'],
            'date' => ['required', 'date'],

            // New clear field name
            'current_reading' => ['required', 'integer', 'min:0'],

            // Old field, allowed only for compatibility
            'value' => ['nullable', 'integer', 'min:0'],

            'status' => ['nullable', 'in:paid,unpaid'],
        ];
    }
}