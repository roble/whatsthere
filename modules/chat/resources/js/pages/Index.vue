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
    ChainOfThought,
    ChainOfThoughtContent,
    ChainOfThoughtHeader,
    ChainOfThoughtImage,
    ChainOfThoughtSearchResult,
    ChainOfThoughtSearchResults,
    ChainOfThoughtStep,
} from '@/components/ai-elements/chain-of-thought';
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
import { Button } from '@/components/ui/button';
import AppHeader from '@/components/AppHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { csrfToken } from '@/lib/utils';
import { useWebMcpTools } from '@/webmcp';
import ContextMap from '@modules/chat/resources/js/components/ContextMap.vue';
import ThinkingIndicator from '@modules/chat/resources/js/components/ThinkingIndicator.vue';
import {
    toMapView,
    viewKey,
    MAP_TOOLS,
    type MapView,
    type MapViewport,
} from '@modules/chat/resources/js/map';
import { thoughtsFor } from '@modules/chat/resources/js/thoughts';
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

/**
 * Which canned reply to ask for, when the server is in test mode.
 *
 * `?scenario=places` on the page pins what comes back, so a state can be
 * returned to while it is being worked on rather than refreshed towards. It
 * does nothing at all unless the server is in test mode, which production
 * refuses outright.
 */
const scenario = new URLSearchParams(window.location.search).get('scenario');

const chat = new Chat({
    messages: props.initialMessages,
    transport: new DefaultChatTransport({
        api:
            route('chat.stream') +
            (scenario ? `?scenario=${encodeURIComponent(scenario)}` : ''),
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
                map: viewport.value,
            },
            headers: { 'X-XSRF-TOKEN': csrfToken() },
        }),
    }),
});

const messages = computed(() => chat.messages);
const status = computed(() => chat.status);

/** The chat pane's floor, and the width it opens at. */
const CHAT_MIN_SIZE = 25;

/**
 * Where the map sits until a conversation gives it somewhere better.
 *
 * The whole country, because that is the whole subject: opening on one city
 * suggests the assistant is about that city. Matches the `countrycodes=ie`
 * restriction on the geocoder, so nothing findable falls outside this frame.
 */
const defaultView: MapView = {
    label: 'Ireland',
    bbox: ['-10.48', '51.42', '-5.99', '55.44'],
};

/**
 * The newest successful call to a map tool wins, so the map holds its last
 * known place while the visitor asks follow-ups that are not about anywhere.
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
                !MAP_TOOLS.some((tool) => part.type === `tool-${tool}`) ||
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

/**
 * Where the map is pointing, sent with every message.
 *
 * Null only until the map has settled once. It is sent even when nothing in the
 * conversation put it there: the visitor can see the map, so "where am I?" on a
 * fresh chat is a question the assistant should be able to answer.
 */
const viewport = ref<MapViewport | null>(null);

const lastMessageId = computed(() => messages.value.at(-1)?.id);

/**
 * The status the composer should show, which is not always the chat's.
 *
 * A failed reply leaves the chat in 'error' until the next send, and the
 * submit button renders that as a cross -- so the composer sits there looking
 * broken while the visitor is perfectly able to ask something else. The
 * failure is already reported on the message it belongs to, with the retry
 * beside it, so the composer goes back to accepting the next question.
 */
const composerStatus = computed(() =>
    status.value === 'error' ? 'ready' : status.value,
);

/**
 * The newest question, which is not always the newest message.
 *
 * The stream announces the reply with a `start` part before the model has
 * produced anything, so a failure leaves an empty assistant message sitting
 * after the question. Anchoring "not delivered" to the last message would then
 * look for it on that stub and never find it.
 */
const lastUserMessageId = computed(
    () => messages.value.findLast((message) => message.role === 'user')?.id,
);

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
        message.id === lastUserMessageId.value
    );
}

