<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Analytics\AiProviderRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $provider = $user?->workspaceOwnerUser()->workspaceAiSetting()->value('provider');
        $models = is_string($provider)
            ? app(AiProviderRegistry::class)->modelsFor($provider)
            : [];

        return [
            'message' => ['nullable', 'string', 'max:12000'],
            'conversation_id' => ['nullable', 'uuid', 'required_with:decisions'],
            'decisions' => ['nullable', 'array', 'min:1'],
            'decisions.*.action' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'model' => ['nullable', 'string', Rule::in($models)],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasMessage = filled($this->input('message'));
            $hasDecisions = is_array($this->input('decisions')) && $this->input('decisions') !== [];

            if (! $hasMessage && ! $hasDecisions) {
                $validator->errors()->add('message', 'Enter a message or respond to a pending setup approval.');

                return;
            }

            if ($hasMessage && $hasDecisions) {
                $validator->errors()->add('message', 'Send either a message or setup approval decisions, not both.');
            }
        });
    }
}
