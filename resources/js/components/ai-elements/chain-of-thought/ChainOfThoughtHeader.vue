<script setup lang="ts">
import type { HtmlHTMLAttributes } from 'vue';
import { BrainIcon, ChevronDownIcon } from '@lucide/vue';
import { Collapsible, CollapsibleTrigger } from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import { useChainOfThought } from './context';

const props = defineProps<{
    class?: HtmlHTMLAttributes['class'];
    hideLabel?: boolean;
}>();

const { isOpen, setIsOpen } = useChainOfThought();
</script>

<template>
    <Collapsible :open="isOpen" @update:open="setIsOpen">
        <CollapsibleTrigger
            :class="
                cn(
                    'text-muted-foreground hover:text-foreground flex w-full items-center gap-2 text-sm transition-colors',
                    props.class,
                )
            "
            v-bind="$attrs"
        >
            <slot name="icon">
                <BrainIcon class="size-4" />
            </slot>
            <span v-if="!props.hideLabel" class="flex-1 text-left">
                <slot>Chain of Thought</slot>
            </span>
            <span v-else class="flex-1" aria-hidden="true" />
            <ChevronDownIcon
                :class="
                    cn(
                        'size-4 transition-transform',
                        isOpen ? 'rotate-180' : 'rotate-0',
                    )
                "
            />
        </CollapsibleTrigger>
    </Collapsible>
</template>
