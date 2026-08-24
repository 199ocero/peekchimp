<?php

namespace App\Http\Requests;

use App\Enums\PublicDashboardSection;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebsiteSharingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $this->user() !== null
            && $project instanceof Project
            && $this->user()->can('share', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', 'distinct', Rule::enum(PublicDashboardSection::class)],
        ];
    }

    /**
     * @return array{enabled: bool, sections: array<int, string>}
     */
    public function sharing(): array
    {
        return [
            'enabled' => $this->boolean('enabled'),
            'sections' => array_values($this->input('sections', [])),
        ];
    }
}
