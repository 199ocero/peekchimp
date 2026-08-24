<?php

return [
    'retention_days' => (int) env('PEEKCHIMP_EVENT_RETENTION_DAYS', 90),
    'ingestion_max_bytes' => 65536,
    'ingestion_max_events' => 10,
    'session_timeout_minutes' => 30,

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
