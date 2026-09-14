<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    VControlNavigation,
    VLayerMaplibreGeojson,
    VMap,
} from '@geoql/v-maplibre';
import { trans } from 'laravel-vue-i18n';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    MAP_STYLES,
    propertyFacts,
    styleUrlFor,
    viewKey,
    type ItineraryStop,
    type MapMarker,
    type MapStyleId,
    type MapView,
    type MapViewport,
    routeDistanceKm,
} from '@modules/chat/resources/js/map';
import {
    useMutationObserver,
    useResizeObserver,
    useStorage,
} from '@vueuse/core';
import IconBuilding from '~icons/lucide/building-2';
import IconPalette from '~icons/lucide/palette';
import IconRoute from '~icons/lucide/route';
import IconScan from '~icons/lucide/scan';
import IconAtm from '~icons/maki/bank';
import IconBeach from '~icons/maki/beach';
import IconPub from '~icons/maki/beer';
import IconBusStation from '~icons/maki/bus';
import IconCafe from '~icons/maki/cafe';
import IconCampSite from '~icons/maki/campsite';
import IconCastle from '~icons/maki/castle';
import IconCinema from '~icons/maki/cinema';
import IconFuel from '~icons/maki/fuel';
import IconGolfCourse from '~icons/maki/golf';
import IconSupermarket from '~icons/maki/grocery';
import IconHospital from '~icons/maki/hospital';
import IconHotel from '~icons/maki/lodging';
import IconMarker from '~icons/maki/marker';
import IconRuins from '~icons/maki/monument';
import IconMuseum from '~icons/maki/museum';
import IconPark from '~icons/maki/park';
import IconCarPark from '~icons/maki/parking';
import IconPharmacy from '~icons/maki/pharmacy';
import IconChurch from '~icons/maki/place-of-worship';
import IconPlayground from '~icons/maki/playground';
import IconTrainStation from '~icons/maki/rail';
import IconRestaurant from '~icons/maki/restaurant';
import IconLibrary from '~icons/maki/library';
import IconSchool from '~icons/lucide/graduation-cap';
import IconToilets from '~icons/maki/toilet';
import IconViewpoint from '~icons/maki/viewpoint';
import {
    Map as MapLibreMap,
    Marker,
    Popup,
    setWorkerUrl,
    type GeoJSONSource,
    type GeoJSONSourceSpecification,
    type LayerSpecification,
    type LngLatBoundsLike,
    type MapOptions,
    type Offset,
} from 'maplibre-gl';
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import 'maplibre-gl/dist/maplibre-gl.css';
import {
    computed,
    h,
    onBeforeUnmount,
    ref,
    render,
    shallowRef,
    watch,
    type Component,
} from 'vue';

/**
 * Tell MapLibre where its worker actually landed.
 *
 * Left alone it resolves the worker from its own `import.meta.url`, which in a
 * production build is whichever chunk it was bundled into -- here
 * `assets/chat/Index-*.js`, so it asks for `assets/chat/maplibre-gl-worker.mjs`
 * and gets a 404. Nothing throws: the map draws its background, controls and
 * markers, and simply never renders a tile.
 *
 * `?worker&url` rather than `?url`: the shipped worker is not self-contained,
 * it imports `./maplibre-gl-shared.mjs`. Copying the one file as an asset
 * leaves that import dangling and the worker dies on load, just as silently.
 * `?worker` makes Vite bundle the worker with its dependencies and hand back
 * the URL of the result.
 */
setWorkerUrl(maplibreWorkerUrl);

const props = defineProps<{ view: MapView }>();

const emit = defineEmits<{
    viewport: [MapViewport];
    selectProperty: [MapMarker];
    openProperty: [MapMarker];
}>();

/**
 * The badge over the map: how many stops the day has and how far it runs.
 *
 * Only for a real day. One stop has no route to measure, and a search result
 * is not an itinerary at all.
 */
const routeSummary = computed(() => {
    const stops = props.view.stops ?? [];

    if (stops.length < 2) {
        return null;
    }

    const km = routeDistanceKm(stops);

    return {
        stops: stops.length,
        distance: km < 10 ? km.toFixed(1) : String(Math.round(km)),
    };
});

// OpenFreeMap serves OpenStreetMap vector tiles with no key, no registration
// and no usage limits, so nothing here needs credentials or a quota alarm.
const stylePreference = useStorage<MapStyleId>('chat.map-style', 'auto');

const styleUrl = (dark: boolean) => styleUrlFor(stylePreference.value, dark);

const show3d = useStorage('chat.map-3d', false);

