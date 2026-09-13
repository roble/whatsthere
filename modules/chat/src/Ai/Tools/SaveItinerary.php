<?php

namespace Modules\Chat\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Models\OnboardingState;
use Stringable;

/**
 * Turns the places found on the map into an ordered day the visitor can follow.
 *
 * The stops live inside the plan rather than in a table of their own: the plan
 * is the source of truth for the trip, and everything already reads it.
 */
class SaveItinerary implements Tool
{
    /**
     * The tool's name as the model, the browser, and the transcript all see it.
     */
    public const string NAME = 'save_itinerary';

    /**
     * How far a single stop's bounding box reaches, in degrees.
     *
     * One stop has no extent, and a zero-size box gives the camera nothing to
     * fit. Roughly half a kilometre is enough to frame the street it is on.
     */
    protected const float SPAN = 0.005;

    /**
     * How far outside the plan's own location a stop may still sit.
     *
     * Half the location's own width again, but never less than roughly five
     * kilometres, so a tight city box still allows the edge of town.
     */
    protected const float MARGIN = 0.5;

    protected const float MIN_MARGIN = 0.05;

    /**
     * How far apart the stops of one day may be, in degrees.
     *
     * Roughly a hundred kilometres: far enough for a real day trip, close
     * enough that a set of stops spread wider than this is a mistake.
     */
    protected const float DAY = 1.0;

