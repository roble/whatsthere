<script setup lang="ts">
import SiteLayout from '@/layouts/SiteLayout.vue';
import { useSettings } from '@/composables/useSettings';
import { MapPinned, MessagesSquare, Plug } from '@lucide/vue';

const settings = useSettings();

// Plain data rather than markup so the three read as one list and stay in step.
const features = [
    {
        icon: MessagesSquare,
        title: 'Ask in plain language',
        description:
            'Describe somewhere, or ask a question about it. The assistant answers in the conversation.',
    },
    {
        icon: MapPinned,
        title: 'The map keeps up',
        description:
            'Answers about a place move the map beside you. Pan it yourself and the assistant knows what you are looking at.',
    },
    {
        icon: Plug,
        title: 'Bring your own agent',
        description:
            'The page publishes WebMCP tools, so your own AI agent can read the chat and drive the map.',
    },
];
</script>

<template>
    <SiteLayout :title="settings.general.site_tagline ?? undefined">
        <main class="mx-auto w-full">
            <div
                class="relative overflow-hidden mask-t-from-95% mask-b-from-95% px-6 md:mask-r-from-95% md:mask-l-from-95% md:px-16 lg:px-8"
            >
                <div class="mt-6 pt-24 pb-12">
                    <h1
                        class="text-foreground/80 dark:text-muted-foreground text-center text-4xl font-bold [text-shadow:0_4px_25px_color-mix(in_oklch,var(--color-primary)_15%,var(--color-background))] md:text-5xl"
                    >
                        {{ $t('Every question is about somewhere') }}
                    </h1>
                    <h2
                        class="text-secondary mt-1 text-center text-5xl font-bold md:text-7xl"
                    >
                        {{ $t('Ask what’s there') }}
                    </h2>
                    <p
                        class="text-muted-foreground mx-auto mt-3 max-w-2xl text-center text-xl tracking-tighter md:text-3xl"
                    >
                        {{
                            $t(
                                'A chat about places, with a live map that follows the conversation.',
                            )
                        }}
                    </p>
                </div>

                <div
                    class="relative z-10 mx-auto grid max-w-5xl grid-cols-1 gap-6 px-6 pt-8 pb-16 sm:px-10 md:grid-cols-3 lg:px-20"
                >
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="bg-background/70 rounded-xl border p-6 backdrop-blur-sm"
                        :data-testid="`feature-${feature.title}`"
                    >
                        <component
                            :is="feature.icon"
                            class="text-secondary size-6"
                            aria-hidden="true"
                        />
                        <h3 class="mt-4 text-lg font-semibold">
                            {{ $t(feature.title) }}
                        </h3>
                        <p class="text-muted-foreground mt-2 text-sm">
                            {{ $t(feature.description) }}
                        </p>
                    </div>
                </div>

                <!-- Light mode pattern -->
                <div
                    class="absolute inset-0 -top-10 -right-20 -bottom-10 -left-20 -z-1 overflow-hidden md:rotate-[-5deg] md:skew-x-10 dark:hidden"
                    style="
                        background-size: 24px;
                        background-position: top left;
                        background-image: url('data:image/svg+xml,%3Csvg viewBox=%220 0 32 32%22 fill=%22none%22 xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cg opacity=%22.4%22 fill=%22%23011E32%22 fill-opacity=%22.24%22%3E%3Cpath fill-rule=%22evenodd%22 clip-rule=%22evenodd%22 d=%22M0 .5V6h.5V.5H6V0H0v.5ZM.5 32H0v-6h.5v5.5H6v.5H.5ZM32 0v6h-.5V.5H26V0h6Zm0 31.5V26h-.5v5.5H26v.5h6v-.5Z%22/%3E%3Cpath opacity=%22.6%22 d=%22M19 0v.5h-6V0zM19 31.5v.5h-6v-.5zM32 19h-.5v-6h.5zM.5 19H0v-6h.5z%22/%3E%3C/g%3E%3C/svg%3E');
                    "
                />
                <!-- Dark mode pattern -->
                <div
                    class="absolute inset-0 -top-10 -right-20 -bottom-10 -left-20 -z-1 hidden overflow-hidden md:rotate-[-5deg] md:skew-x-10 dark:block"
                    style="
                        background-size: 24px;
                        background-position: top left;
                        background-image: url('data:image/svg+xml,%3Csvg viewBox=%220 0 32 32%22 fill=%22none%22 xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cg opacity=%22.5%22 fill=%22%23ffffff%22 fill-opacity=%22.15%22%3E%3Cpath fill-rule=%22evenodd%22 clip-rule=%22evenodd%22 d=%22M0 .5V6h.5V.5H6V0H0v.5ZM.5 32H0v-6h.5v5.5H6v.5H.5ZM32 0v6h-.5V.5H26V0h6Zm0 31.5V26h-.5v5.5H26v.5h6v-.5Z%22/%3E%3Cpath opacity=%22.6%22 d=%22M19 0v.5h-6V0zM19 31.5v.5h-6v-.5zM32 19h-.5v-6h.5zM.5 19H0v-6h.5z%22/%3E%3C/g%3E%3C/svg%3E');
                    "
                />

                <div
                    class="my-8 mb-36 flex flex-col items-center justify-center gap-6"
                >
                    <div class="relative inline-flex">
                        <!-- Stripe layer behind the call to action -->
                        <div
                            class="stripe absolute inset-0 translate-y-3 rounded-full"
                            :style="{ '--mod-color': 'var(--foreground)' }"
                        />
                        <a
                            :href="route('register')"
                            class="hover:bg-foreground/80 text-background bg-foreground/90 relative flex items-center gap-2 rounded-full px-8 py-4 text-base font-semibold shadow-[0_5px_0_0_color-mix(in_oklch,var(--color-foreground)_85%,black)] transition-all duration-200 hover:-translate-y-1 hover:shadow-[0_9px_0_0_color-mix(in_oklch,var(--color-foreground)_85%,black)]"
                            data-testid="landing-register"
                        >
                            <MapPinned class="size-5" aria-hidden="true" />
                            {{ $t('Start exploring') }}
                        </a>
                    </div>

                    <a
                        :href="route('login')"
                        class="text-muted-foreground hover:text-foreground text-sm underline-offset-4 hover:underline"
                        data-testid="landing-login"
                    >
                        {{ $t('Already have an account? Sign in') }}
                    </a>
                </div>
            </div>
        </main>
    </SiteLayout>
</template>
