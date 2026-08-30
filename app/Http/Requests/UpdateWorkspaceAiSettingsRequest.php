<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Analytics\AiProviderRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceAiSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user?->workspaceOwnerUser()->is_admin === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $providers = app(AiProviderRegistry::class);

        return [
            'provider' => ['required', 'string', Rule::in($providers->providers())],
            'model' => ['required', 'string', 'max:120', Rule::in($providers->modelsFor($this->string('provider')->toString()))],
            'api_key' => ['nullable', 'string', 'max:500'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
