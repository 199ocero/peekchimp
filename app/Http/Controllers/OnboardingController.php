<?php

namespace App\Http\Controllers;

use App\Actions\Onboarding\SetUpWebsiteAction;
use App\Http\Requests\StoreWebsiteRequest;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request): Response
    {
        $project = $request->user()?->projects()->with('domains')->oldest()->first();
        $domain = $project?->domains->first();

        return Inertia::render('onboarding/Index', [
            'website' => $project === null ? null : [
                'name' => $project->name,
                'url' => $domain === null ? '' : 'https://'.$domain->domain,
                'domain' => $domain?->domain,
                'timezone' => $project->timezone,
                'siteKey' => $project->site_key,
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaultTimezone' => config('app.timezone', 'UTC'),
            'trackerUrl' => asset('a.js'),
            'isVerified' => (bool) $domain?->is_verified,
        ]);
    }

    public function store(StoreWebsiteRequest $request, SetUpWebsiteAction $setUpWebsite): RedirectResponse
    {
        $setUpWebsite->handle($request->user(), $request->website());

        return to_route('onboarding.show');
    }
}
