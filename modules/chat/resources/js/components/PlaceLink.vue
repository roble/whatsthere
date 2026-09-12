<script setup lang="ts">
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

/** Written by `withPlaceLinks`; a real link from the model has a real URL. */
const PLACE_URL = '#map';

const label = computed(() =>
    (props.node.children ?? []).map((child) => child.value ?? '').join(''),
);

const isPlace = computed(() => props.node.url === PLACE_URL);
</script>

<template>
    <button
        v-if="isPlace"
        type="button"
        data-place
        class="text-primary decoration-primary/40 hover:decoration-primary cursor-pointer font-medium underline decoration-dotted underline-offset-3"
        :aria-label="$t('Show :place on map', { place: label })"
    >
        {{ label }}
    </button>
    <a
        v-else
        :href="node.url"
        target="_blank"
        rel="noreferrer"
        class="text-primary [overflow-wrap:anywhere] underline"
    >
        {{ label }}
    </a>
</template>
