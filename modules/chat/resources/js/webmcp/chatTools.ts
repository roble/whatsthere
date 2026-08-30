import { csrfToken } from '@/lib/utils';
import type { WebMcpTool } from '@/webmcp';
import type { Chat } from '@ai-sdk/vue';
import { router } from '@inertiajs/vue3';
import type { MapView } from '@modules/chat/resources/js/map';
import type { UIMessage } from 'ai';

interface ChatSession {
    id: string;
    title: string;
}

interface ChatToolDeps {
    chat: Chat<UIMessage>;
    /** Read at call time so the tool list itself never has to be rebuilt. */
    sessions: () => ChatSession[];
    currentConversationId: () => string | null;
    mapLocation: () => Record<string, unknown> | null;
    showOnMap: (view: MapView) => void;
}

/** Flatten a UI message's parts down to the text an agent actually wants. */
function textOf(message: UIMessage): string {
    return message.parts
        .filter((part) => part.type === 'text')
        .map((part) => ('text' in part ? part.text : ''))
        .join('\n');
}

/**
 * What a visitor's own AI agent can do with this chat.
 *
 * These run in the page, inside the session the visitor already has, so they
 * need no tokens, no CORS and no second auth surface. Every `execute` reads
 * live state when called rather than closing over it, which keeps this array
 * constant -- the browser only re-registers when the set genuinely changes.
 *
 * To add a capability later, append one entry. The floating badge, the sign-in
 * gating and the browser registration all derive from this same list.
 */
export function chatTools({
    chat,
    sessions,
    currentConversationId,
    mapLocation,
    showOnMap,
}: ChatToolDeps): WebMcpTool[] {
    return [
        {
            name: 'list_chat_sessions',
            description:
                'Saved conversations, newest first. Returns ids for open_chat_session.',
            inputSchema: { type: 'object', properties: {} },
            readOnly: true,
            requiresAuth: true,
            execute: () => ({
                current: currentConversationId(),
                sessions: sessions(),
            }),
        },
        {
            name: 'read_current_chat',
            description:
                'Transcript of the conversation on screen. Call before answering questions about what was already discussed.',
            inputSchema: { type: 'object', properties: {} },
            readOnly: true,
            requiresAuth: true,
            execute: () => ({
                conversation_id: currentConversationId(),
                messages: chat.messages.map((message) => ({
                    role: message.role,
                    text: textOf(message),
                })),
            }),
        },
        {
            name: 'get_chat_session',
            description:
                'Read a saved conversation by id without opening it. Use this to consult another conversation while leaving the visitor where they are.',
            inputSchema: {
                type: 'object',
                properties: {
                    session_id: {
                        type: 'string',
                        description: 'Id from list_chat_sessions.',
                    },
                },
                required: ['session_id'],
            },
            readOnly: true,
            requiresAuth: true,
            execute: async (args) => {
                const id = String(args.session_id);

                // Same-origin, so the session cookie rides along and the server
                // applies the same ownership check as every other route.
                const response = await fetch(route('chat.messages', id), {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    return `Could not read session ${id} (HTTP ${response.status}). Call list_chat_sessions for valid ids.`;
                }

                return await response.json();
            },
        },
        {
            name: 'open_chat_session',
            description:
                'Switch the page to a saved conversation. Ids come from list_chat_sessions.',
            inputSchema: {
                type: 'object',
                properties: {
                    session_id: {
                        type: 'string',
                        description: 'Id from list_chat_sessions.',
                    },
                },
                required: ['session_id'],
            },
            requiresAuth: true,
            execute: async (args) => {
                const id = String(args.session_id);

                if (!sessions().some((session) => session.id === id)) {
                    return `No such session: ${id}. Call list_chat_sessions first.`;
                }

                router.visit(route('chat.show', id));

                return `Opened session ${id}.`;
            },
        },
        {
            name: 'read_map_location',
            description:
                'Where the map beside the conversation is currently pointing. Returns the place name, centre coordinates, zoom, and whether the visitor has dragged it away from that place.',
            inputSchema: { type: 'object', properties: {} },
            readOnly: true,
            requiresAuth: true,
            execute: () =>
                mapLocation() ?? 'The map has not reported a position yet.',
        },
        {
            name: 'show_place_on_map',
            description:
                'Move the map beside the conversation to a place, without sending a message to the assistant. Use for "show me X" when the visitor wants to look rather than talk.',
            inputSchema: {
                type: 'object',
                properties: {
                    place: {
                        type: 'string',
                        description:
                            'The place in Ireland to show, as specific as possible, e.g. "Douglas, Cork".',
                    },
                },
                required: ['place'],
            },
            requiresAuth: true,
            execute: async (args) => {
                const place = String(args.place ?? '').trim();

                if (!place) {
                    return 'A non-empty place is required.';
                }

                // Same-origin, so this reuses the geocoder and cache the
                // assistant's own show_on_map tool goes through.
                const response = await fetch(route('chat.place'), {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ place }),
                });

                if (!response.ok) {
                    return `Could not place ${place} on the map (HTTP ${response.status}). Try a more specific name.`;
                }

                const view = (await response.json()) as MapView;

                showOnMap(view);

                return `The map is now showing ${view.label}.`;
            },
        },
        {
            name: 'start_new_chat',
            description:
                'Start an empty conversation. Only saved once a message is sent.',
            inputSchema: { type: 'object', properties: {} },
            requiresAuth: true,
            execute: () => {
                router.visit(route('chat.index'));

                return 'Started a new chat.';
            },
        },
        {
            name: 'ask_this_assistant',
            description:
                "Ask this site's assistant and wait for its full reply. It has the visitor's history as context; you do not. Prefer it over guessing.",
            inputSchema: {
                type: 'object',
                properties: {
                    message: {
                        type: 'string',
                        description: 'The question to put to the assistant.',
                    },
                },
                required: ['message'],
            },
            requiresAuth: true,
            execute: async (args) => {
                const text = String(args.message ?? '').trim();

                if (!text) {
                    return 'A non-empty message is required.';
                }

                // Resolves once the stream finishes, so the reply below is complete.
                await chat.sendMessage({ text });

                const reply = chat.messages.at(-1);

                return {
                    conversation_id: currentConversationId(),
                    reply: reply ? textOf(reply) : '',
                };
            },
        },
    ];
}
