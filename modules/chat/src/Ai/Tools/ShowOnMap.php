<?php

namespace Modules\Chat\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Moves the map beside the conversation to a place the agent is talking about.
 *
 * The coordinates come from Nominatim rather than the model: a model asked for
 * a latitude will happily invent a plausible one, and a map is only useful if
 * it is pointing at the real place.
 */
class ShowOnMap implements Tool
{
    /**
     * The tool's name as the model, the browser, and the transcript all see it.
     */
    public const string NAME = 'show_on_map';

    /**
     * Get the tool's name.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Show a place on the map next to the conversation. Call this whenever the answer is about somewhere the visitor could look at: a town, address, landmark, neighbourhood, or region.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $place = trim((string) $request['place']);

        if ($place === '') {
            return 'No place was given, so the map was left where it was.';
        }

        $match = $this->geocode($place);

        if ($match === null) {
            return "Could not find [{$place}] on the map, so the map was left where it was. Tell the visitor you could not place it.";
        }

        // south, north, west, east -> the west,south,east,north the embed wants.
        [$south, $north, $west, $east] = $match['boundingbox'];

        return json_encode([
            'label' => $match['display_name'],
            'bbox' => [$west, $south, $east, $north],
            'marker' => [$match['lat'], $match['lon']],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Resolve a free-text place to a Nominatim result.
     *
     * Nominatim is a free community service whose usage policy asks that
     * results be cached, and a demo revisits the same handful of places.
     *
     * @return array{display_name: string, lat: string, lon: string, boundingbox: array{string, string, string, string}}|null
     */
    protected function geocode(string $place): ?array
    {
        $key = 'geocode:'.md5(mb_strtolower($place));

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $match = $this->lookup($place);

        // Only hits are cached: a timeout or a 503 must not pin a place to
        // "not found" for the rest of the day.
        if ($match !== null) {
            Cache::put($key, $match, now()->addDay());
        }

        return $match;
    }

    /**
     * Ask Nominatim where a place is.
     *
     * @return array{display_name: string, lat: string, lon: string, boundingbox: array{string, string, string, string}}|null
     */
    protected function lookup(string $place): ?array
    {
        $response = Http::timeout(5)
            // Nominatim's usage policy rejects requests that do not identify themselves.
            ->withUserAgent(config('app.name').' ('.config('app.url').')')
            ->get('https://nominatim.openstreetmap.org/search', [
                'q' => $place,
                'format' => 'jsonv2',
                'limit' => 1,
            ]);

        if ($response->failed()) {
            return null;
        }

        $match = $response->json('0');

        return isset($match['boundingbox']) && count($match['boundingbox']) === 4
            ? $match
            : null;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'place' => $schema->string()
                ->description('The place to show, as specific as possible, e.g. "Douglas, Cork, Ireland".')
                ->required(),
        ];
    }
}
