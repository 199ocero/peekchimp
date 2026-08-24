<?php

use App\Services\Analytics\AiReferralClassifier;
use Tests\TestCase;

uses(TestCase::class);

test('it classifies supported AI referral hosts', function (string $host, string $provider) {
    expect((new AiReferralClassifier)->classify($host, null))->toBe($provider);
})->with([
    'ChatGPT' => ['chatgpt.com', 'chatgpt'],
    'legacy ChatGPT' => ['chat.openai.com', 'chatgpt'],
    'Claude subdomain' => ['www.claude.ai', 'claude'],
    'Perplexity' => ['perplexity.ai', 'perplexity'],
    'Gemini' => ['gemini.google.com', 'gemini'],
    'Copilot' => ['copilot.microsoft.com', 'copilot'],
]);

test('it classifies supported UTM sources when the referrer is missing', function (string $source, string $provider) {
    expect((new AiReferralClassifier)->classify(null, $source))->toBe($provider);
})->with([
    'ChatGPT' => ['CHATGPT.COM', 'chatgpt'],
    'Claude' => ['claude', 'claude'],
    'Perplexity' => ['perplexity.ai', 'perplexity'],
    'Gemini' => ['gemini', 'gemini'],
    'Copilot' => ['copilot.microsoft.com', 'copilot'],
]);

test('an explicit AI UTM source takes precedence over a different referrer', function () {
    expect((new AiReferralClassifier)->classify('chatgpt.com', 'perplexity'))->toBe('perplexity');
});

test('it leaves ambiguous or unknown traffic unclassified', function (?string $host, ?string $source) {
    expect((new AiReferralClassifier)->classify($host, $source))->toBeNull();
})->with([
    'search engine' => ['www.google.com', null],
    'Bing' => ['www.bing.com', null],
    'unknown host' => ['answers.example.test', null],
    'unknown source' => [null, 'social'],
]);
