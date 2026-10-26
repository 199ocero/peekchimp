<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestroyWebsiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $this->user() !== null
            && $project instanceof Project
            && $this->user()->can('delete', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in([$this->confirmationPhrase()])],
        ];
    }

    /**
     * The website's domain, or its name when no domain has been added yet.
     */
    public function confirmationPhrase(): string
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $project->domains()->value('domain') ?? $project->name;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.in' => 'Type '.$this->confirmationPhrase().' exactly to confirm.',
        ];
    }
}
