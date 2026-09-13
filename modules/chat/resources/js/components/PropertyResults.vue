<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { MapMarker, MapView } from '@modules/chat/resources/js/map';
import {
    formatPrice,
    formatPricePerSqm,
    headingKey,
    highlightBadgeClass,
    highlightLabelKey,
    highlightShortLabelKey,
    isLand,
    typeLabelKey,
} from '@modules/chat/resources/js/listing';
import PropertyPhoto from '@modules/chat/resources/js/components/PropertyPhoto.vue';
import IconBedDouble from '~icons/lucide/bed-double';
import IconBadgeCheck from '~icons/lucide/badge-check';
import IconHome from '~icons/lucide/house';
import IconMaximize2 from '~icons/lucide/maximize-2';
import IconMinimize2 from '~icons/lucide/minimize-2';
import IconSlidersHorizontal from '~icons/lucide/sliders-horizontal';
import IconSparkles from '~icons/lucide/sparkles';
import IconStar from '~icons/lucide/star';
import type { Component } from 'vue';
import { computed, nextTick, ref, watch } from 'vue';

const highlightIcons: Record<string, Component> = {
    value: IconSparkles,
    match: IconBadgeCheck,
    premium: IconStar,
};

const props = withDefaults(
    defineProps<{
        view: MapView;
        selectedId: number | null;
        dense?: boolean;
        loading?: boolean;
    }>(),
    { dense: false, loading: false },
);
defineEmits<{
    select: [MapMarker];
    highlight: [MapMarker | null];
    preferences: [];
}>();

const expanded = ref(false);
const root = ref<HTMLElement | null>(null);

const visible = computed(() => props.view.markers?.length ?? 0);
const total = computed(() => props.view.total ?? 0);
const title = computed(() => headingKey(props.view.markers));
const asRail = computed(() => props.dense && !expanded.value);
const mapCapped = computed(() => visible.value < total.value);

function meta(marker: MapMarker): { beds: string | null; extra: string } {
    if (isLand(marker)) {
        return {
            beds: null,
            extra: marker.size_label || '',
        };
    }

    return {
        beds: marker.bedrooms == null ? null : String(marker.bedrooms),
        extra: '',
    };
}

watch(
    () => props.selectedId,
    async (id) => {
        if (id == null) {
            return;
        }

        await nextTick();
        root.value
            ?.querySelector(`[data-testid="select-property-${id}"]`)
            ?.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
                inline: 'nearest',
            });
    },
);
</script>

