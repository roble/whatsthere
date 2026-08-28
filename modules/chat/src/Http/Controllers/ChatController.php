<?php

namespace Modules\Chat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Models\Conversation;
use Modules\Chat\Ai\ChatAgent;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ChatController
{
    /**
     * Show a blank chat. No row exists until the first message is sent.
     */
    public function index(): Response
    {
        return Inertia::render('Chat::Index', [
            'conversationId' => null,
            'initialMessages' => [],
        ]);
    }

    /**
     * Show an existing conversation belonging to the authenticated user.
     */
    public function show(Request $request, string $conversation): Response
    {
        $owned = $this->ownedConversation($request, $conversation);

        $messages = (new ChatAgent)
            ->continue($owned->id, $request->user())
            ->messages();

        return Inertia::render('Chat::Index', [
            'conversationId' => $owned->id,
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
     * Stream an assistant reply, creating the conversation on first use.
     */
    public function stream(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
        ]);

        $conversation = isset($validated['conversation_id'])
            ? $this->ownedConversation($request, $validated['conversation_id'])
            : $this->startConversation($request, $validated['message']);

        $response = (new ChatAgent)
            ->continue($conversation->id, $request->user())
            ->stream($validated['message'])
            ->usingVercelDataProtocol()
            ->toResponse($request);

        // A brand new chat has to move onto its own URL, so the browser needs
        // the id. It cannot ride in the stream body: the id must be known
        // before the first byte, and the protocol encoder exposes no hook for
        // extra frames.
        $response->headers->set('X-Conversation-Id', $conversation->id);

        return $response;
    }

    /**
     * Create the conversation up front so its id is known before streaming.
     *
     * Laravel\Ai would otherwise create it mid-stream, which is too late to
     * report back to the browser. Creating it here also means the package skips
     * its own title generation, so the title is the opening message.
     */
    protected function startConversation(Request $request, string $message): Conversation
    {
        return Conversation::create([
            'id' => (string) Str::uuid(),
            'participant_type' => Conversation::participantType($request->user()),
            'participant_id' => Conversation::participantKey($request->user()),
            'title' => Str::limit(trim($message), 50, preserveWords: true) ?: __('New chat'),
        ]);
    }

    /**
     * Resolve a conversation the authenticated user owns.
     *
     * Laravel\Ai's continue() performs no ownership check of its own, so every
     * path that accepts an id from the client must come through here first.
     */
    protected function ownedConversation(Request $request, string $id): Conversation
    {
        return Conversation::query()
            ->where('id', $id)
            ->where('participant_type', Conversation::participantType($request->user()))
            ->where('participant_id', Conversation::participantKey($request->user()))
            ->firstOrFail();
    }
}
