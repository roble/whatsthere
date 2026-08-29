<script setup lang="ts">
import {
    Conversation,
    ConversationContent,
    ConversationEmptyState,
    ConversationScrollButton,
} from '@/components/ai-elements/conversation';
import {
    Message,
    MessageContent,
    MessageResponse,
} from '@/components/ai-elements/message';
import {
    PromptInput,
    PromptInputBody,
    PromptInputFooter,
    PromptInputSpeechButton,
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
import { csrfToken } from '@/lib/utils';
import { useWebMcpTools } from '@/webmcp';
import ContextMap from '@modules/chat/resources/js/components/ContextMap.vue';
import {
    viewKey,
    type MapView,
    type MapViewport,
} from '@modules/chat/resources/js/map';
import { chatTools } from '@modules/chat/resources/js/webmcp/chatTools';
import { Chat } from '@ai-sdk/vue';
import { router, usePage } from '@inertiajs/vue3';
import { CircleAlertIcon } from '@lucide/vue';
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
    conversationId: string | null;
    initialMessages: UIMessage[];
    initialMapView: MapView | null;
}>();

// Tracked separately from the prop: a brand new chat learns its id from the
// first stream response, without an Inertia round trip.
const conversationId = ref(props.conversationId);

const title = 'Chat';

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

    // A new chat is created server-side on its first message. Adopt the id and
    // move onto its own URL so a reload lands back on this conversation.
    const id = response.headers.get('X-Conversation-Id');

    if (id && id !== conversationId.value) {
        conversationId.value = id;

        // One partial visit moves the URL and refreshes the shared sidebar
        // props together, keeping Inertia's page.url honest. `only` keeps
        // initialMessages out of the response, which is what stops the reset
        // watcher wiping the chat that is mid-stream.
        router.visit(route('chat.show', id), {
            replace: true,
            preserveState: true,
            preserveScroll: true,
            only: ['chat'],
        });
    }

    return response;
}

const chat = new Chat({
    messages: props.initialMessages,
    transport: new DefaultChatTransport({
        api: route('chat.stream'),
        fetch: guardedFetch,
        // The id is echoed back, but the server never trusts it: it verifies the
        // conversation belongs to the authenticated user before continuing it.
        prepareSendMessagesRequest: ({ messages }) => ({
            body: {
                message: messages
                    .at(-1)
                    ?.parts.filter((part) => part.type === 'text')
                    .map((part) => part.text)
                    .join('\n'),
                conversation_id: conversationId.value,
                map: mapContext.value,
            },
            headers: { 'X-XSRF-TOKEN': csrfToken() },
        }),
    }),
});

const messages = computed(() => chat.messages);
const status = computed(() => chat.status);

/** Where the map sits until a conversation gives it somewhere better. */
const defaultView: MapView = {
    label: 'Cork',
    bbox: ['-8.55', '51.87', '-8.40', '51.92'],
};

/**
 * The tool returns plain text when it cannot place somewhere, so anything that
 * is not a well-formed view means "leave the map alone".
 */
function toMapView(output: unknown): MapView | null {
    try {
        const parsed = JSON.parse(String(output));

        return Array.isArray(parsed?.bbox) && parsed.bbox.length === 4
            ? (parsed as MapView)
            : null;
    } catch {
        return null;
    }
}

/**
 * The newest successful show_on_map call wins, so the map holds its last known
 * place while the visitor asks follow-ups that are not about anywhere.
 */
const conversationView = computed<MapView>(() => {
    for (const message of [...messages.value].reverse()) {
        const parts = [...message.parts].reverse() as Array<{
            type: string;
            state?: string;
            output?: unknown;
        }>;

        for (const part of parts) {
            if (
                part.type !== 'tool-show_on_map' ||
                part.state !== 'output-available'
            ) {
                continue;
            }

            const view = toMapView(part.output);

            if (view) {
                return view;
            }
        }
    }

    // Nothing streamed this visit, so fall back to where the transcript left
    // the map -- which is what reopening a saved conversation hits.
    return props.initialMapView ?? defaultView;
});

/**
 * A place set straight from a WebMCP tool, bypassing the assistant.
 *
 * It outranks the conversation until the assistant moves the map itself, at
 * which point the newer instruction wins and this is dropped.
 */
const overrideView = ref<MapView | null>(null);