/** Shared with the zoom controls MapLibre renders on the opposite corner. */
const controlClass =
    'size-7.25 rounded shadow-[0_0_0_2px_rgba(0,0,0,0.1)] bg-white text-neutral-800 hover:bg-neutral-100';

const BUILDINGS_LAYER = 'buildings-3d';

const ROUTE_SOURCE = 'itinerary-route';
const ROUTE_LAYER = 'itinerary-route-line';

/**
 * The interface's own primary, as a hex.
 *
 * MapLibre parses colours itself and does not understand `oklch()` or CSS
 * variables, so the token in `theme.css` cannot be handed to a paint property.
 * It is the same value in light and dark, so one constant covers both.
 */
const ROUTE_COLOR = '#6754C4';

const MAP_CONTAINER_ID = 'context-map-container';
const container = ref<HTMLDivElement | null>(null);

const mapOptions = computed(
    () =>
        ({
            container: MAP_CONTAINER_ID,
            style: styleUrl(isDark.value),
            bounds: boundsOf(props.view),
            fitBoundsOptions: { padding: 48, maxZoom: 16 },
            attributionControl: { compact: true },
        }) as MapOptions,
);

// shallowRef: the map is a large non-reactive object, and letting Vue walk it
// deeply would be pure overhead.
const map = shallowRef<MapLibreMap | null>(null);
const markers = shallowRef<Marker[]>([]);
const propertyPins = new Map<number, { marker: Marker; popup: Popup }>();
const selectedPropertyId = ref<number | null>(null);
const highlightedPropertyId = ref<number | null>(null);
const canReturnToSearch = ref(false);
const hasUserNavigated = ref(false);

type PlaceMarkerStyle = { icon: Component; color: string };

/**
 * Maki symbols plus distinct backgrounds for every supported place category.
 * Every colour has at least 4.5:1 contrast against the white icon.
 */
const PLACE_MARKER_STYLES: Record<string, PlaceMarkerStyle> = {
    pub: { icon: IconPub, color: '#92400e' },
    restaurant: { icon: IconRestaurant, color: '#b91c1c' },
    cafe: { icon: IconCafe, color: '#7c2d12' },
    hotel: { icon: IconHotel, color: '#4338ca' },
    castle: { icon: IconCastle, color: '#475569' },
    ruins: { icon: IconRuins, color: '#57534e' },
    museum: { icon: IconMuseum, color: '#7e22ce' },
    viewpoint: { icon: IconViewpoint, color: '#1d4ed8' },
    beach: { icon: IconBeach, color: '#0f766e' },
    park: { icon: IconPark, color: '#15803d' },
    playground: { icon: IconPlayground, color: '#be185d' },
    golf_course: { icon: IconGolfCourse, color: '#047857' },
    camp_site: { icon: IconCampSite, color: '#3f6212' },
    church: { icon: IconChurch, color: '#6d28d9' },
    library: { icon: IconLibrary, color: '#3730a3' },
    school: { icon: IconSchool, color: '#1d4ed8' },
    cinema: { icon: IconCinema, color: '#9f1239' },
    pharmacy: { icon: IconPharmacy, color: '#be123c' },
    hospital: { icon: IconHospital, color: '#991b1b' },
    supermarket: { icon: IconSupermarket, color: '#0369a1' },
    fuel: { icon: IconFuel, color: '#334155' },
    atm: { icon: IconAtm, color: '#1e40af' },
    car_park: { icon: IconCarPark, color: '#4b5563' },
    train_station: { icon: IconTrainStation, color: '#6b21a8' },
    bus_station: { icon: IconBusStation, color: '#0e7490' },
    toilets: { icon: IconToilets, color: '#374151' },
};

const FALLBACK_MARKER_STYLE: PlaceMarkerStyle = {
    icon: IconMarker,
    color: '#0f766e',
};

/**
 * A pin rises 42px from its coordinate, but does not extend below it.
 * Downward popups therefore need only a small gap instead of that full lift.
 */
const PLACE_POPUP_OFFSET: Offset = {
    center: [0, -20],
    top: [0, 4],
    'top-left': [0, 4],
    'top-right': [0, 4],
    bottom: [0, -42],
    'bottom-left': [0, -42],
    'bottom-right': [0, -42],
    left: [26, -20],
    right: [-26, -20],
};

const isDark = ref(readTheme());

/**
 * Read the rendered theme rather than the stored preference.
 *
 * `appearance` may be "auto", and the app already owns the writer side in
 * lib/navigation.ts. The `dark` class is the resolved truth either way.
 */
