<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Tools\Request as ToolRequest;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Ai\Tools\InterviewVisitor;
use Modules\Chat\Ai\Tools\SaveMapReadyPlan;
use Modules\Chat\Models\OnboardingState;
use Tests\TestCase;

class OnboardingStateTest extends TestCase
{
    use RefreshDatabase;

    protected function conversationFor(object $user): Conversation
    {
        return Conversation::create([
            'id' => (string) Str::uuid(),
            'participant_type' => Conversation::participantType($user),
            'participant_id' => Conversation::participantKey($user),
            'title' => 'Quiet coffee in Shibuya',
        ]);
    }

    public function test_a_message_answers_the_open_question(): void
    {
        Ai::fakeAgent(ChatAgent::class, ['Noted.']);
        $user = $this->createUser();
        $conversation = $this->conversationFor($user);
        OnboardingState::create([
            'conversation_id' => $conversation->id,
            'current_question' => ['question' => 'When?', 'options' => ['Morning'], 'multiple' => false, 'count' => 1],
        ]);

        $this->actingAs($user)
            ->post(route('chat.stream'), ['message' => 'Morning', 'conversation_id' => $conversation->id])
            ->assertOk();

        $state = OnboardingState::find($conversation->id);

        $this->assertNull($state->current_question);
        $this->assertSame([['question' => 'When?', 'answer' => 'Morning']], $state->answers);
    }

    public function test_skipping_opens_the_map_with_a_minimal_plan(): void
    {
        $user = $this->createUser();
        $conversation = $this->conversationFor($user);

        $this->actingAs($user)
            ->patchJson(route('chat.onboarding', $conversation->id), ['phase' => 'mapping'])
            ->assertOk()
            ->assertJsonPath('phase', 'mapping')
            ->assertJsonPath('plan.goal', 'Quiet coffee in Shibuya');

        $this->actingAs($user)
            ->get(route('chat.show', $conversation->id))
            ->assertInertia(fn ($page) => $page->where('onboarding.phase', 'mapping'));
    }

    public function test_going_back_to_planning_keeps_the_plan(): void
    {
        $user = $this->createUser();
        $conversation = $this->conversationFor($user);
        OnboardingState::create([
            'conversation_id' => $conversation->id,
            'phase' => 'mapping',
            'question_count' => 2,
            'plan' => ['goal' => 'Coffee', 'location' => 'Shibuya', 'details' => []],
        ]);

        $this->actingAs($user)
            ->patchJson(route('chat.onboarding', $conversation->id), ['phase' => 'interviewing'])
            ->assertOk()
            ->assertJsonPath('phase', 'interviewing')
            ->assertJsonPath('plan.location', 'Shibuya');
    }

    public function test_another_user_cannot_change_the_phase(): void
    {
        $conversation = $this->conversationFor($this->createUser());

        $this->actingAs($this->createUser())
            ->patchJson(route('chat.onboarding', $conversation->id), ['phase' => 'mapping'])
            ->assertNotFound();
    }

    public function test_a_fresh_conversation_offers_only_the_interview_tool(): void
    {
        $state = OnboardingState::firstOrCreate(['conversation_id' => (string) Str::uuid()]);
        $agent = new ChatAgent(null, $state);

        $this->assertSame([InterviewVisitor::NAME], array_map(fn ($tool) => $tool->name(), [...$agent->tools()]));

        $state->update(['question_count' => ChatAgent::MIN_QUESTIONS]);
        $this->assertContains('save_map_ready_plan', array_map(fn ($tool) => $tool->name(), [...(new ChatAgent(null, $state))->tools()]));
        $this->assertSame('required', $agent->toolChoice());
        $this->assertStringContainsString('discovery interview', (string) $agent->instructions());
    }

    public function test_only_one_question_is_asked_per_turn(): void
    {
        $state = OnboardingState::create(['conversation_id' => (string) Str::uuid()]);
        $tool = new InterviewVisitor($state);

        $first = (string) $tool->handle(new ToolRequest(['question' => 'Where?', 'options' => ['Tokyo']]));
        $second = (string) $tool->handle(new ToolRequest(['question' => 'When?', 'options' => ['Now']]));

        $this->assertSame(1, json_decode($first, true)['count']);
        $this->assertStringContainsString('No more questions', $second);
        $this->assertSame(1, $state->fresh()->question_count);
    }

    public function test_saving_a_plan_keeps_an_open_map_open(): void
    {
        $this->fakeGeocoder(found: true);
        $state = OnboardingState::create(['conversation_id' => (string) Str::uuid(), 'phase' => 'mapping']);

        (new SaveMapReadyPlan($state))->handle(new ToolRequest(['goal' => 'Coffee', 'location' => 'Shibuya']));

        $this->assertSame('mapping', $state->fresh()->phase);
        $this->assertSame('Shibuya', $state->fresh()->plan['location']);
    }

    public function test_a_plan_with_an_unmappable_location_is_refused(): void
    {
        $this->fakeGeocoder(found: false);
        $state = OnboardingState::create(['conversation_id' => (string) Str::uuid()]);

        $result = (string) (new SaveMapReadyPlan($state))->handle(new ToolRequest([
            'goal' => 'Walks',
            'location' => 'Cape Town - Table Mountain / City Bowl',
        ]));

        $this->assertStringContainsString('cannot find', $result);
        $this->assertNull($state->fresh()->plan);
        $this->assertSame('interviewing', $state->fresh()->phase);
    }

    protected function fakeGeocoder(bool $found): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response($found ? [[
                'lat' => '35.66',
                'lon' => '139.70',
                'display_name' => 'Shibuya, Tokyo, Japan',
                'boundingbox' => ['35.64', '35.68', '139.68', '139.72'],
            ]] : []),
        ]);
    }
}
