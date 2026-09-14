<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Modules\Chat\Models\OnboardingState;
use Tests\TestCase;

class ChatDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_guests_cannot_delete_a_conversation(): void
    {
        $this->delete(route('chat.destroy', 'anything'))
            ->assertRedirect(route('login'));
    }

    public function test_a_user_can_delete_one_of_their_conversations(): void
    {
        $user = $this->createUser();
        $conversation = $this->conversationFor($user, 'To delete');
        $this->messageFor($conversation);

        OnboardingState::create([
            'conversation_id' => $conversation->id,
            'phase' => 'mapping',
            'question_count' => 0,
            'answers' => [],
            'plan' => ['preferences' => []],
            'flow' => 'property',
        ]);

        $this->actingAs($user)
            ->from(route('chat.show', $conversation->id))
            ->delete(route('chat.destroy', $conversation->id))
            ->assertRedirect(route('chat.index'));

        $this->assertDatabaseMissing('agent_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('agent_conversation_messages', [
            'conversation_id' => $conversation->id,
        ]);
        $this->assertDatabaseMissing('onboarding_states', [
            'conversation_id' => $conversation->id,
        ]);
    }

    public function test_deleting_another_conversation_leaves_the_open_chat_in_place(): void
    {
        $user = $this->createUser();
        $open = $this->conversationFor($user, 'Open');
        $other = $this->conversationFor($user, 'Other');

        $this->actingAs($user)
            ->from(route('chat.show', $open->id))
            ->delete(route('chat.destroy', $other->id))
            ->assertRedirect(route('chat.show', $open->id));

        $this->assertDatabaseHas('agent_conversations', ['id' => $open->id]);
        $this->assertDatabaseMissing('agent_conversations', ['id' => $other->id]);
    }

    public function test_a_user_cannot_delete_another_users_conversation(): void
    {
        $conversation = $this->conversationFor($this->createUser(), 'Theirs');

        $this->actingAs($this->createUser())
            ->delete(route('chat.destroy', $conversation->id))
            ->assertNotFound();

        $this->assertDatabaseHas('agent_conversations', ['id' => $conversation->id]);
    }

    public function test_guests_cannot_delete_all_conversations(): void
    {
        $this->delete(route('chat.sessions.destroy'))
            ->assertRedirect(route('login'));
    }

    public function test_a_user_can_delete_all_of_their_conversations(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();

        $mine = $this->conversationFor($user, 'Mine');
        $alsoMine = $this->conversationFor($user, 'Also mine');
        $theirs = $this->conversationFor($other, 'Theirs');

        $this->actingAs($user)
            ->from(route('settings.profile'))
            ->delete(route('chat.sessions.destroy'))
            ->assertRedirect(route('settings.profile'));

        $this->assertDatabaseMissing('agent_conversations', ['id' => $mine->id]);
        $this->assertDatabaseMissing('agent_conversations', ['id' => $alsoMine->id]);
        $this->assertDatabaseHas('agent_conversations', ['id' => $theirs->id]);
    }

    protected function conversationFor(object $user, string $title = 'A chat'): Conversation
    {
        return Conversation::create([
            'id' => (string) Str::uuid(),
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
            'title' => $title,
        ]);
    }

    protected function messageFor(Conversation $conversation): ConversationMessage
    {
        return ConversationMessage::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'participant_type' => $conversation->participant_type,
            'participant_id' => $conversation->participant_id,
            'agent' => 'chat',
            'role' => 'user',
            'content' => 'Hello',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ]);
    }
}
