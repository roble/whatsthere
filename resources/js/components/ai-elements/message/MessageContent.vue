<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

interface Props {
    class?: HTMLAttributes['class'];
}

const props = defineProps<Props>();
</script>

<template>
    <div
        :class="
            cn(
                'flex w-fit flex-col gap-2 overflow-hidden text-sm',
                'group-[.is-user]:bg-primary group-[.is-user]:text-primary-foreground group-[.is-user]:ml-auto group-[.is-user]:rounded-lg group-[.is-user]:px-4 group-[.is-user]:py-3',
                'group-[.is-assistant]:text-foreground',
                props.class,
            )
        "
        v-bind="$attrs"
    >
        <slot />
    </div>
</template>
