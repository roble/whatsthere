<script setup lang="ts">
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import TypewriterText from './TypewriterText.vue';
import IconChevronRight from '~icons/lucide/chevron-right';
import IconHistory from '~icons/lucide/history';
import IconSquarePen from '~icons/lucide/square-pen';

// The shape now lives in the module's page-props declaration, so the sidebar
// and the chat page read one definition instead of restating it.
const page = usePage();
const { toggleSidebar } = useSidebar();

const sessions = computed(() => page.props.chat?.sessions ?? []);

// Which session is open, read from the URL rather than a prop, so the
// highlight is correct on every page the sidebar renders on.
const currentId = computed(
    () => page.url.match(/^\/chat\/([^/?#]+)/)?.[1] ?? null,
);
</script>

<template>
    <SidebarGroup>
        <SidebarGroupContent>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child :tooltip="$t('New chat')">
                        <Link
                            :href="route('chat.index')"
                            data-testid="new-chat"
                        >
                            <IconSquarePen />
                            <span>{{ $t('New chat') }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>

                <!--
                    Collapsed, the list is hidden and this is the way back to it.
                    Expanded, the list is right there, so the shortcut is noise.
                -->
                <SidebarMenuItem
                    class="hidden group-data-[collapsible=icon]:block"
                >
                    <SidebarMenuButton
                        :tooltip="$t('Chat history')"
                        data-testid="chat-history"
                        @click="toggleSidebar"
                    >
                        <IconHistory />
                        <span>{{ $t('Chat history') }}</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>

    <!--
        The only scroll container in the sidebar. `min-h-0` on both this and the
        content is what lets it take the leftover height and scroll internally,
        instead of growing and pushing the user menu off the bottom.
    -->
    <Collapsible
        v-if="sessions.length"
        default-open
        class="group/chats flex min-h-0 flex-1 flex-col"
    >
        <SidebarGroup
            class="flex min-h-0 flex-1 flex-col group-data-[collapsible=icon]:hidden"
            data-testid="chat-sessions"
        >
            <SidebarGroupLabel as-child>
                <CollapsibleTrigger
                    class="hover:bg-sidebar-accent flex w-full items-center rounded-md"
                    data-testid="chat-sessions-toggle"
                >
                    {{ $t('Chats') }}
                    <IconChevronRight
                        class="ml-auto transition-transform duration-200 group-data-[state=open]/chats:rotate-90"
                    />
                </CollapsibleTrigger>
            </SidebarGroupLabel>

            <CollapsibleContent
                class="min-h-0 flex-1 overflow-y-auto"
                data-testid="chat-sessions-list"
            >
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem
                            v-for="session in sessions"
                            :key="session.id"
                        >
                            <SidebarMenuButton
                                as-child
                                :is-active="session.id === currentId"
                            >
                                <Link
                                    :href="route('chat.show', session.id)"
                                    :data-testid="`session-${session.id}`"
                                >
                                    <TypewriterText :text="session.title" />
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </CollapsibleContent>
        </SidebarGroup>
    </Collapsible>
</template>
