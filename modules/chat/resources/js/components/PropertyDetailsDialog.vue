<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { MapMarker } from '@modules/chat/resources/js/map';
import {
    formatDistance,
    formatPricePerSqm,
    isLand,
    nearbyCategoryIcon,
    nearbyCategoryLabel,
    typeLabelKey,
} from '@modules/chat/resources/js/listing';
import PropertyGallery from '@modules/chat/resources/js/components/PropertyGallery.vue';
import IconCompass from '~icons/lucide/compass';
import IconMapPin from '~icons/lucide/map-pin';
import { Button } from '@/components/ui/button';
import { computed, ref, watch } from 'vue';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    property: MapMarker | null;
    nearby: MapMarker[];
    loadingNearby: boolean;
    loadingSummary: boolean;
}>();

const emit = defineEmits<{ nearby: [MapMarker] }>();

const expanded = ref(false);
const images = computed(() => props.property?.images ?? []);
const rate = computed(() =>
    props.property ? formatPricePerSqm(props.property) : null,
);
const description = computed(
    () => props.property?.details?.description?.trim() ?? '',
);
const longDescription = computed(() => description.value.length > 180);
const nearbyPreview = computed(() => props.nearby.slice(0, 6));
const facts = computed(() => {
    const property = props.property;

    if (!property) {
        return [];
    }

    if (isLand(property)) {
        return [
            property.size_label || 'Plot size unlisted',
            'Land',
        ];
    }

    return [
        property.bedrooms == null
            ? 'Beds unlisted'
            : { key: ':count beds', count: property.bedrooms },
        typeLabelKey(property),
        property.ber_rating,
        property.floor_area_sqm
            ? `${Math.round(property.floor_area_sqm)} m²`
            : null,
    ].filter(Boolean);
});

watch(
    () => props.property?.id,
    () => {
        expanded.value = false;
    },
);

function price(property: MapMarker): string {
    return new Intl.NumberFormat('en-IE', {
        style: 'currency',
        currency: property.currency ?? 'EUR',
        maximumFractionDigits: 0,
    }).format((property.asking_price ?? 0) / 100);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[calc(100dvh-2.5rem)] gap-0 overflow-hidden border-white/10 bg-background/94 p-0 shadow-2xl shadow-primary/10 backdrop-blur-2xl sm:max-w-lg"
            data-testid="property-details-dialog"
        >
            <DialogHeader class="sr-only">
                <DialogTitle>{{ property?.name }}</DialogTitle>
                <DialogDescription>{{
                    property?.details?.address
                }}</DialogDescription>
            </DialogHeader>

            <div
                v-if="property"
                class="flex max-h-[calc(100dvh-2.5rem)] flex-col"
            >
                <section
                    class="bg-muted relative aspect-[4/3] w-full shrink-0 overflow-hidden sm:aspect-[16/10]"
                >
                    <PropertyGallery
                        :images="images"
                        :alt="property.name"
                        eager
                        show-thumbnails
                    />
                </section>

                <section
                    class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-5"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-baseline gap-x-2.5">
                            <p
                                class="text-primary text-2xl font-semibold tracking-tight"
                            >
                                {{ price(property) }}
                            </p>
                            <p
                                v-if="rate"
                                class="text-muted-foreground text-sm font-medium"
                            >
                                {{ rate }}
                            </p>
                        </div>
                        <h2 class="text-lg leading-snug font-semibold tracking-tight">
                            {{ property.name }}
                        </h2>
                        <p class="text-muted-foreground text-sm">
                            {{ property.details?.address }}
                        </p>
                    </div>

                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="fact in facts"
                            :key="typeof fact === 'string' ? fact : fact.key"
                            class="bg-muted/80 text-foreground rounded-full px-2.5 py-1 text-sm font-medium"
                        >
                            <template v-if="typeof fact === 'string'">
                                {{ $t(fact) }}
                            </template>
                            <template v-else>
                                {{ $t(fact.key, { count: fact.count }) }}
                            </template>
                        </li>
                    </ul>

                    <div v-if="description" class="space-y-1.5">
                        <p
                            class="text-sm leading-relaxed"
                            :class="expanded ? '' : 'line-clamp-3'"
                        >
                            {{ description }}
                        </p>
                        <button
                            v-if="longDescription"
                            type="button"
                            class="text-primary text-sm font-medium underline-offset-4 hover:underline"
                            @click="expanded = !expanded"
                        >
                            {{ expanded ? $t('Show less') : $t('Show more') }}
                        </button>
                    </div>

                    <section
                        class="border-border/60 from-background/50 to-primary/5 space-y-2.5 rounded-2xl border bg-gradient-to-br px-3.5 py-3"
                        data-testid="property-nearby-summary"
                    >
                        <div class="flex items-center gap-2">
                            <IconMapPin class="text-primary size-4" />
                            <h3 class="text-sm font-semibold tracking-tight">
                                {{ $t('Nearest to this listing') }}
                            </h3>
                        </div>
                        <p
                            v-if="loadingSummary && !nearby.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ $t('Looking around…') }}
                        </p>
                        <p
                            v-else-if="!nearby.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ $t('No nearby places found yet.') }}
                        </p>
                        <ul v-else class="grid grid-cols-2 gap-x-3 gap-y-2">
                            <li
                                v-for="place in nearbyPreview"
                                :key="`${place.categoryKey}-${place.lat}-${place.lon}`"
                                class="flex items-center gap-2 text-sm"
                                :title="place.name"
                            >
                                <span
                                    class="bg-primary/10 text-primary grid size-7 shrink-0 place-items-center rounded-lg"
                                    aria-hidden="true"
                                >
                                    <component
                                        :is="
                                            nearbyCategoryIcon(place.categoryKey)
                                        "
                                        class="size-3.5"
                                    />
                                </span>
                                <span class="min-w-0 flex-1 truncate font-medium">
                                    {{ $t(nearbyCategoryLabel(place.categoryKey)) }}
                                </span>
                                <span
                                    v-if="place.distance_m !== undefined"
                                    class="text-muted-foreground shrink-0 tabular-nums"
                                >
                                    {{ formatDistance(place.distance_m) }}
                                </span>
                            </li>
                        </ul>
                    </section>

                    <Button
                        class="w-full rounded-xl shadow-lg shadow-primary/20 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-primary/25 active:scale-[0.98]"
                        :disabled="loadingNearby"
                        data-testid="show-nearby"
                        @click="emit('nearby', property)"
                    >
                        <IconCompass class="size-4" />
                        {{
                            loadingNearby
                                ? $t('Looking around…')
                                : $t("What's there?")
                        }}
                    </Button>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
