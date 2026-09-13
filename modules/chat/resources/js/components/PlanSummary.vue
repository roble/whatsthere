<script setup lang="ts">
import IconAccessibility from '~icons/lucide/accessibility';
import IconCalendar from '~icons/lucide/calendar-days';
import IconCloudRain from '~icons/lucide/cloud-sun-rain';
import IconCompass from '~icons/lucide/compass';
import IconHeart from '~icons/lucide/heart';
import IconMapPin from '~icons/lucide/map-pin';
import IconPlug from '~icons/lucide/plug-zap';
import IconTag from '~icons/lucide/tag';
import IconTrain from '~icons/lucide/train-front';
import IconUsers from '~icons/lucide/users';
import IconUtensils from '~icons/lucide/utensils';
import IconVolume from '~icons/lucide/volume-1';
import IconWallet from '~icons/lucide/wallet';
import IconWifi from '~icons/lucide/wifi';
import type { Component } from 'vue';

const props = defineProps<{
    plan: {
        goal: string;
        location: string;
        details: Record<string, unknown>;
    };
}>();

type Look = { icon: Component; tone: string };

/**
 * Icon and colour per detail, matched on words in the label the assistant
 * chose. Labels are free text, so this is a keyword lookup with a plain
 * fallback rather than a fixed list of sections.
 */
const LOOKS: Array<[RegExp, Look]> = [
    [
        /wi-?fi|internet/i,
        {
            icon: IconWifi,
            tone: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
        },
    ],
    [
        /power|outlet|plug|charg/i,
        {
            icon: IconPlug,
            tone: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
        },
    ],
    [
        /budget|price|cost|cheap/i,
        {
            icon: IconWallet,
            tone: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        },
    ],
    [
        /quiet|noise|seat|atmosphere|vibe|style|mood/i,
        {
            icon: IconVolume,
            tone: 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
        },
    ],
    [
        /time|timing|when|day|date|hour|morning|evening|weekend/i,
        {
            icon: IconCalendar,
            tone: 'bg-orange-500/15 text-orange-600 dark:text-orange-400',
        },
    ],
    [
        /food|eat|drink|dinner|lunch|coffee|cuisine|diet|vegetarian|vegan/i,
        {
            icon: IconUtensils,
            tone: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
        },
    ],
    [
        /companion|group|kid|child|family|friend|partner|people|who/i,
        {
            icon: IconUsers,
            tone: 'bg-pink-500/15 text-pink-600 dark:text-pink-400',
        },
    ],
    [
        /access|wheelchair|mobility|step/i,
        {
            icon: IconAccessibility,
            tone: 'bg-teal-500/15 text-teal-600 dark:text-teal-400',
        },
    ],
    [
        /weather|rain|sun|indoor|outdoor/i,
        {
            icon: IconCloudRain,
            tone: 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400',
        },
    ],
    [
        /transport|transit|walk|drive|car|bike|distance/i,
        {
            icon: IconTrain,
            tone: 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400',
        },
    ],
    [
        /interest|activity|activities|prefer|type|kind|purpose|want/i,
        {
            icon: IconHeart,
            tone: 'bg-fuchsia-500/15 text-fuchsia-600 dark:text-fuchsia-400',
        },
    ],
];

const FALLBACK: Look = {
    icon: IconTag,
    tone: 'bg-muted text-muted-foreground',
};

function lookFor(label: string): Look {
    return LOOKS.find(([pattern]) => pattern.test(label))?.[1] ?? FALLBACK;
}

function text(value: unknown): string {
    return Array.isArray(value) ? value.join(', ') : String(value ?? '');
}

const rows = () => [
    {
        label: 'Goal',
        value: props.plan.goal,
        look: { icon: IconCompass, tone: 'bg-primary/15 text-primary' },
    },
    {
        label: 'Location',
        value: props.plan.location,
        look: {
            icon: IconMapPin,
            tone: 'bg-red-500/15 text-red-600 dark:text-red-400',
        },
    },
    ...Object.entries(props.plan.details).map(([label, value]) => ({
        label,
        value: text(value),
        look: lookFor(label),
    })),
];
</script>

<template>
    <ul class="grid gap-2 sm:grid-cols-2" data-testid="plan-summary">
        <li
            v-for="(row, index) in rows()"
            :key="row.label"
            class="bg-card flex items-start gap-3 rounded-lg border p-3"
            :class="index < 2 ? 'sm:col-span-2' : ''"
        >
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-md"
                :class="row.look.tone"
            >
                <component :is="row.look.icon" class="size-5" />
            </span>
            <span class="min-w-0">
                <span class="text-muted-foreground block text-xs font-medium">
                    {{ row.label }}
                </span>
                <span class="mt-0.5 block text-sm font-medium">
                    {{ row.value }}
                </span>
            </span>
        </li>
    </ul>
</template>
