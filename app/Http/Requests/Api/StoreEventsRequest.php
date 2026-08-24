<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site' => ['required', 'string', 'max:64'],
            'events' => ['required', 'array', 'min:1', 'max:10'],
            'events.*' => ['required', 'array'],
            'events.*.event_id' => ['nullable', 'uuid'],
            'events.*.event_name' => ['required', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_.:-]{0,99}$/'],
            'events.*.platform' => ['nullable', 'string', 'in:web,ios,android,react-native,flutter'],
            'events.*.session_id' => ['nullable', 'string', 'max:128'],
            'events.*.path' => ['nullable', 'string', 'max:2048'],
            'events.*.referrer' => ['nullable', 'string', 'max:2048'],
            'events.*.utm_source' => ['nullable', 'string', 'max:120'],
            'events.*.utm_medium' => ['nullable', 'string', 'max:120'],
            'events.*.utm_campaign' => ['nullable', 'string', 'max:160'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.properties' => ['nullable', 'array', 'max:20'],
        ];
    }
}
