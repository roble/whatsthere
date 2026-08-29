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

/** What the ShowOnMap tool hands back, once parsed. */
export type MapView = {
    label: string;
    bbox: [string, string, string, string];
    marker?: [string, string];
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
    return `${view.label}|${view.bbox.join(',')}`;
}
