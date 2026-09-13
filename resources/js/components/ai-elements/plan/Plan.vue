<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { Card } from '@/components/ui/card';
import { Collapsible } from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import { computed } from 'vue';
import { providePlan } from './context';

const props = withDefaults(
    defineProps<{
        defaultOpen?: boolean;
        isStreaming?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { defaultOpen: false, isStreaming: false },
);

providePlan({
    isStreaming: computed(() => props.isStreaming),
});
</script>

<template>
    <Collapsible :default-open="props.defaultOpen" as-child data-slot="plan">
        <Card :class="cn('shadow-none', props.class)">
            <slot />
        </Card>
    </Collapsible>
</template>
