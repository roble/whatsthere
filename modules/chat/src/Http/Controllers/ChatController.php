<?php

namespace Modules\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\Request as ToolRequest;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Ai\Tools\EircodeToGeoLocation;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Modules\Chat\Jobs\GenerateConversationTitle;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ChatController
{
    /**
     * The tools whose results move the map.
     *
     * Kept here rather than checked one name at a time: a second map-moving
     * tool that is not listed reopens a conversation on the wrong place, and
     * the failure is silent.
     */
    public const array MAP_TOOLS = [
        ShowOnMap::NAME,
        EircodeToGeoLocation::NAME,
    ];

    /**
     * Show a blank chat. No row exists until the first message is sent.
     */
    public function index(): Response
    {
        return Inertia::render('Chat::Index', [
            'conversationId' => null,
            'initialMessages' => [],
            'initialMapView' => null,
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
            'initialMapView' => $this->lastMapView($messages),
        ]);
    }

    /**
     * Find the place the map was last showing in this conversation.
     *
     * The transcript is rebuilt as plain text, so the tool call that moved the
     * map is dropped on the way to the browser. Without this, reopening a
     * conversation snaps the map back to its default while the messages beside
     * it still discuss somewhere else.
     *
     * @param  iterable<Message>  $messages
     * @return array<string, mixed>|null
     */
    protected function lastMapView(iterable $messages): ?array
    {
        return collect($messages)
            ->filter(fn (Message $message): bool => $message instanceof ToolResultMessage)
            ->flatMap(fn (ToolResultMessage $message): array => $message->toolResults->all())
            ->filter(fn (ToolResult $result): bool => in_array($result->name, self::MAP_TOOLS, true))
            ->map(fn (ToolResult $result): mixed => json_decode((string) $result->result, true))
            ->last(fn (mixed $view): bool => is_array($view) && isset($view['bbox']));
    }

    /**
     * Resolve a place name to a map view.
     *
     * Lets a visitor's own agent move the map without going through the
     * assistant, reusing the same geocoder and cache the ShowOnMap tool uses so
     * there is one place where a place name becomes coordinates.
     */
    public function place(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'place' => ['required', 'string', 'max:200'],
        ]);

        $result = (string) (new ShowOnMap)->handle(
            new ToolRequest(['place' => $validated['place']])
        );

        $view = json_decode($result, true);

        // The tool answers in prose when it cannot place somewhere, which is
        // the same signal the assistant gets.
        return is_array($view)
            ? response()->json($view)
            : response()->json(['message' => $result], 404);
    }

    /**
     * Return one conversation's transcript as JSON.
     *
     * Lets an agent read a saved conversation without navigating the visitor
     * away from the one they are looking at.
     */
    public function messages(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->ownedConversation($request, $conversation);

        $messages = (new ChatAgent)
            ->continue($owned->id, $request->user())
            ->messages();

        return response()->json([
            'id' => $owned->id,
            'title' => $owned->getAttribute('title'),
            'messages' => collect($messages)
                ->values()
                ->map(fn (Message $message): array => [
                    'role' => $message->role->value,
                    'text' => $message->content ?? '',
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
            // Where the visitor's map is pointing. It reaches the model, so it
            // is bounded here rather than trusted as the browser sent it.
            'map' => ['nullable', 'array'],
            'map.label' => ['required_with:map', 'string', 'max:200'],
            'map.center' => ['required_with:map', 'array', 'size:2'],
            'map.center.0' => ['required_with:map', 'numeric', 'between:-90,90'],
            'map.center.1' => ['required_with:map', 'numeric', 'between:-180,180'],
            'map.zoom' => ['required_with:map', 'numeric', 'between:0,24'],
            'map.moved' => ['required_with:map', 'boolean'],
        ]);

        $conversation = isset($validated['conversation_id'])
            ? $this->ownedConversation($request, $validated['conversation_id'])
            : $this->startConversation($request, $validated['message']);

        $stream = (new ChatAgent($validated['map'] ?? null))
            ->continue($conversation->id, $request->user())
            ->stream($validated['message'])
            ->then(function () use ($conversation): void {
                $userMessageCount = $conversation->messages()
                    ->where('role', 'user')
                    ->count();

                if (in_array($userMessageCount, GenerateConversationTitle::RETITLE_AT, true)) {
                    GenerateConversationTitle::dispatch(
                        $conversation->id,
                        $userMessageCount,
                    );
                }
            });

        $response = $stream
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
