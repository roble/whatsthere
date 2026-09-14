<script setup lang="ts">
import PropertyGallery from '@modules/chat/resources/js/components/PropertyGallery.vue';
import { safeListingImageUrls } from '@/lib/safeHttpUrl';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        src?: string | null;
        images?: string[];
        alt?: string;
        showCount?: boolean;
        eager?: boolean;
        navigable?: boolean;
        compact?: boolean;
    }>(),
    {
        src: null,
        images: () => [],
        alt: '',
        showCount: false,
        eager: false,
        navigable: false,
        compact: false,
    },
);

const sources = computed(() => {
    const fromList = safeListingImageUrls(props.images);

    if (fromList.length) {
        return fromList;
    }

    const src = safeListingImageUrls([props.src ?? ''])[0];

    return src ? [src] : [];
});
</script>

<template>
    <span class="relative isolate flex size-full">
        <PropertyGallery
            :src="src"
            :images="images"
            :alt="alt"
            :eager="eager"
            :navigable="navigable"
            :compact="compact"
            :show-dots="navigable && !compact"
            :show-counter="navigable && !compact"
        />
        <span
            v-if="showCount && sources.length > 1"
            class="pointer-events-none absolute right-1 bottom-1 z-20 rounded-md bg-black/55 px-1 py-0.5 text-[9px] font-medium text-white tabular-nums backdrop-blur-md"
        >
            {{ sources.length }}
        </span>
    </span>
</template>
