<?php

namespace Modules\Properties;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The box on the map that a place name refers to.
 *
 * Listings carry a `town`, but that is whatever the agent typed for the
 * immediate locality -- Glasheen, Montenotte, Rochestown -- so a city is
 * spread across dozens of them and matching the column by name can never
 * gather them up. Every property we hold is geocoded, so the honest question
 * is "which of these are inside the place" rather than "which of these are
 * labelled with its name".
 *
 * Deliberately a second geocoding path rather than a call into the chat
 * module's ShowOnMap: modules depend one way here, chat on properties, and a
 * search that cannot run without the chat module would invert that. The two
 * also ask different questions -- where to point the camera, versus what area
 * to search -- and only this one has to fail quietly.
 *
 * A miss is never cached. A geocoder that was briefly unreachable must not pin
 * a place to "nowhere" for a month; the name match still answers in the
 * meantime, so the cost of retrying is a slower search rather than a wrong one.
 */
class PlaceBounds
{
    /**
     * How long a resolved box is kept. Towns do not move.
     */
    protected const int CACHE_DAYS = 30;

    /**
     * Kept short on purpose. This sits in front of a plain database filter that
     * is otherwise instant, so a slow geocoder must degrade the search to the
     * name match rather than make the visitor wait for it.
     */
    protected const int TIMEOUT_SECONDS = 5;

    /**
     * Resolve a place to the box it covers.
     *
     * @return array{float, float, float, float}|null South, north, west, east.
     */
    public function for(string $place, ?string $within = null): ?array
    {
        $place = trim($place);

        if ($place === '') {
            return null;
        }

        // The county disambiguates: Blackrock is a suburb of Cork and also of
        // Dublin, and the geocoder answers with whichever it thinks is more
        // famous unless told which one is meant.
        $query = $within !== null && mb_strtolower(trim($within)) !== mb_strtolower($place)
            ? $place.', '.trim($within)
            : $place;

        $key = 'property-bounds:v1:'.md5(mb_strtolower($query));

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $bounds = $this->lookUp($query);

        if ($bounds !== null) {
            Cache::put($key, $bounds, now()->addDays(self::CACHE_DAYS));
        }

        return $bounds;
    }

    /**
     * @return array{float, float, float, float}|null
     */
    protected function lookUp(string $query): ?array
    {
        $response = Http::withUserAgent(config('app.name').' ('.config('app.url').')')
            ->timeout(self::TIMEOUT_SECONDS)
            ->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query,
                'format' => 'json',
                'limit' => 1,
            ]);

        if ($response->failed()) {
            return null;
        }

        // Nominatim orders a bounding box south, north, west, east -- not the
        // west, south, east, north that Overpass and GeoJSON use. Reading it in
        // the wrong order still returns a box, just of somewhere in the sea.
        $box = $response->json('0.boundingbox');

        if (! is_array($box) || count($box) !== 4) {
            return null;
        }

        [$south, $north, $west, $east] = array_map(floatval(...), $box);

        return $south < $north && $west < $east
            ? [$south, $north, $west, $east]
            : null;
    }
}
