<?php

namespace Modules\Chat\Testing;

use Generator;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Modules\Chat\Ai\Tools\UpdatePropertySearchPreferences;

/**
 * Replies the assistant never gave, for working on the front end.
 *
 * Every visible state of the chat -- each tool, each way a tool can come up
 * empty, reasoning, plain prose, and an outright failure -- costs a model call
 * to reach, and the interesting ones are the least convenient to trigger on
 * purpose. These are the same frames the real stream emits, so the browser
 * cannot tell the difference, and reaching a state is a page refresh rather
 * than a prompt that might not do what you wanted.
 *
 * Frames are copied from the shapes in `Laravel\Ai\Streaming\Events\*`. If the
 * package changes its protocol these go stale silently, which is what
 * `CannedRepliesTest` is for: it holds them against the real event classes.
 */
class CannedReplies
{
    /**
     * Every scenario, and what each one exists to put on screen.
     */
    public const array SCENARIOS = [
        'place' => 'a named place, found and mapped',
        'places' => 'a search with many pins',
        'places_empty' => 'a search that found nothing',
        'not_found' => 'a place the geocoder cannot place',
        'tool_error' => 'a tool that failed rather than came up empty',
        'property_workflow' => 'a property search narrowed and mapped',
        'failure' => 'no reply at all',
    ];

    /**
     * @param  array<string, mixed>  $propertySearchInput  The filters the search applied.
     * @param  string  $propertySearchOutput  That search's real JSON result.
     */
    public function __construct(
        protected string $messageId,
        protected array $propertySearchInput = [],
        protected string $propertySearchOutput = '{"label":"Properties for sale","categoryKey":"property","total":0,"markers":[],"bbox":["-10.7","51.3","-5.4","55.5"]}',
    ) {}

    /**
     * Pick a scenario at random, or return the one that was asked for.
     */
    public static function pick(?string $scenario = null): string
    {
        return isset(self::SCENARIOS[$scenario])
            ? $scenario
            : (string) array_rand(self::SCENARIOS);
    }

