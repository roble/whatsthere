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
                // vue-stream-markdown ships an unlayered
                // `.stream-markdown { color: var(--foreground) }`, so the bubble's
                // inherited colour never reaches the text. It also puts a `dark`
                // class on its own root, which re-triggers our own `.dark` token
                // block there and resets --foreground -- so rebinding the variable
                // does not survive either. Setting the colour on that root wins.
                // ponytail: `!` is load-bearing, an unlayered rule beats layered
                // utilities on specificity alone.
                'group-[.is-user]:bg-primary group-[.is-user]:text-primary-foreground group-[.is-user]:[&_.stream-markdown]:text-primary-foreground! group-[.is-user]:ml-auto group-[.is-user]:rounded-lg group-[.is-user]:px-4 group-[.is-user]:py-3',
                'group-[.is-assistant]:text-foreground',
                props.class,
            )
        "
        v-bind="$attrs"
    >
        <slot />
    </div>
</template>