function readTheme(): boolean {
    return (
        typeof document !== 'undefined' &&
        document.documentElement.classList.contains('dark')
    );
}

function boundsOf(view: MapView): LngLatBoundsLike {
    const [west, south, east, north] = view.bbox.map(Number);

    return [
        [west, south],
        [east, north],
    ];
}

/** Fit searches to what was found, not the often much larger searched area. */
function boundsOfMarkers(view: MapView): LngLatBoundsLike {
    const places = view.markers;

    if (!places?.length) {
        return boundsOf(view);
    }

    const longitudes = places.map((place) => place.lon);
    const latitudes = places.map((place) => place.lat);

    return [
        [Math.min(...longitudes), Math.min(...latitudes)],
        [Math.max(...longitudes), Math.max(...latitudes)],
    ];
}

/** Restore the camera framing that belongs to the current search result set. */
function fitView(view: MapView, animate: boolean): void {
    map.value?.fitBounds(boundsOfMarkers(view), {
        // Keep the outer pins clear of the controls and viewport edge while
        // still letting the results occupy most of the map.
        padding: 48,
        animate,
        // A single address geocodes to a pinpoint bbox, which would otherwise
        // slam the camera to maximum zoom.
        maxZoom: 16,
    });
}

/** Build an upright Maki symbol inside a compact, branded map pin. */
function placeMarkerElement(
    categoryKey: string | undefined,
    name: string,
    askingPrice?: number,
    currency?: string,
): HTMLElement {
    const element = document.createElement('div');
    const markerStyle =
        PLACE_MARKER_STYLES[categoryKey ?? ''] ?? FALLBACK_MARKER_STYLE;

    element.setAttribute('role', 'button');
    element.setAttribute('aria-label', name);
    element.style.setProperty('--marker-color', markerStyle.color);
    element.dataset.markerColor = markerStyle.color;
    const isProperty = categoryKey === 'property' && askingPrice !== undefined;
    element.className = isProperty
        ? "relative grid min-w-14 cursor-pointer place-items-center rounded-full border-2 border-white px-2 py-1 text-xs font-bold text-white shadow-lg ring-1 ring-black/20 transition-[filter] [background-color:var(--marker-color)] after:absolute after:-bottom-1 after:left-1/2 after:size-2 after:-translate-x-1/2 after:rotate-45 after:border-r-2 after:border-b-2 after:border-white after:[background-color:var(--marker-color)] after:content-[''] hover:brightness-110"
        : "relative grid size-9 cursor-pointer place-items-center rounded-full border-2 border-white text-white shadow-lg ring-1 ring-black/20 transition-[filter] [background-color:var(--marker-color)] after:absolute after:-bottom-1 after:left-1/2 after:size-2 after:-translate-x-1/2 after:rotate-45 after:border-r-2 after:border-b-2 after:border-white after:[background-color:var(--marker-color)] after:content-[''] hover:brightness-110";

    if (isProperty) {
        element.textContent = new Intl.NumberFormat('en-IE', {
            style: 'currency',
            currency: currency ?? 'EUR',
            notation: 'compact',
            maximumFractionDigits: 0,
        }).format(askingPrice / 100);
    } else {
        render(
            h(markerStyle.icon, {
                class: 'relative z-10 size-4.5',
                'aria-hidden': 'true',
            }),
            element,
        );
    }

    return element;
}

/**
 * A numbered pin for one stop of the itinerary.
 *
 * Deliberately the same shape as a place pin but numbered and in the interface
 * primary, so a stop on the visitor's day reads as a different kind of thing
 * from a search result without being a different kind of object on the map.
 */
function stopMarkerElement(index: number, stop: ItineraryStop): HTMLElement {
    const element = document.createElement('div');

    element.setAttribute('role', 'button');
    element.setAttribute('aria-label', `${index + 1}. ${stop.title}`);
    element.style.setProperty('--marker-color', ROUTE_COLOR);
    element.className =
        "relative grid size-9 cursor-pointer place-items-center rounded-full border-2 border-white text-sm font-bold text-white shadow-lg ring-1 ring-black/20 transition-[filter] [background-color:var(--marker-color)] after:absolute after:-bottom-1 after:left-1/2 after:size-2 after:-translate-x-1/2 after:rotate-45 after:border-r-2 after:border-b-2 after:border-white after:[background-color:var(--marker-color)] after:content-[''] hover:brightness-110";

    const label = document.createElement('span');
    label.className = 'relative z-10';
    label.textContent = String(index + 1);
    element.append(label);

    return element;
}

