<script setup lang="ts">
import { useMutationObserver } from '@vueuse/core';
import {
    Map as MapLibreMap,
    Marker,
    NavigationControl,
    type LngLatBoundsLike,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

/** What the ShowOnMap tool hands back, once parsed. */
export type MapView = {
    label: string;
    bbox: [string, string, string, string];
    marker?: [string, string];
};

const props = defineProps<{ view: MapView }>();

// OpenFreeMap serves OpenStreetMap vector tiles with no key, no registration
// and no usage limits, so nothing here needs credentials or a quota alarm.
const styleUrl = (dark: boolean) =>
    `https://tiles.openfreemap.org/styles/${dark ? 'dark' : 'positron'}`;

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

    instance.on('load', () => showView(props.view, false));
});

onBeforeUnmount(() => {
    marker.value?.remove();
    map.value?.remove();
    map.value = null;
});

watch(
    () => props.view,
    (view) => showView(view, true),
);

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
    <div
        ref="container"
        class="h-full w-full"
        :aria-label="view.label"
        data-testid="context-map"
    />
</template>
