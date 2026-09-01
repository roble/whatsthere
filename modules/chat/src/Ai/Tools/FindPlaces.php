<?php

namespace Modules\Chat\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;

/**
 * Scatter every pub, castle or beach in an area across the map at once.
 *
 * Where ShowOnMap answers "where is this place", this answers "what is around
 * here", which is the question a map is actually good at. The data is
 * OpenStreetMap by way of Overpass.
 */
class FindPlaces implements Tool
{
    /**
     * The tool's name as the model, the browser, and the transcript all see it.
     */
    public const string NAME = 'find_places';

    /**
     * What the model may search for, and the OpenStreetMap tags behind each.
     *
     * An allow-list rather than a `filter` parameter the model writes itself.
     * Two reasons, and both are load-bearing:
     *
     * - These strings are the only thing interpolated into the query besides
     *   bounding-box floats, so no model output can reach the query language.
     *   A tool that forwards model-authored Overpass QL to a public endpoint is
     *   an injection surface pointed at somebody else's free server.
     * - Overpass QL is niche enough that models write it badly. Picking from a
     *   list is something they do reliably.
     *
     * Adding a category is one line here and one word in the schema enum.
     *
     * @var array<string, string>
     */
    public const array CATEGORIES = [
        'pub' => '["amenity"="pub"]',
        'restaurant' => '["amenity"="restaurant"]',
        'cafe' => '["amenity"="cafe"]',
        'hotel' => '["tourism"="hotel"]',
        'castle' => '["historic"="castle"]',
        'ruins' => '["historic"="ruins"]',
        'museum' => '["tourism"="museum"]',
        'viewpoint' => '["tourism"="viewpoint"]',
        'beach' => '["natural"="beach"]',
        'park' => '["leisure"="park"]',
        'playground' => '["leisure"="playground"]',
        'golf_course' => '["leisure"="golf_course"]',
        'camp_site' => '["tourism"="camp_site"]',
        'church' => '["amenity"="place_of_worship"]',
        'library' => '["amenity"="library"]',
        'cinema' => '["amenity"="cinema"]',
        'pharmacy' => '["amenity"="pharmacy"]',
        'hospital' => '["amenity"="hospital"]',
        'supermarket' => '["shop"="supermarket"]',
        'fuel' => '["amenity"="fuel"]',
        'atm' => '["amenity"="atm"]',
        'car_park' => '["amenity"="parking"]',
        'train_station' => '["railway"="station"]',
        'bus_station' => '["amenity"="bus_station"]',
        'toilets' => '["amenity"="toilets"]',
    ];

    /**
     * How many results to put on the map.
     *
     * Overpass applies this itself, so an area thick with pubs costs one
     * capped response rather than a thousand rows we then throw away. It also
     * caps what lands in the model's context, since the tool result is context.
     */
    protected const LIMIT = 40;

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
        return 'Find all the places of one kind within an area of Ireland and show them on the map together, e.g. every pub in Galway or every castle in Kerry. Use this for "what is around", "where can I find", and "show me the ..." questions. For a single named place, use show_on_map instead.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $category = trim((string) $request['category']);
        $area = trim((string) $request['area']);

        if (! isset(self::CATEGORIES[$category])) {
            return "There is no category called [{$category}], so the map was left where it was.";
        }

        if ($area === '') {
            return 'No area was given, so the map was left where it was.';
        }

        // Reuses ShowOnMap rather than geocoding again, which is also what
        // keeps this inside Ireland: that lookup is pinned to countrycodes=ie,
        // so an area outside it never resolves to a box to search.
        $bounds = $this->boundsOf($area);

        if ($bounds === null) {
            return "Could not find [{$area}] on the map, so nothing was searched. Tell the visitor you could not place it.";
        }

        $places = $this->search($category, $bounds['bbox']);

        if ($places === null) {
            return 'The map data service could not be reached, so the map was left where it was.';
        }

        if ($places === []) {
            return "Found no {$this->label($category)} in [{$bounds['label']}]. The map was left where it was.";
        }

        // Overpass was asked for one more than the cap, so an overflow means
        // there are others we are not showing. Saying "40" when the real answer
        // is "at least 40" is the tool lying through the assistant.
        $capped = count($places) > self::LIMIT;

