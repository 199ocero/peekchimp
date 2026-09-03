<?php

namespace App\Http\Requests;

use App\Models\Goal;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateGoalRequest extends FormRequest
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
        $project = $this->route('project');
        $goal = $this->route('goal');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('goals')->where('project_id', $project instanceof Project ? $project->getKey() : 0)->ignore($goal instanceof Goal ? $goal->getKey() : null),
            ],
            'type' => ['sometimes', 'required', 'string', Rule::in(['event', 'url'])],
            'event_name' => ['nullable', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_.:-]{0,99}$/'],
            'path' => ['nullable', 'string', 'max:2048'],
            'path_operator' => ['nullable', 'string', Rule::in(['exact', 'prefix'])],
            'property_match' => ['nullable', 'array', 'max:5'],
            'property_match.*' => ['nullable'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $goal = $this->route('goal');
            if (! $goal instanceof Goal || ! $this->has('type')) {
                return;
            }

            $type = (string) $this->input('type');
            if ($type === 'event' && ($this->has('event_name') || $goal->type !== 'event') && blank($this->input('event_name', $goal->event_name))) {
                $validator->errors()->add('event_name', 'An event name is required for event goals.');
            }
            if ($type === 'url' && ($this->has('path') || $goal->type !== 'url') && blank($this->input('path', $goal->path))) {
                $validator->errors()->add('path', 'A path is required for URL goals.');
            }
        });
    }
}
