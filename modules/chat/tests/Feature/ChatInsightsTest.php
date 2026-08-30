<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Modules\Chat\Insights\ChatInsights;
use Modules\Chat\Settings\ChatSettings;
use Tests\TestCase;

class ChatInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tool_that_answered_in_prose_counts_as_a_miss(): void
    {
        $conversation = $this->conversation();
        $this->reply($conversation, '0001', toolResults: [
            $this->toolResult('Kinsale', '{"label":"Kinsale","bbox":["1","2","3","4"]}'),
            // The tool returns prose when it cannot place somewhere, so the call
            // still "succeeded" as far as the model is concerned.
            $this->toolResult('Atlantis', 'Could not find [Atlantis] on the map.'),
        ]);

        $insights = ChatInsights::make();

        $this->assertSame(2, $insights->totals()['tool_calls']);
        $this->assertSame(1, $insights->totals()['tool_failures']);
        $this->assertSame(['Atlantis'], $insights->unresolvedRequests()->pluck('input')->all());
        $this->assertSame(['Kinsale'], $insights->popularPlaces()->pluck('place')->all());
    }

    public function test_repeated_misses_are_grouped_and_counted(): void
    {
        $conversation = $this->conversation();

        foreach (['0001', '0002'] as $sequence) {
            $this->reply($conversation, $sequence, toolResults: [
                $this->toolResult('Atlantis', 'Could not find [Atlantis] on the map.'),
            ]);
        }

        $unresolved = ChatInsights::make()->unresolvedRequests();

        // One row, not two: the point is to see what keeps failing.
        $this->assertCount(1, $unresolved);
        $this->assertSame(2, $unresolved->first()['attempts']);
    }

    public function test_a_conversation_with_no_messages_is_a_failed_start(): void
    {
        // The row is created before the first byte and messages are only stored
        // once the stream completes, so an empty one never got its reply.
        $this->conversation();
        $answered = $this->conversation('22222222-2222-2222-2222-222222222222');
        $this->reply($answered, '0001');

        $insights = ChatInsights::make();

        $this->assertSame(1, $insights->failedStarts()->count());
        $this->assertSame(1, $insights->totals()['failed_starts']);
    }

    public function test_reasoning_tokens_are_reported_but_never_added_to_the_total(): void
    {
        $conversation = $this->conversation();
        $this->reply($conversation, '0001', usage: [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'cache_read_input_tokens' => 400,
            // A subset of the completion tokens, not an extra charge.
            'reasoning_tokens' => 30,
        ]);

        $tokens = ChatInsights::make()->tokens();

        $this->assertSame(550, $tokens['total']);
        $this->assertSame(30, $tokens['reasoning']);
    }

    public function test_spend_is_unknown_until_the_model_has_a_rate(): void
    {
        $conversation = $this->conversation();
        $this->reply($conversation, '0001', usage: [
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 1_000_000,
            'cache_read_input_tokens' => 1_000_000,
        ]);

        $this->assertNull(ChatInsights::make()->cost());
        $this->assertSame(['gpt-5.4-mini'], ChatInsights::make()->unpricedModels());

        $settings = app(ChatSettings::class);
        $settings->model_pricing = [
            ['model' => 'gpt-5.4-mini', 'input' => 0.25, 'cached' => 0.025, 'output' => 2.0],
        ];
        $settings->save();

        // A million of each, so the rates add up to themselves.
        $this->assertEqualsWithDelta(2.275, ChatInsights::make()->cost(), 0.0001);
        $this->assertSame([], ChatInsights::make()->unpricedModels());
    }

    public function test_the_window_excludes_older_traffic(): void
    {
        $conversation = $this->conversation();
        $this->reply($conversation, '0001', at: now()->subDays(31));
        $this->reply($conversation, '0002', at: now()->subDay());

        $this->assertSame(1, ChatInsights::make()->totals()['replies']);
    }

    public function test_the_cap_keeps_the_newest_messages_not_the_oldest(): void
    {
        $conversation = $this->conversation();

        $this->reply($conversation, '0001', at: now()->subHours(3));
        $this->reply($conversation, '0002', at: now()->subHours(2));
        $this->reply($conversation, '0003', at: now()->subHour());

        // Ordering ascending under a limit keeps the oldest rows and drops the
        // activity the page exists to show.
        $insights = new ChatInsights(days: 30, maxMessages: 2);

        $this->assertSame(2, $insights->messages()->count());
        $this->assertSame(
            ['00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003'],
            $insights->messages()->pluck('id')->all(),
        );
    }

    public function test_the_window_does_not_load_the_columns_it_never_reads(): void
    {
        $conversation = $this->conversation();
        $this->reply($conversation, '0001');

        $message = ChatInsights::make()->messages()->first();

        // The two big ones: a whole reply, and every reasoning summary behind
        // it. Selecting them for thousands of rows to count roles is waste.
        $this->assertFalse($message->offsetExists('content'));
        $this->assertFalse($message->offsetExists('tool_calls'));
        // Still everything the page actually reads.
        $this->assertTrue($message->offsetExists('usage'));
        $this->assertTrue($message->offsetExists('meta'));
        $this->assertTrue($message->offsetExists('tool_results'));
    }

    protected function conversation(string $id = '11111111-1111-1111-1111-111111111111'): Conversation
    {
        $user = $this->createUser();

        return Conversation::create([
            'id' => $id,
            'participant_type' => Conversation::participantType($user),
            'participant_id' => Conversation::participantKey($user),
            'title' => 'Trip planning',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function toolResult(string $place, string $result): array
    {
        return [
            'id' => 'call-'.md5($place.$result),
            'name' => ShowOnMap::NAME,
            'arguments' => ['place' => $place],
            'result' => $result,
            'result_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<int, array<string, mixed>>  $toolResults
     */
    protected function reply(
        Conversation $conversation,
        string $sequence,
        array $usage = ['prompt_tokens' => 10, 'completion_tokens' => 5],
        array $toolResults = [],
        ?Carbon $at = null,
    ): void {
        ConversationMessage::create([
            'id' => "00000000-0000-0000-0000-00000000{$sequence}",
            'conversation_id' => $conversation->id,
            'participant_type' => $conversation->participant_type,
            'participant_id' => $conversation->participant_id,
            'agent' => ChatAgent::class,
            'role' => 'assistant',
            'content' => 'A reply.',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => $toolResults,
            'usage' => $usage,
            'meta' => ['provider' => 'openai', 'model' => 'gpt-5.4-mini'],
            'created_at' => $at ?? now(),
        ]);
    }
}
