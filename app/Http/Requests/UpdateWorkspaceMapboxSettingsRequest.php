<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceMapboxSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user?->is_admin === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mapbox_public_token' => ['required', 'string', 'max:2048', 'starts_with:pk.'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mapbox_public_token.starts_with' => 'Use a Mapbox public token beginning with pk. Secret tokens cannot be used in the browser.',
        ];
    }
}
