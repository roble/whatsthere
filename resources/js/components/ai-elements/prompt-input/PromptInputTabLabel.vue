<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

interface Props extends /* @vue-ignore */ HTMLAttributes {
    class?: HTMLAttributes['class'];
}

const props = defineProps<Props>();
</script>

<template>
    <h3
        :class="
            cn(
                'text-muted-foreground mb-2 px-3 text-xs font-medium',
                props.class,
            )
        "
        v-bind="props"
    >
        <slot />
    </h3>
</template>
