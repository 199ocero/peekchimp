<?php

namespace App\Services\Analytics;

use App\Models\Project;
use Carbon\CarbonImmutable;

class SessionIdentifier
{
    public function make(Project $project, string $visitorId, ?string $clientNonce, string $eventId, CarbonImmutable $at): string
    {
        $nonce = trim((string) $clientNonce);

        if ($nonce === '') {
            $nonce = $eventId;
        }

        $window = (int) max(60, (int) config('analytics.session_timeout_minutes', 30) * 60);

        return hash_hmac(
            'sha256',
            implode('|', [
                $project->getKey(),
                $visitorId,
                substr($nonce, 0, 128),
                intdiv((int) $at->timestamp, $window),
            ]),
            (string) config('app.key'),
        );
    }
}
