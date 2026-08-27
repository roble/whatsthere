<?php

namespace Modules\Chat\Http\Controllers;

use Inertia\Inertia;

class ChatController
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Chat::Index', [
            'message' => 'Welcome to Chat Module',
            'module' => 'chat',
        ]);
    }
}
