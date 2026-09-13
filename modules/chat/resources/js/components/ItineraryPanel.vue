<script setup lang="ts">
import type { ItineraryStop } from '@modules/chat/resources/js/map';
import IconClock from '~icons/lucide/clock';

defineProps<{ stops: ItineraryStop[] }>();

const emit = defineEmits<{ focus: [stop: ItineraryStop] }>();
</script>

<template>
    <ol class="divide-y" data-testid="itinerary-panel">
        <li v-for="(stop, index) in stops" :key="`${stop.place}-${index}`">
            <!-- Rows rather than cards: a card needs a margin to sit in, and
                 the margin is what puts a gap between the list and the
                 scrollbar. -->
            <button
                type="button"
                class="hover:bg-accent focus-visible:ring-ring flex w-full items-start gap-3 px-4 py-3 text-left transition-colors focus-visible:ring-2 focus-visible:-outline-offset-2 focus-visible:outline-none"
                :data-testid="`itinerary-stop-${index}`"
                @click="emit('focus', stop)"
            >
                <span
                    class="bg-primary text-primary-foreground flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                    aria-hidden="true"
                >
                    {{ index + 1 }}
                </span>
                <span class="min-w-0 flex-1">
                    <!-- Wraps rather than truncates: the pane is resizable down
                         to a narrow column, and a stop whose title is cut off
                         is the one thing the list has to say. -->
                    <span class="flex flex-wrap items-baseline gap-x-2">
                        <span
                            v-if="stop.time"
                            class="text-muted-foreground flex shrink-0 items-center gap-1 text-xs font-medium"
                        >
                            <IconClock class="size-3" aria-hidden="true" />
                            {{ stop.time }}
                        </span>
                        <span class="min-w-0 text-sm font-semibold break-words">
                            {{ stop.title }}
                        </span>
                    </span>
                    <span
                        class="text-muted-foreground mt-0.5 block text-xs break-words"
                    >
                        {{ stop.place }}
                    </span>
                    <span
                        v-if="stop.note"
                        class="text-muted-foreground mt-1 block text-xs break-words"
                    >
                        {{ stop.note }}
                    </span>
                </span>
            </button>
        </li>
    </ol>
</template>
