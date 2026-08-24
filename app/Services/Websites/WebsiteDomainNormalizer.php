<?php

namespace App\Services\Websites;

use Illuminate\Support\Str;
use Illuminate\Support\Uri;

class WebsiteDomainNormalizer
{
    public function normalize(string $url): string
    {
        return Str::of((string) Uri::of($url)->host())
            ->trim()
            ->lower()
            ->rtrim('.')
            ->toString();
    }
}
