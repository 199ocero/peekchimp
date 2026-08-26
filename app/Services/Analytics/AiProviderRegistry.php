<?php

namespace App\Services\Analytics;

use InvalidArgumentException;

class AiProviderRegistry
{
    /** @return array<int, string> */
    public function providers(): array
    {
        return [
            'openai',
            'anthropic',
            'gemini',
            'openrouter',
            'deepseek',
            'ollama',
            'openai-compatible',
        ];
    }

    public function isSupported(string $provider): bool
    {
        return in_array($provider, $this->providers(), true);
    }

    public function requiresApiKey(string $provider): bool
    {
        return $provider !== 'ollama';
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