/** The popup for one stop: what it is, when, and why. */
function stopPopupContent(stop: ItineraryStop): HTMLElement {
    const root = document.createElement('div');
    root.className = 'space-y-1 text-sm';

    const title = document.createElement('div');
    title.className = 'font-semibold';
    title.textContent = stop.time ? `${stop.time} · ${stop.title}` : stop.title;
    root.append(title);

    for (const line of [stop.place, stop.note]) {
        if (!line) {
            continue;
        }

        const row = document.createElement('div');
        row.className = 'text-muted-foreground';
        row.textContent = line;
        root.append(row);
    }

    return root;
}

function formatAskingPrice(place: MapMarker): string {
    return new Intl.NumberFormat('en-IE', {
        style: 'currency',
        currency: place.currency ?? 'EUR',
        maximumFractionDigits: 0,
    }).format((place.asking_price ?? 0) / 100);
}

/** A compact, Daft-style preview. Activating it opens the fuller gallery. */
function propertyPopupContent(place: MapMarker): HTMLElement {
    const root = document.createElement('button');
    root.type = 'button';
    root.dataset.testid = `property-preview-${place.id}`;
    root.className =
        'flex w-80 items-center gap-4 bg-white p-4 text-left text-neutral-900';
    root.addEventListener('click', () => emit('openProperty', place));

    if (place.images?.[0]) {
        const image = document.createElement('img');
        image.src = place.images[0];
        image.alt = '';
        image.className = 'size-28 shrink-0 object-cover';
        root.append(image);
    }

    const details = document.createElement('span');
    details.className = 'min-w-0 space-y-1';

    const price = document.createElement('span');
    price.className = 'block text-xl font-semibold';
    price.textContent = formatAskingPrice(place);

    const address = document.createElement('span');
    address.className = 'block text-base leading-snug';
    address.textContent = place.details?.address ?? place.name;

    const facts = document.createElement('span');
    facts.className = 'block text-sm text-neutral-500';
    facts.textContent = propertyFacts(place, true);

    details.append(price, address, facts);
    root.append(details);

    return root;
}

function refreshPropertyPinStyles(): void {
    propertyPins.forEach(({ marker }, propertyId) => {
        const element = marker.getElement();
        const active =
            propertyId === selectedPropertyId.value ||
            propertyId === highlightedPropertyId.value;

        element.style.setProperty(
            '--marker-color',
            active ? ROUTE_COLOR : (element.dataset.markerColor ?? ROUTE_COLOR),
        );
    });
}

/** Move the map to a view, animating only once the first view has landed. */
function showView(view: MapView, animate: boolean): void {
    const instance = map.value;

    if (!instance) {
        return;
    }

    canReturnToSearch.value = false;
    hasUserNavigated.value = false;
    fitView(view, animate);

    dropMarkers();

    const placed: Marker[] = [];

    if (view.marker) {
        const [lat, lng] = view.marker.map(Number);

        placed.push(new Marker().setLngLat([lng, lat]).addTo(instance));
    }

    for (const place of view.markers ?? []) {
        const element = placeMarkerElement(
            place.categoryKey ?? view.categoryKey,
            place.name,
            place.asking_price,
            place.currency,
        );
        if (place.id !== undefined) {
            element.dataset.testid = `property-pin-${place.id}`;
            element.addEventListener('click', () =>
                emit('selectProperty', place),
            );
        }

        const marker = new Marker({
            element,
            anchor: 'bottom',
            offset: [0, -4],
        }).setLngLat([place.lon, place.lat]);
        const isProperty =
            place.id !== undefined &&
            (place.categoryKey ?? view.categoryKey) === 'property';

        if (isProperty && place.id !== undefined) {
            const popup = new Popup({
                offset: PLACE_POPUP_OFFSET,
                focusAfterOpen: false,
                maxWidth: '320px',
                closeButton: false,
            }).setDOMContent(propertyPopupContent(place));

            propertyPins.set(place.id, { marker, popup });
        } else {
            // Built from DOM nodes, never setHTML: every string comes from
            // OpenStreetMap, which anyone can edit, so it is somebody else's input.
            marker.setPopup(
                new Popup({
                    offset: PLACE_POPUP_OFFSET,
                    focusAfterOpen: false,
                    maxWidth: '280px',
                }).setDOMContent(popupContent(place)),
            );
        }

        placed.push(marker.addTo(instance));
    }

    for (const [index, stop] of (view.stops ?? []).entries()) {
        placed.push(
            new Marker({
                element: stopMarkerElement(index, stop),
                anchor: 'bottom',
                offset: [0, -4],
            })
                .setLngLat([stop.lon, stop.lat])
                .setPopup(
                    new Popup({
                        offset: PLACE_POPUP_OFFSET,
                        focusAfterOpen: false,
                        maxWidth: '280px',
                    }).setDOMContent(stopPopupContent(stop)),
                )
                .addTo(instance),
        );
    }

    markers.value = placed;
    drawRoute(instance);
}

