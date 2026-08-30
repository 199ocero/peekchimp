<?php

namespace App\Services\Websites;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;

class WebsiteHtmlExtractor
{
    public function __construct(private readonly WebsiteUrlNormalizer $normalizer) {}

    /** @return array<string, mixed> */
    public function extract(string $html, string $url): array
    {
        if ($html === '' || ! class_exists(DOMDocument::class)) {
            return $this->emptyResult();
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $this->emptyResult();
        }

        $xpath = new DOMXPath($document);
        $title = $this->firstText($xpath, '//title');
        $description = $this->metaContent($xpath, 'description');
        $robots = $this->robots($xpath);
        $canonical = $this->attribute($xpath, '//link[contains(concat(" ", translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]', 'href');
        $canonical = $canonical === null ? null : $this->normalizer->absolute($url, $canonical);
        $headings = [];

        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
            if ($heading instanceof DOMElement) {
                $headings[] = [
                    'level' => (int) substr(Str::lower($heading->tagName), 1),
                    'text' => Str::limit(Str::squish($heading->textContent), 500, ''),
                ];
            }
            if (count($headings) >= 100) {
                break;
            }
        }

        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $links = [];
        $ctas = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $absolute = $this->normalizer->absolute($url, $link->getAttribute('href'));
            if ($absolute === null) {
                continue;
            }
            $text = Str::limit(Str::squish($link->textContent), 300, '');
            $internal = Str::lower((string) parse_url($absolute, PHP_URL_HOST)) === $host;
            $links[] = [
                'url' => $absolute,
                'path' => $internal ? $this->normalizer->normalizePath($absolute) : null,
                'text' => $text,
                'internal' => $internal,
                'nofollow' => Str::contains(Str::lower($link->getAttribute('rel')), 'nofollow'),
            ];
            if ($this->isCta($link, $text)) {
                $ctas[] = ['type' => 'link', 'text' => $text, 'url' => $absolute];
            }
            if (count($links) >= 200) {
                break;
            }
        }

        foreach ($xpath->query('//button') ?: [] as $button) {
            if ($button instanceof DOMElement) {
                $text = Str::limit(Str::squish($button->textContent), 300, '');
                if ($text !== '') {
                    $ctas[] = ['type' => 'button', 'text' => $text, 'url' => null];
                }
            }
            if (count($ctas) >= 30) {
                break;
            }
        }

        $structuredData = $this->structuredData($xpath);
        foreach (['script', 'style', 'noscript', 'template', 'svg', 'form', 'input', 'select', 'textarea'] as $tag) {
            $nodes = [];
            foreach ($document->getElementsByTagName($tag) as $node) {
                $nodes[] = $node;
            }
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $contentNode = $this->firstNode($xpath, '//main')
            ?? $this->firstNode($xpath, '//article')
            ?? $this->firstNode($xpath, '//body');
        $mainContent = $contentNode instanceof DOMNode ? Str::squish($contentNode->textContent) : '';
        $mainContent = Str::limit($mainContent, (int) config('analytics.website_crawl.max_extracted_characters', 50000), '');
        $words = preg_split('/\s+/u', trim($mainContent), -1, PREG_SPLIT_NO_EMPTY);

        return [
            'title' => $title,
            'meta_description' => $description,
            'canonical_url' => $canonical,
            'robots_directives' => $robots,
            'headings' => $headings,
            'links' => array_slice($links, 0, 200),
            'cta_candidates' => array_slice($ctas, 0, 30),
            'structured_data' => $structuredData,
            'main_content' => $mainContent,
            'word_count' => is_array($words) ? count($words) : 0,
            'content_hash' => $mainContent === '' ? null : hash('sha256', $mainContent),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'title' => null,
            'meta_description' => null,
            'canonical_url' => null,
            'robots_directives' => [],
            'headings' => [],
            'links' => [],
            'cta_candidates' => [],
            'structured_data' => [],
            'main_content' => '',
            'word_count' => 0,
            'content_hash' => null,
        ];
    }

    private function firstText(DOMXPath $xpath, string $query): ?string
    {
        $node = $this->firstNode($xpath, $query);
        $value = $node instanceof DOMNode ? Str::squish($node->textContent) : '';

        return $value === '' ? null : Str::limit($value, 512, '');
    }

    private function metaContent(DOMXPath $xpath, string $name): ?string
    {
        return $this->attribute(
            $xpath,
            '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="'.$name.'"]',
            'content',
        );
    }

    private function attribute(DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $node = $this->firstNode($xpath, $query);
        if (! $node instanceof DOMElement) {
            return null;
        }

        $value = Str::squish($node->getAttribute($attribute));

        return $value === '' ? null : $value;
    }

    private function firstNode(DOMXPath $xpath, string $query): ?DOMNode
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMNode ? $node : null;
    }

    /** @return array<int, string> */
    private function robots(DOMXPath $xpath): array
    {
        $content = $this->metaContent($xpath, 'robots') ?? '';

        return collect(preg_split('/[,\s]+/', Str::lower($content), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, array{valid: bool, types: array<int, string>}> */
    private function structuredData(DOMXPath $xpath): array
    {
        $items = [];
        foreach ($xpath->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "application/ld+json")]') ?: [] as $node) {
            if (! $node instanceof DOMNode) {
                continue;
            }

            try {
                $decoded = json_decode($node->textContent, true, flags: JSON_THROW_ON_ERROR);
                $types = collect(is_array($decoded) ? [$decoded['@type'] ?? null] : [])
                    ->flatten()
                    ->filter(fn (mixed $type): bool => is_string($type))
                    ->values()
                    ->all();
                $items[] = ['valid' => true, 'types' => $types];
            } catch (\Throwable) {
                $items[] = ['valid' => false, 'types' => []];
            }
        }

        return array_slice($items, 0, 20);
    }

    private function isCta(DOMElement $link, string $text): bool
    {
        $signals = Str::lower($text.' '.$link->getAttribute('class').' '.$link->getAttribute('role'));

        return $text !== '' && Str::contains($signals, [
            'button', 'btn', 'cta', 'start', 'buy', 'book', 'contact', 'subscribe', 'sign up', 'signup', 'demo', 'download', 'learn more',
        ]);
    }
}
