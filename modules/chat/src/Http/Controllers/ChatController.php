<?php

namespace Modules\Chat\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Messages\Message;
use Modules\Chat\Ai\ChatAgent;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ChatController
{
    /**
     * Show the chat page, seeded with the user's existing conversation.
     */
    public function index(Request $request): Response
    {
        $messages = $this->agentFor($request)->messages();

        return Inertia::render('Chat::Index', [
            'initialMessages' => collect($messages)
                ->values()
                ->map(fn (Message $message, int $index): array => [
                    'id' => 'history-'.$index,
                    'role' => $message->role->value,
                    'parts' => [
                        ['type' => 'text', 'text' => $message->content ?? ''],
                    ],
                ])
                ->all(),
        ]);
    }

    /**
     * Stream an assistant reply for the user's message.
     */
    public function stream(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        return $this->agentFor($request)
            ->stream($validated['message'])
            ->usingVercelDataProtocol()
            ->toResponse($request);
    }

    /**
     * Resolve the agent bound to the authenticated user's conversation.
     *
     * The conversation is always derived from the authenticated user and never
     * from client input: Laravel\Ai's continue() performs no ownership check,
     * so accepting an id from the request would expose other users' history.
     */
    protected function agentFor(Request $request): ChatAgent
    {
        return (new ChatAgent)->continueLastConversation($request->user());
    }
}