/**
 * The dashed line joining the stops in order.
 *
 * Straight segments, not a routed path: this says "then here, then here",
 * which is what the itinerary knows. Real walking directions would need a
 * routing service and a different promise.
 */
function routeData(stops: ItineraryStop[]): GeoJSON.FeatureCollection {
    return {
        type: 'FeatureCollection',
        features:
            stops.length < 2
                ? []
                : [
                      {
                          type: 'Feature',
                          properties: {},
                          geometry: {
                              type: 'LineString',
                              coordinates: stops.map((stop) => [
                                  stop.lon,
                                  stop.lat,
                              ]),
                          },
                      },
                  ],
    };
}

function boundarySource(
    boundary: NonNullable<MapView['boundaries']>[number],
): GeoJSONSourceSpecification {
    return boundary.source;
}

function boundaryLayer(
    boundary: NonNullable<MapView['boundaries']>[number],
): LayerSpecification {
    return {
        ...boundary.layer,
        id: boundary.id,
        source: boundary.id,
    } as LayerSpecification;
}

/**
 * Put the route on the map, adding its source and layer if the style lost them.
 *
 * Like the buildings layer this has to survive `style.load`, which replaces the
 * whole layer list, so it re-adds rather than assuming it is still there.
 */
function drawRoute(instance: MapLibreMap): void {
    const data = routeData(props.view.stops ?? []);
    const source = instance.getSource(ROUTE_SOURCE);

    if (source) {
        (source as GeoJSONSource).setData(data);
    } else {
        instance.addSource(ROUTE_SOURCE, { type: 'geojson', data });
    }

    if (instance.getLayer(ROUTE_LAYER)) {
        return;
    }

    // Under the first label layer, so place names stay readable through it.
    const firstLabel = instance
        .getStyle()
        .layers.find((layer) => layer.type === 'symbol')?.id;

    instance.addLayer(
        {
            id: ROUTE_LAYER,
            type: 'line',
            source: ROUTE_SOURCE,
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: {
                'line-color': ROUTE_COLOR,
                'line-width': 3,
                'line-dasharray': [2, 2],
                'line-opacity': 0.9,
            },
        },
        firstLabel,
    );
}

/**
 * Take every pin off the map.
 *
 * One list covers the single located place and a whole search of them, so
 * there is one removal path rather than a second one to forget.
 */
function dropMarkers(): void {
    propertyPins.forEach(({ popup }) => popup.remove());
    propertyPins.clear();
    selectedPropertyId.value = null;
    highlightedPropertyId.value = null;
    markers.value.forEach((pin) => {
        render(null, pin.getElement());
        pin.remove();
    });
    markers.value = [];
}

/**
 * The popup for one place: its name, then whatever OpenStreetMap knew.
 *
 * Text nodes only. The website becomes a real link because that is the one
 * thing a visitor wants to click, but its href is checked to be http(s) so a
 * `javascript:` value edited into the map data cannot run here.
 */
