<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    MAP_STYLES,
    styleUrlFor,
    viewKey,
    type MapStyleId,
    type MapView,
    type MapViewport,
} from '@modules/chat/resources/js/map';
import {
    useMutationObserver,
    useResizeObserver,
    useStorage,
} from '@vueuse/core';
import IconBuilding from '~icons/lucide/building-2';
import IconPalette from '~icons/lucide/palette';
import {
    Map as MapLibreMap,
    Marker,
    NavigationControl,
    setWorkerUrl,
    type LngLatBoundsLike,
} from 'maplibre-gl';
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url';
import 'maplibre-gl/dist/maplibre-gl.css';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

/**
 * Tell MapLibre where its worker actually landed.
 *
 * Left alone it resolves the worker from its own `import.meta.url`, which in a
 * production build is whichever chunk it was bundled into -- here
 * `assets/chat/Index-*.js`, so it asks for `assets/chat/maplibre-gl-worker.mjs`
 * and gets a 404. Nothing throws: the map draws its background, controls and
 * markers, and simply never renders a tile. Importing the worker with `?url`
 * makes Vite emit it as a real asset and hand back the hashed path it can be
 * fetched from.
 */
setWorkerUrl(maplibreWorkerUrl);

const props = defineProps<{ view: MapView }>();

const emit = defineEmits<{ viewport: [MapViewport] }>();

// OpenFreeMap serves OpenStreetMap vector tiles with no key, no registration
// and no usage limits, so nothing here needs credentials or a quota alarm.
const stylePreference = useStorage<MapStyleId>('chat.map-style', 'auto');

const styleUrl = (dark: boolean) => styleUrlFor(stylePreference.value, dark);

const show3d = useStorage('chat.map-3d', false);

/** Shared with the zoom controls MapLibre renders on the opposite corner. */
const controlClass =
    'size-7.25 rounded shadow-[0_0_0_2px_rgba(0,0,0,0.1)] bg-white text-neutral-800 hover:bg-neutral-100';

const BUILDINGS_LAYER = 'buildings-3d';

const container = ref<HTMLDivElement | null>(null);

// shallowRef: the map is a large non-reactive object, and letting Vue walk it
// deeply would be pure overhead.
const map = shallowRef<MapLibreMap | null>(null);
const marker = shallowRef<Marker | null>(null);

const isDark = ref(false);

/**
 * Read the rendered theme rather than the stored preference.
 *
 * `appearance` may be "auto", and the app already owns the writer side in
 * lib/navigation.ts. The `dark` class is the resolved truth either way.
 */
function readTheme(): boolean {
    return document.documentElement.classList.contains('dark');
}

function boundsOf(view: MapView): LngLatBoundsLike {
    const [west, south, east, north] = view.bbox.map(Number);

    return [
        [west, south],
        [east, north],
    ];
}

/** Move the map to a view, animating only once the first view has landed. */
function showView(view: MapView, animate: boolean): void {
    const instance = map.value;

    if (!instance) {
        return;
    }

    instance.fitBounds(boundsOf(view), {
        padding: 48,
        animate,
        // A single address geocodes to a pinpoint bbox, which would otherwise
        // slam the camera to maximum zoom.
        maxZoom: 16,
    });

    marker.value?.remove();
    marker.value = null;

    if (view.marker) {
        const [lat, lng] = view.marker.map(Number);

        marker.value = new Marker().setLngLat([lng, lat]).addTo(instance);
    }
}

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

    emit('viewport', {
        label: props.view.label,
        center: [center.lat, center.lng],
        zoom: Math.round(instance.getZoom() * 10) / 10,
        moved:
            center.lng < west ||
            center.lng > east ||
            center.lat < south ||
            center.lat > north,
    });
}

onMounted(() => {
    if (!container.value) {
        return;
    }

    isDark.value = readTheme();

    const instance = new MapLibreMap({
        container: container.value,
        style: styleUrl(isDark.value),
        bounds: boundsOf(props.view),
        fitBoundsOptions: { padding: 48, maxZoom: 16 },
        attributionControl: { compact: true },
    });

    instance.addControl(new NavigationControl(), 'top-right');
    map.value = instance;

    // moveend covers both the camera the conversation sets and the visitor
    // dragging it, so one listener keeps the reported viewport honest.
    instance.on('moveend', reportViewport);
    instance.on('style.load', () => addBuildings(instance));
    instance.on('load', () => showView(props.view, false));
});

onBeforeUnmount(() => {
    marker.value?.remove();
    map.value?.remove();
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

// setStyle keeps the camera and the marker, so switching basemap does not
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
    <div class="relative h-full w-full">
        <!-- MapLibre owns the contents of this element, so the control sits
             beside it rather than inside it. -->
        <div
            ref="container"
            class="h-full w-full"
            :aria-label="view.label"
            data-testid="context-map"
        />

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
    </div>
</template>
