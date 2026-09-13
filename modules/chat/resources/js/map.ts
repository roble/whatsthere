/**
 * Basemaps OpenFreeMap serves, all keyless and unmetered.
 *
 * `auto` follows the app's own light/dark setting; anything else pins the map
 * regardless of the interface around it.
 */
export const MAP_STYLES = [
    { id: 'auto', label: 'Match interface', dark: null },
    { id: 'fiord', label: 'Fiord', dark: true },
    { id: 'dark', label: 'Dark', dark: true },
    { id: 'positron', label: 'Positron', dark: false },
    { id: 'liberty', label: 'Liberty', dark: false },
    { id: 'bright', label: 'Bright', dark: false },
] as const;

export type MapStyleId = (typeof MAP_STYLES)[number]['id'];

/** Resolve a preference plus the current interface theme to a style URL. */
export function styleUrlFor(preference: MapStyleId, isDark: boolean): string {
    const style =
        preference === 'auto' ? (isDark ? 'fiord' : 'positron') : preference;

    return `https://tiles.openfreemap.org/styles/${style}`;
}

/** One of many results, as FindPlaces hands them back. */
export type MapMarker = {
    id?: number;
    asking_price?: number;
    currency?: string;
    bedrooms?: number | null;
    bathrooms?: number | null;
    floor_area_sqm?: number | null;
    plot_area_sqm?: number | null;
    size_label?: string | null;
    price_per_sqm?: number | null;
    distance_m?: number;
    ber_rating?: string | null;
    property_type?: string;
    url?: string | null;
    agent?: string | null;
    images?: string[];
    highlight?: 'value' | 'match' | 'typical' | 'premium';
    lat: number;
    lon: number;
    name: string;
    /** Set when several searches share one map, so each pin keeps its symbol. */
    categoryKey?: string;
    /** Whatever OpenStreetMap knew that a visitor can act on. Keys from FindPlaces::DETAIL_TAGS. */
    details?: Partial<
        Record<
            | 'address'
            | 'hours'
            | 'website'
            | 'phone'
            | 'cuisine'
            | 'wheelchair'
            | 'outdoor_seating'
            | 'internet_access'
            | 'description',
            string
        >
    >;
};

/** One stop of the visitor's day, as SaveItinerary hands it back. */
export type ItineraryStop = {
    lat: number;
    lon: number;
    /** What the visitor is doing here. */
    title: string;
    /** The stop as the geocoder found it. */
    place: string;
    /** "HH:MM", when the day has times. */
    time?: string;
    note?: string;
};

/**
 * A named GeoJSON overlay supplied with a map result. Boundaries remain map
 * data, rather than being baked into a particular property-search screen, so
 * school catchments, flood zones and price areas can share the same contract.
 */
export type MapBoundaryLayer = {
    id: string;
    source: {
        type: 'geojson';
        data: GeoJSON.FeatureCollection;
    };
    layer: Record<string, unknown>;
};

/** What a map tool hands back, once parsed. */
export type MapView = {
    total?: number;
    label: string;
    bbox: [string, string, string, string];
    /** A single located place, from ShowOnMap. */
    marker?: [string, string];
    /** Everything of one kind in an area, from FindPlaces. */
    markers?: MapMarker[];
    /** Stable FindPlaces category used to select a map symbol. */
    categoryKey?: string;
    /** What was searched for, already pluralised by the tool. */
    category?: string;
    /** The visitor's day in order, from SaveItinerary. */
    stops?: ItineraryStop[];
    /** Optional map-analysis boundaries, such as catchments or flood zones. */
    boundaries?: MapBoundaryLayer[];
};

/** Where the map actually sits right now, which the visitor may have panned. */
export type MapViewport = {
    label: string;
    center: [number, number];
    bounds: [number, number, number, number];
    zoom: number;
    moved: boolean;
    interacted: boolean;
};

/**
 * Identity for a view.
 *
 * The view is re-derived from the transcript on every streamed token, so the
 * object reference changes constantly while the place is nothing new. Comparing
 * this instead means the camera only moves when the place actually differs.
 */
export function viewKey(view: MapView): string {
    // The pins are part of the identity, not decoration. Two searches of the
    // same town share a bounding box and often a count, so without them
    // "cafes in Shibuya" searched twice would leave the first set on the map.
    const pins = (view.markers ?? [])
        .map(
            (marker) =>
                `${marker.id ?? ''}:${marker.name}@${marker.lat},${marker.lon}:${marker.asking_price ?? ''}`,
        )
        .join(';');

    // Stops belong to the identity for the same reason: reordering a day, or
    // swapping one stop for another nearby, leaves the label and the box alone.
    const stops = (view.stops ?? [])
        .map((stop) => JSON.stringify(stop))
        .join(';');

    const boundaries = (view.boundaries ?? [])
        .map((boundary) => JSON.stringify(boundary))
        .join(';');

    return `${view.label}|${view.bbox.join(',')}|${pins}|${stops}|${boundaries}`;
}