function popupContent(place: MapMarker): HTMLElement {
    const root = document.createElement('div');
    root.className = 'space-y-1 text-sm';

    const title = document.createElement('div');
    title.className = 'font-semibold';
    title.textContent = place.name;
    root.append(title);

    if (place.asking_price !== undefined) {
        root.dataset.testid = `property-details-${place.id}`;
        const price = document.createElement('p');
        price.className = 'text-primary font-semibold';
        price.textContent =
            new Intl.NumberFormat('en-IE', {
                style: 'currency',
                currency: place.currency ?? 'EUR',
                maximumFractionDigits: 0,
            }).format(place.asking_price / 100) + ' asking price';
        const facts = document.createElement('p');
        facts.textContent = propertyFacts(place);
        root.append(price, facts);
    }

    const details = place.details ?? {};

    const lines: Array<[string, string | undefined]> = [
        ['📍', details.address],
        ['🕒', details.hours],
        ['🍽️', details.cuisine?.replaceAll(';', ', ').replaceAll('_', ' ')],
        [
            '♿',
            details.wheelchair
                ? trans('Wheelchair: :value', { value: details.wheelchair })
                : undefined,
        ],
        ['📶', details.internet_access ? trans('Wi-Fi') : undefined],
        [
            '🪑',
            details.outdoor_seating === 'yes'
                ? trans('Outdoor seating')
                : undefined,
        ],
        ['📞', details.phone],
    ];

    for (const [icon, text] of lines) {
        if (!text) {
            continue;
        }

        const line = document.createElement('div');
        line.className = 'text-muted-foreground flex gap-1.5';
        const glyph = document.createElement('span');
        glyph.setAttribute('aria-hidden', 'true');
        glyph.textContent = icon;
        const body = document.createElement('span');
        body.textContent = text;
        line.append(glyph, body);
        root.append(line);
    }

    if (details.description) {
        const description = document.createElement('p');
        description.className =
            'text-muted-foreground line-clamp-3 pt-1 text-xs';
        description.textContent = details.description;
        root.append(description);
    }

    if (details.website && /^https?:\/\//i.test(details.website)) {
        const link = document.createElement('a');
        link.href = details.website;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.className = 'text-primary block truncate pt-1 underline';
        link.textContent = details.website
            .replace(/^https?:\/\/(www\.)?/i, '')
            .replace(/\/$/, '');
        root.append(link);
    }

    return root;
}

/** Center a search result and open the popup already attached to its pin. */
function focusMarker(place: MapMarker): void {
    const instance = map.value;

    if (!instance) {
        return;
    }

    if (place.id !== undefined) {
        const propertyPin = propertyPins.get(place.id);

        if (propertyPin) {
            selectedPropertyId.value = place.id;
            refreshPropertyPinStyles();
            canReturnToSearch.value = Boolean(props.view.markers?.length);
            propertyPins.forEach(({ popup }) => popup.remove());

            instance.easeTo({
                center: [place.lon, place.lat],
                zoom: Math.max(instance.getZoom(), 15),
                duration: 600,
                essential: true,
            });
            propertyPin.popup.addTo(instance);

            return;
        }
    }

    const selected = markers.value.find((pin) => {
        const position = pin.getLngLat();

        return position.lat === place.lat && position.lng === place.lon;
    });

    if (!selected?.getPopup()) {
        return;
    }

    canReturnToSearch.value = Boolean(props.view.markers?.length);
    markers.value.forEach((pin) => pin.getPopup()?.remove());

    instance.easeTo({
        center: [place.lon, place.lat],
        zoom: Math.max(instance.getZoom(), 15),
        duration: 600,
        essential: true,
    });

    selected.getPopup()?.addTo(instance);
}

function highlightMarker(place: MapMarker | null): void {
    highlightedPropertyId.value = place?.id ?? null;
    refreshPropertyPinStyles();
}

function returnToSearch(): void {
    if (!props.view.markers?.length) {
        return;
    }

    propertyPins.forEach(({ popup }) => popup.remove());
    markers.value.forEach((pin) => pin.getPopup()?.remove());
    canReturnToSearch.value = false;
    fitView(props.view, true);
}

defineExpose({ focusMarker, highlightMarker });

/**
 * Report where the map ended up, so the assistant can answer "what about
 * here?" against what the visitor is actually looking at.
 *
 * `moved` distinguishes the camera the conversation set from one the visitor
 * dragged somewhere else, which is the difference between the label being
 * trustworthy and being stale.
 */
function reportViewport(): void {
    const instance = map.value;

    if (!instance) {
        return;
    }

    const center = instance.getCenter();
    const [west, south, east, north] = props.view.bbox.map(Number);
    const bounds = instance.getBounds();

    emit('viewport', {
        label: props.view.label,
        center: [center.lat, center.lng],
        bounds: [
            bounds.getWest(),
            bounds.getSouth(),
            bounds.getEast(),
            bounds.getNorth(),
        ],
        zoom: Math.round(instance.getZoom() * 10) / 10,
        interacted: hasUserNavigated.value,
        moved:
            center.lng < west ||
            center.lng > east ||
            center.lat < south ||
            center.lat > north,
    });
}

function initializeMap(instance: unknown): void {
    const loadedMap = instance as MapLibreMap;

    map.value = loadedMap;

    // moveend covers both the camera the conversation sets and the visitor
    // dragging it, so one listener keeps the reported viewport honest.
    loadedMap.on('moveend', reportViewport);
    loadedMap.on('movestart', (event) => {
        if (event.originalEvent) {
            hasUserNavigated.value = true;

            if (props.view.markers?.length) {
                canReturnToSearch.value = true;
            }
        }
    });
    loadedMap.on('style.load', () => {
        addBuildings(loadedMap);
        drawRoute(loadedMap);
    });
    addBuildings(loadedMap);
    showView(props.view, false);
}

