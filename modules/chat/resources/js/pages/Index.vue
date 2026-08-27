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

const chat = new Chat({
    messages: props.initialMessages,
    transport: new DefaultChatTransport({
        api: route('chat.stream'),
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
    </AppLayout>
</template>
