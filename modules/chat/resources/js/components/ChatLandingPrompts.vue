<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { usePreferredReducedMotion } from '@vueuse/core';
import { Motion } from 'motion-v';
import type { Component } from 'vue';
import { computed } from 'vue';
import IconArrowRight from '~icons/lucide/arrow-right';
import IconRefreshCw from '~icons/lucide/refresh-cw';
import IconSparkles from '~icons/lucide/sparkles';

export type LandingPrompt = {
    icon: Component;
    text: string;
};

const props = defineProps<{
    prompts: LandingPrompt[];
}>();

defineEmits<{
    refresh: [];
    select: [text: string];
}>();

const reduced = usePreferredReducedMotion();
const still = computed(() => reduced.value === 'reduce');
</script>

<template>
    <div class="chat-landing relative px-3 py-4" data-testid="chat-landing">
        <div class="chat-landing__card relative overflow-hidden">
            <div
                class="chat-landing__mesh pointer-events-none absolute inset-0"
                aria-hidden="true"
            />

            <header class="relative space-y-2 px-4 pt-4 pb-1">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-2">
                        <span
                            class="border-primary/20 bg-primary/10 text-primary inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold tracking-wide uppercase"
                        >
                            <IconSparkles class="size-3" aria-hidden="true" />
                            {{ $t('AI property search') }}
                        </span>
                        <h2
                            class="text-foreground text-lg font-semibold tracking-tight"
                        >
                            {{ $t('What matters to you?') }}
                        </h2>
                        <p
                            class="text-muted-foreground text-[13px] leading-relaxed"
                        >
                            {{
                                $t(
                                    'Every matching home and plot is already on the map. Tell me what matters and I will narrow it down.',
                                )
                            }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground hover:text-foreground hover:bg-primary/10 h-8 shrink-0 rounded-xl px-2.5"
                        data-testid="refresh-examples"
                        @click="$emit('refresh')"
                    >
                        <IconRefreshCw
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        {{ $t('More ideas') }}
                    </Button>
                </div>
            </header>

            <ul class="relative space-y-2 px-3 pb-3.5 pt-2">
                <li
                    v-for="(example, index) in props.prompts"
                    :key="example.text"
                >
                    <Motion
                        v-if="!still"
                        as="div"
                        :initial="{ opacity: 0, y: 10 }"
                        :animate="{ opacity: 1, y: 0 }"
                        :transition="{
                            duration: 0.35,
                            delay: 0.06 * index,
                            ease: [0.22, 1, 0.36, 1],
                        }"
                    >
                        <button
                            type="button"
                            class="chat-landing__prompt group w-full"
                            @click="$emit('select', example.text)"
                        >
                            <span
                                class="bg-primary/12 text-primary grid size-8 shrink-0 place-items-center rounded-xl ring-1 ring-primary/15 transition-all duration-200 group-hover:bg-primary/20 group-hover:ring-primary/30"
                                aria-hidden="true"
                            >
                                <component
                                    :is="example.icon"
                                    class="size-4"
                                />
                            </span>
                            <span
                                class="min-w-0 flex-1 text-left text-[13px] leading-snug font-medium [overflow-wrap:anywhere]"
                            >
                                {{ $t(example.text) }}
                            </span>
                            <IconArrowRight
                                class="text-muted-foreground size-4 shrink-0 opacity-0 transition-all duration-200 group-hover:translate-x-0.5 group-hover:text-primary group-hover:opacity-100"
                                aria-hidden="true"
                            />
                        </button>
                    </Motion>
                    <button
                        v-else
                        type="button"
                        class="chat-landing__prompt group w-full"
                        @click="$emit('select', example.text)"
                    >
                        <span
                            class="bg-primary/12 text-primary grid size-8 shrink-0 place-items-center rounded-xl ring-1 ring-primary/15"
                            aria-hidden="true"
                        >
                            <component :is="example.icon" class="size-4" />
                        </span>
                        <span
                            class="min-w-0 flex-1 text-left text-[13px] leading-snug font-medium [overflow-wrap:anywhere]"
                        >
                            {{ $t(example.text) }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
