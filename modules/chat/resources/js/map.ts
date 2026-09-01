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
    lat: number;
    lon: number;
    name: string;
};

/** What a map tool hands back, once parsed. */
export type MapView = {
    label: string;
    bbox: [string, string, string, string];
    /** A single located place, from ShowOnMap or an Eircode lookup. */
    marker?: [string, string];
    /** Everything of one kind in an area, from FindPlaces. */
    markers?: MapMarker[];
    /** What was searched for, already pluralised by the tool. */
    category?: string;
    /** Set when there were more results than the tool was willing to show. */
    capped?: boolean;
};

/** Where the map actually sits right now, which the visitor may have panned. */
export type MapViewport = {
    label: string;
    center: [number, number];
    zoom: number;
    moved: boolean;
};

/**
 * Identity for a view.
 *
 * The view is re-derived from the transcript on every streamed token, so the
 * object reference changes constantly while the place is nothing new. Comparing
 * this instead means the camera only moves when the place actually differs.
 */
export function viewKey(view: MapView): string {
    // The marker count is part of the identity, not decoration. Two searches
    // of the same town share a bounding box, so without it "cafes in Galway"
    // after "pubs in Galway" would leave the first set of pins on the map.
    return `${view.label}|${view.bbox.join(',')}|${view.markers?.length ?? 0}`;
}

/**
 * The tools whose results move the map.
 *
 * Mirrors `ChatController::MAP_TOOLS`. Streamed parts are named `tool-<name>`,
 * so these are the bare names and the `tool-` prefix is added where matched.
 */
export const MAP_TOOLS = [
    'show_on_map',
    'eircode_to_geolocation',
    'find_places',
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
