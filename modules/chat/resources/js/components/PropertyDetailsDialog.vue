<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { MapMarker } from '@modules/chat/resources/js/map';
import IconChevronLeft from '~icons/lucide/chevron-left';
import IconChevronRight from '~icons/lucide/chevron-right';
import IconImage from '~icons/lucide/image';
import { computed, ref, watch } from 'vue';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{ property: MapMarker | null }>();

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
                        {{ property.bedrooms ?? '?' }} {{ $t('bedrooms') }} ·
                        {{ property.property_type }}
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
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
