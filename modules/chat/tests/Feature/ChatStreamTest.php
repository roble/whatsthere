<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Models\Conversation;
use Modules\Chat\Ai\ChatAgent;
use Tests\TestCase;

class ChatStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_the_chat_page(): void
    {
        $this->get(route('chat.index'))->assertRedirect(route('login'));
    }

    public function test_guests_cannot_stream_a_message(): void
    {
        $response = $this->post(route('chat.stream'), ['message' => 'Hello']);

        $response->assertRedirect(route('login'));

        // The chat page detects an expired session via `response.redirected`,
        // because fetch follows the redirect and would otherwise hand the SDK a
        // 200 containing the login page. If this ever became a 200 or a JSON
        // error, that detection would silently stop working.
        $this->assertTrue($response->isRedirect());
    }

    public function test_chat_page_renders_for_an_authenticated_user(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('chat.index'));

        $response->assertOk();
        $response->assertInertia(
            // Module pages resolve through module-loader.js, not PHP, so the
            // Inertia view finder cannot confirm they exist. Skip that check.
            fn ($page) => $page->component('Chat::Index', false)->has('initialMessages', 0)
        );
    }

    public function test_it_streams_a_reply_using_the_vercel_protocol(): void
    {
        Ai::fakeAgent(ChatAgent::class, ['Hello from the assistant.']);

        $response = $this->actingAs($this->createUser())
            ->post(route('chat.stream'), ['message' => 'Hi there']);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/event-stream; charset=utf-8');
        $response->assertHeader('x-vercel-ai-ui-message-stream', 'v1');

        $stream = $response->streamedContent();

        $this->assertStringContainsString('data: [DONE]', $stream);
        $this->assertSame('Hello from the assistant.', $this->textFrom($stream));

        Ai::assertAgentWasPrompted(ChatAgent::class, 'Hi there');
    }

    public function test_the_reply_is_persisted_against_the_user(): void
    {
        Ai::fakeAgent(ChatAgent::class, ['Stored reply.']);

        $user = $this->createUser();

        $this->actingAs($user)
            ->post(route('chat.stream'), ['message' => 'Remember this'])
            ->streamedContent();

        $this->assertDatabaseHas('agent_conversations', [
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
        ]);

        $this->assertDatabaseHas('agent_conversation_messages', [
            'role' => 'user',
            'content' => 'Remember this',
        ]);
    }

    /**
     * Reassemble the assistant's reply from the protocol's text-delta frames.
     */
    protected function textFrom(string $stream): string
    {
        return collect(explode("\n", $stream))
            ->filter(fn (string $line): bool => str_starts_with($line, 'data: {'))
            ->map(fn (string $line): array => json_decode(substr($line, 6), true) ?? [])
            ->where('type', 'text-delta')
            ->pluck('delta')
            ->implode('');
    }

    public function test_a_message_is_required(): void
    {
        $this->actingAs($this->createUser())
            ->post(route('chat.stream'), ['message' => ''])
            ->assertSessionHasErrors('message');
    }

    public function test_guests_cannot_open_a_conversation(): void
    {
        $this->get(route('chat.show', 'anything'))->assertRedirect(route('login'));
    }

    public function test_a_new_chat_reports_its_conversation_id(): void
    {
        Ai::fakeAgent(ChatAgent::class, ['Reply.']);

        $user = $this->createUser();

        $response = $this->actingAs($user)
            ->post(route('chat.stream'), ['message' => 'First message']);

        $response->assertOk();

        // The browser has no other way to learn the id of a chat it just
        // started, and needs it to move onto /chat/{id}.
        $id = $response->headers->get('X-Conversation-Id');

        $this->assertNotNull($id);
        $this->assertDatabaseHas('agent_conversations', [
            'id' => $id,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
            'title' => 'First message',
        ]);
    }

    public function test_an_existing_conversation_restores_its_history(): void
    {
        Ai::fakeAgent(ChatAgent::class, ['Stored reply.']);

        $user = $this->createUser();

        $response = $this->actingAs($user)
            ->post(route('chat.stream'), ['message' => 'Remember this']);
        $response->streamedContent();

        $id = $response->headers->get('X-Conversation-Id');

        $this->actingAs($user)
            ->get(route('chat.show', $id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Chat::Index', false)
                ->where('conversationId', $id)
                ->has('initialMessages', 2)
            );
    }

    public function test_a_user_cannot_open_another_users_conversation(): void
    {
        $conversation = $this->conversationFor($this->createUser());

        $this->actingAs($this->createUser())
            ->get(route('chat.show', $conversation->id))
            ->assertNotFound();
    }

    public function test_a_user_cannot_stream_into_another_users_conversation(): void
    {
        Ai::fakeAgent(ChatAgent::class, ['Reply.']);

        $conversation = $this->conversationFor($this->createUser());

        $this->actingAs($this->createUser())->post(route('chat.stream'), [
            'message' => 'Hello',
            'conversation_id' => $conversation->id,
        ])->assertNotFound();

        $this->assertDatabaseMissing('agent_conversation_messages', [
            'conversation_id' => $conversation->id,
        ]);
    }

    public function test_streaming_into_an_unknown_conversation_is_rejected(): void
    {
        Ai::fakeAgent(ChatAgent::class, ['Reply.']);

        $this->actingAs($this->createUser())->post(route('chat.stream'), [
            'message' => 'Hello',
            'conversation_id' => 'not-a-conversation',
        ])->assertNotFound();
    }

    public function test_the_session_list_only_contains_your_own_conversations(): void
    {
        $user = $this->createUser();

        $this->conversationFor($user, 'Mine');
        $this->conversationFor($this->createUser(), 'Theirs');

        $this->actingAs($user)
            ->get(route('chat.index'))
            ->assertInertia(fn ($page) => $page
                ->has('chat.sessions', 1)
                ->where('chat.sessions.0.title', 'Mine')
            );
    }

    /**
     * Create a conversation owned by the given user.
     */
    protected function conversationFor(object $user, string $title = 'A chat'): Conversation
    {
        return Conversation::create([
            'id' => (string) Str::uuid(),
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
            'title' => $title,
        ]);
    }
}
