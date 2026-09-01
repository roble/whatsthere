<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Ai\Tools\EircodeToGeoLocation;
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

    public function test_an_eircode_lookup_also_restores_the_map(): void
    {
        $user = $this->createUser();
        $conversation = $this->conversationFor($user);

        $this->storeMessage($conversation, '0001', 'user', 'Where is D02 X285?');
        $this->storeMessage($conversation, '0002', 'assistant', 'That is in Dublin 2.', [
            [
                'id' => 'call-1',
                'name' => EircodeToGeoLocation::NAME,
                'arguments' => ['eircode' => 'D02 X285'],
                'result' => json_encode([
                    'label' => 'D02 X285',
                    'bbox' => ['-6.2515', '53.3325', '-6.2435', '53.3375'],
                    'marker' => ['53.335', '-6.2475'],
                ]),
                'result_id' => null,
            ],
        ]);

        // Every map-moving tool has to be listed, or reopening the
        // conversation snaps the map back to its default while the messages
        // beside it still discuss somewhere else.
        $this->actingAs($user)->get(route('chat.show', $conversation->id))
            ->assertInertia(
                fn ($page) => $page->component('Chat::Index', false)
                    ->where('initialMapView.label', 'D02 X285')
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
