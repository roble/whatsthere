<?php

namespace Modules\Chat\Providers;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Laravel\Ai\Models\Conversation;
use Modules\Chat\Jobs\GenerateConversationTitle;

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
        // The page schedules a refresh after these counts so a newly generated
        // title appears without polling. Shared so the list is not duplicated.
        Inertia::share('chat.retitle_at', GenerateConversationTitle::RETITLE_AT);

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
                ->toArray();
        });
    }
}
