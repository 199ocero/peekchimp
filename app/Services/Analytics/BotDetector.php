<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;

class BotDetector
{
    /**
     * @return string|null A stable reason that is safe to use in metrics.
     */
    public function reason(Request $request, string $platform): ?string
    {
        $userAgent = strtolower(trim((string) $request->userAgent()));

        if ($platform !== 'web') {
            return null;
        }

        if ($userAgent === '') {
            return 'missing_user_agent';
        }

        $patterns = [
            'bot', 'crawler', 'spider', 'slurp', 'headless', 'phantom', 'selenium',
            'playwright', 'puppeteer', 'lighthouse', 'pingdom', 'uptimerobot',
            'monitoring', 'facebookexternalhit', 'twitterbot', 'linkedinbot',
            'ahrefs', 'semrush', 'mj12bot', 'dotbot', 'petalbot', 'bytespider',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($userAgent, $pattern)) {
                return 'known_crawler_or_automation';
            }
        }

        if ($request->header('Sec-Fetch-Site') === null && $request->header('Accept-Language') === null) {
            return 'suspicious_browser_headers';
        }

        return null;
    }
}
