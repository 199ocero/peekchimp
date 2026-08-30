<?php

namespace App\Services\Websites;

use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

class WebsitePageFetcher
{
    public function __construct(
        private readonly WebsiteUrlGuard $guard,
        private readonly WebsiteUrlNormalizer $normalizer,
    ) {}

    /**
     * @return array{url: string, final_url: string, status: int|null, content_type: string|null, body: string, response_time_ms: int|null, response_bytes: int, redirect_chain: array<int, array{url: string, status: int}>, error: string|null}
     */
    public function fetch(Project $project, string $url, string $accept = 'text/html, application/xhtml+xml'): array
    {
        $requestedUrl = $this->normalizer->canonicalize($url) ?? $url;
        $currentUrl = $requestedUrl;
        $redirects = [];
        $startedAt = microtime(true);

        for ($redirect = 0; $redirect <= (int) config('analytics.website_crawl.max_redirects', 5); $redirect++) {
            if (! $this->guard->allows($project, $currentUrl)) {
                return $this->failure($requestedUrl, $currentUrl, $redirects, 'The URL is outside the verified public website.');
            }

            try {
                $response = Http::accept($accept)
                    ->withUserAgent('PeekchimpBot/1.0 (+https://peekchimp.com)')
                    ->connectTimeout((int) config('analytics.website_crawl.connect_timeout_seconds', 3))
                    ->timeout((int) config('analytics.website_crawl.request_timeout_seconds', 10))
                    ->withOptions(['stream' => true])
                    ->withoutRedirecting()
                    ->get($currentUrl);
            } catch (ConnectionException) {
                return $this->failure($requestedUrl, $currentUrl, $redirects, 'The page could not be reached.');
            }

            $status = $response->status();
            $location = $response->header('Location');
            if ($status >= 300 && $status < 400 && $location !== '') {
                $redirects[] = ['url' => $currentUrl, 'status' => $status];
                $nextUrl = $this->normalizer->absolute($currentUrl, $location);
                if ($nextUrl === null) {
                    return $this->failure($requestedUrl, $currentUrl, $redirects, 'The page returned an invalid redirect.');
                }
                $currentUrl = $nextUrl;

                continue;
            }

            $maximumBytes = (int) config('analytics.website_crawl.max_response_bytes', 2097152);
            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = $this->readBody($stream, $maximumBytes);
            $bytes = strlen($body);
            if ($bytes > $maximumBytes) {
                return [
                    'url' => $requestedUrl,
                    'final_url' => $currentUrl,
                    'status' => $status,
                    'content_type' => $response->header('Content-Type'),
                    'body' => '',
                    'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'response_bytes' => $bytes,
                    'redirect_chain' => $redirects,
                    'error' => 'The response exceeded the crawl size limit.',
                ];
            }

            return [
                'url' => $requestedUrl,
                'final_url' => $currentUrl,
                'status' => $status,
                'content_type' => $response->header('Content-Type'),
                'body' => $body,
                'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response_bytes' => $bytes,
                'redirect_chain' => $redirects,
                'error' => null,
            ];
        }

        return $this->failure($requestedUrl, $currentUrl, $redirects, 'The page exceeded the redirect limit.');
    }

    private function readBody(StreamInterface $stream, int $maximumBytes): string
    {
        $body = '';
        $maximumReadBytes = $maximumBytes + 1;

        while (! $stream->eof() && strlen($body) < $maximumReadBytes) {
            $remainingBytes = $maximumReadBytes - strlen($body);
            $chunk = $stream->read(min(8192, $remainingBytes));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return $body;
    }

    /**
     * @param  array<int, array{url: string, status: int}>  $redirects
     * @return array{url: string, final_url: string, status: null, content_type: null, body: string, response_time_ms: null, response_bytes: int, redirect_chain: array<int, array{url: string, status: int}>, error: string}
     */
    private function failure(string $url, string $finalUrl, array $redirects, string $error): array
    {
        return [
            'url' => $url,
            'final_url' => $finalUrl,
            'status' => null,
            'content_type' => null,
            'body' => '',
            'response_time_ms' => null,
            'response_bytes' => 0,
            'redirect_chain' => $redirects,
            'error' => $error,
        ];
    }
}