// Keyed, not by reference: the view is rebuilt from the transcript on every
// token, so watching the object would clear the override immediately.
watch(
    () => viewKey(conversationView.value),
    () => {
        overrideView.value = null;
    },
);

const mapView = computed<MapView>(
    () => overrideView.value ?? conversationView.value,
);

const viewport = ref<MapViewport | null>(null);

/**
 * The map position worth telling the assistant about.
 *
 * A conversation that has never mentioned a place is sitting on the default
 * region, which nobody chose. Sending that would invite the assistant to
 * answer about Cork when the visitor never brought it up.
 */
const mapContext = computed<MapViewport | null>(() => {
    if (!viewport.value) {
        return null;
    }

    return !viewport.value.moved && mapView.value === defaultView
        ? null
        : viewport.value;
});

const lastMessageId = computed(() => messages.value.at(-1)?.id);

/**
 * In flight: the message left the browser but nothing has come back yet. The
 * status flips to 'streaming' as soon as the first token lands, so this only
 * covers the wait before any reply exists.
 */
function isPending(message: UIMessage): boolean {
    return (
        status.value === 'submitted' &&
        message.role === 'user' &&
        message.id === lastMessageId.value
    );
}

/**
 * The caret and the per-character reveal belong only on the reply being written
 * right now. Every other message is settled text and renders in one go.
 */
function isWriting(message: UIMessage): boolean {
    return (
        status.value === 'streaming' &&
        message.role === 'assistant' &&
        message.id === lastMessageId.value
    );
}

/**
 * The send failed outright. Session expiry raises its own dialog, so it is
 * excluded here rather than reported in two places at once.
 */
function isUndelivered(message: UIMessage): boolean {
    return (
        status.value === 'error' &&
        !sessionExpired.value &&
        message.role === 'user' &&
        message.id === lastMessageId.value
    );
}

/**
 * Scrolling is driven by sending, not by receiving. On send the new message is
 * pulled up to the top of the pane and the reply is left to grow underneath it;
 * the reply itself is never chased.
 *
 * The target is out of reach at first -- nothing sits below the new message yet
 * -- so it clamps to the true bottom and creeps up as tokens arrive, settling
 * the moment the message reaches the top. Any manual scroll releases the
 * anchor, and nothing re-arms it until the next send.
 */
const ANCHOR_OFFSET = 16;

const pane = ref<{ $el?: HTMLElement } | HTMLElement | null>(null);
const conversation = ref<{
    pinToEnd: () => void;
    releasePin: () => void;
} | null>(null);
let scroller: HTMLElement | null = null;
let anchorId: string | null = null;

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

function releaseAnchor() {
    anchorId = null;
}

function followAnchor() {
    if (anchorId === null) {
        return;
    }

    nextTick(() => {
        scroller ??= findScroller();

        const message = scroller?.querySelector<HTMLElement>(
            `[data-testid="message-${anchorId}"]`,
        );

        if (!scroller || !message) {
            return;
        }

        // Measured from rects rather than offsetTop, which is relative to
        // whichever ancestor happens to be positioned.
        const top =
            scroller.scrollTop +
            message.getBoundingClientRect().top -
            scroller.getBoundingClientRect().top;

        scroller.scrollTop = Math.min(
            top - ANCHOR_OFFSET,
            scroller.scrollHeight - scroller.clientHeight,
        );
    });
}

onMounted(() => {
    scroller = findScroller();
    // Touching the scroll yourself ends the follow. Listening for the gesture
    // rather than for scroll events is what distinguishes a reader's scroll
    // from our own, which fires the same event.
    scroller?.addEventListener('wheel', releaseAnchor, { passive: true });
    scroller?.addEventListener('touchstart', releaseAnchor, { passive: true });
});

onBeforeUnmount(() => {
    scroller?.removeEventListener('wheel', releaseAnchor);
    scroller?.removeEventListener('touchstart', releaseAnchor);
});

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
    followAnchor,
);

/**
 * Inertia reuses this component when moving between sessions, so the Chat
 * instance has to be reset by hand. initialMessages is a fresh array on every
 * visit, which makes it the reliable signal -- conversationId is not, because
 * starting a new chat goes /chat -> /chat with the prop null both times.
 */
watch(
    () => props.initialMessages,
    (initial) => {
        conversationId.value = props.conversationId;
        chat.messages = initial;
        releaseAnchor();
        nextTick(() => conversation.value?.pinToEnd());
    },
);

