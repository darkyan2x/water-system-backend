<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReadingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $role = strtolower((string) $this->user()?->role);

        return in_array($role, ['admin', 'master', 'reader'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id'   => 'required|exists:users,id',
            'date' => ['required', 'date'],
            'value' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', 'in:paid,unpaid'],
        ];
    }
}
