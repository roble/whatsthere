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
        return 'Show a place in Ireland on the map next to the conversation. Call this whenever the answer is about somewhere the visitor could look at: a town, address, landmark, neighbourhood, or county. Only Irish places can be found.';
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
        $key = 'geocode:ie:'.md5(mb_strtolower($place));

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
     * Name the place a point falls in.
     *
     * The browser can say where the visitor dragged the map, but only as
     * coordinates, and a model cannot turn 53.35, -6.25 into "Dublin". Rounding
     * to roughly a kilometre before caching keeps small pans off Nominatim.
     */
    public function placeAt(float $latitude, float $longitude): ?string
    {
        $latitude = round($latitude, 2);
        $longitude = round($longitude, 2);
        $key = "reverse-geocode:{$latitude},{$longitude}";

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        // zoom 14 names the suburb or town rather than the house number: the
        // visitor panned to look at an area, not at a doorstep.
        $name = $this->nominatim('reverse', [
            'lat' => $latitude,
            'lon' => $longitude,
            'zoom' => 14,
        ])['display_name'] ?? null;

        if ($name !== null) {
            Cache::put($key, $name, now()->addDay());
        }

        return $name;
    }

    /**
     * Ask Nominatim where a place is.
     *
     * @return array{display_name: string, lat: string, lon: string, boundingbox: array{string, string, string, string}}|null
     */
    protected function lookup(string $place): ?array
    {
        // countrycodes is the half of the Ireland scope that cannot be talked
        // around: the instructions ask the model to stay in Ireland, this makes
        // anywhere else unfindable.
        $match = $this->nominatim('search', [
            'q' => $place,
            'limit' => 1,
            'countrycodes' => 'ie',
        ])[0] ?? null;

        return isset($match['boundingbox']) && count($match['boundingbox']) === 4
            ? $match
            : null;
    }

    /**
     * Call Nominatim, or return null if it will not answer.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    protected function nominatim(string $path, array $query): ?array
    {
        $response = Http::timeout(5)
            // Nominatim's usage policy rejects requests that do not identify themselves.
            ->withUserAgent(config('app.name').' ('.config('app.url').')')
            ->get("https://nominatim.openstreetmap.org/{$path}", $query + ['format' => 'jsonv2']);

        return $response->failed() ? null : $response->json();
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'place' => $schema->string()
                ->description('The place in Ireland to show, as specific as possible, e.g. "Douglas, Cork".')
                ->required(),
        ];
    }
}