    public function __construct(protected OnboardingState $state) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): Stringable|string
    {
        return 'Save the visitor\'s itinerary: the stops of their day in the order they will visit them. Call this when they ask for a day plan, an itinerary, a route, an order to do things in, or what to do first. Always pass every stop, because this replaces the whole list. Prefer places you have already found with find_places, and write each place as a name a map can find, e.g. "Livraria Lello, Porto, Portugal". For a marker with no real name of its own, such as "Viewpoint 2", pass that marker\'s lat and lon as well, because no name will find it. Every stop must be a different place. Order the stops as one continuous route: start at one end of the area and work across it, so the visitor never walks back past somewhere they have already been. Times follow the route, not the other way round -- when a meal has to fall at a certain hour, choose somewhere to eat between the stops on either side of it rather than sending them back across town.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $missing = [];
        $found = [];

        foreach ((array) ($request['stops'] ?? []) as $stop) {
            $place = trim((string) ($stop['place'] ?? ''));
            $title = trim((string) ($stop['title'] ?? '')) ?: $place;

            if ($place === '') {
                continue;
            }

            // The coordinates come from the geocoder, never from the model: a
            // model asked for a latitude will invent a plausible one, and an
            // itinerary is only followable if its pins are the real places.
            $point = $this->locate($place, $stop);

            if ($point === null) {
                $missing[] = "{$place} (not found)";

                continue;
            }

            $found[] = [
                'place' => $place,
                'point' => $point,
                'stop' => array_filter([
                    'time' => trim((string) ($stop['time'] ?? '')),
                    'title' => $title,
                    'place' => $place,
                    'note' => trim((string) ($stop['note'] ?? '')),
                ], fn (string $value): bool => $value !== '') + $point,
            ];
        }

        $stops = $this->keep($found, $missing);

        if ($stops === []) {
            $reasons = $missing === [] ? '' : ' '.implode('; ', $missing).'.';

            return 'No stop could be placed on the map, so the itinerary was left as it was.'.$reasons.' Call save_itinerary again with each place written as "name, city, country", and pick somewhere the map knows if a place cannot be found.';
        }

        $plan = $this->store($stops);

        // Whatever could not be placed rides inside the JSON rather than after
        // it: the browser reads this result as a map view, and a sentence
        // appended to the encoded object would make the whole thing unparseable
        // and leave the map where it was.
        return json_encode(array_filter([
            'label' => $plan['location'] ?: 'Your itinerary',
            'bbox' => $this->bbox($stops),
            'stops' => $stops,
            'unplaced' => $missing,
        ]), JSON_THROW_ON_ERROR);
    }

    /**
     * Decide which located stops actually make it onto the map.
     *
     * Two things are refused here, and both were real failures:
     *
     * - **Stops on a point already used.** Unnamed places give the model
     *   nothing to tell one from another, and it answers with the same string
     *   several times. Three stops on one coordinate draw as a single pin with
     *   the others hidden underneath and a route with no length, so the day
     *   looks broken rather than wrong.
     * - **Stops outside the plan's location** -- but only when most of the day
     *   is inside it. One stop in the wrong region is a geocoding accident and
     *   stretches the box over everything; a whole day somewhere else is a
     *   decision the visitor is allowed to make, and refusing it left them
     *   unable to plan a day trip at all.
     *
     * @param  list<array{place: string, point: array{lat: float, lon: float}, stop: array<string, mixed>}>  $found
     * @param  list<string>  $missing
     * @return list<array<string, mixed>>
     */
    protected function keep(array $found, array &$missing): array
    {
        $location = trim((string) ($this->state->plan['location'] ?? ''));
        $area = $this->area($location);

        $inside = count(array_filter($found, fn (array $entry): bool => $this->within($area ?? [], $entry['point'])));

        // Half the day or more inside means the outliers are the mistakes. A
        // day that has left the area altogether is allowed -- but only if it
        // went somewhere, together: stops strewn across three continents are
        // bad coordinates wearing a day trip's clothes.
        $enforce = $area !== null
            && ($inside * 2 >= count($found) || ! $this->coherent($found));

        $stops = [];
        $taken = [];

        foreach ($found as $entry) {
            $key = $entry['point']['lat'].','.$entry['point']['lon'];

            if (isset($taken[$key])) {
                $missing[] = "{$entry['place']} (the same point as \"{$taken[$key]}\", so it was left out)";

                continue;
            }

            if ($enforce && ! $this->within($area, $entry['point'])) {
                $missing[] = "{$entry['place']} (found, but too far from {$location})";

                continue;
            }

            $taken[$key] = $entry['stop']['title'];
            $stops[] = $entry['stop'];
        }

        return $stops;
    }

    /**
     * Could these stops be one day out, wherever they are?
     *
     * A day trip stays within a couple of hours' travel, so the stops sit
     * close together whether or not they sit where the plan said.
     *
     * @param  list<array{place: string, point: array{lat: float, lon: float}, stop: array<string, mixed>}>  $found
     */
    protected function coherent(array $found): bool
    {
        $latitudes = array_column(array_column($found, 'point'), 'lat');
        $longitudes = array_column(array_column($found, 'point'), 'lon');

        return max($latitudes) - min($latitudes) <= self::DAY
            && max($longitudes) - min($longitudes) <= self::DAY;
    }

    /**
     * The box a stop is expected to fall inside, from the plan's location.
     *
     * Null when the plan names no location, or the geocoder cannot place it:
     * there is then nothing to check against, and a stop is better shown in
     * the wrong place than silently dropped.
     *
     * @return array{float, float, float, float}|null west, south, east, north
     */
    protected function area(string $location): ?array
    {
        if ($location === '') {
            return null;
        }

        $view = json_decode((string) (new ShowOnMap)->handle(new Request(['place' => $location])), true);

        if (! is_array($view) || ! isset($view['bbox'])) {
            return null;
        }

        [$west, $south, $east, $north] = array_map(floatval(...), $view['bbox']);

        $horizontal = max(($east - $west) * self::MARGIN, self::MIN_MARGIN);
        $vertical = max(($north - $south) * self::MARGIN, self::MIN_MARGIN);

        return [
            $west - $horizontal,
            $south - $vertical,
            $east + $horizontal,
            $north + $vertical,
        ];
    }

    /**
     * Is a located stop inside the expected area?
     *
     * @param  array{float, float, float, float}|array{}  $area
     * @param  array{lat: float, lon: float}  $located
     */
    protected function within(array $area, array $located): bool
    {
        if ($area === []) {
            return false;
        }

        [$west, $south, $east, $north] = $area;

        return $located['lon'] >= $west
            && $located['lon'] <= $east
            && $located['lat'] >= $south
            && $located['lat'] <= $north;
    }

    /**
     * Find where a stop actually is.
     *
     * The geocoder first, through ShowOnMap, so there stays one place where a
     * free-text name becomes coordinates and its day-long cache covers the
     * repeats an itinerary is full of.
     *
     * Coordinates the model passed are the fallback, and only that. Plenty of
     * real places have no name in OpenStreetMap -- unnamed viewpoints are the
     * common case -- and no string identifies them to a geocoder, so without
     * this the model has nothing to offer but the name of the area, and a day
     * of lookouts collapses onto the middle of the mountain. What it passes
     * here is copied from a find_places result rather than invented, and it
     * still has to survive the area check in `keep()`, which is what stops an
     * invented pair from landing anywhere.
     *
     * @param  array<string, mixed>  $stop
     * @return array{lat: float, lon: float}|null
     */
    protected function locate(string $place, array $stop): ?array
    {
        // Server-issued coordinates win when they are offered. A label such as
        // "Viewpoint 1" is not unfindable, it is worse: Nominatim may match a
        // different place of that name, scattering one day across continents.
        if ($given = $this->given($place, $stop)) {
            return $given;
        }

        $view = json_decode((string) (new ShowOnMap)->handle(new Request(['place' => $place])), true);

        if (is_array($view) && isset($view['marker'])) {
            [$latitude, $longitude] = $view['marker'];

            return ['lat' => (float) $latitude, 'lon' => (float) $longitude];
        }

        return null;
    }

    /**
     * Coordinates previously returned by find_places for this conversation.
     *
     * @param  array<string, mixed>  $stop
     * @return array{lat: float, lon: float}|null
     */
    protected function given(string $place, array $stop): ?array
    {
        if (! isset($stop['lat'], $stop['lon'])) {
            return null;
        }

        $latitude = (float) $stop['lat'];
        $longitude = (float) $stop['lon'];

        $validPoint = abs($latitude) <= 90
            && abs($longitude) <= 180
            && ($latitude !== 0.0 || $longitude !== 0.0);

        if (! $validPoint || ! FindPlaces::issuedMarker((string) $this->state->getKey(), $place, $latitude, $longitude)) {
            return null;
        }

        return ['lat' => $latitude, 'lon' => $longitude];
    }

    /**
     * Write the stops into the plan without disturbing the rest of it.
     *
     * save_map_ready_plan always writes a complete plan, so the merge has to
     * happen here; a whole-plan write from this tool would drop the goal.
     *
     * @param  array<int, array<string, mixed>>  $stops
     * @return array{goal: string, location: string, details: array<string, mixed>, stops: array<int, array<string, mixed>>}
     */
    protected function store(array $stops): array
    {
        $plan = ($this->state->plan ?? []) + ['goal' => '', 'location' => '', 'details' => []];
        $plan['stops'] = $stops;

        $this->state->update(['plan' => $plan]);

        return $plan;
    }

    /**
     * The box that frames every stop.
     *
     * @param  array<int, array<string, mixed>>  $stops
     * @return array{string, string, string, string}
     */
    protected function bbox(array $stops): array
    {
        $latitudes = array_column($stops, 'lat');
        $longitudes = array_column($stops, 'lon');

        return [
            (string) (min($longitudes) - self::SPAN),
            (string) (min($latitudes) - self::SPAN),
            (string) (max($longitudes) + self::SPAN),
            (string) (max($latitudes) + self::SPAN),
        ];
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'stops' => $schema->array()->items($schema->object([
                'title' => $schema->string()
                    ->description('What the visitor is doing here, in a few words, e.g. "Coffee and pastries".')
                    ->required(),
                'place' => $schema->string()
                    ->description('The stop as a map can find it, e.g. "Majestic Café, Porto, Portugal".')
                    ->required(),
                'time' => $schema->string()
                    ->description('When it happens, as "HH:MM", if the day has times.'),
                'note' => $schema->string()
                    ->description('One short line on why this stop suits them, if it needs saying.'),
                'lat' => $schema->number()
                    ->description('Only for a place with no findable name, such as an unnamed viewpoint: copy the marker\'s lat from the find_places result. Leave out otherwise.'),
                'lon' => $schema->number()
                    ->description('The marker\'s lon from the same find_places result. Leave out unless lat is given.'),
            ]))->required(),
        ];
    }
}
