<?php

use App\Services\SearchConsole\SearchConsolePropertyMatcher;

it('extracts exact hosts from domain and url prefix properties', function () {
    $matcher = new SearchConsolePropertyMatcher;

    expect($matcher->host('sc-domain:Example.COM.'))->toBe('example.com')
        ->and($matcher->host('https://www.example.com/blog/'))->toBe('www.example.com')
        ->and($matcher->host('not-a-property'))->toBeNull();
});