        return json_encode(array_filter([
            'label' => ucfirst($this->label($category))." in {$bounds['label']}",
            // Named for the browser as well as the model: the step beside the
            // map would otherwise have to pluralise the raw category itself,
            // and "church" and "pharmacy" do not take a bare s.
            'category' => $this->label($category),
            'bbox' => $bounds['bbox'],
            'markers' => array_slice($places, 0, self::LIMIT),
            'capped' => $capped,
        ]), JSON_THROW_ON_ERROR);
    }

    /**
     * Resolve an area name to the box to search inside.
     *
     * ponytail: a bounding box, not the area's real outline, so a search of
     * somewhere non-rectangular picks up its neighbours -- a box around Kerry
     * reaches into Clare. Fixing it properly means resolving the place to an
     * OpenStreetMap area and filtering on that, which is exact but only works
     * for places mapped as boundaries, so free-text areas would stop working.
     *
     * @return array{label: string, bbox: array{string, string, string, string}}|null
     */
    protected function boundsOf(string $area): ?array
    {
        $view = json_decode(
            (string) (new ShowOnMap)->handle(new ToolRequest(['place' => $area])),
            true
        );

        // ShowOnMap answers in prose when it cannot place somewhere, so
        // anything that is not a decoded view means "not found".
        return is_array($view) && isset($view['bbox'])
            ? ['label' => $view['label'], 'bbox' => $view['bbox']]
            : null;
    }

    /**
     * Ask Overpass what of this kind sits inside the box.
     *
     * Null means the service did not answer, which is different from it
     * answering that there is nothing there.
     *
     * @param  array{string, string, string, string}  $bbox
     * @return list<array{lat: float, lon: float, name: string}>|null
     */
    protected function search(string $category, array $bbox): ?array
    {
        $key = 'overpass:'.$category.':'.md5(implode(',', $bbox));

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $elements = $this->overpass($category, $bbox);

        if ($elements === null) {
            return null;
        }

        $places = $this->toMarkers($elements, $category);

        // Only answers are cached. A timeout must not pin an area to "nothing
        // here" for the rest of the day.
        Cache::put($key, $places, now()->addDay());

        return $places;
    }

    /**
     * Turn Overpass elements into the markers the map draws.
     *
     * A node carries its own lat/lon; a way or relation carries a `center`
     * because the query asked for `out center`. Reading only the top-level
     * pair would silently drop every building, which is most castles, hotels
     * and supermarkets.
     *
     * @param  array<int, array<string, mixed>>  $elements
     * @return list<array{lat: float, lon: float, name: string}>
     */
    protected function toMarkers(array $elements, string $category): array
    {
        $markers = [];

        foreach ($elements as $element) {
            $latitude = $element['lat'] ?? $element['center']['lat'] ?? null;
            $longitude = $element['lon'] ?? $element['center']['lon'] ?? null;

            if ($latitude === null || $longitude === null) {
                continue;
            }

            // Every other OpenStreetMap tag is dropped here. The result is the
            // model's context as well as the map's data, and a marker needs a
            // point and something to call it.
            $markers[] = [
                'lat' => (float) $latitude,
                'lon' => (float) $longitude,
                'name' => (string) ($element['tags']['name'] ?? ucfirst($this->label($category, plural: false))),
            ];
        }

        return $markers;
    }

    /**
     * Call Overpass, or return null if it will not answer.
     *
     * @param  array{string, string, string, string}  $bbox
     * @return array<int, array<string, mixed>>|null
     */
    protected function overpass(string $category, array $bbox): ?array
    {
        [$west, $south, $east, $north] = $bbox;

        // Casting to float is what makes this safe to interpolate: the values
        // arrive as strings from Nominatim, and the tag filter beside them is a
        // constant, so nothing model-authored reaches the query.
        $box = sprintf(
            '%F,%F,%F,%F',
            (float) $south,
            (float) $west,
            (float) $north,
            (float) $east
        );

        // One more than we mean to keep, which is how the caller can tell a
        // full house from a coincidence and avoid reporting the cap as a total.
        $query = sprintf(
            '[out:json][timeout:25];nwr%s(%s);out center %d;',
            self::CATEGORIES[$category],
            $box,
            self::LIMIT + 1
        );

        $response = Http::asForm()
            // Overpass is a donated service whose usage policy asks that
            // clients identify themselves and go easy, hence the cache above.
            ->withUserAgent(config('app.name').' ('.config('app.url').')')
            // Generous: Overpass regularly takes several seconds, and the
            // alternative to waiting is the answer arriving without its map.
            ->timeout(30)
            ->post('https://overpass-api.de/api/interpreter', ['data' => $query]);

        return $response->failed() ? null : ($response->json('elements') ?? []);
    }

    /**
     * The category as it reads in a sentence.
     */
    protected function label(string $category, bool $plural = true): string
    {
        $words = str_replace('_', ' ', $category);

        return $plural ? str($words)->plural()->value() : $words;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()
                ->description('The kind of place to find.')
                ->enum(array_keys(self::CATEGORIES))
                ->required(),
            'area' => $schema->string()
                ->description('The town, city, county or neighbourhood in Ireland to search inside, e.g. "Galway" or "Kinsale, Cork".')
                ->required(),
        ];
    }
}
