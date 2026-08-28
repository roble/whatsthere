<?php

namespace Modules\Chat\Providers;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Laravel\Ai\Models\Conversation;

class ChatServiceProvider extends ModuleServiceProvider
{
    protected array $providers = [
        // YourServiceProvider::class,
    ];

    /**
     * Share Inertia data globally.
     *
     * The session list lives in the global sidebar, so it is shared rather than
     * passed per page. The query is covered by the conversations table's
     * participant/updated_at index.
     */
    protected function shareInertiaData(): void
    {
        Inertia::share('chat.sessions', function (): array {
            $user = Auth::user();

            if ($user === null) {
                return [];
            }

            return Conversation::query()
                ->where('participant_type', Conversation::participantType($user))
                ->where('participant_id', Conversation::participantKey($user))
                ->latest('updated_at')
                ->get(['id', 'title'])
                ->map(fn (Conversation $conversation): array => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                ])
                ->all();
        });
    }
}
