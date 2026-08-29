<script setup lang="ts">
import type { ChatStatus } from 'ai';
import type { HTMLAttributes } from 'vue';
import { ArrowDownIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { computed } from 'vue';
import { useStickToBottomContext } from 'vue-stick-to-bottom';

interface Props {
    class?: HTMLAttributes['class'];
    status?: ChatStatus;
}

const props = defineProps<Props>();
const { isAtBottom, scrollToBottom } = useStickToBottomContext();
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
            <span
                class="size-1 animate-bounce rounded-full bg-current [animation-delay:-0.3s]"
            />
            <span
                class="size-1 animate-bounce rounded-full bg-current [animation-delay:-0.15s]"
            />
            <span class="size-1 animate-bounce rounded-full bg-current" />
        </span>
        <ArrowDownIcon v-else class="size-4" />
    </Button>
</template>
