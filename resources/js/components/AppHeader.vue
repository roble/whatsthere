<script setup lang="ts">
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Breadcrumb as BreadcrumbType } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The sidebar toggle and breadcrumb trail.
 *
 * Split out of AppLayout because a page may need it somewhere other than the
 * top of the content area -- the chat puts it inside its left column so the map
 * beside it runs the full height.
 */
const props = defineProps<{
    title?: string;
    breadcrumbs?: BreadcrumbType[];
}>();

const page = usePage();

// Manual breadcrumbs win; otherwise fall back to the auto-generated ones.
const displayBreadcrumbs = computed(() => {
    if (props.breadcrumbs?.length) return props.breadcrumbs;
    return page.props.breadcrumbs || [];
});
</script>

<template>
    <header
        class="flex h-14 shrink-0 items-center gap-2 border-b transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14"
    >
        <div class="flex items-center gap-2 px-4">
            <SidebarTrigger class="-ml-1" />
            <Separator
                orientation="vertical"
                class="mr-2 data-[orientation=vertical]:h-4"
            />
            <Breadcrumb v-if="props.title || displayBreadcrumbs.length">
                <BreadcrumbList>
                    <template v-if="displayBreadcrumbs.length">
                        <template
                            v-for="(breadcrumb, index) in displayBreadcrumbs"
                            :key="index"
                        >
                            <BreadcrumbItem>
                                <BreadcrumbLink v-if="breadcrumb.url" as-child>
                                    <Link :href="breadcrumb.url">
                                        {{
                                            $t(
                                                breadcrumb.attributes?.label ||
                                                    breadcrumb.title,
                                            )
                                        }}
                                    </Link>
                                </BreadcrumbLink>
                                <BreadcrumbPage v-else>
                                    {{
                                        $t(
                                            breadcrumb.attributes?.label ||
                                                breadcrumb.title,
                                        )
                                    }}
                                </BreadcrumbPage>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator
                                v-if="index < displayBreadcrumbs.length - 1"
                            />
                        </template>
                    </template>
                    <BreadcrumbItem v-else-if="props.title">
                        <BreadcrumbPage>
                            {{ $t(props.title) }}
                        </BreadcrumbPage>
                    </BreadcrumbItem>
                </BreadcrumbList>
            </Breadcrumb>
        </div>
    </header>
</template>
