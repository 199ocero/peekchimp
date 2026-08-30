<?php

namespace App\Services\Analytics;

use InvalidArgumentException;

class AiProviderRegistry
{
    /**
     * @var array<string, array{
     *     label: string,
     *     default_model: string,
     *     model_docs_url: string,
     *     models: array<int, array{value: string, label: string, tier: string, description: string}>
     * }>
     */
    private const PROVIDERS = [
        'openai' => [
            'label' => 'OpenAI',
            'default_model' => 'gpt-5.6-terra',
            'model_docs_url' => 'https://developers.openai.com/api/docs/models',
            'models' => [
                [
                    'value' => 'gpt-5.6-luna',
                    'label' => 'GPT-5.6 Luna',
                    'tier' => 'Budget',
                    'description' => 'Fast and cost-efficient for simple analytics questions.',
                ],
                [
                    'value' => 'gpt-5.6-terra',
                    'label' => 'GPT-5.6 Terra',
                    'tier' => 'Balanced',
                    'description' => 'A strong balance of reasoning, speed, and cost.',
                ],
                [
                    'value' => 'gpt-5.6-sol',
                    'label' => 'GPT-5.6 Sol',
                    'tier' => 'Most capable',
                    'description' => 'Best for complex analysis and multi-step investigations.',
                ],
            ],
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'default_model' => 'claude-sonnet-5',
            'model_docs_url' => 'https://platform.claude.com/docs/en/models/overview',
            'models' => [
                [
                    'value' => 'claude-haiku-4-5-20251001',
                    'label' => 'Claude Haiku 4.5',
                    'tier' => 'Budget',
                    'description' => 'The quickest Claude option for straightforward questions.',
                ],
                [
                    'value' => 'claude-sonnet-5',
                    'label' => 'Claude Sonnet 5',
                    'tier' => 'Balanced',
                    'description' => 'A dependable choice for analysis and tool use.',
                ],
                [
                    'value' => 'claude-opus-5',
                    'label' => 'Claude Opus 5',
                    'tier' => 'Most capable',
                    'description' => 'Suited to the most demanding investigations.',
                ],
            ],
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'default_model' => 'gemini-3.6-flash',
            'model_docs_url' => 'https://ai.google.dev/gemini-api/docs/models',
            'models' => [
                [
                    'value' => 'gemini-3.5-flash-lite',
                    'label' => 'Gemini 3.5 Flash-Lite',
                    'tier' => 'Budget',
                    'description' => 'The lowest-cost choice for simple, high-volume questions.',
                ],
                [
                    'value' => 'gemini-3.6-flash',
                    'label' => 'Gemini 3.6 Flash',
                    'tier' => 'Balanced',
                    'description' => 'Fast responses with stronger reasoning and tool use.',
                ],
                [
                    'value' => 'gemini-3.1-pro-preview',
                    'label' => 'Gemini 3.1 Pro Preview',
                    'tier' => 'Most capable',
                    'description' => 'For complex analysis where quality matters more than cost.',
                ],
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'default_model' => 'deepseek-v4-flash',
            'model_docs_url' => 'https://api-docs.deepseek.com/quick_start/pricing',
            'models' => [
                [
                    'value' => 'deepseek-v4-flash',
                    'label' => 'DeepSeek V4 Flash',
                    'tier' => 'Budget',
                    'description' => 'A fast, economical model for everyday questions.',
                ],
                [
                    'value' => 'deepseek-v4-pro',
                    'label' => 'DeepSeek V4 Pro',
                    'tier' => 'Most capable',
                    'description' => 'Better suited to deeper analysis and multi-step work.',
                ],
            ],
        ],
    ];

    /** @return array<int, string> */
    public function providers(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * @return array<int, array{
     *     value: string,
     *     label: string,
     *     defaultModel: string,
     *     modelDocsUrl: string,
     *     models: array<int, array{value: string, label: string, tier: string, description: string}>
     * }>
     */
    public function catalog(): array
    {
        return collect(self::PROVIDERS)
            ->map(fn (array $provider, string $value): array => [
                'value' => $value,
                'label' => $provider['label'],
                'defaultModel' => $provider['default_model'],
                'modelDocsUrl' => $provider['model_docs_url'],
                'models' => $provider['models'],
            ])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function modelsFor(string $provider): array
    {
        return array_column($this->modelCatalogFor($provider), 'value');
    }

    /** @return array<int, array{value: string, label: string, tier: string, description: string}> */
    public function modelCatalogFor(string $provider): array
    {
        return self::PROVIDERS[$provider]['models'] ?? [];
    }

    public function defaultModelFor(string $provider): ?string
    {
        return self::PROVIDERS[$provider]['default_model'] ?? null;
    }

    public function isSupported(string $provider): bool
    {
        return in_array($provider, $this->providers(), true);
    }

    public function requiresApiKey(string $provider): bool
    {
        return $this->isSupported($provider);
    }

    /** @return array{driver: string, key: string, url?: string} */
    public function runtimeConfig(string $provider, string $apiKey, ?string $baseUrl = null): array
    {
        if (! $this->isSupported($provider)) {
            throw new InvalidArgumentException('Unsupported AI provider.');
        }

        $config = [
            'driver' => $provider,
            'key' => $apiKey,
        ];
        if ($baseUrl !== null && $baseUrl !== '') {
            $config['url'] = $baseUrl;
        }

        return $config;
    }
}
