<script setup lang="ts">
import PropertyGallery from '@modules/chat/resources/js/components/PropertyGallery.vue';
import {
    CHAT_LISTING_MARKERS,
    resolveListingImages,
} from '@modules/chat/resources/js/listingImages';
import { safeListingImageUrl } from '@/lib/safeHttpUrl';
import { computed, inject } from 'vue';
import type { MapMarker } from '@modules/chat/resources/js/map';

const props = defineProps<{
    node: {
        url?: string;
        title?: string | null;
        alt?: string | null;
    };
}>();

const markers = inject(
    CHAT_LISTING_MARKERS,
    computed((): MapMarker[] => []),
);

const listing = computed(() =>
    resolveListingImages(props.node.url, markers.value),
);

const fallbackSrc = computed(() => safeListingImageUrl(props.node.url));

const alt = computed(
    () => listing.value?.name ?? props.node.alt ?? props.node.title ?? '',
);
</script>

<template>
    <figure
        v-if="listing?.images.length"
        class="chat-listing-gallery"
        data-testid="chat-listing-gallery"
    >
        <div class="chat-listing-gallery__frame">
            <PropertyGallery
                :images="listing.images"
                :alt="alt"
                :initial-index="listing.startIndex"
                navigable
                always-show-controls
                show-dots
                show-counter
            />
        </div>
    </figure>
    <figure
        v-else-if="fallbackSrc"
        class="chat-listing-gallery chat-listing-gallery--single"
        data-testid="chat-listing-image"
    >
        <img
            :src="fallbackSrc"
            :alt="alt"
            class="chat-listing-gallery__photo"
            loading="lazy"
            decoding="async"
            referrerpolicy="no-referrer"
            draggable="false"
        />
    </figure>
</template>