const page = usePage<{
    chat?: {
        sessions: { id: string; title: string }[];
        retitle_at?: number[];
    };
}>();

const sessions = computed(() => page.props.chat?.sessions ?? []);

const currentTitle = computed(
    () =>
        sessions.value.find((session) => session.id === conversationId.value)
            ?.title ?? null,
);

let retitleTimer: ReturnType<typeof setTimeout> | undefined;

function refreshSessions() {
    router.reload({ only: ['chat'] });
}

/**
 * The title is rewritten by a queued job, so the browser is never told. Rather
 * than poll, refresh once a reply lands and again a few seconds after a
 * milestone, which is the only moment the title can have changed.
 */
function scheduleSessionRefresh() {
    refreshSessions();

    const userMessages = messages.value.filter(
        (message) => message.role === 'user',
    ).length;

    if (page.props.chat?.retitle_at?.includes(userMessages)) {
        clearTimeout(retitleTimer);
        retitleTimer = setTimeout(refreshSessions, 5000);
    }
}

watch(status, (next, previous) => {
    if (previous === 'streaming' && next === 'ready') {
        scheduleSessionRefresh();
    }
});

onBeforeUnmount(() => clearTimeout(retitleTimer));

// A constant array: every execute reads live state when called, so the browser
// never re-registers just because a session was added or a message arrived.
useWebMcpTools(
    chatTools({
        chat,
        sessions: () => sessions.value,
        currentConversationId: () => conversationId.value,
        mapLocation: () => mapContext.value,
        showOnMap: (view) => {
            overrideView.value = view;
        },
    }),
);

function goToLogin() {
    window.location.href = route('login');
}

function handleSubmit(message: PromptInputMessage) {
    if (!message.text.trim()) {
        return;
    }

    chat.sendMessage({ text: message.text });

    // The conversation holds itself at the end until now; from here the anchor
    // owns the viewport, so the two must not both be driving it.
    conversation.value?.releasePin();

    // Anchor on the message just appended, once it has actually rendered.
    nextTick(() => {
        anchorId = messages.value.at(-1)?.id ?? null;
        followAnchor();
    });
}
</script>

<template>
    <AppLayout
        :title="currentTitle ?? $t(title)"
        :breadcrumbs="[{ title: currentTitle ?? $t('New chat') }]"
    >
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
                    <Conversation ref="conversation">
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
                                :class="
                                    message.role === 'user'
                                        ? 'flex-col items-end'
                                        : undefined
                                "
                                :data-testid="`message-${message.id}`"
                            >
                                <MessageContent
                                    :class="
                                        isPending(message) &&
                                        'animate-pulse opacity-60'
                                    "
                                    :data-pending="
                                        isPending(message) || undefined
                                    "
                                >
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
                                            :mode="
                                                isWriting(message)
                                                    ? 'streaming'
                                                    : 'static'
                                            "
                                            animation-split="char"
                                            :animation-duration="90"
                                            caret="block"
                                        />
                                    </template>
                                </MessageContent>

                                <p
                                    v-if="isUndelivered(message)"
                                    class="text-destructive mt-1 flex items-center gap-1 text-xs"
                                    :data-testid="`undelivered-${message.id}`"
                                >
                                    <CircleAlertIcon
                                        class="size-3.5 shrink-0"
                                    />
                                    {{ $t('Not delivered') }}
                                </p>
                            </Message>
                        </ConversationContent>

                        <ConversationScrollButton :status="status" />
                    </Conversation>

                    <div class="p-4">
                        <PromptInput
                            data-testid="chat-form"
                            @submit="handleSubmit"
                        >
                            <PromptInputBody>
                                <PromptInputTextarea
                                    :placeholder="$t('Send a message...')"
                                    rows="1"
                                    class="min-h-0"
                                    data-testid="chat-input"
                                />
                            </PromptInputBody>
                            <PromptInputFooter align="inline-end">
                                <PromptInputTools>
                                    <PromptInputSpeechButton
                                        :aria-label="$t('Dictate a message')"
                                        data-testid="chat-mic"
                                    />
                                    <PromptInputSubmit
                                        :status="status"
                                        data-testid="chat-submit"
                                    />
                                </PromptInputTools>
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
                    <ContextMap :view="mapView" @viewport="viewport = $event" />
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
