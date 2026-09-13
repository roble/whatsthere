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
import type { PromptInputMessage } from '@/components/ai-elements/prompt-input';
import {
    Plan,
    PlanContent,
    PlanDescription,
    PlanFooter,
    PlanHeader,
    PlanTitle,
} from '@/components/ai-elements/plan';
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
import ItineraryPanel from '@modules/chat/resources/js/components/ItineraryPanel.vue';
import ChatListingImage from '@modules/chat/resources/js/components/ChatListingImage.vue';
import PlaceLink from '@modules/chat/resources/js/components/PlaceLink.vue';
import PlanSummary from '@modules/chat/resources/js/components/PlanSummary.vue';
import PropertyDetailsDialog from '@modules/chat/resources/js/components/PropertyDetailsDialog.vue';
import PropertyFiltersDialog, {
    type PropertyPreferences,
} from '@modules/chat/resources/js/components/PropertyFiltersDialog.vue';
import PropertyFilterBar from '@modules/chat/resources/js/components/PropertyFilterBar.vue';
import ChatComposerDock from '@modules/chat/resources/js/components/ChatComposerDock.vue';
import ChatLandingPrompts from '@modules/chat/resources/js/components/ChatLandingPrompts.vue';
import PropertyResults from '@modules/chat/resources/js/components/PropertyResults.vue';
import ThinkingIndicator from '@modules/chat/resources/js/components/ThinkingIndicator.vue';
import {
    itineraryView,
    mergeViews,
    toMapView,
    viewKey,
    MAP_TOOLS,
    type ItineraryStop,
    type MapMarker,
    type MapView,
    type MapViewport,
} from '@modules/chat/resources/js/map';
import { thoughtsFor } from '@modules/chat/resources/js/thoughts';
import {
    nearestByCategory,
    slimSelectedProperty,
} from '@modules/chat/resources/js/listing';
import { CHAT_LISTING_MARKERS } from '@modules/chat/resources/js/listingImages';
import {
    chatTools,
    type TripPhase,
} from '@modules/chat/resources/js/webmcp/chatTools';
import { Chat } from '@ai-sdk/vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    Building2Icon,
    CheckIcon,
    CircleAlertIcon,
    ClipboardListIcon,
    HomeIcon,
    KeyRoundIcon,
    MapPinIcon,
    RouteIcon,
    TreesIcon,
} from '@lucide/vue';
import { DefaultChatTransport, type UIMessage } from 'ai';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    provide,
    ref,
    watch,
} from 'vue';

type Onboarding = {
    flow?: 'trip' | 'property';
    phase: string;
    question_count: number;
    answers?: Array<{ question: string; answer: string }>;
    current_question: {
        question: string;
        options: string[];
        multiple: boolean;
        count?: number;
    } | null;
    plan: {
        goal: string;
        location: string;
        details: Record<string, string>;
        stops?: ItineraryStop[];
        preferences?: PropertyPreferences;
    } | null;
};

const props = defineProps<{
    flow: 'trip' | 'property';
    conversationId: string | null;
    initialMessages: UIMessage[];
    initialMapView: MapView | null;
    onboarding: Onboarding | null;
}>();

type ExamplePrompt = {
    icon: typeof HomeIcon;
    text: string;
};

const EXAMPLE_PROMPT_COUNT = 4;

const promptIdeas: ExamplePrompt[] = [
    {
        icon: HomeIcon,
        text: 'I want to buy a house in Cork for under €350,000.',
    },
    {
        icon: Building2Icon,
        text: 'Help me find a two-bedroom apartment in Cork.',
    },
    {
        icon: KeyRoundIcon,
        text: 'Find a home in County Cork with at least three bedrooms.',
    },
    { icon: TreesIcon, text: 'I am looking for a bungalow in Midleton.' },
    { icon: HomeIcon, text: 'Show me houses in Cork under €400,000.' },
    {
        icon: MapPinIcon,
        text: 'I want to buy an apartment in Cork for under €250,000.',
    },
    {
        icon: TreesIcon,
        text: 'Show me building sites in Cork under €80,000.',
    },
    {
        icon: MapPinIcon,
        text: 'I want a plot of land near Midleton.',
    },
    {
        icon: KeyRoundIcon,
        text: 'Find agricultural land in County Cork.',
    },
    {
        icon: HomeIcon,
        text: 'Show me the cheapest homes in Cork per square metre.',
    },
    {
        icon: MapPinIcon,
        text: 'Which listing has the best hospital, school and bus stop nearby?',
    },
];

function samplePromptIdeas(excludedTexts = new Set<string>()): ExamplePrompt[] {
    const unseenIdeas = promptIdeas.filter(
        (idea) => !excludedTexts.has(idea.text),
    );
    const pool = [
        ...(unseenIdeas.length >= EXAMPLE_PROMPT_COUNT
            ? unseenIdeas
            : promptIdeas),
    ];

    for (let index = pool.length - 1; index > 0; index -= 1) {
        const randomIndex = Math.floor(Math.random() * (index + 1));
        [pool[index], pool[randomIndex]] = [pool[randomIndex], pool[index]];
    }

    return pool.slice(0, EXAMPLE_PROMPT_COUNT);
}

const examplePrompts = ref<ExamplePrompt[]>(samplePromptIdeas());

function refreshExamplePrompts(): void {
    examplePrompts.value = samplePromptIdeas(
        new Set(examplePrompts.value.map((idea) => idea.text)),
    );
}

// Tracked separately from the prop: a brand new chat learns its id from the
// first stream response, without an Inertia round trip.
const conversationId = ref(props.conversationId);
const pendingConversationUrl = ref<string | null>(null);
const onboarding = ref<Onboarding | null>(props.onboarding);
const propertyFlow = ref(props.flow === 'property');
watch(
    () => props.flow,
    (flow) => {
        propertyFlow.value = flow === 'property';
    },
);
const searchError = ref('');
const searchingProperties = ref(false);
const selectedPropertyId = ref<number | null>(null);
const propertyFiltersOpen = ref(false);
const selectedAnswers = ref<string[]>([]);
const otherAnswer = ref('');

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

    // A new chat is created server-side on its first message. Adopt the id so
    // follow-up messages use the same conversation, but do not navigate yet:
    // an Inertia visit cancels the active stream before Laravel can persist the
    // assistant reply and its interview-tool result.
    const id = response.headers.get('X-Conversation-Id');

    if (id && id !== conversationId.value) {
        conversationId.value = id;
        pendingConversationUrl.value = route('chat.show', id);
        onboarding.value ??= {
            phase: 'interviewing',
            question_count: 0,
            current_question: null,
            plan: null,
        };
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
                // Only on the first message: whatever the landing page's
                // filters were set to seeds the new conversation, so the reply
                // narrows the search already on screen instead of reopening
                // the widest one. Afterwards the conversation owns them.
                preferences: conversationId.value
                    ? null
                    : propertyPreferences.value,
                selected_property: slimSelectedProperty(selectedProperty.value),
            },
            headers: { 'X-XSRF-TOKEN': csrfToken() },
        }),
    }),
});

