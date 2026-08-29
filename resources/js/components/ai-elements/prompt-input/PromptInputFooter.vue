<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { InputGroupAddon } from '@/components/ui/input-group';
import { cn } from '@/lib/utils';

type PromptInputFooterProps = InstanceType<typeof InputGroupAddon>['$props'];

interface Props extends /* @vue-ignore */ PromptInputFooterProps {
    class?: HTMLAttributes['class'];
    // 'inline-end' puts the tools on the same row as the textarea, which keeps
    // a composer with no left-hand tools one line tall.
    align?: PromptInputFooterProps['align'];
}

const props = withDefaults(defineProps<Props>(), { align: 'block-end' });
</script>

<template>
    <InputGroupAddon
        :align="props.align"
        :class="cn('justify-between gap-1', props.class)"
    >
        <slot />
    </InputGroupAddon>
</template>
