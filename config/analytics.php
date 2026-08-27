<?php

return [
    'retention_days' => (int) env('PEEKCHIMP_EVENT_RETENTION_DAYS', 90),
    'ingestion_max_bytes' => 65536,
    'ingestion_max_events' => 10,
    'session_timeout_minutes' => 30,

    'rollups' => [
        'enabled' => (bool) env('PEEKCHIMP_ANALYTICS_ROLLUPS', true),
        'closed_after_minutes' => 60,
        'cache_seconds' => 60,
    ],

    'change_detection' => [
        'minimum_combined_count' => 40,
        'minimum_count' => 10,
        'minimum_percentage' => 25.0,
        'minimum_dimension_percentage' => 30.0,
        'minimum_rate_denominator' => 50,
        'minimum_rate_point_change' => 5.0,
        'max_candidates' => 5,
    ],

    'ai' => [
        'enabled' => (bool) env('PEEKCHIMP_AI_ENABLED', true),
        'providers' => ['openai', 'anthropic', 'gemini', 'openrouter', 'deepseek', 'ollama', 'openai-compatible'],
        'max_payload_bytes' => 12288,
        'max_candidates' => 5,
        'cooldown_hours' => 6,
        'request_timeout_seconds' => 120,
        'stale_after_seconds' => 180,
    ],

    'geolocation' => [
        'database_path' => env(
            'PEEKCHIMP_GEOIP_DATABASE_PATH',
            storage_path('app/private/geoip/dbip-country-lite.mmdb'),
        ),
        'database_url' => env('PEEKCHIMP_GEOIP_DATABASE_URL'),
        'country_headers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'PEEKCHIMP_COUNTRY_HEADERS',
                'CF-IPCountry,X-Vercel-IP-Country,CloudFront-Viewer-Country,Eo-IpCountry',
            )),
        ))),
    ],

    'ai_referrals' => [
        'chatgpt' => [
            'label' => 'ChatGPT',
            'hosts' => ['chatgpt.com', 'chat.openai.com'],
            'utm_sources' => ['chatgpt.com', 'chatgpt'],
        ],
        'claude' => [
            'label' => 'Claude',
            'hosts' => ['claude.ai'],
            'utm_sources' => ['claude.ai', 'claude'],
        ],
        'perplexity' => [
            'label' => 'Perplexity',
            'hosts' => ['perplexity.ai'],
            'utm_sources' => ['perplexity.ai', 'perplexity'],
        ],
        'gemini' => [
            'label' => 'Gemini',
            'hosts' => ['gemini.google.com'],
            'utm_sources' => ['gemini.google.com', 'gemini'],
        ],
        'copilot' => [
            'label' => 'Copilot',
            'hosts' => ['copilot.microsoft.com'],
            'utm_sources' => ['copilot.microsoft.com', 'copilot'],
        ],
    ],
];
