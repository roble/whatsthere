<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Models\Conversation;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Ai\ConversationTitleAgent;
use Modules\Chat\Jobs\GenerateConversationTitle;
use RuntimeException;
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

    public function test_a_provider_failure_stays_inside_the_stream(): void
    {
        // Thrown while the stream is being iterated, which is where a real
        // provider failure lands -- after the response headers have gone out.
        Ai::fakeAgent(ChatAgent::class, fn () => throw new RuntimeException('Incorrect API key provided: sk-abc.'));

        $response = $this->actingAs($this->createUser())
            ->post(route('chat.stream'), ['message' => 'Hi there']);

        // 200, because the headers were committed before the provider was
        // reached. The protocol carries the error in a frame instead.
        $response->assertOk();
        $response->assertHeader('content-type', 'text/event-stream; charset=utf-8');

        $stream = $response->streamedContent();

        $this->assertStringContainsString('"type":"error"', $stream);
        $this->assertStringContainsString('data: [DONE]', $stream);

        // Letting it escape the generator made Laravel render a whole HTML
        // error page over the top of the stream, which is what sent nginx a
        // second set of headers and produced the duplicate Date warning.
        $this->assertStringNotContainsString('<!DOCTYPE html>', $stream);

        // The provider names keys and account state in its errors.
        $this->assertStringNotContainsString('sk-abc', $stream);
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

    public function test_title_generation_is_queued_after_the_third_user_message(): void
    {
        Queue::fake([GenerateConversationTitle::class]);
        Ai::fakeAgent(ChatAgent::class, ['First reply.', 'Second reply.', 'Third reply.', 'Fourth reply.']);

        $user = $this->createUser();
        $conversationId = $this->sendMessages($user, [
            'First message',
            'Second message',
        ]);

        Queue::assertNotPushed(GenerateConversationTitle::class);

        $this->sendMessages($user, ['Third message'], $conversationId);

        Queue::assertPushed(
            GenerateConversationTitle::class,
            fn (GenerateConversationTitle $job): bool => $job->conversationId === $conversationId,
        );

        $this->sendMessages($user, ['Fourth message'], $conversationId);

        // Four is not a milestone, so no second job.
        Queue::assertPushed(GenerateConversationTitle::class, 1);
    }

    public function test_the_title_is_regenerated_at_later_milestones(): void
    {
        Queue::fake([GenerateConversationTitle::class]);
        Ai::fakeAgent(ChatAgent::class, array_fill(0, 12, 'Reply.'));

        $user = $this->createUser();
        $conversationId = $this->sendMessages($user, ['Message 1', 'Message 2', 'Message 3']);

        for ($i = 4; $i <= 10; $i++) {
            $this->sendMessages($user, ["Message {$i}"], $conversationId);
        }

        // Milestones 3 and 10 both fire, and carry distinct unique ids so the
        // first does not suppress the second.
        Queue::assertPushed(GenerateConversationTitle::class, 2);
        Queue::assertPushed(
            GenerateConversationTitle::class,
            fn (GenerateConversationTitle $job): bool => $job->atMessageCount === 10
                && $job->uniqueId() === $conversationId.':10',
        );
    }

    public function test_renaming_a_conversation_does_not_reorder_the_sidebar(): void
    {
        // Without this the sync queue runs the job inline on the third message,
        // consuming the single faked title before the assertion below.
        Queue::fake([GenerateConversationTitle::class]);
        Ai::fakeAgent(ChatAgent::class, ['Reply one.', 'Reply two.', 'Reply three.']);
        Ai::fakeAgent(ConversationTitleAgent::class, ['A better title']);

        $user = $this->createUser();
        $conversationId = $this->sendMessages($user, ['One', 'Two', 'Three']);

        $before = Conversation::query()->findOrFail($conversationId)->getAttribute('updated_at');

        $this->travel(10)->minutes();
        (new GenerateConversationTitle($conversationId))->handle(new ConversationTitleAgent);

        $conversation = Conversation::query()->findOrFail($conversationId);

        $this->assertSame('A better title', $conversation->getAttribute('title'));
        $this->assertEquals(
            $before,
            $conversation->getAttribute('updated_at'),
            'Renaming must not touch updated_at, which orders the session list.',
        );
    }

    public function test_a_user_can_read_one_of_their_conversations_as_json(): void
    {
        Ai::fakeAgent(ChatAgent::class, ['Stored reply.']);

        $user = $this->createUser();
        $conversationId = $this->sendMessages($user, ['Remember this']);

        $response = $this->actingAs($user)->getJson(route('chat.messages', $conversationId));

        $response->assertOk();
        $response->assertJsonPath('id', $conversationId);
        $response->assertJsonPath('messages.0.role', 'user');
        $response->assertJsonPath('messages.0.text', 'Remember this');
        $response->assertJsonCount(2, 'messages');
    }

    public function test_a_user_cannot_read_another_users_conversation_as_json(): void
    {
        $conversation = $this->conversationFor($this->createUser());

        $this->actingAs($this->createUser())
            ->getJson(route('chat.messages', $conversation->id))
            ->assertNotFound();
    }

    public function test_guests_cannot_read_a_conversation_as_json(): void
    {
        $this->get(route('chat.messages', 'anything'))->assertRedirect(route('login'));
    }

    public function test_the_title_agent_renames_the_conversation_from_its_first_three_turns(): void
    {
        Queue::fake([GenerateConversationTitle::class]);
        Ai::fakeAgent(ChatAgent::class, ['First reply.', 'Second reply.', 'Third reply.']);
        Ai::fakeAgent(ConversationTitleAgent::class, ['Laravel queue monitoring']);

        $user = $this->createUser();
        $conversationId = $this->sendMessages($user, [
            'How do I monitor my Laravel queues?',
            'I am using the database driver.',
            'Can I see failed jobs too?',
        ]);

        (new GenerateConversationTitle($conversationId))->handle(new ConversationTitleAgent);

        $this->assertSame(
            'Laravel queue monitoring',
            Conversation::query()->findOrFail($conversationId)->getAttribute('title'),
        );

        Ai::assertAgentWasPrompted(
            ConversationTitleAgent::class,
            fn ($prompt): bool => $prompt->contains('How do I monitor my Laravel queues?')
                && $prompt->contains('Can I see failed jobs too?'),
        );
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

    /**
     * @param  list<string>  $messages
     */
    protected function sendMessages(object $user, array $messages, ?string $conversationId = null): string
    {
        foreach ($messages as $message) {
            $response = $this->actingAs($user)->post(route('chat.stream'), array_filter([
                'message' => $message,
                'conversation_id' => $conversationId,
            ]));

            $response->assertOk();
            $response->streamedContent();
            $conversationId ??= $response->headers->get('X-Conversation-Id');
        }

        $this->assertNotNull($conversationId);

        return $conversationId;
    }
}
