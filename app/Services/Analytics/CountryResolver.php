<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;

class CountryResolver
{
    public function __construct(private readonly DbIpCountryLookup $databaseLookup) {}

    public function resolve(Request $request): ?string
    {
        return $this->resolveLocation($request)['country'];
    }

    /** @return array{country: string|null, latitude: float|null, longitude: float|null} */
    public function resolveLocation(Request $request): array
    {
        $location = $this->databaseLookup->findLocation((string) $request->ip());
        $headers = config('analytics.geolocation.country_headers', []);

        foreach (is_array($headers) ? $headers : [] as $header) {
            if (! is_string($header)) {
                continue;
            }

            $country = $this->normalize($request->header($header));

            if ($country !== null) {
                $location['country'] = $country;

                return $location;
            }
        }

        $location['country'] = $this->normalize($location['country']);

        return $location;
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