/**
 * An assistant message the model never wrote anything into.
 *
 * The `start` part creates it before the first token, so a reply that fails
 * outright leaves this stub behind. Rendered, it is an empty bubble sitting
 * where the answer should be, which reads as the thoughts having vanished.
 */
function isEmptyReply(message: UIMessage): boolean {
    return message.role === 'assistant' && message.parts.length === 0;
}

/**
 * How many times one message may be resent by hand.
 *
 * A send that has failed three times is failing for a reason retrying will not
 * fix, and an offer that never stops being offered reads as a broken button.
 */
const RETRY_LIMIT = 3;

/** Attempts so far, per message id. */
const retries = ref<Record<string, number>>({});

function retriesLeft(message: UIMessage): number {
    return RETRY_LIMIT - (retries.value[message.id] ?? 0);
}

/**
 * Send a failed message again.
 *
 * regenerate() keeps a *user* message and re-requests from it, so the bubble
 * stays put rather than being appended a second time. The failure surfaces the
 * same way it did the first time -- through the error status -- so there is
 * nothing to catch here that is not already shown.
 */
function retry(message: UIMessage) {
    retries.value[message.id] = (retries.value[message.id] ?? 0) + 1;

    chat.clearError();
    chat.regenerate({ messageId: message.id });
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
        retries.value = {};
        releaseAnchor();
        nextTick(() => conversation.value?.pinToEnd());
    },
);

const page = usePage();

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
        mapLocation: () => viewport.value,
        showOnMap: (view) => {
            overrideView.value = view;
        },
    }),
);

/**
 * The steps to show beside a reply.
 *
 * Which parts become steps, and how each one reads, lives in the thought
 * registry -- adding a kind is an entry there, not a branch in this template.
 */
