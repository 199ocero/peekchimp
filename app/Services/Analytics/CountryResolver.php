<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;

class CountryResolver
{
    public function __construct(private readonly DbIpCountryLookup $databaseLookup) {}

    public function resolve(Request $request): ?string
    {
        $headers = config('analytics.geolocation.country_headers', []);

        foreach (is_array($headers) ? $headers : [] as $header) {
            if (! is_string($header)) {
                continue;
            }

            $country = $this->normalize($request->header($header));

            if ($country !== null) {
                return $country;
            }
        }

        return $this->normalize($this->databaseLookup->find((string) $request->ip()));
    }

    private function normalize(mixed $country): ?string
    {
        if (! is_string($country)) {
            return null;
        }

        $country = strtoupper(trim($country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 && $country !== 'XX'
            ? $country
            : null;
    }
}
