<?php

test('uses HTTPS asset URLs behind a trusted proxy', function () {
    $response = $this
        ->withServerVariables([
            'HTTP_HOST' => 'peekchimp.com',
            'HTTP_X_FORWARDED_HOST' => 'peekchimp.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'SERVER_PORT' => 80,
        ])
        ->get(route('home'));

    $response
        ->assertSuccessful()
        ->assertSee('https://peekchimp.com/build/assets/', false);
});
