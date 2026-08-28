<script setup lang="ts">
import {
    Conversation,
    ConversationContent,
    ConversationEmptyState,
    ConversationScrollButton,
} from '@/components/ai-elements/conversation';
import { Loader } from '@/components/ai-elements/loader';
import {
    Message,
    MessageContent,
    MessageResponse,
} from '@/components/ai-elements/message';
import {
    PromptInput,
    PromptInputBody,
    PromptInputFooter,
    PromptInputSubmit,
    PromptInputTextarea,
    PromptInputTools,
    type PromptInputMessage,
} from '@/components/ai-elements/prompt-input';
import {
    Reasoning,
    ReasoningContent,
    ReasoningTrigger,
} from '@/components/ai-elements/reasoning';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    ResizableHandle,
    ResizablePanel,
    ResizablePanelGroup,
} from '@/components/ui/resizable';
import AppLayout from '@/layouts/AppLayout.vue';
import { Chat } from '@ai-sdk/vue';
import { DefaultChatTransport, type UIMessage } from 'ai';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

const props = defineProps<{
    initialMessages: UIMessage[];
}>();

const title = 'Chat';

/**
 * Laravel's VerifyCsrfToken accepts the encrypted XSRF-TOKEN cookie as a header.
 * Inertia v3 dropped axios, so nothing sets this for us.
 */
function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const sessionExpired = ref(false);

/**
 * fetch follows redirects transparently, so an expired session arrives here as
 * a 200 containing the login page rather than an error -- the SDK would parse
 * it as an empty stream and show nothing. A real stream is never redirected,
 * so that flag distinguishes the two. Covers both expiry routes: the auth
 * redirect to login, and a 419 CSRF failure, which the exception handler turns
 * into a redirect back to this page.
 */
async function guardedFetch(
    input: RequestInfo | URL,
    init?: RequestInit,
): Promise<Response> {
    const response = await fetch(input, init);

    if (
        response.redirected ||
        response.status === 401 ||
        response.status === 419
    ) {
        sessionExpired.value = true;

        throw new Error('Session expired.');
    }

    return response;
}

const chat = new Chat({
    messages: props.initialMessages,
    transport: new DefaultChatTransport({
        api: route('chat.stream'),
        fetch: guardedFetch,
        // The server derives the conversation from the authenticated user, so
        // only the new message is sent -- never a conversation id.
        prepareSendMessagesRequest: ({ messages }) => ({
            body: {
                message: messages
                    .at(-1)
                    ?.parts.filter((part) => part.type === 'text')
                    .map((part) => part.text)
                    .join('\n'),
            },
            headers: { 'X-XSRF-TOKEN': csrfToken() },
        }),
    }),
});

const messages = computed(() => chat.messages);
const status = computed(() => chat.status);

/**
 * vue-stick-to-bottom only re-pins when its internal isAtBottom flag is already
 * true, and its resize path never sets that flag, so streaming replies do not
 * follow the bottom. Drive it ourselves, releasing as soon as the user scrolls
 * up and re-arming when they come back.
 */
const pane = ref<{ $el?: HTMLElement } | HTMLElement | null>(null);
const stick = ref(true);
let scroller: HTMLElement | null = null;

function findScroller(): HTMLElement | null {
    const value = pane.value;
    const root = value instanceof HTMLElement ? value : (value?.$el ?? null);

    if (!(root instanceof HTMLElement)) {
        return null;
    }

    return (
        [...root.querySelectorAll<HTMLElement>('*')].find((element) =>
            /(auto|scroll)/.test(getComputedStyle(element).overflowY),
        ) ?? null
    );
}

function distanceFromBottom(element: HTMLElement): number {
    return element.scrollHeight - element.scrollTop - element.clientHeight;
}

function onScroll() {
    if (scroller) {
        stick.value = distanceFromBottom(scroller) < 48;
    }
}

function pinToBottom() {
    if (!stick.value) {
        return;
    }

    nextTick(() => {
        scroller ??= findScroller();

        if (scroller) {
            scroller.scrollTop = scroller.scrollHeight;
        }
    });
}