const messages = computed(() => chat.messages);
const status = computed(() => chat.status);

type Question = NonNullable<Onboarding['current_question']>;
type MapPlan = NonNullable<Onboarding['plan']>;

function toolOutput<T>(part: {
    type: string;
    state?: string;
    output?: unknown;
}): T | null {
    if (part.state !== 'output-available') {
        return null;
    }

    if (typeof part.output === 'object' && part.output !== null) {
        return part.output as T;
    }

    if (typeof part.output === 'string') {
        try {
            return JSON.parse(part.output) as T;
        } catch {
            return null;
        }
    }

    return null;
}

/**
 * The onboarding state as the transcript tells it, in order.
 *
 * One walk rather than one lookup per tool: a question is only open until the
 * visitor answers it or a plan is saved, which a "latest question anywhere"
 * search cannot tell. When the transcript carries no tool output at all (a
 * reopened conversation is plain text) the server's row stands in.
 */
const transcriptState = computed(() => {
    let question: Question | null = null;
    let plan: MapPlan | null = null;
    let stops: ItineraryStop[] | null = null;
    let sawTools = false;

    for (const message of messages.value) {
        if (message.role === 'user') {
            question = null;
            continue;
        }

        for (const part of message.parts) {
            if (part.type === 'tool-interview_visitor') {
                sawTools = true;
                question = toolOutput<Question>(part) ?? question;
            } else if (part.type === 'tool-save_map_ready_plan') {
                sawTools = true;
                const saved = toolOutput<MapPlan>(part);

                if (saved) {
                    plan = saved;
                    question = null;
                }
            } else if (part.type === 'tool-save_itinerary') {
                sawTools = true;
                // The itinerary tool answers with a map view, not a plan, so
                // its stops are tracked apart and folded back in below.
                stops =
                    toolOutput<{ stops?: ItineraryStop[] }>(part)?.stops ??
                    stops;
            }
        }
    }

    return { question, plan, stops, sawTools };
});

const activeQuestion = computed(() =>
    propertyFlow.value
        ? (onboarding.value?.current_question ?? null)
        : (transcriptState.value.question ??
          (transcriptState.value.sawTools
              ? null
              : (onboarding.value?.current_question ?? null))),
);

const activePlan = computed(() => {
    if (propertyFlow.value) return onboarding.value?.plan ?? null;
    const plan = transcriptState.value.plan ?? onboarding.value?.plan ?? null;
    const stops = transcriptState.value.stops;

    // A plan saved this turn arrives without the stops the row already holds,
    // and stops saved this turn are newer than the row's, so the two halves are
    // merged rather than one winning outright.
    return plan && stops ? { ...plan, stops } : plan;
});

const onboardingPhase = computed(() => {
    if (propertyFlow.value) return onboarding.value?.phase ?? 'interviewing';
    if (onboarding.value?.phase === 'mapping') {
        return 'mapping';
    }

    return activePlan.value && !activeQuestion.value
        ? 'reviewing'
        : 'interviewing';
});

const isMapStaging = computed(
    () => onboarding.value !== null && onboardingPhase.value !== 'mapping',
);

const isPreparingOnboarding = computed(
    () => isMapStaging.value && !activeQuestion.value && !activePlan.value,
);

watch(
    [propertyFlow, onboardingPhase],
    ([isPropertyFlow, phase]) => {
        if (isPropertyFlow && phase === 'reviewing') {
            propertyFiltersOpen.value = true;
        }
    },
    { immediate: true },
);

/**
 * "Show my map" is answered by a whole turn, not by the click.
 *
 * The staging card closes as soon as the phase flips, so without this the map
 * sits empty -- or on the bare location `locate()` flew to -- while the
 * assistant is still searching. It stays up until something is actually placed,
 * or until the turn ends with nothing (the assistant answered in prose).
 */
const awaitingPlaces = ref(false);

watch(
    () => props.onboarding,
    (value) => {
        onboarding.value = value;
    },
);

watch(activeQuestion, () => {
    selectedAnswers.value = [];
    otherAnswer.value = '';
});

/** Chat column width as a percentage of the split (not the whole window). */
const CHAT_DEFAULT_SIZE = 40;
const CHAT_MIN_SIZE = 32;
const CHAT_MAX_SIZE = 58;

/**
 * Where the map sits until a conversation gives it somewhere better.
 *
 * The whole world, because that is the whole subject: opening on one country
 * or city would imply a scope the assistant no longer has.
 */
const defaultView: MapView = {
    label: 'World',
    bbox: ['-180', '-85', '180', '85'],
};

/**
 * The newest successful call to a map tool wins, so the map holds its last
 * known place while the visitor asks follow-ups that are not about anywhere.
 */
