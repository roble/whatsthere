<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { ChevronDownIcon } from '@lucide/vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import { ref, watch } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        label: string;
        description?: string;
        status?: 'complete' | 'active' | 'pending';
        /** Steps open by default; a long body can ask to start folded away. */
        defaultOpen?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        status: 'complete',
        description: undefined,
        defaultOpen: true,
    },
);

const statusStyles = {
    complete: 'text-muted-foreground',
    active: 'text-foreground',
    pending: 'text-muted-foreground/50',
};

const isOpen = ref(props.defaultOpen);

// A streaming step is mounted before its body exists, so a caller deciding
// from the body -- "this one is a long list, start it folded" -- can only say
// so once the result lands. Without this the decision would arrive too late to
// have any effect.
watch(
    () => props.defaultOpen,
    (open) => {
        isOpen.value = open;
    },
);
</script>

<template>
    <Collapsible v-model:open="isOpen">
        <div
            :class="
                cn(
                    'flex gap-2 text-sm',
                    statusStyles[props.status],
                    'fade-in-0 slide-in-from-top-2 animate-in',
                    props.class,
                )
            "
            v-bind="$attrs"
        >
            <div class="relative mt-0.5">
                <slot name="icon" />
                <div
                    class="bg-border absolute top-3 bottom-0 left-1/2 -mx-px w-px"
                />
            </div>
            <div class="min-w-0 flex-1">
                <CollapsibleTrigger
                    class="hover:text-foreground flex w-full items-center gap-2 text-left transition-colors"
                >
                    <span class="min-w-0 flex-1">{{ props.label }}</span>
                    <ChevronDownIcon
                        :class="
                            cn(
                                'size-3.5 shrink-0 transition-transform',
                                isOpen ? 'rotate-180' : 'rotate-0',
                            )
                        "
                    />
                </CollapsibleTrigger>
                <CollapsibleContent
                    class="data-[state=closed]:fade-out-0 data-[state=closed]:slide-out-to-top-2 data-[state=open]:slide-in-from-top-2 data-[state=closed]:animate-out data-[state=open]:animate-in mt-2 space-y-2 outline-none"
                >
                    <div
                        v-if="props.description"
                        class="text-muted-foreground text-xs"
                    >
                        {{ props.description }}
                    </div>
                    <slot />
                </CollapsibleContent>
            </div>
        </div>
    </Collapsible>
</template>
