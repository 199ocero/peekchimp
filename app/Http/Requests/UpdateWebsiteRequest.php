<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $this->user() !== null
            && $project instanceof Project
            && $this->user()->can('update', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'timezone' => ['required', 'timezone:all'],
        ];
    }

    /**
     * @return array{name: string, url: string, timezone: string}
     */
    public function website(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'url' => $this->string('url')->toString(),
            'timezone' => $this->string('timezone')->toString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.url' => 'Enter the full website URL, including https:// or http://.',
            'timezone.timezone' => 'Choose a valid timezone for your reports.',
        ];
    }
}