/**
 * The tools whose results move the map.
 *
 * Mirrors `ChatController::MAP_TOOLS`. Streamed parts are named `tool-<name>`,
 * so these are the bare names and the `tool-` prefix is added where matched.
 */
export const MAP_TOOLS = [
    'search_properties',
    'update_property_search_preferences',
    'compare_listing_amenities',
    'show_on_map',
    'find_places',
    'save_itinerary',
] as const;

/**
 * Read a map view out of a tool result.
 *
 * The map tools answer in prose when they cannot place somewhere, so anything
 * that is not a well-formed view means "leave the map alone".
 */
export function toMapView(output: unknown): MapView | null {
    try {
        const parsed = JSON.parse(String(output));

        return Array.isArray(parsed?.bbox) && parsed.bbox.length === 4
            ? (parsed as MapView)
            : null;
    } catch {
        return null;
    }
}

/**
 * One view for everything a single reply put on the map.
 *
 * The assistant often searches twice in one turn ("restaurants" then
 * "museums") and finishes by placing the town itself. Taking the last result
 * left one pin where twenty belonged. Any searches in the reply win over a
 * plain placement; their pins are pooled, and the box grows to hold them all.
 */
export function mergeViews(views: MapView[]): MapView | null {
    const propertySearches = views.filter(
        (view) => view.categoryKey === 'property',
    );
    if (propertySearches.length) {
        return propertySearches.at(-1) ?? null;
    }
    // An itinerary is the deliberate answer of the whole reply, not one search
    // among several, so it wins outright over the lookups that fed it.
    const itineraries = views.filter((view) => view.stops?.length);

    if (itineraries.length) {
        return itineraries.at(-1) ?? null;
    }

    const searches = views.filter((view) => view.markers?.length);

    if (searches.length === 0) {
        return views.at(-1) ?? null;
    }

    if (searches.length === 1) {
        return searches[0];
    }

    const markers = searches.flatMap((view) =>
        (view.markers ?? []).map((marker) => ({
            ...marker,
            categoryKey: marker.categoryKey ?? view.categoryKey,
        })),
    );

    const lons = searches.flatMap((view) => [+view.bbox[0], +view.bbox[2]]);
    const lats = searches.flatMap((view) => [+view.bbox[1], +view.bbox[3]]);

    const area = searches[0].label.split(' in ').slice(1).join(' in ');
    const categories = searches
        .map((view) => view.category ?? view.label)
        .join(' and ');

    return {
        label: area ? `${categories} in ${area}` : categories,
        category: categories,
        bbox: [
            String(Math.min(...lons)),
            String(Math.min(...lats)),
            String(Math.max(...lons)),
            String(Math.max(...lats)),
        ],
        markers,
    };
}

/**
 * The map view for an itinerary already saved earlier in the conversation.
 *
 * Rebuilt from the plan rather than kept around, because the transcript view is
 * derived from the last reply and the visitor may have searched for other
 * things since. Mirrors what `SaveItinerary` returns.
 */
export function itineraryView(
    plan: { location?: string; stops?: ItineraryStop[] } | null,
): MapView | null {
    const stops = plan?.stops ?? [];

    if (!stops.length) {
        return null;
    }

    const lats = stops.map((stop) => stop.lat);
    const lons = stops.map((stop) => stop.lon);

    // One stop has no extent, and a zero-size box gives the camera nothing to
    // fit. Matches SaveItinerary::SPAN.
    const span = 0.005;

    return {
        label: plan?.location || 'Your itinerary',
        bbox: [
            String(Math.min(...lons) - span),
            String(Math.min(...lats) - span),
            String(Math.max(...lons) + span),
            String(Math.max(...lats) + span),
        ],
        stops,
    };
}

/**
 * How far the drawn route runs, in kilometres.
 *
 * Great-circle distance between consecutive stops, summed. This measures the
 * dashed line the map actually draws, not a walked path -- the same promise
 * `routeData` makes. A routing service would give real walking distance and a
 * different number.
 */
export function routeDistanceKm(stops: ItineraryStop[]): number {
    const radians = (degrees: number) => (degrees * Math.PI) / 180;
    const earthRadiusKm = 6371;

    return stops.slice(1).reduce((total, stop, index) => {
        const previous = stops[index];

        const deltaLat = radians(stop.lat - previous.lat);
        const deltaLon = radians(stop.lon - previous.lon);

        const a =
            Math.sin(deltaLat / 2) ** 2 +
            Math.cos(radians(previous.lat)) *
                Math.cos(radians(stop.lat)) *
                Math.sin(deltaLon / 2) ** 2;

        return total + 2 * earthRadiusKm * Math.asin(Math.sqrt(a));
    }, 0);
}
