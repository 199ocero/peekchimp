<?php

namespace App\Http\Controllers;

use App\Models\Insight;
use App\Services\Analytics\InsightActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InsightActionController extends Controller
{
    public function store(Request $request, Insight $insight, InsightActionService $actions): RedirectResponse
    {
        $insight->loadMissing('project');
        Gate::authorize('view', $insight->project);
        $key = $request->string('action')->toString();
        abort_unless(collect($actions->actions($insight))->contains('key', $key), 422, 'Unknown insight action.');
        $result = $actions->execute($key, $insight, $request->user());

        return $result['redirect'] !== null
            ? redirect()->to($result['redirect'])
            : back()->with('status', 'Insight action recorded.');
    }
}
