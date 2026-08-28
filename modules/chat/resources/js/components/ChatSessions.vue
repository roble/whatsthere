<script setup lang="ts">
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
import IconHistory from '~icons/lucide/history';
import IconSquarePen from '~icons/lucide/square-pen';

interface ChatSession {
    id: string;
    title: string;
}

const page = usePage<{ chat?: { sessions: ChatSession[] } }>();
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

    <SidebarGroup
        v-if="sessions.length"
        class="group-data-[collapsible=icon]:hidden"
        data-testid="chat-sessions"
    >
        <SidebarGroupLabel>{{ $t('Chats') }}</SidebarGroupLabel>
        <SidebarGroupContent>
            <SidebarMenu>
                <SidebarMenuItem v-for="session in sessions" :key="session.id">
                    <SidebarMenuButton
                        as-child
                        :is-active="session.id === currentId"
                    >
                        <Link
                            :href="route('chat.show', session.id)"
                            :data-testid="`session-${session.id}`"
                        >
                            <span>{{ session.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
