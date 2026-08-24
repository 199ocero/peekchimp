<?php

test('new visitors start in dark mode', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('class="dark"', false);
});

test('an explicit light preference stays light', function () {
    $response = $this->withCookie('appearance', 'light')->get(route('home'));

    $response->assertOk();
    $response->assertDontSee('class="dark"', false);
});

test('an explicit system preference remains available', function () {
    $response = $this->withCookie('appearance', 'system')->get(route('home'));

    $response->assertOk();
    $response->assertDontSee('class="dark"', false);
});

test('light and dark appearances share the emerald primary hue', function () {
    $styles = file_get_contents(resource_path('css/app.css'));
    $application = file_get_contents(resource_path('js/app.ts'));

    expect($styles)
        ->toContain('--primary: #007a52;')
        ->toContain('--primary: #009965;')
        ->not->toContain('--primary: #b45309;')
        ->not->toContain('--primary: #fbbf24;');

    expect($application)
        ->toContain("color: '#009965'")
        ->not->toContain("color: '#fbbf24'");
});
