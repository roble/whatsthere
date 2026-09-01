<?php

namespace Modules\Chat\Testing;

use Generator;
use Modules\Chat\Ai\Tools\EircodeToGeoLocation;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\ShowOnMap;

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
        'eircode' => 'an Eircode resolved to a point',
        'places' => 'a search with many pins',
        'places_capped' => 'a search with more results than it will show',
        'places_empty' => 'a search that found nothing',
        'not_found' => 'a place the geocoder cannot place',
        'refused' => 'a question outside Ireland, declined',
        'tool_error' => 'a tool that failed rather than came up empty',
        'failure' => 'no reply at all',
    ];

    public function __construct(protected string $messageId) {}

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
            'eircode' => $this->eircode(),
            'places' => $this->places(),
            'places_capped' => $this->placesCapped(),
            'places_empty' => $this->placesEmpty(),
            'not_found' => $this->notFound(),
            'refused' => $this->refused(),
            'tool_error' => $this->toolError(),
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
        yield from $this->thinking('**Locating Kinsale** — a town in County Cork, so show_on_map can place it.');

        yield from $this->tool(ShowOnMap::NAME, ['place' => 'Kinsale, Cork'], json_encode([
            'label' => 'Kinsale, County Cork, Munster, Éire / Ireland',
            'bbox' => ['-8.5424283', '51.6927609', '-8.4897026', '51.7157766'],
            'marker' => ['51.7057370', '-8.5229823'],
        ]));

        yield from $this->prose('Kinsale sits at the mouth of the Bandon, about 25km south of Cork city. It is known for its harbour, the star-shaped Charles Fort, and a run of restaurants along the waterfront.');
    }

    protected function eircode(): Generator
    {
        yield from $this->thinking('**Resolving D02 X285** — a Dublin 2 routing key, so the Eircode tool can place it.');

        yield from $this->tool(EircodeToGeoLocation::NAME, ['eircode' => 'D02 X285'], json_encode([
            'label' => 'D02 X285',
            'bbox' => ['-6.2515', '53.3325', '-6.2435', '53.3375'],
            'marker' => ['53.335', '-6.2475'],
        ]));

        yield from $this->prose('That Eircode is in Dublin 2, just south of the Liffey near Merrion Square.');
    }

    protected function places(): Generator
    {
        yield from $this->thinking('**Preparing to use find_places tool** — the question is about what is in an area rather than where one place is.');

        yield from $this->tool(FindPlaces::NAME, ['category' => 'pub', 'area' => 'Galway'], json_encode([
            'label' => 'Pubs in Cathair na Gaillimhe, County Galway, Connacht, Éire / Ireland',
            'category' => 'pubs',
            'bbox' => ['-9.1426901', '53.2485189', '-8.9548381', '53.3197423'],
            'markers' => [
                ['lat' => 53.2741952, 'lon' => -9.0476288, 'name' => 'Darcy’s Bar'],
                ['lat' => 53.2745932, 'lon' => -9.0483, 'name' => 'O’Connell’s'],
                ['lat' => 53.2738, 'lon' => -9.0483, 'name' => 'MacNeill’s'],
                ['lat' => 53.2719, 'lon' => -9.0543, 'name' => 'The Dew Drop Inn'],
                ['lat' => 53.2726, 'lon' => -9.0521, 'name' => 'Tigh Neachtain'],
                ['lat' => 53.2733, 'lon' => -9.0509, 'name' => 'The Quays'],
                ['lat' => 53.2751, 'lon' => -9.0498, 'name' => 'Garavan’s'],
            ],
        ]));

        yield from $this->prose('There are seven pubs on the map around the centre of Galway. Tigh Neachtain is the pick for a quiet pint, and The Quays is the one everyone is sent to.');
    }

    protected function placesCapped(): Generator
    {
        yield from $this->thinking('**Searching Dublin for hospitals** — a big area, so this may well hit the cap.');

        yield from $this->tool(FindPlaces::NAME, ['category' => 'hospital', 'area' => 'Dublin'], json_encode([
            'label' => 'Hospitals in Dublin, Leinster, Éire / Ireland',
            'category' => 'hospitals',
            'bbox' => ['-6.3870', '53.2986', '-6.1147', '53.4106'],
            'markers' => [
                ['lat' => 53.3092, 'lon' => -6.2632, 'name' => 'Mount Carmel Community Hospital'],
                ['lat' => 53.3396, 'lon' => -6.2967, 'name' => 'St James’s Hospital'],
                ['lat' => 53.3591, 'lon' => -6.2921, 'name' => 'Mater Misericordiae University Hospital'],
                ['lat' => 53.3181, 'lon' => -6.3062, 'name' => 'Our Lady’s Children’s Hospital'],
                ['lat' => 53.3013, 'lon' => -6.1774, 'name' => 'St Columcille’s Hospital'],
                ['lat' => 53.3878, 'lon' => -6.2453, 'name' => 'Beaumont Hospital'],
            ],
            'capped' => true,
        ]));

        yield from $this->prose('There are at least six hospitals across Dublin — the map is showing as many as it will draw at once, so treat that as a sample rather than the full list.');
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

    protected function refused(): Generator
    {
        yield from $this->thinking('**Out of scope** — Tokyo is not in Ireland, so decline and offer something else.');

        yield from $this->prose('I only cover places in Ireland, so Tokyo is outside what I can help with. If there is somewhere here you are curious about, I am happy to show you.');
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
