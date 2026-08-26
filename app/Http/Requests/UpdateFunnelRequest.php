<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateFunnelRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['sometimes', 'array', 'min:2', 'max:5'],
            'steps.*' => ['required', 'array'],
            'steps.*.name' => ['required', 'string', 'max:120'],
            'steps.*.type' => ['required', 'string', Rule::in(['event', 'url'])],
            'steps.*.event_name' => ['nullable', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_.:-]{0,99}$/'],
            'steps.*.path' => ['nullable', 'string', 'max:2048'],
            'steps.*.path_operator' => ['nullable', 'string', Rule::in(['exact', 'prefix'])],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('steps')) {
                return;
            }

            foreach ((array) $this->input('steps', []) as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $type = (string) ($step['type'] ?? '');
                if ($type === 'event' && blank($step['event_name'] ?? null)) {
                    $validator->errors()->add("steps.{$index}.event_name", 'An event name is required for event steps.');
                }
                if ($type === 'url' && blank($step['path'] ?? null)) {
                    $validator->errors()->add("steps.{$index}.path", 'A path is required for URL steps.');
                }
            }
        });
    }
}
