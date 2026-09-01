<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $this->user() !== null
            && $project instanceof Project
            && $this->user()->can('manage', $project);
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
            'timezone' => ['required', 'timezone:all'],
            'autocapture_enabled' => ['sometimes', 'boolean'],
            'growth_context' => ['sometimes', 'array'],
            'growth_context.audience' => ['nullable', 'string', 'max:2000'],
            'growth_context.products_services' => ['nullable', 'string', 'max:3000'],
            'growth_context.value_proposition' => ['nullable', 'string', 'max:2000'],
            'growth_context.brand_voice' => ['nullable', 'string', 'max:1000'],
            'growth_context.primary_conversion_goals' => ['nullable', 'array', 'max:10'],
            'growth_context.primary_conversion_goals.*' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array{name: string, timezone: string, autocapture_enabled?: bool, growth_context?: array{audience: string, products_services: string, value_proposition: string, brand_voice: string, primary_conversion_goals: array<int, string>}}
     */
    public function website(): array
    {
        $website = [
            'name' => $this->string('name')->toString(),
            'timezone' => $this->string('timezone')->toString(),
        ];

        if ($this->has('autocapture_enabled')) {
            $website['autocapture_enabled'] = $this->boolean('autocapture_enabled');
        }

        if ($this->has('growth_context')) {
            $conversionGoals = $this->input('growth_context.primary_conversion_goals', []);
            if (! is_array($conversionGoals)) {
                $conversionGoals = [];
            }

            $website['growth_context'] = [
                'audience' => $this->string('growth_context.audience')->trim()->toString(),
                'products_services' => $this->string('growth_context.products_services')->trim()->toString(),
                'value_proposition' => $this->string('growth_context.value_proposition')->trim()->toString(),
                'brand_voice' => $this->string('growth_context.brand_voice')->trim()->toString(),
                'primary_conversion_goals' => collect($conversionGoals)
                    ->filter(fn (mixed $goal): bool => is_string($goal) && trim($goal) !== '')
                    ->map(fn (string $goal): string => trim($goal))
                    ->values()
                    ->all(),
            ];
        }

        return $website;
    }
}
