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
     * How many results a search returns.
     *
     * One number rather than fetching more than are shown: Overpass caps the
     * query itself, so everything it sends back reaches the map and the model,
     * ranked rather than truncated. A reply that searches twice pools both
     * sets, so the map can carry more than this.
     */
    protected const LIMIT = 40;

    /**
     * The OpenStreetMap tags worth carrying to the popup and the model.
     * Everything else is dropped: the result is context as well as map data.
     */
    protected const array DETAIL_TAGS = [
        'opening_hours' => 'hours',
        'website' => 'website',
        'contact:website' => 'website',
        'phone' => 'phone',
        'contact:phone' => 'phone',
        'cuisine' => 'cuisine',
        'wheelchair' => 'wheelchair',
        'outdoor_seating' => 'outdoor_seating',
        'internet_access' => 'internet_access',
        'description' => 'description',
    ];

    public function __construct(protected ?string $conversationId = null) {}

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
        return 'Find up to 40 places of one kind within an area and show them on the map together, e.g. pubs in Galway or castles in Bavaria. Use this for "what is around", "where can I find", and "show me the ..." questions. For a single named place, use show_on_map instead.';
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

        // Reuses ShowOnMap rather than maintaining a second geocoding path.
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

        $this->rememberMarkers($places);

        return json_encode([
            'label' => ucfirst($this->label($category))." in {$bounds['label']}",
            // Stable machine value for choosing the matching map symbol. Keep
            // this separate from the plural display copy below.
            'categoryKey' => $category,
            // Named for the browser as well as the model: the step beside the
            // map would otherwise have to pluralise the raw category itself,
            // and "church" and "pharmacy" do not take a bare s.
            'category' => $this->label($category),
            'bbox' => $bounds['bbox'],
            'markers' => $places,
            'note' => 'Each marker may carry details such as hours, address, cuisine, wheelchair access or a website. Mention the ones that matter for the visitor\'s plan, for example accessibility or opening hours.',
        ], JSON_THROW_ON_ERROR);
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
        // Versioned: a cached answer from before markers carried details would
        // otherwise serve bare pins for a day.
        $key = 'overpass:v4:'.$category.':'.md5(implode(',', $bbox));

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
     * Ranked, not truncated: named places first, then the ones OpenStreetMap
     * knows most about. A search finding a handful of named cafés among dozens
     * of unnamed ones shows the ones a visitor can look up, and within those
     * the entries carrying hours, a website or an address come before the bare
     * point somebody dropped on the map -- which is the best proxy available
     * for somewhere worth going.
     *
     * @param  array<int, array<string, mixed>>  $elements
     * @return list<array{lat: float, lon: float, name: string, details?: array<string, string>}>
     */
    protected function toMarkers(array $elements, string $category): array
    {
        $ranked = [];
        $unnamed = 0;

        foreach ($elements as $element) {
            $latitude = $element['lat'] ?? $element['center']['lat'] ?? null;
            $longitude = $element['lon'] ?? $element['center']['lon'] ?? null;

            if ($latitude === null || $longitude === null) {
                continue;
            }

            $tags = (array) ($element['tags'] ?? []);
            $details = $this->details($tags);

            $marker = [
                'lat' => (float) $latitude,
                'lon' => (float) $longitude,
                'name' => $this->nameFor($tags, $details, $category, $unnamed),
            ];

            if ($details !== []) {
                $marker['details'] = $details;
            }

            $ranked[] = [
                'named' => isset($tags['name']),
                'weight' => count($details),
                'marker' => $marker,
            ];
        }

        // Sorting is stable, so places of equal rank keep the order Overpass
        // sent them in rather than being shuffled about by the comparison.
        usort($ranked, fn (array $a, array $b): int => [$b['named'], $b['weight']] <=> [$a['named'], $a['weight']]);

        return array_column($ranked, 'marker');
    }

    /**
     * Remember the exact points this conversation may put in an itinerary.
     *
     * @param  list<array{lat: float, lon: float, name: string, details?: array<string, string>}>  $markers
     */
    protected function rememberMarkers(array $markers): void
    {
        if ($this->conversationId === null) {
            return;
        }

        foreach ($markers as $marker) {
            Cache::put(
                self::markerKey($this->conversationId, $marker['name'], $marker['lat'], $marker['lon']),
                true,
                now()->addDay(),
            );
        }
    }

    /**
     * Confirm that find_places issued this exact label and point to the conversation.
     */
    public static function issuedMarker(string $conversationId, string $name, float $latitude, float $longitude): bool
    {
        return Cache::has(self::markerKey($conversationId, $name, $latitude, $longitude));
    }

    protected static function markerKey(string $conversationId, string $name, float $latitude, float $longitude): string
    {
        $identity = implode('|', [
            $conversationId,
            mb_strtolower(trim($name)),
            sprintf('%.7F', $latitude),
            sprintf('%.7F', $longitude),
        ]);

        return 'find-places:marker:'.hash('sha256', $identity);
    }

    /**
     * What to call a place on the pin and in the model's context.
     *
     * The English name where the map has one: the assistant and the visitor
     * both read the pin, and neither may read kanji.
     *
     * Unnamed places are the awkward case. Calling every one of them
     * "Viewpoint" leaves seventeen pins nobody can tell apart, and leaves the
     * model with no string that identifies one rather than another -- asked
     * for an itinerary from them it reaches for the name of the search itself,
     * and every stop lands on the same coordinates. So they are distinguished
     * by their street where OpenStreetMap knows one, and numbered where it
     * does not.
     *
     * @param  array<string, mixed>  $tags
     * @param  array<string, string>  $details
     * @param  int  $unnamed  Running count, so the numbering is stable within one search.
     */
    protected function nameFor(array $tags, array $details, string $category, int &$unnamed): string
    {
        $name = $tags['name:en'] ?? $tags['name'] ?? null;

        if ($name !== null) {
            return (string) $name;
        }

        $kind = ucfirst($this->label($category, plural: false));
        $unnamed++;

        return isset($details['address'])
            ? "{$kind} at {$details['address']} #{$unnamed}"
            : "{$kind} {$unnamed}";
    }

    /**
     * Pick out the tags a visitor can act on, under stable short keys.
     *
     * @param  array<string, mixed>  $tags
     * @return array<string, string>
     */
    protected function details(array $tags): array
    {
        $details = [];

        foreach (self::DETAIL_TAGS as $tag => $key) {
            $value = trim((string) ($tags[$tag] ?? ''));

            if ($value !== '' && ! isset($details[$key])) {
                $details[$key] = mb_substr($value, 0, 200);
            }
        }

        // A house number is only an address next to its street.
        $street = isset($tags['addr:street'])
            ? trim(($tags['addr:housenumber'] ?? '').' '.$tags['addr:street'])
            : '';
        $address = trim(implode(', ', array_filter([$street, $tags['addr:city'] ?? null])));

        if ($address !== '') {
            $details = ['address' => $address] + $details;
        }

        return $details;
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

        $query = sprintf(
            '[out:json][timeout:25];nwr%s(%s);out center %d;',
            self::CATEGORIES[$category],
            $box,
            self::LIMIT
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
                ->description('The town, city, region, or neighbourhood to search inside, e.g. "Galway, Ireland" or "Shinjuku, Tokyo".')
                ->required(),
        ];
    }
}
