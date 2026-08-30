<?php

namespace App\Services\Websites;

use Illuminate\Support\Str;

class WebsiteUrlNormalizer
{
    public function absolute(string $baseUrl, string $reference): ?string
    {
        $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5));

        if ($reference === '' || Str::startsWith($reference, ['#', 'mailto:', 'tel:', 'javascript:', 'data:'])) {
            return null;
        }

        if (Str::startsWith($reference, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $this->canonicalize($scheme.':'.$reference);
        }

        if (filter_var($reference, FILTER_VALIDATE_URL)) {
            return $this->canonicalize($reference);
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $authority = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (Str::startsWith($reference, '/')) {
            return $this->canonicalize($authority.$reference);
        }

        $basePath = $parts['path'] ?? '/';
        $directory = Str::endsWith($basePath, '/') ? $basePath : dirname($basePath).'/';

        return $this->canonicalize($authority.$directory.$reference);
    }

    public function canonicalize(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = Str::lower((string) $parts['scheme']);
        $host = Str::lower(rtrim((string) $parts['host'], '.'));
        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $key) {
            $normalizedKey = Str::lower((string) $key);
            if (Str::startsWith($normalizedKey, 'utm_') || in_array($normalizedKey, ['gclid', 'fbclid'], true)) {
                unset($query[$key]);
            }
        }
        ksort($query);

        return $scheme.'://'.$host
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path
            .($query === [] ? '' : '?'.http_build_query($query));
    }

    public function normalizePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : '/';
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        $normalized = '/'.implode('/', $segments);

        return $normalized !== '/' ? rtrim($normalized, '/') : '/';
    }
}
