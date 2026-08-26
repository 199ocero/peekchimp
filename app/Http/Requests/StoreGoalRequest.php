<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreGoalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && Gate::allows('manage', $project);
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
            'type' => ['required', 'string', Rule::in(['event', 'url'])],
            'event_name' => ['nullable', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_.:-]{0,99}$/', Rule::requiredIf(fn (): bool => $this->input('type') === 'event')],
            'path' => ['nullable', 'string', 'max:2048', Rule::requiredIf(fn (): bool => $this->input('type') === 'url')],
            'path_operator' => ['nullable', 'string', Rule::in(['exact', 'prefix'])],
            'property_match' => ['nullable', 'array', 'max:5'],
            'property_match.*' => ['nullable'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