onBeforeUnmount(() => {
    dropMarkers();
    map.value = null;
});

watch(
    () => viewKey(props.view),
    () => showView(props.view, true),
);

/**
 * Extrude the building footprints OpenFreeMap already ships.
 *
 * Runs on every style.load rather than once on load: swapping basemap replaces
 * the whole layer list, so this has to be re-added each time.
 */
function addBuildings(instance: MapLibreMap): void {
    if (instance.getLayer(BUILDINGS_LAYER)) {
        return;
    }

    // Slip it under the first label layer so place names stay readable.
    const firstLabel = instance
        .getStyle()
        .layers.find((layer) => layer.type === 'symbol')?.id;

    instance.addLayer(
        {
            id: BUILDINGS_LAYER,
            type: 'fill-extrusion',
            source: 'openmaptiles',
            'source-layer': 'building',
            // Tiles stop at z14, so footprints only exist this far in.
            minzoom: 14,
            filter: ['!=', ['get', 'hide_3d'], true],
            layout: { visibility: show3d.value ? 'visible' : 'none' },
            paint: {
                'fill-extrusion-color': isDark.value ? '#5b678a' : '#d4d4d4',
                'fill-extrusion-height': ['get', 'render_height'],
                'fill-extrusion-base': ['get', 'render_min_height'],
                'fill-extrusion-opacity': 0.9,
            },
        },
        firstLabel,
    );
}

watch(show3d, (enabled) => {
    const instance = map.value;

    if (!instance) {
        return;
    }

    if (instance.getLayer(BUILDINGS_LAYER)) {
        instance.setLayoutProperty(
            BUILDINGS_LAYER,
            'visibility',
            enabled ? 'visible' : 'none',
        );
    }

    // Flat buildings seen from directly above are just footprints, so the tilt
    // is what actually makes this read as 3D.
    instance.easeTo({ pitch: enabled ? 55 : 0, duration: 600 });
});

/**
 * MapLibre's own resize tracking listens to the window, so dragging the panel
 * divider or collapsing the sidebar leaves the canvas at its old width and the
 * map renders cut off down one side.
 */
useResizeObserver(container, () => map.value?.resize());

// setStyle keeps the camera and the markers, so switching basemap does not
// throw away the place the conversation put us on.
watch(stylePreference, () => map.value?.setStyle(styleUrl(isDark.value)));

useMutationObserver(
    () => document.documentElement,
    () => {
        const dark = readTheme();

        if (dark === isDark.value) {
            return;
        }

        isDark.value = dark;
        map.value?.setStyle(styleUrl(dark));
    },
    { attributes: true, attributeFilter: ['class'] },
);
</script>

<template>
    <div ref="container" class="relative h-full w-full">
        <VMap
            :options="mapOptions"
            class="h-full w-full"
            :aria-label="view.label"
            data-testid="context-map"
            @loaded="initializeMap"
        >
            <VControlNavigation position="top-right" />
            <VLayerMaplibreGeojson
                v-for="boundary in view.boundaries ?? []"
                :key="boundary.id"
                :source-id="boundary.id"
                :layer-id="boundary.id"
                :source="boundarySource(boundary)"
                :layer="boundaryLayer(boundary)"
            />
        </VMap>

        <!-- Sized and coloured to MapLibre's own controls, so these read as
             part of the same set as the zoom buttons opposite. -->
        <div class="absolute top-2.5 left-2.5 z-10 flex flex-col gap-2">
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="secondary"
                        size="icon"
                        :class="controlClass"
                        :aria-label="$t('Map style')"
                        data-testid="map-style-trigger"
                    >
                        <IconPalette class="size-4" />
                    </Button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="start" class="w-44">
                    <DropdownMenuRadioGroup v-model="stylePreference">
                        <DropdownMenuRadioItem
                            v-for="style in MAP_STYLES"
                            :key="style.id"
                            :value="style.id"
                            :data-testid="`map-style-${style.id}`"
                        >
                            {{ $t(style.label) }}
                        </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                </DropdownMenuContent>
            </DropdownMenu>

            <Button
                variant="secondary"
                size="icon"
                :class="[
                    controlClass,
                    show3d && 'bg-neutral-800 text-white hover:bg-neutral-700',
                ]"
                :aria-label="$t('3D buildings')"
                :aria-pressed="show3d"
                data-testid="map-3d-toggle"
                @click="show3d = !show3d"
            >
                <IconBuilding class="size-4" />
            </Button>
        </div>

        <div
            v-if="routeSummary"
            class="bg-card/95 text-foreground border-border absolute top-2.5 left-1/2 z-10 flex -translate-x-1/2 items-center gap-2 rounded-full border px-3.5 py-2 text-sm font-semibold shadow-md backdrop-blur"
            data-testid="map-route-summary"
        >
            <IconRoute class="text-primary size-4" />
            {{
                $t(':stops stops · :distance km', {
                    stops: String(routeSummary.stops),
                    distance: routeSummary.distance,
                })
            }}
        </div>

        <Button
            v-if="canReturnToSearch"
            variant="secondary"
            size="icon"
            :class="[controlClass, 'absolute top-27 right-2.5 z-10']"
            :aria-label="$t('Return to search results')"
            data-testid="map-return-to-search"
            @click="returnToSearch"
        >
            <IconScan class="size-4" />
        </Button>
    </div>
