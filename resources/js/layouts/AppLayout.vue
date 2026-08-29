<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import PageTransition from '@/components/PageTransition.vue';

import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { useSidebarState } from '@/composables/useSidebarState';
import { Breadcrumb as BreadcrumbType } from '@/types';
import { Head } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        title?: string;
        breadcrumbs?: BreadcrumbType[];
        /** Off when the page renders AppHeader somewhere of its own. */
        header?: boolean;
    }>(),
    { header: true },
);

// Persist sidebar state across Inertia navigation
const { isOpen } = useSidebarState();
</script>

<template>
    <SidebarProvider v-model:open="isOpen">
        <Head :title="title" />
        <AppSidebar />
        <SidebarInset>
            <AppHeader
                v-if="header"
                :title="title"
                :breadcrumbs="breadcrumbs"
            />

            <!-- Page Heading -->
            <header
                v-if="$slots.header"
                class="flex h-16 shrink-0 items-center gap-2 dark:bg-gray-800"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1">
                <PageTransition>
                    <slot />
                </PageTransition>
            </main>
        </SidebarInset>
    </SidebarProvider>
</template>