const conversationView = computed<MapView>(() => {
    for (const message of [...messages.value].reverse()) {
        const views = (
            message.parts as Array<{
                type: string;
                state?: string;
                output?: unknown;
            }>
        )
            .filter(
                (part) =>
                    MAP_TOOLS.some((tool) => part.type === `tool-${tool}`) &&
                    part.state === 'output-available',
            )
            .map((part) => toMapView(part.output))
            .filter((view): view is MapView => view !== null);

        const merged = mergeViews(views);

        if (merged) {
            return merged;
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
const viewport = ref<MapViewport | null>(null);
const overrideView = ref<MapView | null>(null);
const propertyView = ref<MapView | null>(
    props.initialMapView?.categoryKey === 'property'
        ? props.initialMapView
        : null,
);

// Keyed, not by reference: the view is rebuilt from the transcript on every
// token, so watching the object would clear the override immediately.
watch(
    () => viewKey(conversationView.value),
    () => {
        overrideView.value = null;

        const view = conversationView.value;

        if (view.categoryKey !== 'amenities') {
            return;
        }

        const winner = view.markers?.find(
            (marker) =>
                marker.categoryKey === 'property' && marker.highlight === 'match',
        );

        if (winner?.id != null) {
            selectedPropertyId.value = winner.id;
        }
    },
);

const mapView = computed<MapView>(() => {
    if (overrideView.value) {
        return overrideView.value;
    }

    if (propertyFlow.value && conversationView.value.categoryKey === 'amenities') {
        return conversationView.value;
    }

    if (propertyFlow.value && propertyView.value) {
        return propertyView.value;
    }

    const current = conversationView.value;

    if (
        propertyFlow.value &&
        selectedPropertyId.value !== null &&
        current.categoryKey !== 'property' &&
        propertyView.value
    ) {
        return {
            ...propertyView.value,
            markers: [
                ...(propertyView.value.markers ?? []),
                ...(current.markers ?? []).map((marker) => ({
                    ...marker,
                    categoryKey: marker.categoryKey ?? current.categoryKey,
                })),
            ],
        };
    }

    return current;
});

const propertyListingView = computed<MapView | null>(() => {
    if (!propertyView.value) {
        return null;
    }

    return propertyView.value;
});

/** Keep photo URLs when a streamed tool payload is slimmer than the server view. */
function mergePropertyMarkers(
    existing: MapMarker[] | undefined,
    incoming: MapMarker[] | undefined,
): MapMarker[] | undefined {
    if (!incoming?.length) {
        return incoming;
    }

    const byId = new Map(
        (existing ?? [])
            .filter((marker) => marker.id != null)
            .map((marker) => [marker.id!, marker]),
    );

    return incoming.map((marker) => {
        if (marker.id == null) {
            return marker;
        }

        const previous = byId.get(marker.id);

        if (!previous) {
            return marker;
        }

        const images =
            marker.images?.length ? marker.images : (previous.images ?? []);

        return {
            ...previous,
            ...marker,
            images,
        };
    });
}

watch(
    () => viewKey(conversationView.value),
    () => {
        if (conversationView.value.categoryKey !== 'property') {
            return;
        }

        // Tool payloads are compact during streaming; full listings reload
        // once the turn finishes via refreshOnboarding.
        if (status.value === 'streaming' || status.value === 'submitted') {
            const incoming = conversationView.value;

            propertyView.value = {
                ...incoming,
                markers: mergePropertyMarkers(
                    propertyView.value?.markers,
                    incoming.markers,
                ),
            };
        }

        const markers =
            propertyView.value?.markers ?? conversationView.value.markers ?? [];
        const stillSelected =
            selectedPropertyId.value !== null &&
            markers.some((marker) => marker.id === selectedPropertyId.value);

        if (!stillSelected) {
            selectedPropertyId.value = null;
        }
    },
);

watch(
    () => props.initialMapView,
    (view) => {
        if (propertyFlow.value && view?.categoryKey === 'property') {
            propertyView.value = view;
        }
    },
);

function focusListing(marker: MapMarker, openDetails = false): void {
    selectedPropertyId.value = marker.id ?? null;
    contextMap.value?.focusMarker(marker);

    if (openDetails) {
        propertyDetails.value = marker;
    }
}

function selectProperty(marker: MapMarker): void {
    focusListing(marker, true);
}

function clearSelectedProperty(): void {
    selectedPropertyId.value = null;
    propertyDetails.value = null;
    contextMap.value?.clearSelection();
}

const selectedProperty = computed(
    () =>
        propertyView.value?.markers?.find(
            (marker) => marker.id === selectedPropertyId.value,
        ) ?? null,
);

/**
 * A selected listing is already a conversation. Keep the results as a rail
 * and drop the landing card, or the chip + prompts crush the list into a
 * sliver of one card.
 */
const listingsCompact = computed(
    () => messages.value.length > 0 || selectedPropertyId.value !== null,
);

const propertyPreferences = computed<PropertyPreferences | null>(() => {
    const preferences = onboarding.value?.plan?.preferences;

    return preferences ? (preferences as PropertyPreferences) : null;
});

async function applyPropertyFilters(
    preferences: PropertyPreferences,
): Promise<void> {
    if (searchingProperties.value) {
        return;
    }

    searchingProperties.value = true;
    searchError.value = '';

    try {
        // Before a conversation exists there is nowhere to save these, so the
        // search is run without persisting and the browser keeps the answer
        // until a first message creates the conversation that owns it.
        const response = await guardedFetch(
            conversationId.value
                ? route(
                      'chat.property-preferences.update',
                      conversationId.value,
                  )
                : route('chat.property-search'),
            {
                method: conversationId.value ? 'PATCH' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(preferences),
            },
        );

        if (!response.ok) {
            throw new Error('Search failed');
        }

        const result = await response.json();
        onboarding.value = conversationId.value
            ? result
            : { ...(onboarding.value ?? {}), plan: result.plan };
        overrideView.value = result.map_view as MapView;
        propertyView.value = result.map_view as MapView;
        selectedPropertyId.value = null;
        propertyFiltersOpen.value = false;
    } catch {
        searchError.value =
            'Could not update the property search. Please try again.';
    } finally {
        searchingProperties.value = false;
    }
}

/**
 * The categories a property's surroundings are worth showing as.
 *
 * The everyday questions about a home's area -- schools, food, a park, the
 * shop, getting into town -- rather than every category the tool can search.
 * Each one is a separate cached lookup, so the list is kept short on purpose.
 */
const NEARBY_CATEGORIES = [
    'school',
    'university',
    'college',
    'hospital',
    'clinic',
    'bus_stop',
    'train_station',
    'supermarket',
    'pharmacy',
    'park',
] as const;

const loadingNearby = ref(false);
const loadingNearbySummary = ref(false);
const nearbyPlaces = ref<MapMarker[]>([]);
const nearbyCache = new Map<string, MapView>();

function nearbyCacheKey(property: MapMarker): string {
    return `${property.lat.toFixed(5)},${property.lon.toFixed(5)}`;
}

/**
 * Put everything around a property onto the map, without the assistant.
 *
 * The visitor has already pointed at the property, so there is nothing to
 * interpret and no reason to spend a model call on it.
 */
async function fetchNearby(property: MapMarker): Promise<MapView> {
    const key = nearbyCacheKey(property);
    const cached = nearbyCache.get(key);

    if (cached) {
        return cached;
    }

    const response = await guardedFetch(route('chat.nearby'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            lat: property.lat,
            lon: property.lon,
            label: property.name,
            categories: NEARBY_CATEGORIES,
        }),
    });

    if (!response.ok) {
        throw new Error('Nearby search failed');
    }

    const view = (await response.json()) as MapView;
    nearbyCache.set(key, view);

    return view;
}

async function loadNearbySummary(property: MapMarker): Promise<void> {
    loadingNearbySummary.value = true;

    try {
        nearbyPlaces.value = nearestByCategory(
            (await fetchNearby(property)).markers ?? [],
        );
    } catch {
        nearbyPlaces.value = [];
    } finally {
        loadingNearbySummary.value = false;
    }
}

async function showNearby(property: MapMarker): Promise<void> {
    if (loadingNearby.value) {
        return;
    }

    loadingNearby.value = true;
    searchError.value = '';
    propertyDetails.value = null;
    selectedPropertyId.value = property.id ?? null;

    try {
        const view = await fetchNearby(property);

        overrideView.value = {
            ...view,
            categoryKey: 'property',
            markers: [
                { ...property, categoryKey: 'property' },
                ...(view.markers ?? []),
            ],
        };
    } catch {
        searchError.value = 'Could not load what is nearby. Please try again.';
    } finally {
        loadingNearby.value = false;
    }
}

function highlightProperty(marker: MapMarker | null): void {
    contextMap.value?.highlightMarker(marker);
}

function openPropertyDetails(marker: MapMarker): void {
    selectProperty(marker);
    propertyDetails.value = marker;
}

// Cleared by the map filling up, or by the turn ending either way -- never left
// to hang if the reply never places anything.
watch([() => viewKey(mapView.value), status], () => {
    if (!awaitingPlaces.value) {
        return;
    }

    const placed = mapView.value.markers?.length || mapView.value.stops?.length;

    if (placed || status.value === 'ready' || status.value === 'error') {
        awaitingPlaces.value = false;
    }
});

/**
 * Where the map is pointing, sent with every message.
 *
 * Null only until the map has settled once. It is sent even when nothing in the
 * conversation put it there: the visitor can see the map, so "where am I?" on a
 * fresh chat is a question the assistant should be able to answer.
 */
type ContextMapHandle = {
    focusMarker: (marker: MapMarker) => void;
    highlightMarker: (marker: MapMarker | null) => void;
    clearSelection: () => void;
};

const contextMap = ref<ContextMapHandle | null>(null);
const propertyDetails = ref<MapMarker | null>(null);
const propertyDetailsOpen = computed({
    get: () => propertyDetails.value !== null,
    set: (open: boolean) => {
        if (!open) {
            propertyDetails.value = null;
        }
    },
});

watch(propertyDetails, (property) => {
    nearbyPlaces.value = [];

    if (property) {
        void loadNearbySummary(property);
    }
});

function focusMapMarker(marker: MapMarker): void {
    if (marker.id !== undefined) {
        focusListing(marker);

        return;
    }

    contextMap.value?.focusMarker(marker);
}

/**
 * Open a stop's pin from the itinerary list.
 *
 * A stop calls it a title where a place calls it a name, and `focusMarker`
 * matches on coordinates anyway, so this is only the shape adapter.
 */
/**
 * Places the map can currently be pointed at, by lowercased name.
 *
 * The reply names the same places the tools just put on the map, so the two
 * are matched by name rather than by re-parsing the prose. Only what is on the
 * map right now is linkable: an older search's pins are gone, and a link that
 * moves the camera nowhere is worse than plain text.
 */
const linkablePlaces = computed(() => {
    const places = new Map<string, MapMarker>();

    for (const stop of itineraryStops.value) {
        places.set(stop.title.toLowerCase(), {
            lat: stop.lat,
            lon: stop.lon,
            name: stop.title,
        });
    }

    for (const marker of mapView.value.markers ?? []) {
        places.set(marker.name.toLowerCase(), marker);
    }

    return places;
});

/** Listings whose photo URLs can be expanded into a carousel in chat. */
const listingMarkers = computed(() => {
    const byId = new Map<number, MapMarker>();

    for (const marker of propertyView.value?.markers ?? []) {
        if (marker.id != null) {
            byId.set(marker.id, marker);
        }
    }

    for (const marker of mapView.value.markers ?? []) {
        if (marker.id != null && !byId.has(marker.id)) {
            byId.set(marker.id, marker);
        }
    }

    return [...byId.values()];
});

provide(CHAT_LISTING_MARKERS, listingMarkers);

/**
 * Turn place names in a reply into links to their pin.
 *
 * A string rewrite rather than a walk over the rendered output: the markdown is
 * re-rendered on every streamed token, so anything done to the DOM would be
 * undone immediately. Longest names first, so "Museu Picasso Cafe" is not eaten
 * by "Museu Picasso".
 */
function withPlaceLinks(text: string): string {
    const names = [...linkablePlaces.value.values()]
        .map((place) => place.name)
        // Short names collide with ordinary words often enough to be noise.
        .filter((name) => name.length > 3)
        .sort((a, b) => b.length - a.length);

    if (!names.length) {
        return text;
    }

    const alternatives = names
        .map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
        .join('|');

    // Not already inside a link, and not part of a longer word.
    const pattern = new RegExp(
        `(?<![\\[\\w])(${alternatives})(?![\\w\\]])`,
        'g',
    );

    // The markdown renderer strips the href off every anchor it makes, so the
    // target is a placeholder: what identifies the place on the way back is the
    // link text, which is the name itself.
    return text.replace(pattern, (name) => {
        const place = linkablePlaces.value.get(name.toLowerCase());
        const href = place?.id !== undefined ? `#map-${place.id}` : '#map';

        return `[${name.replace(/[[\]]/g, '\\$&')}](${href})`;
    });
}

/**
 * One listener for the whole transcript rather than a component per link: the
 * links are markdown output, so there is no Vue node to bind to.
 */
const markdownRenderers = { link: PlaceLink, image: ChatListingImage };

function onTranscriptClick(event: MouseEvent): void {
    const link = (event.target as HTMLElement | null)?.closest?.(
        '[data-place]',
    );

    if (!link) {
        return;
    }

    const id = Number(link.getAttribute('data-place-id') ?? '');
    const byId =
        Number.isFinite(id) && id > 0
            ? (propertyView.value?.markers ?? mapView.value.markers ?? []).find(
                  (marker) => marker.id === id,
              )
            : undefined;
    const place =
        byId ??
        linkablePlaces.value.get((link.textContent ?? '').trim().toLowerCase());

    if (!place) {
        return;
    }

    event.preventDefault();
    focusMapMarker(place);
}

function focusStop(stop: ItineraryStop): void {
    focusMapMarker({ lat: stop.lat, lon: stop.lon, name: stop.title });
}

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

let followFrame: number | null = null;

/**
 * Coalesced to one run per frame. Tokens arrive far faster than frames are
 * painted, and every run reads layout, so following on each token forced a
 * layout per token for nothing the visitor could see.
 */
function followAnchor() {
    if (anchorId === null || followFrame !== null) {
        return;
    }

    followFrame = requestAnimationFrame(() => {
        followFrame = null;
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
    // The onboarding row is what the assistant just wrote to; picking it up
    // here is what keeps a later reload landing on the same screen.
    router.reload({ only: ['chat', 'onboarding'] });
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

/**
 * The phase an agent should see the page in. Old conversations predate the
 * onboarding row and open straight onto the map.
 */
const tripPhase = computed<TripPhase>(() => {
    if (!conversationId.value && !messages.value.length) {
        return 'landing';
    }

    return onboarding.value === null
        ? 'mapping'
        : (onboardingPhase.value as TripPhase);
});

// Every execute reads live state when called, so the only thing that rebuilds
// this list, and re-registers with the browser, is a change of phase.
useWebMcpTools(
    computed(() =>
        chatTools({
            chat,
            sessions: () => sessions.value,
            currentConversationId: () => conversationId.value,
            mapLocation: () => viewport.value,
            showOnMap: (view) => {
                overrideView.value = view;
            },
            trip: () => ({
                phase: tripPhase.value,
                question_count:
                    activeQuestion.value?.count ??
                    onboarding.value?.question_count ??
                    0,
                question: activeQuestion.value,
                answers: onboarding.value?.answers ?? [],
                plan: activePlan.value,
            }),
            send,
            openMap: showMap,
            startTrip,
            showPlan,
            showItinerary,
        })
            .filter(
                (tool) =>
                    !propertyFlow.value ||
                    [
                        'answer_question',
                        'open_map',
                        'ask_this_assistant',
                        'read_current_chat',
                        'list_chat_sessions',
                        'open_chat_session',
                    ].includes(tool.name),
            )
            .map((tool) => ({
                // Marked rather than dropped: a phase tool is still part of what
                // this page offers, it is simply not this moment's turn. The
                // browser is only handed the available ones; the panel shows all
                // of them, so the visitor can see where their agent is headed.
                ...tool,
                available:
                    !tool.phases || tool.phases.includes(tripPhase.value),
            })),
    ),
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
    void send(message.text);
}

/**
 * Send as the visitor. Resolves once the assistant's reply has finished
 * streaming, which is what lets an agent chain answers through WebMCP.
 */
async function send(text: string): Promise<void> {
    if (!text.trim()) {
        return;
    }
    if (propertyFlow.value && onboarding.value) {
        onboarding.value.current_question = null;
    }

    // The first stream cannot provide an interview question until the model has
    // started responding. Switch the map into its staged state before sending,
    // so the blank world map never flashes between the landing and the plan.
    // The property flow has nothing to stage: it is already showing results.
    if (
        !conversationId.value &&
        onboarding.value === null &&
        !propertyFlow.value
    ) {
        onboarding.value = {
            phase: 'interviewing',
            question_count: 0,
            current_question: null,
            plan: null,
        };
    }

    const finished = chat.sendMessage({ text });

    // The conversation holds itself at the end until now; from here the anchor
    // owns the viewport, so the two must not both be driving it.
    conversation.value?.releasePin();

    // Anchor on the message just appended, once it has actually rendered.
    nextTick(() => {
        anchorId = messages.value.at(-1)?.id ?? null;
        followAnchor();
    });

    // Whatever the tools wrote is on the server whether or not the reply
    // itself made it back, so a failed turn still has to be picked up: a
    // search that succeeded before the provider gave out would otherwise leave
    // the filters on screen describing a search the results no longer match.
    try {
        await finished;
    } finally {
        await refreshOnboarding();
    }
}

/**
 * Adopt whatever the server now holds for this conversation.
 *
 * Only safe once the stream has ended either way: moving a new conversation
 * onto its durable URL any earlier aborts the stream and loses that state.
 */
async function refreshOnboarding(): Promise<void> {
    // Always read back from the conversation itself once there is one. A plain
    // reload would re-request whatever URL the page is still sitting on, and a
    // first message leaves that as the index -- which answers with the default
    // preferences and would overwrite the search that just ran.
    const target = conversationId.value
        ? route('chat.show', conversationId.value)
        : null;

    await new Promise<void>((resolve) =>
        target
            ? router.visit(target, {
                  replace: true,
                  preserveState: true,
                  preserveScroll: true,
                  only: ['onboarding', 'initialMapView'],
                  onFinish: () => {
                      pendingConversationUrl.value = null;
                      resolve();
                  },
              })
            : router.reload({
                  only: ['onboarding', 'initialMapView'],
                  onFinish: () => resolve(),
              }),
    );
}

/**
 * A new trip from wherever the page is.
 *
 * Reset in place rather than visiting /chat: an Inertia visit remounts this
 * component, and the WebMCP tool mid-call would keep sending through the old
 * instance into the conversation it was meant to leave. The first reply's
 * conversation id then moves the URL, as it does for a typed first message.
 */
async function startTrip(goal: string): Promise<void> {
    propertyFlow.value = true;
    searchError.value = '';
    conversationId.value = null;
    onboarding.value = null;
    chat.messages = [];
    retries.value = {};
    overrideView.value = null;
    releaseAnchor();
    await nextTick();

    await send(goal);
}

function startExample(text: string): void {
    handleSubmit({ text, files: [] });
}

function toggleAnswer(answer: string): void {
    if (!activeQuestion.value?.multiple) {
        selectedAnswers.value = [answer];
        return;
    }

    selectedAnswers.value = selectedAnswers.value.includes(answer)
        ? selectedAnswers.value.filter((value) => value !== answer)
        : [...selectedAnswers.value, answer];
}

function submitAnswer(): void {
    const answer = [...selectedAnswers.value, otherAnswer.value.trim()]
        .filter(Boolean)
        .join(', ');

    if (!answer) {
        return;
    }

    handleSubmit({ text: answer, files: [] });
}

/**
 * Open the map for good.
 *
 * Recorded on the server rather than asked of the assistant: a skip that
 * depends on the model choosing to save a plan is a skip that sometimes does
 * not happen. Both buttons land here.
 */
/**
 * Record a phase change on the server and adopt the row it returns.
 *
 * Only the two visitor-driven moves go through here: opening the map and
 * returning to the interview. The rest of the phase changes are made by the
 * assistant's tools.
 */
async function setPhase(phase: 'mapping' | 'interviewing'): Promise<void> {
    if (!conversationId.value) {
        return;
    }

    const response = await guardedFetch(
        route('chat.onboarding', conversationId.value),
        {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ phase }),
        },
    );

    if (!response.ok) throw new Error('Could not update preferences.');
    onboarding.value = await response.json();
}

async function showMap(): Promise<void> {
    if (!conversationId.value) {
        return;
    }

    if (propertyFlow.value) {
        if (searchingProperties.value) return;
        searchError.value = '';
        searchingProperties.value = true;
        try {
            const response = await guardedFetch(
                route('chat.onboarding', conversationId.value),
                {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ phase: 'mapping' }),
                },
            );
            if (!response.ok) throw new Error('Search failed');
            const result = await response.json();
            onboarding.value = result;
            overrideView.value = result.map_view;
            propertyView.value = result.map_view;
        } catch {
            searchError.value =
                'Could not search properties. Please try again.';
        } finally {
            searchingProperties.value = false;
        }
        return;
    }

    await setPhase('mapping');

    // A map that opens on the blank world reads as a dead button. Fly to the
    // plan's location at once, then let the assistant fill it from the plan.
    const location = onboarding.value?.plan?.location;

    if (location) {
        void locate(location);
    }

    if (status.value === 'ready') {
        awaitingPlaces.value = true;

        chat.sendMessage({
            text: location
                ? `Show me places for my plan in ${location}.`
                : 'Show me places for my plan.',
        });
    }
}

/** Move the map to a named place through the assistant's own geocoder. */
async function locate(place: string): Promise<void> {
    const response = await fetch(route('chat.place'), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ place }),
    });

    if (response.ok) {
        overrideView.value = (await response.json()) as MapView;
    }
}

