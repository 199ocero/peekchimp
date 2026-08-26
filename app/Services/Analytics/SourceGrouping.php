<?php

namespace App\Services\Analytics;

use Illuminate\Support\Str;

class SourceGrouping
{
    /**
     * @return array{source: string, category: string}
     */
    public function classify(?string $referrerHost, ?string $utmSource, ?string $utmMedium = null): array
    {
        $source = Str::lower(trim((string) ($utmSource ?: $referrerHost)));
        $host = Str::lower(trim((string) $referrerHost));
        $medium = Str::lower(trim((string) $utmMedium));

        if ($source === '' && $host === '') {
            return ['source' => 'Direct', 'category' => 'direct'];
        }

        $known = [
            'google' => ['google.com', 'google.', 'googleusercontent.com'],
            'facebook' => ['facebook.com', 'fb.com', 'm.facebook.com'],
            'reddit' => ['reddit.com', 'redd.it'],
            'x/twitter' => ['twitter.com', 'x.com', 't.co', 'twitter', 'x'],
            'linkedin' => ['linkedin.com'],
            'chatgpt' => ['chatgpt.com', 'chat.openai.com'],
            'claude' => ['claude.ai'],
            'perplexity' => ['perplexity.ai'],
            'gemini' => ['gemini.google.com'],
            'copilot' => ['copilot.microsoft.com'],
        ];

        foreach ($known as $label => $needles) {
            if ($source === $label) {
                return ['source' => Str::title($label), 'category' => $this->category($label)];
            }
            foreach ($needles as $needle) {
                if ($this->matches($host, $source, $needle)) {
                    return ['source' => Str::title($label), 'category' => $this->category($label)];
                }
            }
        }

        if (in_array($medium, ['organic', 'organic-search'], true)) {
            return ['source' => $utmSource ?: 'Other search', 'category' => 'search'];
        }

        if (in_array($medium, ['social', 'social-network', 'social_media'], true)) {
            return ['source' => $utmSource ?: 'Other social', 'category' => 'social'];
        }

        return ['source' => $utmSource ?: ($referrerHost ?: 'Other'), 'category' => 'other'];
    }

    private function category(string $label): string
    {
        return $label === 'google'
            ? 'search'
            : (in_array($label, ['chatgpt', 'claude', 'perplexity', 'gemini', 'copilot'], true) ? 'ai' : 'social');
    }

    private function matches(string $host, string $source, string $needle): bool
    {
        $needle = Str::lower(trim($needle));

        if ($needle === '') {
            return false;
        }

        if (str_ends_with($needle, '.')) {
            return str_starts_with($host, $needle) || str_starts_with($source, $needle);
        }

        return $host === $needle
            || Str::endsWith($host, '.'.$needle)
            || $source === $needle
            || Str::endsWith($source, '.'.$needle);
    }
}