<template>
    <section
        ref="root"
        class="property-results border-border/40 text-card-foreground relative flex flex-col border-b bg-gradient-to-b from-card/40 to-transparent backdrop-blur-xl"
        :class="
            expanded
                ? 'bg-background/92 absolute inset-0 z-40 h-full border-0 shadow-2xl shadow-black/20'
                : asRail
                  ? 'shrink-0'
                  : 'min-h-0 flex-1'
        "
        data-testid="property-results"
    >
        <header
            class="flex items-center gap-2 px-3"
            :class="
                expanded
                    ? 'border-border/50 sticky top-0 z-10 border-b bg-background/90 py-2 backdrop-blur-xl'
                    : asRail
                      ? 'py-1.5'
                      : 'py-2'
            "
        >
            <h2
                class="min-w-0 truncate text-[13px] font-semibold tracking-tight"
            >
                {{ $t(title) }}
            </h2>
            <span
                v-if="!loading"
                class="bg-muted/80 text-muted-foreground shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium tabular-nums"
                :title="
                    mapCapped
                        ? $t(':shown of :total match your filters', {
                              shown: visible,
                              total,
                          })
                        : $t(':count match your filters', { count: total })
                "
            >
                <template v-if="mapCapped">
                    {{
                        $t(':shown of :total', {
                            shown: visible,
                            total,
                        })
                    }}
                </template>
                <template v-else>
                    {{ $t(':count matches', { count: total }) }}
                </template>
            </span>
            <p
                v-else
                class="text-muted-foreground shrink-0 text-[10px]"
            >
                {{ $t('Updating listings…') }}
            </p>
            <Button
                v-if="!loading && visible"
                variant="ghost"
                size="sm"
                class="text-muted-foreground hover:text-foreground ml-auto h-7 shrink-0 gap-1 rounded-lg px-2 text-[11px] transition-all duration-200 hover:bg-primary/10"
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
                <IconMinimize2 v-if="expanded" class="size-3.5" />
                <IconMaximize2 v-else class="size-3.5" />
                {{ $t(expanded ? 'Close' : 'See all listings') }}
            </Button>
        </header>

        <p
            v-if="mapCapped && !loading && !asRail"
            class="text-muted-foreground px-3 pb-1 text-[10px] leading-snug"
        >
            {{
                $t(
                    'The map shows the closest :shown matches. Open the list for every result.',
                    { shown: visible },
                )
            }}
        </p>

        <div
            v-if="loading"
            class="property-rail flex gap-2.5 overflow-hidden px-3 pb-2.5"
            aria-busy="true"
            aria-live="polite"
        >
            <div
                v-for="index in 4"
                :key="index"
                class="border-border/40 bg-background/60 w-[10.5rem] shrink-0 overflow-hidden rounded-2xl border shadow-sm"
            >
                <div
                    class="from-muted via-background/50 to-muted aspect-[4/5] w-full animate-pulse bg-gradient-to-br"
                />
            </div>
        </div>

        <div
            v-else-if="!view.markers?.length"
            class="flex flex-col items-center gap-3 px-4 py-8 text-center"
            data-testid="property-no-results"
        >
            <span
                class="grid size-11 place-items-center rounded-2xl bg-primary/10 text-primary shadow-inner shadow-primary/10"
                aria-hidden="true"
            >
                <IconHome class="size-5" />
            </span>
            <div class="space-y-1">
                <p class="text-sm font-semibold tracking-tight">
                    {{ $t('Nothing matched') }}
                </p>
                <p class="text-muted-foreground max-w-xs text-xs leading-relaxed">
                    {{
                        $t(
                            'No matching homes or land in our database. Widen a filter to see more.',
                        )
                    }}
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                class="rounded-xl"
                @click="$emit('preferences')"
            >
                <IconSlidersHorizontal class="size-3.5" />
                {{ $t('Widen filters') }}
            </Button>
        </div>

        <div v-else-if="asRail" class="property-rail relative pb-2.5">
            <div
                class="pointer-events-none absolute inset-y-0 left-0 z-10 w-3 bg-gradient-to-r from-background/95 to-transparent"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute inset-y-0 right-0 z-10 w-8 bg-gradient-to-l from-background/95 to-transparent"
                aria-hidden="true"
            />
            <div
                class="flex snap-x snap-mandatory gap-2.5 overflow-x-auto px-3 [scrollbar-width:thin]"
            >
                <button
                    v-for="(property, index) in view.markers"
                    :key="property.id"
                    type="button"
                    :data-testid="`select-property-${property.id}`"
                    :aria-pressed="selectedId === property.id"
                    class="group border-border/45 bg-background/70 focus-visible:ring-ring motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-right-2 relative w-[10.5rem] shrink-0 snap-start overflow-hidden rounded-2xl border text-left shadow-md shadow-black/10 transition-all duration-200 outline-none hover:-translate-y-0.5 hover:border-primary/35 hover:shadow-xl hover:shadow-primary/15 focus-visible:ring-2 active:scale-[0.98]"
                    :class="
                        selectedId === property.id
                            ? 'border-red-500/90 ring-2 ring-red-500/35 shadow-red-500/15'
                            : ''
                    "
                    :style="{ animationDelay: `${Math.min(index, 8) * 35}ms` }"
                    @click="$emit('select', property)"
                    @mouseenter="$emit('highlight', property)"
                    @mouseleave="$emit('highlight', null)"
                    @focus="$emit('highlight', property)"
                    @blur="$emit('highlight', null)"
                >
                    <span class="relative block aspect-[4/5] w-full overflow-hidden">
                        <PropertyPhoto
                            :images="property.images ?? []"
                            :alt="property.name"
                            :eager="index < 5"
                            navigable
                            compact
                        />
                        <span
                            class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/75 via-black/15 to-transparent"
                            aria-hidden="true"
                        />
                        <span
                            v-if="highlightBadgeClass(property.highlight)"
                            class="absolute top-2 right-2 inline-flex max-w-[calc(100%-1rem)] items-center gap-1 rounded-full border px-1.5 py-0.5 text-[9px] font-semibold tracking-wide uppercase shadow-sm backdrop-blur-md"
                            :class="highlightBadgeClass(property.highlight)"
                            :title="
                                highlightLabelKey(property.highlight)
                                    ? $t(highlightLabelKey(property.highlight)!)
                                    : undefined
                            "
                        >
                            <component
                                :is="highlightIcons[property.highlight ?? '']"
                                class="size-2.5 shrink-0 opacity-90"
                                aria-hidden="true"
                            />
                            <span class="truncate">
                                {{
                                    $t(
                                        highlightShortLabelKey(
                                            property.highlight,
                                        )!,
                                    )
                                }}
                            </span>
                        </span>
                        <span
                            v-if="selectedId === property.id"
                            class="absolute top-2 left-2 inline-flex items-center gap-1 rounded-full border border-red-300/30 bg-red-950/75 px-1.5 py-0.5 text-[9px] font-semibold tracking-wide text-red-50 uppercase shadow-sm shadow-red-500/20 backdrop-blur-md"
                        >
                            {{ $t('Selected') }}
                        </span>
                        <span
                            v-else-if="!isLand(property) && meta(property).beds"
                            class="absolute top-2 left-2 inline-flex items-center gap-0.5 rounded-full border border-white/15 bg-black/45 px-1.5 py-0.5 text-[10px] font-medium text-white backdrop-blur-md"
                        >
                            <IconBedDouble class="size-2.5" aria-hidden="true" />
                            {{ meta(property).beds }}
                        </span>
                        <span class="absolute inset-x-0 bottom-0 space-y-0.5 p-2.5">
                            <span class="block text-[15px] font-semibold tracking-tight text-white">
                                {{ formatPrice(property) }}
                            </span>
                            <span
                                class="block truncate text-[11px] leading-tight text-white/90"
                            >
                                {{ property.name }}
                            </span>
                            <span
                                v-if="formatPricePerSqm(property)"
                                class="block text-[10px] text-white/70"
                            >
                                {{ formatPricePerSqm(property) }}
                            </span>
                        </span>
                    </span>
                </button>
            </div>
        </div>

        <div
            v-else
            class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-3"
        >
            <button
                v-for="(property, index) in view.markers"
                :key="property.id"
                type="button"
                :data-testid="`select-property-${property.id}`"
                :aria-pressed="selectedId === property.id"
                class="group hover:bg-primary/6 focus-visible:ring-ring motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-1 flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5 text-left transition-all duration-200 outline-none focus-visible:ring-2 active:scale-[0.99]"
                :class="
                    selectedId === property.id
                        ? 'bg-red-500/10 ring-1 ring-red-500/50'
                        : ''
                "
                :style="{ animationDelay: `${Math.min(index, 8) * 30}ms` }"
                @click="$emit('select', property)"
                @mouseenter="$emit('highlight', property)"
                @mouseleave="$emit('highlight', null)"
                @focus="$emit('highlight', property)"
                @blur="$emit('highlight', null)"
            >
                <span
                    class="relative size-14 shrink-0 overflow-hidden rounded-xl shadow-sm ring-1 ring-white/5"
                >
                    <PropertyPhoto
                        :images="property.images ?? []"
                        :alt="property.name"
                        :eager="index < 8"
                        show-count
                    />
                    <span
                        v-if="highlightBadgeClass(property.highlight)"
                        class="absolute top-1 right-1 inline-flex items-center justify-center rounded-full border p-0.5 shadow-sm backdrop-blur-md"
                        :class="highlightBadgeClass(property.highlight)"
                        :title="
                            highlightLabelKey(property.highlight)
                                ? $t(highlightLabelKey(property.highlight)!)
                                : undefined
                        "
                    >
                        <component
                            :is="highlightIcons[property.highlight ?? '']"
                            class="size-2.5 shrink-0 opacity-90"
                            aria-hidden="true"
                        />
                    </span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex min-w-0 items-center gap-1.5">
                        <span class="text-primary text-sm font-semibold">{{
                            formatPrice(property)
                        }}</span>
                        <span
                            v-if="highlightBadgeClass(property.highlight)"
                            class="hidden items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase sm:inline-flex"
                            :class="{
                                'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300':
                                    property.highlight === 'value',
                                'border-sky-500/20 bg-sky-500/10 text-sky-700 dark:text-sky-300':
                                    property.highlight === 'match',
                                'border-amber-500/20 bg-amber-500/10 text-amber-800 dark:text-amber-300':
                                    property.highlight === 'premium',
                            }"
                        >
                            <component
                                :is="highlightIcons[property.highlight ?? '']"
                                class="size-3 shrink-0 opacity-80"
                                aria-hidden="true"
                            />
                            {{
                                $t(
                                    highlightShortLabelKey(property.highlight)!,
                                )
                            }}
                        </span>
                    </span>
                    <span
                        class="text-muted-foreground block truncate text-[11px]"
                    >
                        <template v-if="isLand(property)">
                            {{
                                meta(property).extra ||
                                $t('Plot size unlisted')
                            }}
                            · {{ $t('Land') }}
                        </template>
                        <template v-else>
                            {{
                                meta(property).beds == null
                                    ? $t('Beds unlisted')
                                    : $t(':count beds', {
                                          count: Number(meta(property).beds),
                                      })
                            }}
                            · {{ $t(typeLabelKey(property)) }}
                        </template>
                        <template v-if="formatPricePerSqm(property)">
                            · {{ formatPricePerSqm(property) }}
                        </template>
                    </span>
                    <span class="text-foreground/85 block truncate text-[13px]">
                        {{ property.name }}
                    </span>
                </span>
            </button>
        </div>
    </section>
</template>
