<script setup lang="ts">
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import TypewriterText from './TypewriterText.vue';
import IconChevronRight from '~icons/lucide/chevron-right';
import IconHistory from '~icons/lucide/history';
import IconSquarePen from '~icons/lucide/square-pen';
import IconTrash2 from '~icons/lucide/trash-2';

type Session = { id: string; title: string };

const page = usePage();
const { toggleSidebar } = useSidebar();

const sessions = computed(() => page.props.chat?.sessions ?? []);

const currentId = computed(
    () => page.url.match(/^\/chat\/([^/?#]+)/)?.[1] ?? null,
);

const contextMenu = ref<{
    x: number;
    y: number;
    session: Session;
} | null>(null);
const pendingDelete = ref<Session | null>(null);
const deleteDialogOpen = ref(false);
const deletingId = ref<string | null>(null);

function openContextMenu(event: MouseEvent, session: Session): void {
    event.preventDefault();
    contextMenu.value = {
        x: event.clientX,
        y: event.clientY,
        session,
    };
}

function closeContextMenu(): void {
    contextMenu.value = null;
}

function requestDelete(session: Session): void {
    closeContextMenu();
    pendingDelete.value = session;
    deleteDialogOpen.value = true;
}

function confirmDelete(): void {
    const session = pendingDelete.value;

    if (!session || deletingId.value) {
        return;
    }

    deletingId.value = session.id;
    deleteDialogOpen.value = false;

    router.delete(route('chat.destroy', session.id), {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
            pendingDelete.value = null;
        },
    });
}

function onDocumentClick(): void {
    closeContextMenu();
}

function onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        closeContextMenu();
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onDocumentKeydown);
    document.addEventListener('scroll', closeContextMenu, true);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onDocumentKeydown);
    document.removeEventListener('scroll', closeContextMenu, true);
});
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
                                    @contextmenu.prevent="
                                        openContextMenu($event, session)
                                    "
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

    <Teleport to="body">
        <div
            v-if="contextMenu"
            class="bg-popover text-popover-foreground border-border fixed z-50 min-w-[10rem] overflow-hidden rounded-lg border p-1 shadow-lg"
            :style="{
                top: `${contextMenu.y}px`,
                left: `${contextMenu.x}px`,
            }"
            role="menu"
            data-testid="chat-session-context-menu"
            @click.stop
        >
            <button
                type="button"
                class="hover:bg-destructive/10 text-destructive focus-visible:ring-ring flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-sm outline-none focus-visible:ring-2"
                role="menuitem"
                data-testid="delete-chat-session"
                @click="requestDelete(contextMenu.session)"
            >
                <IconTrash2 class="size-4 shrink-0" aria-hidden="true" />
                {{ $t('Delete chat') }}
            </button>
        </div>
    </Teleport>

    <AlertDialog v-model:open="deleteDialogOpen">
        <AlertDialogContent data-testid="delete-chat-dialog">
            <AlertDialogHeader>
                <AlertDialogTitle>
                    {{ $t('Delete this chat?') }}
                </AlertDialogTitle>
                <AlertDialogDescription>
                    {{
                        $t(
                            'This removes the conversation and its messages permanently. This cannot be undone.',
                        )
                    }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel data-testid="cancel-delete-chat">
                    {{ $t('Cancel') }}
                </AlertDialogCancel>
                <AlertDialogAction
                    class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    data-testid="confirm-delete-chat"
                    :disabled="deletingId !== null"
                    @click.prevent="confirmDelete"
                >
                    {{ $t('Delete chat') }}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
