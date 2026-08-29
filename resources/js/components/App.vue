<script setup lang="ts">
import DynamicDialog from '@/components/DynamicDialog.vue';
import GlobalComponents from '@/components/GlobalComponents.vue';
import { Toaster } from '@/components/ui/sonner';
import { syncWebMcpTools, webMcpAuthenticated } from '@/webmcp';
import { usePage } from '@inertiajs/vue3';
import { watchEffect } from 'vue';

const page = usePage();

// Gated tools are withheld while signed out, and restored the moment a visit
// brings a user back, without anything re-registering by hand.
watchEffect(() => {
    // props is undefined on the first render, before Inertia initialises.
    webMcpAuthenticated.value = !!page.props?.auth?.user;
});

syncWebMcpTools();
</script>
<template>
    <GlobalComponents position="top" />
    <Toaster />
    <slot />
    <DynamicDialog />
    <GlobalComponents position="bottom" />
</template>