    /**
     * The frames one scenario streams, in order.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function frames(string $scenario): Generator
    {
        yield ['type' => 'start', 'messageId' => $this->messageId];

        yield from match ($scenario) {
            'place' => $this->place(),
            'places' => $this->places(),
            'places_empty' => $this->placesEmpty(),
            'not_found' => $this->notFound(),
            'tool_error' => $this->toolError(),
            'property_workflow' => $this->propertySearch(),
            'failure' => $this->failure(),
            default => $this->place(),
        };

        // The failure scenario ends the stream itself: a reply that never
        // arrived does not get a 'finish'.
        if ($scenario !== 'failure') {
            yield ['type' => 'finish'];
        }
    }

    protected function place(): Generator
    {
        yield from $this->thinking('**Locating Shibuya** — a district in Tokyo, so show_on_map can place it.');

        yield from $this->tool(ShowOnMap::NAME, ['place' => 'Shibuya, Tokyo, Japan'], json_encode([
            'label' => 'Shibuya, Tokyo, Japan',
            'bbox' => ['139.6613', '35.6281', '139.7239', '35.6924'],
            'marker' => ['35.6595', '139.7005'],
        ]));

        yield from $this->prose('Shibuya is a major commercial district in Tokyo, known for its scramble crossing, nightlife, fashion, and dense rail connections.');
    }

    protected function places(): Generator
    {
        yield from $this->thinking('**Preparing to use find_places tool** — the question is about what is in an area rather than where one place is.');

        yield from $this->tool(FindPlaces::NAME, ['category' => 'cafe', 'area' => 'Shibuya, Tokyo'], json_encode([
            'label' => 'Cafes in Shibuya, Tokyo, Japan',
            'categoryKey' => 'cafe',
            'category' => 'cafes',
            'bbox' => ['139.6613', '35.6281', '139.7239', '35.6924'],
            'markers' => [
                ['lat' => 35.6583, 'lon' => 139.7015, 'name' => 'Streamer Coffee Company'],
                ['lat' => 35.6558, 'lon' => 139.7032, 'name' => 'White Glass Coffee'],
                ['lat' => 35.6590, 'lon' => 139.6994, 'name' => 'About Life Coffee Brewers'],
                ['lat' => 35.6588, 'lon' => 139.6942, 'name' => 'FabCafe Tokyo'],
                ['lat' => 35.6621, 'lon' => 139.6968, 'name' => 'Roasted Coffee Laboratory'],
                ['lat' => 35.6628, 'lon' => 139.7041, 'name' => 'Coffee Supreme Tokyo'],
                ['lat' => 35.6650, 'lon' => 139.7060, 'name' => 'The Local Coffee Stand'],
                ['lat' => 35.6604, 'lon' => 139.7037, 'name' => 'Blue Bottle Coffee Shibuya'],
                ['lat' => 35.6574, 'lon' => 139.7024, 'name' => 'Sarutahiko Coffee'],
                ['lat' => 35.6641, 'lon' => 139.7010, 'name' => 'Verve Coffee Roasters'],
            ],
        ]));

        yield from $this->prose('Here are ten cafes around Shibuya. Streamer is known for latte art, while About Life is a good compact stop near the station.');
    }

    protected function placesEmpty(): Generator
    {
        yield from $this->thinking('**Looking for castles in Kinsale** — a small town, so there may be none mapped.');

        // The map tools answer in prose when they come up empty, which still
        // reaches the browser as a successful call. This is what tells the
        // route of thought to say "found no" rather than "found".
        yield from $this->tool(
            FindPlaces::NAME,
            ['category' => 'castle', 'area' => 'Kinsale'],
            'Found no castles in [Kinsale, County Cork, Ireland]. The map was left where it was.',
        );

        yield from $this->prose('There are no castles mapped inside Kinsale itself, though Charles Fort and James Fort both sit just outside the town.');
    }

    protected function notFound(): Generator
    {
        yield from $this->thinking('**Trying to place Narnia** — this is unlikely to resolve.');

        yield from $this->tool(
            ShowOnMap::NAME,
            ['place' => 'Narnia'],
            'Could not find [Narnia] on the map, so the map was left where it was. Tell the visitor you could not place it.',
        );

        yield from $this->prose('I could not place that one. If you can give me a town or a county, I will find it.');
    }

    protected function toolError(): Generator
    {
        yield from $this->thinking('**Searching Cork for playgrounds** — this one is going to fail outright.');

        yield ['type' => 'tool-input-available', 'toolCallId' => 'call-error', 'toolName' => FindPlaces::NAME, 'input' => ['category' => 'playground', 'area' => 'Cork']];
        yield ['type' => 'tool-output-error', 'toolCallId' => 'call-error', 'errorText' => 'The map data service could not be reached.'];

        yield from $this->prose('The map data service is not answering just now. Give it a minute and ask me again.');
    }

    protected function failure(): Generator
    {
        yield from $this->thinking('**Preparing to answer** — and then the provider gives out.');

        // Deliberately the sort of message a provider sends, so the frame
        // sanitiser in ChatController is exercised too: what reaches the
        // browser should never be this text.
        yield ['type' => 'error', 'errorText' => 'Rate limit reached for gpt-5.4-mini in organization org-EXAMPLE0000 on requests per day (RPD): Limit 50, Used 50.'];
    }

    /**
     * The search the assistant runs on an ordinary message.
     *
     * The result is the real one: `ChatController` runs the actual tool against
     * the actual database and hands the output in, so test mode exercises the
     * same search, the same pins, and the same results list as a live visitor.
     * Only the words around it are invented.
     */
    protected function propertySearch(): Generator
    {
        yield from $this->thinking('**Narrowing the search** — applying only the filters that were named.');
        yield from $this->tool(
            UpdatePropertySearchPreferences::NAME,
            $this->propertySearchInput,
            $this->propertySearchOutput,
        );
        yield from $this->prose('Here is what matches. Tell me what to change and I will narrow it further.');
    }

    /**
     * A reasoning block, streamed in pieces the way the model sends it.
     *
     * @return Generator<int, array<string, mixed>>
     */
    protected function thinking(string $text): Generator
    {
        $id = $this->messageId.'-reasoning';

        yield ['type' => 'reasoning-start', 'id' => $id];

        foreach ($this->pieces($text) as $piece) {
            yield ['type' => 'reasoning-delta', 'id' => $id, 'delta' => $piece];
        }

        yield ['type' => 'reasoning-end', 'id' => $id];
    }

    /**
     * A tool call and its result.
     *
     * @param  array<string, mixed>  $input
     * @return Generator<int, array<string, mixed>>
     */
    protected function tool(string $name, array $input, string $output): Generator
    {
        $id = 'call-'.$name;

        yield ['type' => 'tool-input-available', 'toolCallId' => $id, 'toolName' => $name, 'input' => $input];
        yield ['type' => 'tool-output-available', 'toolCallId' => $id, 'output' => $output];
    }

    /**
     * The reply itself, streamed a few words at a time.
     *
     * @return Generator<int, array<string, mixed>>
     */
    protected function prose(string $text): Generator
    {
        yield ['type' => 'text-start', 'id' => $this->messageId];

        foreach ($this->pieces($text) as $piece) {
            yield ['type' => 'text-delta', 'id' => $this->messageId, 'delta' => $piece];
        }

        yield ['type' => 'text-end', 'id' => $this->messageId];
    }

    /**
     * Break text into delta-sized pieces, keeping the spaces.
     *
     * @return list<string>
     */
    protected function pieces(string $text): array
    {
        return preg_split('/(?<= )/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
    }
}
