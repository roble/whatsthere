<script setup lang="ts">
import type { ChatStatus } from 'ai';
import type { HTMLAttributes } from 'vue';
import { ArrowDownIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { computed } from 'vue';
import { useConversationContext } from './context';

interface Props {
    class?: HTMLAttributes['class'];
    status?: ChatStatus;
}

const props = defineProps<Props>();
const { isAtBottom, scrollToBottom } = useConversationContext();
const showScrollButton = computed(() => !isAtBottom.value);

// A reply is on its way: the button doubles as the only progress indicator,
// since nothing auto-scrolls the reply into view any more.
const isBusy = computed(
    () => props.status === 'submitted' || props.status === 'streaming',
);

function handleClick() {
    scrollToBottom();
}
</script>

<template>
    <Button
        v-if="showScrollButton"
        :class="
            cn(
                'dark:bg-background dark:hover:bg-muted absolute bottom-4 left-[50%] translate-x-[-50%] rounded-full',
                props.class,
            )
        "
        :aria-label="isBusy ? 'Generating response' : 'Scroll to bottom'"
        size="icon"
        type="button"
        variant="outline"
        v-bind="$attrs"
        @click="handleClick"
    >
        <span v-if="isBusy" class="flex items-center gap-0.5">
            <span class="dot" />
            <span class="dot" />
            <span class="dot" />
        </span>
        <ArrowDownIcon v-else class="size-4" />
    </Button>
</template>

<style scoped>
/*
 * Tailwind's animate-bounce travels 25% of the element's own height, which on a
 * 4px dot is a 1px twitch. The dots need a fixed travel, not a relative one.
 */
.dot {
    width: 0.25rem;
    height: 0.25rem;
    border-radius: calc(infinity * 1px);
    background-color: currentColor;
    animation: dot-bounce 0.9s ease-in-out infinite;
}

/*
 * The stagger lives here, not in an [animation-delay:] utility: the shorthand
 * above is unlayered and would reset a delay coming from @layer utilities,
 * leaving all three dots in lockstep.
 */
.dot:nth-child(2) {
    animation-delay: 0.15s;
}

.dot:nth-child(3) {
    animation-delay: 0.3s;
}

@keyframes dot-bounce {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-4px);
    }
}

/* Respect reduced motion preferences */
@media (prefers-reduced-motion: reduce) {
    .dot {
        animation: none;
    }
}
</style>
