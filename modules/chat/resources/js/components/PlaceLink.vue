<script setup lang="ts">
import { safeHttpUrl } from '@/lib/safeHttpUrl';
import IconMapPin from '~icons/lucide/map-pin';
import { computed } from 'vue';

/**
 * The markdown renderer's `link` node, replaced.
 *
 * Two reasons to own this. The stock renderer opens a tooltip offering to copy
 * or open the URL, which is meaningless for a place name; and it strips the
 * href it was given, so the target cannot carry anything back to us.
 *
 * A place link is a button, not a destination: clicking it moves the map. The
 * transcript's own click handler picks it up by `data-place`, so this component
 * needs no wiring of its own.
 */
const props = defineProps<{
    node: {
        url?: string;
        children?: Array<{ value?: string }>;
    };
}>();

const placeMatch = computed(() =>
    /^#map(?:-(\d+))?$/.exec(props.node.url ?? ''),
);

const label = computed(() =>
    (props.node.children ?? []).map((child) => child.value ?? '').join(''),
);

const isPlace = computed(() => placeMatch.value !== null);
const placeId = computed(() => placeMatch.value?.[1] ?? '');
const externalUrl = computed(() => safeHttpUrl(props.node.url));
</script>

<template>
    <button
        v-if="isPlace"
        type="button"
        data-place
        :data-place-id="placeId || undefined"
        class="text-primary decoration-primary/50 hover:bg-primary/12 hover:decoration-primary focus-visible:ring-ring inline-flex cursor-pointer items-center gap-0.5 rounded-lg bg-primary/10 px-1.5 py-0.5 text-[0.92em] font-medium underline decoration-dotted underline-offset-2 shadow-sm shadow-primary/5 transition-all duration-200 hover:-translate-y-px hover:shadow-md hover:shadow-primary/15 focus-visible:ring-2 focus-visible:outline-none active:scale-[0.98]"
        :aria-label="$t('Show :place on map', { place: label })"
    >
        <IconMapPin class="size-3 shrink-0 opacity-80" aria-hidden="true" />
        {{ label }}
    </button>
    <a
        v-else-if="externalUrl"
        :href="externalUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="text-primary [overflow-wrap:anywhere] underline"
    >
        {{ label }}
    </a>
    <span v-else class="[overflow-wrap:anywhere]">{{ label }}</span>
</template>