onMounted(() => {
    scroller = findScroller();
    scroller?.addEventListener('scroll', onScroll, { passive: true });
    pinToBottom();
});

onBeforeUnmount(() => scroller?.removeEventListener('scroll', onScroll));

// Fires on every streamed delta, not just on new messages.
watch(
    () =>
        messages.value
            .map((message) =>
                message.parts
                    .map((part) => ('text' in part ? part.text : ''))
                    .join(''),
            )
            .join(''),
    pinToBottom,
);

function goToLogin() {
    window.location.href = route('login');
}

function handleSubmit(message: PromptInputMessage) {
    if (!message.text.trim()) {
        return;
    }

    chat.sendMessage({ text: message.text });
}
</script>

<template>
    <AppLayout :title="$t(title)" :breadcrumbs="[{ title: $t(title) }]">
        <div
            class="flex h-[calc(100svh_-_3.5rem_-_6px)] flex-col md:h-[calc(100svh_-_4.5rem_-_6px)]"
            data-testid="chat-page"
        >
            <ResizablePanelGroup
                direction="horizontal"
                auto-save-id="chat-split"
            >
                <ResizablePanel
                    :default-size="55"
                    :min-size="30"
                    ref="pane"
                    class="flex flex-col"
                    data-testid="chat-pane"
                >
                    <Conversation
                        :initial="{ damping: 1, stiffness: 1, mass: 0.05 }"
                        :resize="{ damping: 1, stiffness: 1, mass: 0.05 }"
                    >
                        <ConversationContent data-testid="chat-messages">
                            <ConversationEmptyState
                                v-if="!messages.length"
                                :title="$t('Ask me anything')"
                                :description="
                                    $t('Your conversation is saved as you go.')
                                "
                                data-testid="chat-empty"
                            />

                            <Message
                                v-for="message in messages"
                                :key="message.id"
                                :from="message.role"
                                :data-testid="`message-${message.id}`"
                            >
                                <MessageContent>
                                    <template
                                        v-for="(part, index) in message.parts"
                                        :key="index"
                                    >
                                        <Reasoning
                                            v-if="part.type === 'reasoning'"
                                            :is-streaming="
                                                status === 'streaming'
                                            "
                                            :data-testid="`reasoning-${message.id}`"
                                        >
                                            <ReasoningTrigger />
                                            <ReasoningContent
                                                :content="part.text"
                                            />
                                        </Reasoning>

                                        <MessageResponse
                                            v-else-if="part.type === 'text'"
                                            :content="part.text"
                                        />
                                    </template>
                                </MessageContent>
                            </Message>

                            <Loader
                                v-if="status === 'submitted'"
                                data-testid="chat-loading"
                            />
                        </ConversationContent>

                        <ConversationScrollButton />
                    </Conversation>

                    <div class="p-4">
                        <PromptInput
                            data-testid="chat-form"
                            @submit="handleSubmit"
                        >
                            <PromptInputBody>
                                <PromptInputTextarea
                                    :placeholder="$t('Send a message...')"
                                    data-testid="chat-input"
                                />
                            </PromptInputBody>
                            <PromptInputFooter>
                                <PromptInputTools />
                                <PromptInputSubmit
                                    :status="status"
                                    data-testid="chat-submit"
                                />
                            </PromptInputFooter>
                        </PromptInput>
                    </div>
                </ResizablePanel>

                <ResizableHandle with-handle />

                <ResizablePanel
                    :default-size="45"
                    :min-size="20"
                    data-testid="context-pane"
                >
                    <div
                        class="text-muted-foreground flex h-full items-center justify-center p-8 text-center text-sm"
                    >
                        {{ $t('Nothing here yet.') }}
                    </div>
                </ResizablePanel>
            </ResizablePanelGroup>
        </div>

        <AlertDialog :open="sessionExpired">
            <AlertDialogContent data-testid="session-expired-dialog">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {{ $t('Your session has expired') }}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {{
                            $t(
                                'You were signed out, so your message was not sent. Sign in again to continue the conversation.',
                            )
                        }}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogAction
                        data-testid="session-expired-login"
                        @click="goToLogin"
                    >
                        {{ $t('Go to login') }}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
