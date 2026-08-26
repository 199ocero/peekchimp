<?php

test('new visitors follow their system appearance', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertDontSee('class="dark"', false);
    $response->assertSee("const appearance = 'system'", false);
    $response->assertSee("window.matchMedia('(prefers-color-scheme: dark)')", false);
});

test('an explicit light preference stays light', function () {
    $response = $this->withUnencryptedCookie('appearance', 'light')->get(route('home'));

    $response->assertOk();
    $response->assertDontSee('class="dark"', false);
});

test('an explicit dark preference stays dark', function () {
    $response = $this->withUnencryptedCookie('appearance', 'dark')->get(route('home'));

    $response->assertOk();
    $response->assertSee('class="dark"', false);
});

test('an explicit system preference remains available', function () {
    $response = $this->withUnencryptedCookie('appearance', 'system')->get(route('home'));

    $response->assertOk();
    $response->assertDontSee('class="dark"', false);
});

test('light and dark appearances share the emerald primary hue', function () {
    $styles = file_get_contents(resource_path('css/app.css'));
    $application = file_get_contents(resource_path('js/app.ts'));

    expect($styles)
        ->toContain('--background: #f5f7f6;')
        ->toContain('--card: #ffffff;')
        ->toContain('--foreground: #151816;')
        ->toContain('--border: #dfe4e1;')
        ->toContain('--background: #0b0d0e;')
        ->toContain('--card: #131517;')
        ->toContain('--foreground: #f4f7f5;')
        ->toContain('--border: #23262b;')
        ->toContain('--primary: #007a52;')
        ->toContain('--primary: #009965;')
        ->not->toContain('--primary: #b45309;')
        ->not->toContain('--primary: #fbbf24;');

    expect($application)
        ->toContain("color: '#009965'")
        ->not->toContain("color: '#fbbf24'");
});
