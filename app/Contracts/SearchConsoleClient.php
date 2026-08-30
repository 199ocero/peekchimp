<?php

namespace App\Contracts;

use App\Models\SearchConsoleConnection;
use Carbon\CarbonImmutable;

interface SearchConsoleClient
{
    public function authorizationUrl(string $state): string;

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function exchangeAuthorizationCode(string $code): array;

    /** @return array<int, array{siteUrl: string, permissionLevel: string}> */
    public function listSites(string $accessToken): array;

    public function latestDataDate(SearchConsoleConnection $connection): ?CarbonImmutable;

    /**
     * @param  array<int, string>  $dimensions
     * @return array<int, array{keys?: array<int, string>, clicks: float|int, impressions: float|int, ctr?: float|int, position?: float|int}>
     */
    public function query(SearchConsoleConnection $connection, CarbonImmutable $date, array $dimensions = []): array;

    public function revoke(SearchConsoleConnection $connection): void;
}