function thoughts(message: UIMessage) {
    return thoughtsFor(message.parts);
}

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
        :header="false"
    >
        <!-- Full viewport height: the header now sits inside the left column
             rather than above both, so nothing is stacked on top of this. -->
        <div class="flex h-svh flex-col" data-testid="chat-page">
            <ResizablePanelGroup
                direction="horizontal"
                auto-save-id="chat-split"
            >
                <!-- Opens at its minimum so the map gets the room by default;
                     the divider is there for anyone who wants more text. -->
                <ResizablePanel
                    :default-size="CHAT_MIN_SIZE"
                    :min-size="CHAT_MIN_SIZE"
                    ref="pane"
                    class="flex flex-col"
                    data-testid="chat-pane"
                >
                    <AppHeader
                        :title="currentTitle ?? $t(title)"
                        :breadcrumbs="[
                            { title: currentTitle ?? $t('New chat') },
                        ]"
                    />

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
                                v-show="!isEmptyReply(message)"
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
                                    <!-- The whole process in one collapsible,
                                         reasoning and tool calls interleaved in
                                         the order they streamed.

                                         Labelled "Route of thought": the
                                         components keep the upstream ai-elements
                                         names so they still diff against the
                                         registry, only the visible string is
                                         ours. -->
                                    <ChainOfThought
                                        v-if="thoughts(message).length"
                                        :default-open="isWriting(message)"
                                        :data-testid="`thoughts-${message.id}`"
                                    >
                                        <ChainOfThoughtHeader
                                            v-if="isWriting(message)"
                                            hide-label
                                        >
                                            <template #icon>
                                                <ThinkingIndicator />
                                            </template>
                                        </ChainOfThoughtHeader>
                                        <ChainOfThoughtHeader v-else>
                                            {{ $t('Route of thought') }}
                                        </ChainOfThoughtHeader>

                                        <ChainOfThoughtContent>
                                            <ChainOfThoughtStep
                                                v-for="thought in thoughts(
                                                    message,
                                                )"
                                                :key="thought.id"
                                                :label="
                                                    $t(
                                                        thought.label,
                                                        thought.params,
                                                    )
                                                "
                                                :description="
                                                    thought.description
                                                "
                                                :status="thought.status"
                                                :data-testid="`thought-${message.id}-${thought.id}`"
                                            >
                                                <template #icon>
                                                    <component
                                                        :is="thought.icon"
                                                        class="size-4"
                                                    />
                                                </template>

                                                <!-- One branch per ThoughtBody
                                                     variant in the registry. -->
                                                <MessageResponse
                                                    v-if="
                                                        thought.body?.kind ===
                                                        'markdown'
                                                    "
                                                    class="text-muted-foreground! text-xs leading-relaxed"
                                                    :content="thought.body.text"
                                                    mode="static"
                                                />
                                                <!-- The vendored component is
                                                     a single non-wrapping row,
                                                     so a search of any size
                                                     runs off the edge. Set
                                                     here rather than upstream
                                                     so it still diffs against
                                                     the registry. -->
                                                <ChainOfThoughtSearchResults
                                                    v-else-if="
                                                        thought.body?.kind ===
                                                        'results'
                                                    "
                                                    class="flex-wrap gap-y-1.5"
                                                >
                                                    <ChainOfThoughtSearchResult
                                                        v-for="item in thought
                                                            .body.items"
                                                        :key="item"
                                                    >
                                                        {{ item }}
                                                    </ChainOfThoughtSearchResult>
                                                </ChainOfThoughtSearchResults>
                                                <ChainOfThoughtImage
                                                    v-else-if="
                                                        thought.body?.kind ===
                                                        'image'
                                                    "
                                                    :caption="
                                                        thought.body.caption
                                                    "
                                                >
                                                    <img
                                                        :src="thought.body.src"
                                                        alt=""
                                                    />
                                                </ChainOfThoughtImage>
                                            </ChainOfThoughtStep>
                                        </ChainOfThoughtContent>
                                    </ChainOfThought>

                                    <template
                                        v-for="(part, index) in message.parts"
                                        :key="index"
                                    >
                                        <!-- animation-split is left on "auto"
                                             deliberately: the library wraps every
                                             unit in its own inline-block span, so
                                             "char" means hundreds of boxes relaid
                                             out on each streamed token. "auto"
                                             splits latin text by word. -->
                                        <MessageResponse
                                            v-if="part.type === 'text'"
                                            :content="part.text"
                                            :mode="
                                                isWriting(message)
                                                    ? 'streaming'
                                                    : 'static'
                                            "
                                            animation-split="auto"
                                            :animation-duration="90"
                                            caret="block"
                                        />
                                    </template>
                                </MessageContent>

                                <div
                                    v-if="isUndelivered(message)"
                                    class="mt-1 flex flex-col items-end gap-0.5"
                                    :data-testid="`undelivered-${message.id}`"
                                >
                                    <p
                                        class="text-destructive flex items-center gap-1 text-xs"
                                    >
                                        <CircleAlertIcon
                                            class="size-3.5 shrink-0"
                                        />
                                        {{ $t('Not delivered') }}
                                    </p>

                                    <!-- Withdrawn once the attempts are spent,
                                         rather than left there doing nothing. -->
                                    <Button
                                        v-if="retriesLeft(message) > 0"
                                        variant="link"
                                        size="sm"
                                        class="text-muted-foreground h-auto p-0 text-xs"
                                        :data-testid="`retry-${message.id}`"
                                        @click="retry(message)"
                                    >
                                        {{ $t('Try again') }}
                                    </Button>
                                </div>
                            </Message>

                            <!-- Sent, nothing back yet: no assistant message
                                 exists to hang a chain of thought on. -->
                            <ThinkingIndicator v-if="status === 'submitted'" />
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
                                        :status="composerStatus"
                                        data-testid="chat-submit"
                                    />
                                </PromptInputTools>
                            </PromptInputFooter>
                        </PromptInput>
                    </div>
                </ResizablePanel>

                <ResizableHandle with-handle />

                <ResizablePanel
                    :default-size="100 - CHAT_MIN_SIZE"
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
