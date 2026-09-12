<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Tests\TestCase;

class ChatMapViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_blank_chat_has_no_map_view(): void
    {
        $response = $this->actingAs($this->createUser())->get(route('chat.index'));

        $response->assertInertia(
            fn ($page) => $page->component('Chat::Index', false)
                ->where('initialMapView', null)
        );
    }

    public function test_reopening_a_conversation_restores_the_last_place_shown(): void
    {
        $user = $this->createUser();
        $conversation = $this->conversationFor($user);

        $this->storeMessage($conversation, '0001', 'user', 'Where should I go near Cork?');
        $this->storeMessage($conversation, '0002', 'assistant', 'Kinsale is worth the trip.', [
            [
                'id' => 'call-1',
                'name' => ShowOnMap::NAME,
                'arguments' => ['place' => 'Kinsale, Cork'],
                'result' => json_encode([
                    'label' => 'Kinsale, County Cork, Ireland',
                    'bbox' => ['-8.5424283', '51.6927609', '-8.4897026', '51.7157766'],
                    'marker' => ['51.7057370', '-8.5229823'],
                ]),
                'result_id' => null,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('chat.show', $conversation->id));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Chat::Index', false)
                ->where('initialMapView.label', 'Kinsale, County Cork, Ireland')
                ->where('initialMapView.bbox', ['-8.5424283', '51.6927609', '-8.4897026', '51.7157766'])
        );
    }

    public function test_a_search_restores_every_pin_it_put_on_the_map(): void
    {
        $user = $this->createUser();
        $conversation = $this->conversationFor($user);

        $this->storeMessage($conversation, '0001', 'user', 'Where can I get a pint in Galway?');
        $this->storeMessage($conversation, '0002', 'assistant', 'There are a few good ones.', [
            [
                'id' => 'call-1',
                'name' => FindPlaces::NAME,
                'arguments' => ['category' => 'pub', 'area' => 'Galway'],
                'result' => json_encode([
                    'label' => 'Pubs in Galway, Ireland',
                    'bbox' => ['-9.1000', '53.2500', '-9.0000', '53.3000'],
                    'markers' => [
                        ['lat' => 53.2741, 'lon' => -9.0476, 'name' => "Darcy's Bar"],
                        ['lat' => 53.2745, 'lon' => -9.048, 'name' => 'The Skeff'],
                    ],
                ]),
                'result_id' => null,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('chat.show', $conversation->id));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Chat::Index', false)
                ->where('initialMapView.label', 'Pubs in Galway, Ireland')
                // The pins, not just the camera: a search that reopened on an
                // empty map would be showing the wrong answer entirely.
                ->count('initialMapView.markers', 2)
        );
    }

    public function test_searches_in_one_reply_are_pooled_on_reopen(): void
    {
        $user = $this->createUser();
        $conversation = $this->conversationFor($user);

        $search = fn (string $category, string $label, array $bbox, string $name): array => [
            'id' => 'call-'.$category,
            'name' => FindPlaces::NAME,
            'arguments' => ['category' => $category, 'area' => 'Lisbon'],
            'result' => json_encode([
                'label' => $label,
                'category' => $category.'s',
                'categoryKey' => $category,
                'bbox' => $bbox,
                'markers' => [['lat' => 38.7, 'lon' => -9.1, 'name' => $name]],
            ]),
            'result_id' => null,
        ];

        $this->storeMessage($conversation, '0001', 'user', 'Show me places for my plan.');
        $this->storeMessage($conversation, '0002', 'assistant', 'Here you go.', [
            $search('restaurant', 'Restaurants in Lisbon, Portugal', ['-9.2', '38.6', '-9.1', '38.7'], 'Saraiva'),
            $search('museum', 'Museums in Lisbon, Portugal', ['-9.1', '38.7', '-9.0', '38.8'], 'MAAT'),
            [
                'id' => 'call-place',
                'name' => ShowOnMap::NAME,
                'arguments' => ['place' => 'Lisbon'],
                'result' => json_encode(['label' => 'Lisbon, Portugal', 'bbox' => ['-9.2', '38.6', '-9.0', '38.8'], 'marker' => ['38.7', '-9.1']]),
                'result_id' => null,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('chat.show', $conversation->id))
            ->assertInertia(
                fn ($page) => $page->component('Chat::Index', false)
                    ->where('initialMapView.label', 'restaurants and museums in Lisbon, Portugal')
                    ->where('initialMapView.bbox', ['-9.2', '38.6', '-9', '38.8'])
                    ->where('initialMapView.markers.1.categoryKey', 'museum')
                    ->count('initialMapView.markers', 2)
            );
    }

    public function test_only_the_latest_reply_decides_the_reopened_map(): void
    {
        $user = $this->createUser();
        $conversation = $this->conversationFor($user);

        $search = fn (string $id, string $name): array => [
            'id' => $id,
            'name' => FindPlaces::NAME,
            'arguments' => ['category' => 'cafe', 'area' => 'Shibuya'],
            'result' => json_encode([
                'label' => 'Cafes in Shibuya, Tokyo',
                'category' => 'cafes',
                'categoryKey' => 'cafe',
                'bbox' => ['139.69', '35.65', '139.71', '35.67'],
                'markers' => [['lat' => 35.66, 'lon' => 139.70, 'name' => $name]],
            ]),
            'result_id' => null,
        ];

        $this->storeMessage($conversation, '0001', 'user', 'Cafes in Shibuya?');
        $this->storeMessage($conversation, '0002', 'assistant', 'Here.', [$search('call-1', 'First Cafe')]);
        $this->storeMessage($conversation, '0003', 'user', 'Search again.');
        $this->storeMessage($conversation, '0004', 'assistant', 'Again.', [$search('call-2', 'Second Cafe')]);

        $this->actingAs($user)
            ->get(route('chat.show', $conversation->id))
            ->assertInertia(
                fn ($page) => $page->component('Chat::Index', false)
                    ->count('initialMapView.markers', 1)
                    ->where('initialMapView.markers.0.name', 'Second Cafe')
            );
    }

    public function test_a_conversation_that_never_moved_the_map_has_no_map_view(): void
    {
        $user = $this->createUser();
        $conversation = $this->conversationFor($user);

        $this->storeMessage($conversation, '0001', 'user', 'What is 17 times 4?');
        $this->storeMessage($conversation, '0002', 'assistant', '68');

        $response = $this->actingAs($user)->get(route('chat.show', $conversation->id));

        $response->assertInertia(
            fn ($page) => $page->component('Chat::Index', false)
                ->where('initialMapView', null)
        );
    }

    public function test_a_dragged_map_is_named_from_where_it_now_points(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'display_name' => 'Douglas, Cork, County Cork, Ireland',
            ]),
        ]);

        $instructions = (string) (new ChatAgent([
            'label' => 'Dublin, County Dublin, Ireland',
            'center' => [51.7921, -8.4234],
            'zoom' => 13.0,
            'moved' => true,
        ]))->instructions();

        $this->assertStringContainsString('Douglas, Cork, County Cork, Ireland', $instructions);
        // The stale label is what made the assistant answer about Dublin while
        // the visitor was looking at Cork.
        $this->assertStringNotContainsString('Dublin', $instructions);
    }

    public function test_an_untouched_map_keeps_the_label_the_conversation_set(): void
    {
        Http::fake();

        $instructions = (string) (new ChatAgent([
            'label' => 'Dublin, County Dublin, Ireland',
            'center' => [53.3547, -6.2509],
            'zoom' => 10.9,
            'moved' => false,
        ]))->instructions();

        $this->assertStringContainsString('Dublin, County Dublin, Ireland', $instructions);
        // Every message would otherwise pay for a geocoder round trip.
        Http::assertNothingSent();
    }

    protected function conversationFor(mixed $user): Conversation
    {
        return Conversation::create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'participant_type' => Conversation::participantType($user),
            'participant_id' => Conversation::participantKey($user),
            'title' => 'Trip planning',
        ]);
    }

    /**
     * Store one message row.
     *
     * Ids are sequential rather than random because the conversation store
     * orders by id, so random UUIDs would shuffle the transcript.
     *
     * @param  array<int, array<string, mixed>>  $toolResults
     */
    protected function storeMessage(
        Conversation $conversation,
        string $sequence,
        string $role,
        string $content,
        array $toolResults = [],
    ): void {
        ConversationMessage::create([
            'id' => "00000000-0000-0000-0000-00000000{$sequence}",
            'conversation_id' => $conversation->id,
            'participant_type' => $conversation->participant_type,
            'participant_id' => $conversation->participant_id,
            'agent' => ChatAgent::class,
            'role' => $role,
            'content' => $content,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => $toolResults,
            'usage' => [],
            'meta' => [],
        ]);
    }
}
