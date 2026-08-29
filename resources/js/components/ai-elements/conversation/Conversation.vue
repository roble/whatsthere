<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import { onBeforeUnmount, onMounted, provide, ref } from 'vue';
import { ConversationKey } from './context';

interface Props {
    ariaLabel?: string;
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), {
    ariaLabel: 'Conversation',
});

/** How close to the end still reads as being at the end. */
const BOTTOM_THRESHOLD = 32;

const scrollRef = ref<HTMLElement | null>(null);
const contentRef = ref<HTMLElement | null>(null);
const isAtBottom = ref(true);

/**
 * Held at the end only while a conversation settles into place: markdown,
 * fonts and images all land after the first paint, so a single scroll on mount
 * stops well short of the real bottom.
 *
 * Nothing re-arms this when a reply arrives -- a chat that chases the reply
 * drags the text out from under whoever is reading it. Where the viewport goes
 * during a reply is the owning page's decision.
 */
const pinned = ref(true);

function sync() {
    const element = scrollRef.value;

    if (element) {
        isAtBottom.value =
            element.scrollHeight - element.scrollTop - element.clientHeight <=
            BOTTOM_THRESHOLD;
    }
}

function toEnd() {
    const element = scrollRef.value;

    if (element) {
        element.scrollTop = element.scrollHeight;
    }
}

/** Jump to the end and stay there until something moves the view. */
function pinToEnd() {
    pinned.value = true;
    toEnd();
    sync();
}

function releasePin() {
    pinned.value = false;
}

function scrollToBottom() {
    scrollRef.value?.scrollTo({
        top: scrollRef.value.scrollHeight,
        behavior: 'smooth',
    });
}

let observer: ResizeObserver | undefined;

onMounted(() => {
    // Growing content changes the distance to the end without ever firing a
    // scroll event, so the button would otherwise go stale mid-reply.
    observer = new ResizeObserver(() => {
        if (pinned.value) {
            toEnd();
        }

        sync();
    });

    if (contentRef.value) {
        observer.observe(contentRef.value);
    }

    pinToEnd();
});

onBeforeUnmount(() => observer?.disconnect());

provide(ConversationKey, { scrollRef, isAtBottom, scrollToBottom });

defineExpose({ pinToEnd, releasePin });
</script>

<template>
    <div
        :class="cn('relative min-h-0 flex-1', props.class)"
        role="log"
        :aria-label="props.ariaLabel"
    >
        <div
            ref="scrollRef"
            class="size-full [scrollbar-gutter:stable_both-edges] overflow-y-auto"
            @scroll.passive="sync"
            @wheel.passive="releasePin"
            @touchstart.passive="releasePin"
        >
            <div ref="contentRef">
                <slot />
            </div>
        </div>
    </div>
</template>
