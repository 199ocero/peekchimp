<?php

use Inertia\Testing\AssertableInertia as Assert;

test('renders the Welcome landing page', function () {
    $response = $this->get(route('home'));

    $response
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});

test('landing page explains the product and offers appearance controls', function () {
    $landingPage = file_get_contents(resource_path('js/pages/Welcome.vue'));

    expect($landingPage)
        ->toContain('Know what brings people to your site.')
        ->toContain('DashboardTrafficChart')
        ->toContain('Last 7 days · Sample data')
        ->toContain('aria-label="Change appearance"')
        ->toContain('DropdownMenuRadioItem');
});
