<script setup lang="ts">
import { Shimmer } from '@/components/ai-elements/shimmer';
import {
    CompassIcon,
    FootprintsIcon,
    MapPinIcon,
    RadarIcon,
    SparklesIcon,
    TelescopeIcon,
} from '@lucide/vue';
import { usePreferredReducedMotion } from '@vueuse/core';
import { AnimatePresence, Motion } from 'motion-v';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * What the assistant says it is doing while it works.
 *
 * Deliberately about looking for places rather than about computing, because
 * that is what this assistant does. Keep the list short: these are a flourish,
 * and a long rotation starts reading as a slot machine. "Whatsthering" is the
 * one gag, and stays the only one.
 */
const VERBS = [
    { icon: TelescopeIcon, label: 'Scouting' },
    { icon: MapPinIcon, label: 'Pinpointing' },
    { icon: CompassIcon, label: 'Getting our bearings' },
    { icon: FootprintsIcon, label: 'Nosing about' },
    { icon: RadarIcon, label: 'Whatsthering' },
    { icon: SparklesIcon, label: 'Divining' },
];

/** Long enough to read the phrase, short enough that a wait feels tended to. */
const INTERVAL = 2200;

/**
 * A random *starting point*, then straight through the list.
 *
 * Picking randomly each time repeats back to back often enough to look broken.
 * This is unpredictable between messages and never stutters within one.
 */
const index = ref(Math.floor(Math.random() * VERBS.length));

const verb = computed(() => VERBS[index.value % VERBS.length]);

const reduced = usePreferredReducedMotion();
const still = computed(() => reduced.value === 'reduce');

let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    if (still.value) {
        return;
    }

    timer = setInterval(() => {
        index.value += 1;
    }, INTERVAL);
});

onBeforeUnmount(() => clearInterval(timer));

/**
 * Icons swap by scaling and rotating through each other rather than by morphing
 * their paths. Every lucide glyph is one stroke weight on the same 24x24 grid,
 * so overlapping the two reads as one shape turning into the next -- and a real
 * path interpolator does worse here, because the icons differ in subpath count
 * and it has to invent segments to bridge them.
 *
 * Transform and opacity only: no blur, no layout, nothing that costs a reflow
 * while a reply is streaming beside it.
 */
const ENTER = { scale: 1, rotate: 0, opacity: 1 };
const LEAVE = { scale: 0.4, rotate: 40, opacity: 0 };
const ARRIVE = { scale: 0.4, rotate: -40, opacity: 0 };
const SPRING = { type: 'spring', stiffness: 260, damping: 22 } as const;
</script>

<template>
    <span
        class="text-muted-foreground flex items-center gap-2 text-sm"
        role="status"
        :aria-label="$t('Thinking')"
        data-testid="thinking"
    >
        <!-- Announced once by the label above; the rotation is decoration and
             would otherwise be read out every couple of seconds. -->
        <span class="relative block size-4 shrink-0" aria-hidden="true">
            <component :is="verb.icon" v-if="still" class="size-4" />
            <AnimatePresence v-else :initial="false" mode="sync">
                <Motion
                    :key="verb.label"
                    as="span"
                    class="absolute inset-0 block"
                    :initial="ARRIVE"
                    :animate="ENTER"
                    :exit="LEAVE"
                    :transition="SPRING"
                >
                    <component :is="verb.icon" class="size-4" />
                </Motion>
            </AnimatePresence>
        </span>

        <span
            class="relative block overflow-hidden text-left"
            aria-hidden="true"
        >
            <Shimmer v-if="still" as="span" :duration="1">
                {{ $t(verb.label) }}...
            </Shimmer>
            <AnimatePresence v-else :initial="false" mode="wait">
                <Motion
                    :key="verb.label"
                    as="span"
                    class="block"
                    :initial="{ y: 6, opacity: 0 }"
                    :animate="{ y: 0, opacity: 1 }"
                    :exit="{ y: -6, opacity: 0 }"
                    :transition="{ duration: 0.22 }"
                >
                    <Shimmer as="span" :duration="1">
                        {{ $t(verb.label) }}...
                    </Shimmer>
                </Motion>
            </AnimatePresence>
        </span>
    </span>
</template>
