import { csrfToken } from '@/lib/utils';
import type { WebMcpTool } from '@/webmcp';
import type { Chat } from '@ai-sdk/vue';
import { router } from '@inertiajs/vue3';
import type { ItineraryStop, MapView } from '@modules/chat/resources/js/map';
import type { UIMessage } from 'ai';

interface ChatSession {
    id: string;
    title: string;
}

/** Where the visitor is in the map-ready onboarding. */
export type TripPhase = 'landing' | 'interviewing' | 'reviewing' | 'mapping';

export interface TripState {
    phase: TripPhase;
    question_count: number;
    question: {
        question: string;
        options: string[];
        multiple: boolean;
        count?: number;
    } | null;
    answers: Array<{ question: string; answer: string }>;
    plan: {
        goal: string;
        location: string;
        details: Record<string, unknown>;
        stops?: ItineraryStop[];
    } | null;
}

/** A page tool that is only offered during some phases of the trip. */
export type ChatWebMcpTool = WebMcpTool & { phases?: TripPhase[] };

interface ChatToolDeps {
    chat: Chat<UIMessage>;
    /** Read at call time so the tool list itself never has to be rebuilt. */
    sessions: () => ChatSession[];
    currentConversationId: () => string | null;
    mapLocation: () => Record<string, unknown> | null;
    showOnMap: (view: MapView) => void;
    trip: () => TripState;
    /** Send as the visitor and resolve once the assistant's reply is complete. */
    send: (text: string) => Promise<void>;
    /** Leave the interview and open the map, deterministically. */
    openMap: () => Promise<void>;
    /** Start a fresh conversation with a goal and wait for the first question. */
    startTrip: (goal: string) => Promise<void>;
    /** Bring the plan card back over the open map. */
    showPlan: () => void;
    /** Open the itinerary in the composer's place and point the map at it. */
    showItinerary: () => void;
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
 * live state when called rather than closing over it.
 *
 * Tools carry the phases they belong to. The page registers only the ones
 * that fit the moment, so an agent inspecting the page during the interview
 * sees answer_question and skip_interview, and after the map opens sees the
 * map tools instead. The browser is told each time the set changes.
 */
export function chatTools({
    chat,
    sessions,
    currentConversationId,
    mapLocation,
    showOnMap,
    trip,
    send,
    openMap,
    startTrip,
    showPlan,
    showItinerary,
}: ChatToolDeps): ChatWebMcpTool[] {
    return [
        {
            name: 'read_trip_plan',
            description:
                'Where the visitor is in planning a trip: the phase (interviewing, reviewing, or mapping), the open question with its options, the answers so far, and the saved plan. Call this first to decide what to do next.',
            inputSchema: { type: 'object', properties: {} },
            readOnly: true,
            requiresAuth: true,
            execute: () => ({
                conversation_id: currentConversationId(),
                ...trip(),
            }),
        },
        {
            name: 'start_trip',
            description:
                'Start planning a new trip from one sentence describing the goal and place, e.g. "A rainy Sunday in Porto with kids". Starts a fresh conversation and returns the first interview question. Answer it with answer_question.',
            inputSchema: {
                type: 'object',
                properties: {
                    goal: {
                        type: 'string',
                        description:
                            'What the visitor wants to do and where, in their words.',
                    },
                },
                required: ['goal'],
            },
            requiresAuth: true,
            execute: async (args) => {
                const goal = String(args.goal ?? '').trim();

                if (!goal) {
                    return 'A non-empty goal is required.';
                }

                await startTrip(goal);

                return { conversation_id: currentConversationId(), ...trip() };
            },
        },
        {
            name: 'answer_question',
            description:
                'Answer the open interview question on behalf of the visitor. Pass one of its options verbatim, several joined with ", " if it allows multiple, or free text. Returns the next question, or the saved plan when the interview is complete. Ask the visitor before answering things you do not know. If no question is open, the answer is still passed on and the interview continues.',
            inputSchema: {
                type: 'object',
                properties: {
                    answer: {
                        type: 'string',
                        description:
                            "An option label from read_trip_plan, or the visitor's own words.",
                    },
                },
                required: ['answer'],
            },
            requiresAuth: true,
            phases: ['interviewing'],
            execute: async (args) => {
                const answer = String(args.answer ?? '').trim();

                if (!answer) {
                    return 'A non-empty answer is required.';
                }

                // No open question, say after an interrupted reply, is not a
                // dead end: the assistant reads the answer and asks on from it.
                await send(answer);

                return trip();
            },
        },
        {
            name: 'skip_interview',
            description:
                'End the interview early and open the map with whatever is known so far. Use when the visitor says they just want to see the map.',
            inputSchema: { type: 'object', properties: {} },
            requiresAuth: true,
            phases: ['interviewing'],
            execute: async () => {
                await openMap();

                return trip();
            },
        },
        {
            name: 'open_map',
            description:
                'Accept the reviewed plan and open the map. The assistant then searches for places that match the plan.',
            inputSchema: { type: 'object', properties: {} },
            requiresAuth: true,
            phases: ['reviewing'],
            execute: async () => {
                await openMap();

                return trip();
            },
        },
        {
            name: 'update_trip_plan',
            description:
                'Change the saved plan in plain language, e.g. "make it vegetarian and move it to Saturday evening". The assistant rewrites the plan and, once the map is open, refreshes the places on it. Returns the updated plan.',
            inputSchema: {
                type: 'object',
                properties: {
                    change: {
                        type: 'string',
                        description: 'What should be different about the plan.',
                    },
                },
                required: ['change'],
            },
            requiresAuth: true,
            phases: ['reviewing', 'mapping'],
            execute: async (args) => {
                const change = String(args.change ?? '').trim();

                if (!change) {
                    return 'A non-empty change is required.';
                }

                await send(`Update my plan: ${change}`);

                return trip();
            },
        },
        {
            name: 'ask_this_assistant',
            description:
                "Ask this site's assistant and wait for its full reply. It knows the visitor's plan and history; you do not. During the interview it asks questions rather than answering, so prefer answer_question then. Once the map is open, asking about places moves the map.",
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

                await send(text);

                const reply = chat.messages.at(-1);

                return {
                    conversation_id: currentConversationId(),
                    reply: reply ? textOf(reply) : '',
                    phase: trip().phase,
                    plan: trip().plan,
                };
            },
        },
        {
            name: 'read_current_chat',
            description:
                'Transcript of the conversation on screen, most recent last. Call before answering questions about what was already discussed.',
            inputSchema: {
                type: 'object',
                properties: {
                    limit: {
                        type: 'integer',
                        description:
                            'How many of the most recent messages to return. Default 20.',
                    },
                },
            },
            readOnly: true,
            requiresAuth: true,
            execute: (args) => {
                const limit = Math.max(1, Number(args.limit) || 20);

                return {
                    conversation_id: currentConversationId(),
                    messages: chat.messages.slice(-limit).map((message) => ({
                        role: message.role,
                        text: textOf(message),
                    })),
                };
            },
        },
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
            name: 'show_trip_plan',
            description:
                'Bring the saved plan back on screen over the map so the visitor can review it. Returns the plan. Use update_trip_plan to change it.',
            inputSchema: { type: 'object', properties: {} },
            requiresAuth: true,
            phases: ['mapping'],
            execute: () => {
                showPlan();

                return trip().plan ?? 'There is no saved plan yet.';
            },
        },
        {
            name: 'read_itinerary',
            description:
                "The visitor's day in the order they will do it: each stop's title, the place as the map found it, its coordinates, and a time and note where the assistant gave them. Empty until an itinerary has been built.",
            inputSchema: { type: 'object', properties: {} },
            readOnly: true,
            requiresAuth: true,
            phases: ['mapping'],
            execute: () => {
                const stops = trip().plan?.stops ?? [];

                return stops.length
                    ? stops
                    : 'There is no itinerary yet. Call update_itinerary to have one built.';
            },
        },
        {
            name: 'show_itinerary',
            description:
                'Put the itinerary on screen over the map and move the map to cover its stops. Returns the stops. Use update_itinerary to change them.',
            inputSchema: { type: 'object', properties: {} },
            requiresAuth: true,
            phases: ['mapping'],
            execute: () => {
                const stops = trip().plan?.stops ?? [];

                if (!stops.length) {
                    return 'There is no itinerary yet. Call update_itinerary to have one built.';
                }

                showItinerary();

                return stops;
            },
        },
        {
            name: 'update_itinerary',
            description:
                'Ask the assistant to build or change the itinerary, described in plain language, e.g. "add a lunch stop near the museum" or "plan my Sunday with three indoor stops". Builds the first itinerary when there is none. Waits for the reply, so read_itinerary afterwards returns the new day.',
            inputSchema: {
                type: 'object',
                properties: {
                    change: {
                        type: 'string',
                        description:
                            'What the itinerary should become, or what to change about it.',
                    },
                },
                required: ['change'],
            },
            requiresAuth: true,
            phases: ['mapping'],
            execute: async (args) => {
                const change = String(args.change ?? '').trim();

                if (!change) {
                    return 'Describe what the itinerary should become.';
                }

                const before = JSON.stringify(trip().plan?.stops ?? []);

                await send(`Update my itinerary: ${change}`);

                const stops = trip().plan?.stops ?? [];

                // The assistant can decline, and the server drops stops it
                // cannot place. Returning the stop list either way reads as
                // success, so an agent would have to diff it to notice its
                // instruction went nowhere. Its reason is in the reply.
                if (JSON.stringify(stops) === before) {
                    const reply = chat.messages.at(-1);

                    return `The itinerary did not change. The assistant said: ${
                        reply ? textOf(reply) : 'nothing.'
                    }`;
                }

                return stops;
            },
        },
        {
            name: 'read_map_location',
            description:
                'Where the map beside the conversation is currently pointing. Returns the place name, centre coordinates, zoom, and whether the visitor has dragged it away from that place.',
            inputSchema: { type: 'object', properties: {} },
            readOnly: true,
            requiresAuth: true,
            phases: ['mapping'],
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
                            'The place to show, as specific as possible, e.g. "Shibuya, Tokyo, Japan".',
                    },
                },
                required: ['place'],
            },
            requiresAuth: true,
            phases: ['mapping'],
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
    ];
}