const skipInterview = showMap;

/**
 * Reopen the interview from the map. The plan and answers stay; the assistant
 * is told the visitor wants more questions, and its tools force one.
 */
async function backToPlanning(): Promise<void> {
    if (!conversationId.value || status.value !== 'ready') {
        return;
    }

    planOpen.value = false;
    try {
        await setPhase('interviewing');
        await send(
            propertyFlow.value
                ? 'I want to change my buying preferences. Ask which preference I want to change, keeping the others.'
                : 'I want to refine my plan. Ask me a few more questions.',
        );
    } catch {
        searchError.value = 'Could not update preferences. Please try again.';
    }
}

/** The plan card can be brought back over the open map. */
const planOpen = ref(false);

function showPlan(): void {
    planOpen.value = true;
}

/**
 * The itinerary takes the composer's place while it is open.
 *
 * Same swap the interview question card uses, so the transcript above stays
 * visible and the visitor is never taken away from the conversation.
 */
const itineraryOpen = ref(false);

const itineraryStops = computed<ItineraryStop[]>(
    () => activePlan.value?.stops ?? [],
);

function showItinerary(): void {
    itineraryOpen.value = true;

    // The conversation may have searched for other things since the itinerary
    // was saved, so the map is pointed back at it rather than left wherever the
    // last reply put it.
    overrideView.value = itineraryView(activePlan.value) ?? overrideView.value;
}