</template>

<!--
    Deliberately not `scoped`. MapLibre builds the popup itself, outside Vue,
    so the element never receives a scope attribute and a scoped rule would
    never match it. Every selector below is the library's own class name.
-->
<style>
/*
 * Left alone the popup paints itself white and inherits the app's text colour,
 * which in dark mode is near-white -- so the name is white on white. The close
 * button has the same problem, and the CSS reset strips the padding it relies
 * on for its size, collapsing it to a 7px sliver.
 */
.maplibregl-popup-content {
    background: var(--popover);
    color: var(--popover-foreground);
    border: 1px solid var(--border);
    /* Not var(--radius): that is tuned for cards and reads as a lozenge here. */
    border-radius: 0.5rem;
    box-shadow: 0 4px 12px rgb(0 0 0 / 0.18);
    /* Right side leaves room for the close button to sit out of the text. */
    padding: 0.5rem 2rem 0.5rem 0.75rem;
    font-size: 0.8125rem;
    line-height: 1.35;
}

/*
 * The tip is drawn as a CSS triangle out of borders, so its colour has to
 * follow the popup or a white arrow is left pointing at a dark card. Which
 * border carries the colour depends on which side the popup opened.
 */
.maplibregl-popup-anchor-top .maplibregl-popup-tip,
.maplibregl-popup-anchor-top-left .maplibregl-popup-tip,
.maplibregl-popup-anchor-top-right .maplibregl-popup-tip {
    border-bottom-color: var(--popover);
}

.maplibregl-popup-anchor-bottom .maplibregl-popup-tip,
.maplibregl-popup-anchor-bottom-left .maplibregl-popup-tip,
.maplibregl-popup-anchor-bottom-right .maplibregl-popup-tip {
    border-top-color: var(--popover);
}

.maplibregl-popup-anchor-left .maplibregl-popup-tip {
    border-right-color: var(--popover);
}

.maplibregl-popup-anchor-right .maplibregl-popup-tip {
    border-left-color: var(--popover);
}

/*
 * Pull the triangle one pixel into the bordered card. Without the overlap the
 * content border draws a hairline straight across the base of the arrow.
 */
.maplibregl-popup-anchor-top .maplibregl-popup-tip,
.maplibregl-popup-anchor-top-left .maplibregl-popup-tip,
.maplibregl-popup-anchor-top-right .maplibregl-popup-tip {
    margin-bottom: -1px;
}

.maplibregl-popup-anchor-bottom .maplibregl-popup-tip,
.maplibregl-popup-anchor-bottom-left .maplibregl-popup-tip,
.maplibregl-popup-anchor-bottom-right .maplibregl-popup-tip {
    margin-top: -1px;
}

.maplibregl-popup-anchor-left .maplibregl-popup-tip {
    margin-right: -1px;
}

.maplibregl-popup-anchor-right .maplibregl-popup-tip {
    margin-left: -1px;
}

.maplibregl-popup-close-button {
    position: absolute;
    top: 0.125rem;
    right: 0.125rem;
    display: flex;
    align-items: center;
    justify-content: center;
    /* Explicit box: the reset removed the padding that used to size it. */
    width: 1.5rem;
    height: 1.5rem;
    padding: 0;
    border-radius: 0.25rem;
    color: var(--popover-foreground);
    font-size: 1.125rem;
    line-height: 1;
    opacity: 0.55;
    cursor: pointer;
}

.maplibregl-popup-close-button:hover,
.maplibregl-popup-close-button:focus-visible {
    background: var(--accent);
    opacity: 1;
}
</style>
