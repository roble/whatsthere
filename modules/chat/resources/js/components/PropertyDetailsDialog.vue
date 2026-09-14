<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { propertyFacts, type MapMarker } from '@modules/chat/resources/js/map';
import IconChevronLeft from '~icons/lucide/chevron-left';
import IconChevronRight from '~icons/lucide/chevron-right';
import IconCompass from '~icons/lucide/compass';
import IconExternalLink from '~icons/lucide/external-link';
import IconImage from '~icons/lucide/image';
import { Button } from '@/components/ui/button';
import { computed, ref, watch } from 'vue';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    property: MapMarker | null;
    loadingNearby: boolean;
}>();

const emit = defineEmits<{ nearby: [MapMarker] }>();

const imageIndex = ref(0);
const images = computed(() => props.property?.images ?? []);
const selectedImage = computed(() => images.value[imageIndex.value]);

watch(
    () => props.property?.id,
    () => {
        imageIndex.value = 0;
    },
);

function price(property: MapMarker): string {
    return new Intl.NumberFormat('en-IE', {
        style: 'currency',
        currency: property.currency ?? 'EUR',
        maximumFractionDigits: 0,
    }).format((property.asking_price ?? 0) / 100);
}

/**
 * What to call the portal a listing came from.
 *
 * Unknown providers fall back to their own name rather than being hidden: a
 * link to somewhere is more use than no link, and a provider we have not
 * named yet is a data question, not a reason to drop it.
 */
const PORTALS: Record<string, string> = {
    daft: 'Daft.ie',
    myhome: 'MyHome.ie',
    ppr: 'Property Price Register',
};

/**
 * Checked rather than trusted. These URLs arrive from scraped listing data, so
 * the same `http(s)` guard the map popup uses applies here -- a `javascript:`
 * value edited into a fixture must not become a live link in a dialog.
 */
const sourceUrl = computed(() => {
    const source = props.property?.source;

    return source && /^https?:\/\//i.test(source.url) ? source : null;
});

const sourceLabel = computed(() =>
    sourceUrl.value
        ? (PORTALS[sourceUrl.value.provider] ??
          sourceUrl.value.provider.replace(/^./, (c) => c.toUpperCase()))
        : '',
);

function previousImage(): void {
    imageIndex.value =
        (imageIndex.value - 1 + images.value.length) % images.value.length;
}

function nextImage(): void {
    imageIndex.value = (imageIndex.value + 1) % images.value.length;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] gap-0 overflow-y-auto p-0 sm:max-w-3xl"
            data-testid="property-details-dialog"
        >
            <DialogHeader class="sr-only">
                <DialogTitle>{{ property?.name }}</DialogTitle>
                <DialogDescription>{{
                    property?.details?.address
                }}</DialogDescription>
            </DialogHeader>

            <div v-if="property" class="grid md:grid-cols-2">
                <section
                    class="bg-muted relative aspect-[4/3] overflow-hidden md:aspect-auto"
                >
                    <img
                        v-if="selectedImage"
                        :src="selectedImage"
                        :alt="property.name"
                        class="size-full object-cover"
                    />
                    <IconImage
                        v-else
                        class="text-muted-foreground absolute inset-0 m-auto size-12"
                    />

                    <template v-if="images.length > 1">
                        <button
                            type="button"
                            class="bg-background/85 hover:bg-background absolute top-1/2 left-3 grid size-9 -translate-y-1/2 place-items-center rounded-full shadow"
                            :aria-label="$t('Previous image')"
                            @click="previousImage"
                        >
                            <IconChevronLeft class="size-5" />
                        </button>
                        <button
                            type="button"
                            class="bg-background/85 hover:bg-background absolute top-1/2 right-3 grid size-9 -translate-y-1/2 place-items-center rounded-full shadow"
                            :aria-label="$t('Next image')"
                            @click="nextImage"
                        >
                            <IconChevronRight class="size-5" />
                        </button>
                        <span
                            class="bg-background/85 absolute right-3 bottom-3 rounded-full px-2 py-1 text-xs font-medium"
                            >{{ imageIndex + 1 }} / {{ images.length }}</span
                        >
                    </template>
                </section>

                <section class="flex min-w-0 flex-col gap-4 p-6">
                    <div class="space-y-1">
                        <p class="text-primary text-2xl font-semibold">
                            {{ price(property) }}
                        </p>
                        <h2 class="text-xl leading-snug font-semibold">
                            {{ property.name }}
                        </h2>
                        <p class="text-muted-foreground">
                            {{ property.details?.address }}
                        </p>
                    </div>
                    <p class="text-muted-foreground text-sm">
                        {{ propertyFacts(property) }}
                    </p>
                    <p
                        v-if="property.details?.description"
                        class="text-sm leading-relaxed"
                    >
                        {{ property.details.description }}
                    </p>

                    <div
                        v-if="images.length > 1"
                        class="flex gap-2 overflow-x-auto pb-1"
                    >
                        <button
                            v-for="(image, index) in images"
                            :key="image"
                            type="button"
                            class="size-14 shrink-0 overflow-hidden rounded border-2"
                            :class="
                                index === imageIndex
                                    ? 'border-primary'
                                    : 'border-transparent'
                            "
                            :aria-label="
                                $t('View image :number', {
                                    number: String(index + 1),
                                })
                            "
                            @click="imageIndex = index"
                        >
                            <img
                                :src="image"
                                :alt="''"
                                class="size-full object-cover"
                            />
                        </button>
                    </div>

                    <!-- The map is already open beside this, so "what is around
                         here" is answered by dropping the surroundings onto it
                         rather than by describing them. -->
                    <Button
                        class="w-full"
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

                    <!-- Everything above is a copy taken when the listing was
                         imported. This is the only live thing on the card, so
                         it is the way to check the home is still for sale and
                         to arrange a viewing. -->
                    <Button
                        v-if="sourceUrl"
                        as-child
                        variant="outline"
                        class="w-full"
                    >
                        <a
                            :href="sourceUrl.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            data-testid="property-source-link"
                        >
                            <IconExternalLink class="size-4" />
                            {{ $t('View on :portal', { portal: sourceLabel }) }}
                        </a>
                    </Button>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