function toggleItinerary(): void {
    if (itineraryOpen.value) {
        itineraryOpen.value = false;

        return;
    }

    showItinerary();
}

/**
 * Show the day as soon as the assistant has one.
 *
 * Keyed on the stops themselves rather than on their count, so rewriting a
 * three-stop day into a different three-stop day still brings it forward. It
 * does not fire for the itinerary already saved when the page loads: reopening
 * an old conversation should land on the map, not on a panel the visitor did
 * not ask for.
 */
watch(
    () =>
        itineraryStops.value
            .map((stop) => `${stop.title}@${stop.lat},${stop.lon}`)
            .join(';'),
    (stops) => {
        if (stops !== '') {
            itineraryOpen.value = true;
        }
    },
);

// A phase change means a new card, so a stale "open" must not carry over.
watch(tripPhase, () => {
    planOpen.value = false;
    itineraryOpen.value = false;
});
</script>

<template>
    <AppLayout
        :title="currentTitle ?? $t(title)"
        :breadcrumbs="[{ title: currentTitle ?? $t('New chat') }]"
        :header="false"
    >
        <!-- Full viewport height: the header now sits inside the left column
             rather than above both, so nothing is stacked on top of this. -->
        <div
            class="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden"
            data-testid="chat-page"
        >
            <ResizablePanelGroup
                direction="horizontal"
                auto-save-id="chat-split-v2"
                class="min-h-0 min-w-0 flex-1 overflow-hidden"
            >
                <ResizablePanel
                    :default-size="CHAT_DEFAULT_SIZE"
                    :min-size="CHAT_MIN_SIZE"
                    :max-size="CHAT_MAX_SIZE"
                    ref="pane"
                    class="chat-pane relative flex min-h-0 min-w-[20rem] flex-col overflow-hidden"
                    data-testid="chat-pane"
                >
                    <AppHeader
                        class="relative z-[1] border-b border-border/40 bg-background/40 backdrop-blur-md"
                        :title="currentTitle ?? $t(title)"
                        :breadcrumbs="[
                            { title: currentTitle ?? $t('New chat') },
                        ]"
                    />

                    <PropertyFilterBar
                        v-if="
                            propertyFlow && !isMapStaging && propertyPreferences
                        "
                        :preferences="propertyPreferences"
                        :saving="searchingProperties"
                        :compact="listingsCompact"
                        @update="applyPropertyFilters"
                        @preferences="propertyFiltersOpen = true"
                    />

                    <div
                        class="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden"
                    >
                    <!-- After the first message the listings become a rail so
                         the transcript stays the reading surface. Expand still
                         opens the full set without covering the search strip. -->
                    <PropertyResults
                        v-if="
                            propertyFlow && !isMapStaging && propertyListingView
                        "
                        :class="listingsCompact ? undefined : 'flex-1'"
                        :dense="listingsCompact"
                        :loading="searchingProperties"
                        :view="propertyListingView"
                        :selected-id="selectedPropertyId"
                        @select="selectProperty"
                        @highlight="highlightProperty"
                        @preferences="propertyFiltersOpen = true"
                    />

                    <PropertyFiltersDialog
                        v-model:open="propertyFiltersOpen"
                        :preferences="propertyPreferences"
                        :saving="searchingProperties"
                        @save="applyPropertyFilters"
                    />

                    <PropertyDetailsDialog
                        v-model:open="propertyDetailsOpen"
                        :property="propertyDetails"
                        :nearby="nearbyPlaces"
                        :loading-nearby="loadingNearby"
                        :loading-summary="loadingNearbySummary"
                        @nearby="showNearby"
                    />

                    <Conversation
                        ref="conversation"
                        class="chat-transcript min-h-0 flex-1"
                        :class="listingsCompact ? undefined : 'flex-none'"
                    >
                        <ConversationContent
                            :class="[
                                'chat-transcript__content',
                                messages.length &&
                                    'chat-transcript__content--active',
                            ]"
                            data-testid="chat-messages"
                            @click="onTranscriptClick"
                        >
                            <!-- The opening screen sits beside the map rather
                                 than in place of it. Every property we hold is
                                 already pinned, so the first message narrows a
                                 search the visitor can see, instead of starting
                                 one they cannot. -->
                            <!-- The opening screen sits beside the map
                                 rather than in place of it, and stays small:
                                 the results above it are the thing worth
                                 looking at, so this is one line of orientation
                                 and a few starting points. -->
                            <ChatLandingPrompts
                                v-if="
                                    !messages.length &&
                                    propertyFlow &&
                                    !selectedPropertyId
                                "
                                :prompts="examplePrompts"
                                @refresh="refreshExamplePrompts"
                                @select="startExample"
                            />

                            <ConversationEmptyState
                                v-else-if="!messages.length"
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
                                :class="[
                                    message.role === 'user'
                                        ? 'chat-message-user max-w-[92%] flex-col items-end'
                                        : 'chat-message-assistant max-w-full',
                                ]"
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
                                    <!-- Keyed on whether the turn is still
                                         writing: `default-open` is only read
                                         once, so without a remount every
                                         finished turn stays expanded and the
                                         transcript becomes three copies of
                                         itself. -->
                                    <ChainOfThought
                                        v-if="thoughts(message).length"
                                        :key="`thoughts-${message.id}-${isWriting(message)}`"
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
                                                :default-open="
                                                    thought.body?.kind !==
                                                    'results'
                                                "
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
                                                        :key="`${item.marker.lat},${item.marker.lon}`"
                                                        as="button"
                                                        type="button"
                                                        class="focus-visible:ring-ring cursor-pointer transition-transform hover:-translate-y-0.5 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                                        :aria-label="
                                                            $t(
                                                                'Show :place on map',
                                                                {
                                                                    place: item.label,
                                                                },
                                                            )
                                                        "
                                                        @click="
                                                            focusMapMarker(
                                                                item.marker,
                                                            )
                                                        "
                                                    >
                                                        {{ item.label }}
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
                                                        class="max-h-full max-w-full rounded-md object-contain"
                                                        referrerpolicy="no-referrer"
                                                    />
                                                </ChainOfThoughtImage>
                                            </ChainOfThoughtStep>
                                        </ChainOfThoughtContent>
                                    </ChainOfThought>

                                    <template
                                        v-for="(part, index) in message.parts"
                                        :key="index"
                                    >
                                        <!-- The library's word animation is off
                                             on purpose. It wraps every word in a
                                             Vue TransitionGroup, and on each
                                             streamed token Vue measures every
                                             word and reads its computed style, so
                                             the cost grows with the reply: a
                                             long answer froze the page for ten
                                             seconds at a time and the animation
                                             never showed. Tokens arriving is the
                                             typewriter; the caret marks it. -->
                                        <MessageResponse
                                            v-if="part.type === 'text'"
                                            :content="
                                                message.role === 'assistant'
                                                    ? withPlaceLinks(part.text)
                                                    : part.text
                                            "
                                            :mode="
                                                isWriting(message)
                                                    ? 'streaming'
                                                    : 'static'
                                            "
                                            :enable-animate="false"
                                            :node-renderers="markdownRenderers"
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

                    <div v-if="activeQuestion && isMapStaging" class="p-4">
                        <div
                            role="group"
                            :aria-label="activeQuestion.question"
                            class="bg-card text-card-foreground max-h-[min(62svh,42rem)] space-y-3 overflow-y-auto rounded-xl border p-4 text-sm shadow-sm"
                            data-testid="onboarding-question"
                        >
                            <h2 class="text-sm font-semibold">
                                {{ activeQuestion.question }}
                            </h2>
                            <p
                                v-if="activeQuestion.count"
                                class="text-muted-foreground text-xs"
                            >
                                {{
                                    $t('Question :count of up to 10', {
                                        count: String(activeQuestion.count),
                                    })
                                }}
                            </p>
                            <button
                                v-for="option in activeQuestion.options"
                                :key="option"
                                type="button"
                                :aria-pressed="selectedAnswers.includes(option)"
                                class="border-input hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring flex w-full items-start gap-1 rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                :class="
                                    selectedAnswers.includes(option) &&
                                    'border-primary bg-primary/5'
                                "
                                @click="toggleAnswer(option)"
                            >
                                <span
                                    class="border-muted-foreground mt-0.5 grid size-5 shrink-0 place-items-center border"
                                    :class="[
                                        activeQuestion.multiple
                                            ? 'rounded-sm'
                                            : 'rounded-full',
                                        selectedAnswers.includes(option) &&
                                            'border-primary bg-primary text-primary-foreground',
                                    ]"
                                    aria-hidden="true"
                                >
                                    <CheckIcon
                                        v-if="selectedAnswers.includes(option)"
                                        class="size-3"
                                    />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium">{{
                                        option
                                    }}</span>
                                </span>
                            </button>
                            <input
                                v-model="otherAnswer"
                                type="text"
                                class="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                :placeholder="
                                    $t('Other — tell us in your own words')
                                "
                                data-testid="onboarding-other"
                                @keydown.enter.prevent="submitAnswer"
                            />
                            <div
                                class="flex items-center justify-between gap-3 pt-1"
                            >
                                <Button
                                    v-if="!propertyFlow"
                                    variant="ghost"
                                    size="sm"
                                    @click="skipInterview"
                                >
                                    {{ $t('Skip for now') }}
                                </Button>
                                <Button
                                    :disabled="
                                        selectedAnswers.length === 0 &&
                                        !otherAnswer.trim()
                                    "
                                    @click="submitAnswer"
                                    data-testid="submit-onboarding-answer"
                                >
                                    {{
                                        activeQuestion.multiple
                                            ? $t('Continue')
                                            : $t('Submit answer')
                                    }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- The itinerary takes the composer's place, exactly as
                         the interview question does, so the conversation above
                         it stays where the visitor left it. -->
                    <!-- Full bleed, no card: the list scrolls against the
                         pane's own edge, so the scrollbar sits outside the
                         stops rather than inset within a rounded box. The
                         header keeps its place while the list moves under it.
                         Scrollbars are themed globally, not here. -->
                    <div
                        v-else-if="itineraryOpen"
                        class="flex max-h-[45vh] min-h-0 flex-col border-t"
                    >
                        <div
                            class="flex items-center justify-between gap-3 px-4 py-2"
                        >
                            <h2 class="text-sm font-semibold">
                                {{ $t('Your itinerary') }}
                            </h2>
                            <Button
                                variant="ghost"
                                size="sm"
                                data-testid="close-itinerary"
                                @click="itineraryOpen = false"
                            >
                                {{ $t('Close') }}
                            </Button>
                        </div>
                        <ItineraryPanel
                            :stops="itineraryStops"
                            class="min-h-0 flex-1 overflow-y-auto"
                            @focus="focusStop"
                        />
                    </div>

                    <ChatComposerDock
                        v-else
                        :selected-property="selectedProperty"
                        :composer-status="composerStatus"
                        :placeholder="
                            $t(
                                selectedProperty
                                    ? 'Ask about this property or its area…'
                                    : 'Send a message...',
                            )
                        "
                        @submit="handleSubmit"
                        @clear-selected="clearSelectedProperty"
                    />
                    </div>
                </ResizablePanel>

                <ResizableHandle with-handle />

                <ResizablePanel
                    :default-size="100 - CHAT_DEFAULT_SIZE"
                    :min-size="100 - CHAT_MAX_SIZE"
                    :max-size="100 - CHAT_MIN_SIZE"
                    class="min-h-0 min-w-0 overflow-hidden"
                    data-testid="context-pane"
                >
                    <div class="relative size-full min-h-0 min-w-0 overflow-hidden">
                        <ContextMap
                            ref="contextMap"
                            :class="
                                isMapStaging || awaitingPlaces || loadingNearby
                                    ? 'blur-md'
                                    : undefined
                            "
                            :view="mapView"
                            :selected-id="selectedPropertyId"
                            @viewport="viewport = $event"
                            @select-property="selectProperty"
                            @open-property="openPropertyDetails"
                        />
                        <div
                            v-if="searchError"
                            class="border-border/60 bg-background/80 absolute right-3 bottom-14 left-3 z-30 rounded-xl border p-3 text-sm shadow-lg backdrop-blur-xl"
                            role="alert"
                            data-testid="property-search-error"
                        >
                            {{ $t(searchError) }}
                            <Button
                                variant="outline"
                                size="sm"
                                @click="showMap"
                                >{{ $t('Retry') }}</Button
                            >
                        </div>
                        <div
                            v-if="isMapStaging && !activeQuestion"
                            class="bg-background/35 absolute inset-0 grid place-items-center p-6 backdrop-blur-xs"
                        >
                            <!-- Nothing to frame yet: while the interview is
                                 being built there is no plan to read, so the
                                 indicator stands on the blurred map alone. -->
                            <div
                                v-if="isPreparingOnboarding"
                                class="flex flex-col items-center gap-3"
                            >
                                <ThinkingIndicator size="lg" />
                                <Button
                                    v-if="
                                        !propertyFlow &&
                                        conversationId &&
                                        status !== 'streaming' &&
                                        status !== 'submitted'
                                    "
                                    variant="ghost"
                                    size="sm"
                                    data-testid="skip-preparing"
                                    @click="skipInterview"
                                >
                                    {{ $t('Skip for now') }}
                                </Button>
                            </div>

                            <div
                                v-else-if="
                                    propertyFlow &&
                                    onboardingPhase === 'reviewing'
                                "
                                class="flex flex-col items-center gap-3"
                            >
                                <Button
                                    variant="outline"
                                    @click="propertyFiltersOpen = true"
                                    >{{ $t('Open buying preferences') }}</Button
                                >
                            </div>

                            <Plan
                                v-else
                                :default-open="true"
                                :is-streaming="status === 'streaming'"
                                class="w-full max-w-xl shadow-lg"
                                data-testid="onboarding-card"
                            >
                                <PlanHeader>
                                    <div class="space-y-1">
                                        <PlanTitle>
                                            {{
                                                onboardingPhase === 'reviewing'
                                                    ? $t(
                                                          propertyFlow
                                                              ? 'Your buying preferences'
                                                              : 'Your map plan',
                                                      )
                                                    : $t(
                                                          'A few quick questions',
                                                      )
                                            }}
                                        </PlanTitle>
                                        <PlanDescription>
                                            {{
                                                onboardingPhase === 'reviewing'
                                                    ? $t(
                                                          'Review this before opening the map.',
                                                      )
                                                    : $t(
                                                          'A few details help make the map useful.',
                                                      )
                                            }}
                                        </PlanDescription>
                                    </div>
                                </PlanHeader>

                                <PlanContent>
                                    <div
                                        v-if="
                                            onboardingPhase === 'reviewing' &&
                                            activePlan
                                        "
                                        class="space-y-4"
                                    >
                                        <PlanSummary :plan="activePlan" />
                                    </div>
                                </PlanContent>

                                <PlanFooter
                                    v-if="onboardingPhase === 'reviewing'"
                                    class="w-full"
                                >
                                    <Button
                                        v-if="propertyFlow"
                                        variant="outline"
                                        :disabled="
                                            searchingProperties ||
                                            status === 'streaming' ||
                                            status === 'submitted'
                                        "
                                        data-testid="edit-property-preferences"
                                        @click="propertyFiltersOpen = true"
                                        >{{ $t('Change preferences') }}</Button
                                    >
                                    <Button
                                        class="ml-auto"
                                        @click="showMap"
                                        data-testid="show-map"
                                        :disabled="
                                            searchingProperties ||
                                            status === 'streaming' ||
                                            status === 'submitted'
                                        "
                                    >
                                        {{
                                            $t(
                                                propertyFlow
                                                    ? 'Search properties'
                                                    : 'Show my map',
                                            )
                                        }}
                                    </Button>
                                </PlanFooter>
                            </Plan>
                        </div>

                        <div
                            v-if="
                                awaitingPlaces ||
                                searchingProperties ||
                                loadingNearby
                            "
                            class="bg-background/35 absolute inset-0 z-10 grid place-items-center p-6 backdrop-blur-xs"
                            data-testid="map-loading"
                        >
                            <ThinkingIndicator size="lg" />
                        </div>

                        <!-- Styled like ContextMap's own controls so they read
                             as part of the map, not the chat. Pressed while the
                             card is open, like the 3D toggle. One row rather
                             than two absolute buttons fighting over the corner. -->
                        <div
                            class="absolute bottom-2.5 left-2.5 z-10 flex items-center gap-2"
                        >
                            <Button
                                v-if="
                                    activePlan && !isMapStaging && !propertyFlow
                                "
                                variant="secondary"
                                size="sm"
                                class="h-7.25 gap-1.5 rounded px-2 shadow-[0_0_0_2px_rgba(0,0,0,0.1)]"
                                :class="
                                    planOpen
                                        ? 'bg-neutral-800 text-white hover:bg-neutral-700'
                                        : 'bg-white text-neutral-800 hover:bg-neutral-100'
                                "
                                :aria-pressed="planOpen"
                                data-testid="show-plan"
                                @click="planOpen = !planOpen"
                            >
                                <ClipboardListIcon class="size-4" />
                                {{ $t('Plan') }}
                            </Button>

                            <Button
                                v-if="itineraryStops.length && !isMapStaging"
                                variant="secondary"
                                size="sm"
                                class="h-7.25 gap-1.5 rounded px-2 shadow-[0_0_0_2px_rgba(0,0,0,0.1)]"
                                :class="
                                    itineraryOpen
                                        ? 'bg-neutral-800 text-white hover:bg-neutral-700'
                                        : 'bg-white text-neutral-800 hover:bg-neutral-100'
                                "
                                :aria-pressed="itineraryOpen"
                                data-testid="show-itinerary"
                                @click="toggleItinerary"
                            >
                                <RouteIcon class="size-4" />
                                {{ $t('Itinerary') }}
                            </Button>
                        </div>

                        <!-- Sits inside the map panel, so it has to fit the
                             map panel: the card is capped at the overlay's
                             height and scrolls its own contents rather than
                             growing past the bottom, which took the footer
                             buttons off screen with it. -->
                        <div
                            v-if="
                                activePlan &&
                                !isMapStaging &&
                                !propertyFlow &&
                                planOpen
                            "
                            class="absolute inset-0 grid place-items-center p-6"
                            @click.self="planOpen = false"
                        >
                            <Plan
                                :default-open="true"
                                class="flex max-h-full w-full max-w-xl flex-col shadow-lg"
                                data-testid="plan-card"
                            >
                                <PlanHeader>
                                    <div class="space-y-1">
                                        <PlanTitle>
                                            {{ $t('Your map plan') }}
                                        </PlanTitle>
                                        <PlanDescription>
                                            {{
                                                $t(
                                                    'Tell the assistant what to change and the plan follows.',
                                                )
                                            }}
                                        </PlanDescription>
                                    </div>
                                </PlanHeader>
                                <PlanContent
                                    class="min-h-0 flex-1 overflow-y-auto"
                                >
                                    <PlanSummary :plan="activePlan" />
                                </PlanContent>
                                <PlanFooter class="justify-between">
                                    <Button
                                        variant="outline"
                                        :disabled="status !== 'ready'"
                                        data-testid="back-to-planning"
                                        @click="backToPlanning"
                                    >
                                        {{
                                            $t(
                                                propertyFlow
                                                    ? 'Change preferences'
                                                    : 'Back to planning',
                                            )
                                        }}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        data-testid="hide-plan"
                                        @click="planOpen = false"
                                    >
                                        {{ $t('Back to the map') }}
                                    </Button>
                                </PlanFooter>
                            </Plan>
                        </div>
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
