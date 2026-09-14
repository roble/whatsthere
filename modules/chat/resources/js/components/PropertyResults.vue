<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    propertyFacts,
    type MapMarker,
    type MapView,
} from '@modules/chat/resources/js/map';
import IconChevronRight from '~icons/lucide/chevron-right';
import IconImage from '~icons/lucide/image';
import IconImages from '~icons/lucide/images';
import IconMaximize2 from '~icons/lucide/maximize-2';
import IconMinimize2 from '~icons/lucide/minimize-2';

defineProps<{ view: MapView; selectedId: number | null }>();
defineEmits<{
    select: [MapMarker];
    highlight: [MapMarker | null];
}>();

/**
 * Owned by the page, not here. The height this section is allowed to take is
 * decided by the column it sits in, and a parent passing `max-h-72` for the
 * collapsed layout merges with whatever class this root sets rather than
 * losing to it -- so expanding from the inside produced `h-full max-h-72` and
 * a panel that never grew.
 */
const expanded = defineModel<boolean>('expanded', { default: false });

function price(marker: MapMarker): string {
    return new Intl.NumberFormat('en-IE', {
        style: 'currency',
        currency: marker.currency ?? 'EUR',
        maximumFractionDigits: 0,
    }).format((marker.asking_price ?? 0) / 100);
}
</script>

<template>
    <!--
        Deliberately not an overlay. Covering the column with `absolute inset-0`
        also covered the filter bar above it, and the filters are the reason to
        open the full list in the first place. Growing inside the column leaves
        them where they were.
    -->
    <section
        class="bg-card text-card-foreground flex min-h-0 flex-col border-b"
        data-testid="property-results"
        :data-expanded="expanded ? 'true' : 'false'"
    >
        <header class="flex items-center gap-3 border-b px-2 py-1">
            <h2 class="truncate font-semibold whitespace-nowrap">
                {{ $t('Properties for sale') }}
            </h2>
            <p
                class="text-muted-foreground ml-auto shrink-0 text-xs whitespace-nowrap"
            >
                {{ view.markers?.length ?? 0 }} {{ $t('visible of') }}
                {{ view.total ?? 0 }} {{ $t('matches') }}
            </p>
            <Button
                variant="ghost"
                size="icon"
                class="size-8 shrink-0"
                :aria-label="
                    $t(expanded ? 'Close full results' : 'Expand results')
                "
                :data-testid="
                    expanded
                        ? 'collapse-property-results'
                        : 'expand-property-results'
                "
                @click="expanded = !expanded"
            >
                <IconMinimize2 v-if="expanded" class="size-4" />
                <IconMaximize2 v-else class="size-4" />
            </Button>
        </header>
        <div class="min-h-0 flex-1 divide-y overflow-y-auto px-3">
            <p
                v-if="!view.markers?.length"
                class="text-muted-foreground p-2 text-sm"
                data-testid="property-no-results"
            >
                {{
                    $t(
                        'No matching properties in our database. Try changing your preferences.',
                    )
                }}
            </p>
            <button
                v-for="property in view.markers"
                :key="property.id"
                type="button"
                :data-testid="`select-property-${property.id}`"
                :aria-pressed="selectedId === property.id"
                class="hover:bg-primary/5 focus-visible:bg-primary/5 flex w-full items-center gap-2 py-1.5 text-left transition-colors outline-none"
                :class="
                    selectedId === property.id
                        ? 'bg-primary/10 hover:bg-primary/10 focus-visible:bg-primary/10'
                        : ''
                "
                @click="$emit('select', property)"
                @mouseenter="$emit('highlight', property)"
                @mouseleave="$emit('highlight', null)"
                @focus="$emit('highlight', property)"
                @blur="$emit('highlight', null)"
            >
                <span
                    class="bg-muted relative flex h-10 w-14 shrink-0 items-center justify-center overflow-hidden rounded"
                >
                    <img
                        v-if="property.images?.[0]"
                        :src="property.images[0]"
                        :alt="property.name"
                        class="size-full object-cover"
                        loading="lazy"
                    />
                    <IconImage v-else class="text-muted-foreground size-5" />
                    <span
                        v-if="property.images?.length"
                        class="bg-background/90 text-foreground absolute right-0.5 bottom-0.5 flex items-center gap-0.5 rounded px-1 py-0.5 text-[9px] font-medium"
                        :aria-label="$t('Property images')"
                    >
                        <IconImages class="size-2.5" />
                        {{ property.images.length }}
                    </span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="text-primary block font-semibold">{{
                        price(property)
                    }}</span>
                    <span class="block truncate text-sm font-medium">{{
                        property.name
                    }}</span>
                    <span class="text-muted-foreground block truncate text-xs">
                        {{ propertyFacts(property) }}
                    </span>
                    <span
                        v-if="selectedId === property.id"
                        class="mt-2 block text-sm"
                    >
                        {{ property.details?.description }}
                    </span>
                </span>
                <span
                    class="bg-primary text-primary-foreground flex size-7 shrink-0 items-center justify-center rounded-full"
                    aria-hidden="true"
                >
                    <IconChevronRight class="size-3.5" />
                </span>
            </button>
        </div>
    </section>
</template>
