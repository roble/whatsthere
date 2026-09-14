<script setup lang="ts">
import { safeListingImageUrls } from '@/lib/safeHttpUrl';
import IconChevronLeft from '~icons/lucide/chevron-left';
import IconChevronRight from '~icons/lucide/chevron-right';
import IconImageOff from '~icons/lucide/image-off';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        src?: string | null;
        images?: string[];
        alt?: string;
        eager?: boolean;
        navigable?: boolean;
        showDots?: boolean;
        showCounter?: boolean;
        showThumbnails?: boolean;
        initialIndex?: number;
        /** Swipe-only: no dots, counter, or always-visible arrows. */
        compact?: boolean;
        /** Keep arrows visible even without hover — used in chat embeds. */
        alwaysShowControls?: boolean;
    }>(),
    {
        src: null,
        images: () => [],
        alt: '',
        eager: false,
        navigable: true,
        showDots: true,
        showCounter: true,
        showThumbnails: false,
        initialIndex: 0,
        compact: false,
        alwaysShowControls: false,
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

const index = ref(props.initialIndex);
const status = ref<'empty' | 'loading' | 'ready' | 'error'>(
    sources.value.length ? 'loading' : 'empty',
);
const imageEl = ref<HTMLImageElement | null>(null);
const touchStartX = ref<number | null>(null);
const pointerStartX = ref<number | null>(null);
const swiped = ref(false);

const canNavigate = computed(
    () => props.navigable && sources.value.length > 1,
);

const showDots = computed(() => props.showDots && !props.compact);

const showCounter = computed(() => props.showCounter && !props.compact);

const current = computed(() => sources.value[index.value] ?? null);

watch(
    () => sources.value.join('\0'),
    () => {
        const lastIndex = Math.max(sources.value.length - 1, 0);

        index.value = Math.min(props.initialIndex, lastIndex);
        status.value = sources.value.length ? 'loading' : 'empty';
    },
);

watch(
    () => props.initialIndex,
    (nextIndex) => {
        index.value = nextIndex;
    },
);

watch(current, async () => {
    await nextTick();

    const image = imageEl.value;

    if (image?.complete && image.naturalWidth > 0) {
        status.value = 'ready';
    }
});

function markReady(image: Event | HTMLImageElement): void {
    const target = image instanceof HTMLImageElement ? image : image.target;

    if (target instanceof HTMLImageElement && target.naturalWidth > 0) {
        status.value = 'ready';
    }
}

function fail(): void {
    if (index.value < sources.value.length - 1) {
        index.value += 1;
        status.value = 'loading';

        return;
    }

    status.value = 'error';
}

function syncFromElement(): void {
    const image = imageEl.value;

    if (image?.complete && image.naturalWidth > 0) {
        status.value = 'ready';
    }
}

function goTo(nextIndex: number, event?: Event): void {
    event?.stopPropagation();
    event?.preventDefault();

    if (!canNavigate.value) {
        return;
    }

    const length = sources.value.length;
    index.value = ((nextIndex % length) + length) % length;
    status.value = 'loading';
}

function previous(event?: Event): void {
    goTo(index.value - 1, event);
}

function next(event?: Event): void {
    goTo(index.value + 1, event);
}

function applySwipe(delta: number): boolean {
    if (!canNavigate.value || Math.abs(delta) < 36) {
        return false;
    }

    swiped.value = true;

    if (delta > 0) {
        previous();
    } else {
        next();
    }

    return true;
}

function onTouchStart(event: TouchEvent): void {
    if (!canNavigate.value) {
        return;
    }

    touchStartX.value = event.changedTouches[0]?.clientX ?? null;
    swiped.value = false;
}

function onTouchEnd(event: TouchEvent): void {
    if (touchStartX.value === null || !canNavigate.value) {
        return;
    }

    const endX = event.changedTouches[0]?.clientX ?? touchStartX.value;
    applySwipe(endX - touchStartX.value);
    touchStartX.value = null;
}

function onPointerDown(event: PointerEvent): void {
    if (!canNavigate.value || event.pointerType === 'touch') {
        return;
    }

    pointerStartX.value = event.clientX;
    swiped.value = false;
}

function onPointerUp(event: PointerEvent): void {
    if (pointerStartX.value === null || !canNavigate.value) {
        return;
    }

    applySwipe(event.clientX - pointerStartX.value);
    pointerStartX.value = null;
}

function onImageClick(event: MouseEvent): void {
    if (!canNavigate.value || swiped.value) {
        return;
    }

    const target = event.currentTarget;

    if (!(target instanceof HTMLElement)) {
        return;
    }

    const rect = target.getBoundingClientRect();
    const ratio = (event.clientX - rect.left) / rect.width;

    if (ratio < 0.35) {
        previous(event);
    } else if (ratio > 0.65) {
        next(event);
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (!canNavigate.value) {
        return;
    }

    if (event.key === 'ArrowLeft') {
        previous(event);
    } else if (event.key === 'ArrowRight') {
        next(event);
    }
}

function onClickCapture(event: MouseEvent): void {
    if (swiped.value) {
        event.stopPropagation();
        event.preventDefault();
        swiped.value = false;
    }
}

onMounted(async () => {
    await nextTick();
    syncFromElement();
});
</script>

<template>
    <span
        class="property-gallery group relative isolate flex size-full items-center justify-center overflow-hidden bg-muted"
        :class="canNavigate ? 'cursor-ew-resize' : ''"
        :data-state="status"
        :tabindex="canNavigate ? 0 : undefined"
        role="group"
        :aria-roledescription="canNavigate ? $t('Image gallery') : undefined"
        :aria-label="alt || undefined"
        @touchstart.passive="onTouchStart"
        @touchend.passive="onTouchEnd"
        @pointerdown="onPointerDown"
        @pointerup="onPointerUp"
        @pointercancel="pointerStartX = null"
        @click="onImageClick"
        @click.capture="onClickCapture"
        @keydown="onKeydown"
    >
        <span
            v-if="status === 'loading'"
            class="absolute inset-0 animate-pulse bg-gradient-to-br from-muted via-background/40 to-muted"
            aria-hidden="true"
        />

        <img
            v-if="current && status !== 'error'"
            :key="current"
            ref="imageEl"
            :src="current"
            :alt="alt"
            class="relative z-10 size-full object-cover transition-opacity duration-300"
            :class="status === 'ready' ? 'opacity-100' : 'opacity-70'"
            :loading="eager ? 'eager' : 'lazy'"
            :fetchpriority="eager ? 'high' : 'auto'"
            decoding="async"
            referrerpolicy="no-referrer"
            draggable="false"
            @load="markReady"
            @error="fail"
        />

        <IconImageOff
            v-if="status === 'empty' || status === 'error'"
            class="relative z-10 size-5 text-muted-foreground/70"
            aria-hidden="true"
        />

        <template v-if="canNavigate && status === 'ready'">
            <button
                type="button"
                class="absolute top-1/2 z-20 grid -translate-y-1/2 place-items-center rounded-full border border-white/20 bg-black/45 text-white shadow-lg backdrop-blur-md transition-all duration-200 hover:scale-105 active:scale-95 focus-visible:ring-2 focus-visible:ring-white/40 focus-visible:outline-none"
                :class="[
                    compact ? 'left-0.5 size-5' : 'left-1.5 size-7',
                    alwaysShowControls
                        ? 'opacity-90'
                        : compact
                          ? 'opacity-0 group-hover:opacity-90 group-focus-within:opacity-90'
                          : 'opacity-90 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100',
                ]"
                :aria-label="$t('Previous image')"
                @click="previous"
            >
                <IconChevronLeft :class="compact ? 'size-3' : 'size-4'" />
            </button>
            <button
                type="button"
                class="absolute top-1/2 z-20 grid -translate-y-1/2 place-items-center rounded-full border border-white/20 bg-black/45 text-white shadow-lg backdrop-blur-md transition-all duration-200 hover:scale-105 active:scale-95 focus-visible:ring-2 focus-visible:ring-white/40 focus-visible:outline-none"
                :class="[
                    compact ? 'right-0.5 size-5' : 'right-1.5 size-7',
                    alwaysShowControls
                        ? 'opacity-90'
                        : compact
                          ? 'opacity-0 group-hover:opacity-90 group-focus-within:opacity-90'
                          : 'opacity-90 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100',
                ]"
                :aria-label="$t('Next image')"
                @click="next"
            >
                <IconChevronRight :class="compact ? 'size-3' : 'size-4'" />
            </button>

            <span
                v-if="showCounter"
                class="absolute top-2 right-2 z-20 rounded-full border border-white/15 bg-black/50 px-2 py-0.5 text-[10px] font-semibold text-white tabular-nums backdrop-blur-md"
            >
                {{ index + 1 }} / {{ sources.length }}
            </span>

            <span
                v-if="showDots"
                class="absolute inset-x-0 bottom-2 z-20 flex justify-center gap-1 px-2"
            >
                <button
                    v-for="(_, dotIndex) in sources"
                    :key="dotIndex"
                    type="button"
                    class="size-1.5 rounded-full transition-all duration-200"
                    :class="
                        dotIndex === index
                            ? 'scale-125 bg-white shadow-sm'
                            : 'bg-white/45 hover:bg-white/70'
                    "
                    :aria-label="
                        $t('View image :number', {
                            number: String(dotIndex + 1),
                        })
                    "
                    @click="goTo(dotIndex, $event)"
                />
            </span>
        </template>

        <div
            v-if="showThumbnails && canNavigate && sources.length > 1"
            class="absolute inset-x-0 bottom-0 z-20 flex gap-1.5 overflow-x-auto bg-gradient-to-t from-black/70 via-black/25 to-transparent px-3 pt-8 pb-2.5 [scrollbar-width:none]"
        >
            <button
                v-for="(thumb, thumbIndex) in sources"
                :key="thumb"
                type="button"
                class="size-10 shrink-0 overflow-hidden rounded-full border-2 bg-black/30 shadow-[0_0_0_1px_rgba(0,0,0,0.45),0_4px_10px_rgba(0,0,0,0.28)] transition-all duration-200 hover:scale-105 focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:outline-none active:scale-95"
                :class="
                    thumbIndex === index
                        ? 'border-white opacity-100'
                        : 'border-white/80 opacity-85 hover:border-white hover:opacity-100'
                "
                :aria-label="
                    $t('View image :number', {
                        number: String(thumbIndex + 1),
                    })
                "
                :aria-current="thumbIndex === index ? 'true' : undefined"
                @click="goTo(thumbIndex, $event)"
            >
                <img
                    :src="thumb"
                    alt=""
                    class="size-full object-cover"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    draggable="false"
                />
            </button>
        </div>
    </span>
</template>
