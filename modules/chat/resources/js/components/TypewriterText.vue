<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        text: string;
        /** Milliseconds per character. */
        speed?: number;
    }>(),
    { speed: 26 },
);

const shown = ref(props.text);
let timer: ReturnType<typeof setInterval> | undefined;

const reducedMotion = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Only animates on *change*, never on first paint -- otherwise every session in
 * the sidebar would type itself out on page load. The one moment a title
 * changes under the visitor is when the queued rename lands, which is exactly
 * what is worth drawing the eye to.
 */
watch(
    () => props.text,
    (next) => {
        clearInterval(timer);

        if (reducedMotion()) {
            shown.value = next;

            return;
        }

        let index = 0;
        shown.value = '';

        timer = setInterval(() => {
            index += 1;
            shown.value = next.slice(0, index);

            if (index >= next.length) {
                clearInterval(timer);
            }
        }, props.speed);
    },
);

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <span>{{ shown }}</span>
</template>
