<?php

namespace App\Http\Controllers;

use App\Services\FrontendConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class IndexController extends Controller
{
    public function __invoke(FrontendConfig $config): Response|RedirectResponse|InertiaResponse
    {
        if (empty($config->getFramework())) {
            return response()->view('setup');
        }

        if (Auth::check()) {
            return redirect()->route('chat.index');
        }

        return Inertia::render('Index', [
            // Share here your frontend data, e.g. products, announcements, etc.
        ])->withSSR();
    }
}
