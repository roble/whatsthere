<script setup lang="ts">
import SiteLayout from '@/layouts/SiteLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bot,
    Check,
    Compass,
    Euro,
    GraduationCap,
    House,
    Map,
    MapPin,
    MessageCircle,
    MousePointer2,
    ShoppingCart,
    Sparkles,
    TrainFront,
    Trees,
} from '@lucide/vue';
import { computed } from 'vue';

const page = usePage();

const canRegister = computed(
    () =>
        route().has('register') &&
        page.props.auth?.registration_enabled !== false,
);
const primaryHref = computed(() =>
    canRegister.value ? route('register') : route('login'),
);
const primaryLabel = computed(() =>
    canRegister.value ? 'Start searching' : 'Sign in to continue',
);

const proofPoints = [
    'Real Cork listings',
    'OpenStreetMap data',
    'No forms to fill in',
];

const journeyExamples = [
    {
        id: 'family-home',
        icon: House,
        eyebrow: 'Family home',
        title: 'Three bedrooms under €400k',
        prompt: 'A 3 bed house in Cork under 400k.',
        tags: ['3+ beds', 'House', 'Under €400k'],
        result: 'Every match on the map, in one message.',
    },
    {
        id: 'schools',
        icon: GraduationCap,
        eyebrow: 'Schools nearby',
        title: 'What is within walking distance',
        prompt: 'Which schools are near this one?',
        tags: ['Primary', 'Secondary', 'Walkable'],
        result: 'Schools around the property, pinned beside it.',
    },
    {
        id: 'commute',
        icon: TrainFront,
        eyebrow: 'Getting to work',
        title: 'Close to a station',
        prompt: 'Show me the transport links around here.',
        tags: ['Rail', 'Bus', 'Park and ride'],
        result: 'The commute, before you book a viewing.',
    },
    {
        id: 'first-home',
        icon: Euro,
        eyebrow: 'First home',
        title: 'A two-bed apartment under €250k',
        prompt: 'Looking for a 2 bed apartment in Cork under 250k.',
        tags: ['2 beds', 'Apartment', 'Under €250k'],
        result: 'Filters you can change with one click.',
    },
    {
        id: 'everyday',
        icon: ShoppingCart,
        eyebrow: 'Everyday life',
        title: 'The shop, the pharmacy, the coffee',
        prompt: 'What is actually around this house?',
        tags: ['Supermarkets', 'Pharmacies', 'Cafés'],
        result: 'The ordinary things a listing never mentions.',
    },
    {
        id: 'green-space',
        icon: Trees,
        eyebrow: 'Green space',
        title: 'Somewhere to walk on a Sunday',
        prompt: 'Are there parks near this address?',
        tags: ['Parks', 'Playgrounds', 'Open space'],
        result: 'Open space mapped around the door.',
    },
];

const workflow = [
    {
        id: 'look',
        number: '01',
        icon: Map,
        title: 'Start on the map',
        description:
            'Every property we hold is already pinned. There is nothing to fill in before you can see something.',
    },
    {
        id: 'narrow',
        number: '02',
        icon: MessageCircle,
        title: 'Say what matters',
        description:
            'One sentence narrows the search. Anything you leave out simply stays open, and the filters are one click away.',
    },
    {
        id: 'surroundings',
        number: '03',
        icon: Compass,
        title: 'Ask what is there',
        description:
            'Pick a property and see the schools, shops, parks and transport around it, drawn from OpenStreetMap.',
    },
];

const guestTools = ['open_login', 'open_signup'];
const planningTools = [
    'ask_this_assistant',
    'read_current_chat',
    'read_map_location',
    'show_place_on_map',
    'open_chat_session',
];
</script>

<template>
    <SiteLayout
        title="Property search on a live map"
        description="Search homes for sale in Cork in plain language, then ask what is actually around any of them — schools, shops, parks and transport — on the same map. Your browser agent can drive all of it through WebMCP."
    >
        <main class="w-full overflow-hidden">
            <section
                class="relative px-6 pt-32 pb-20 sm:pt-40 sm:pb-28 lg:px-8"
                data-testid="landing-hero"
            >
                <div
                    class="bg-primary/15 absolute top-16 left-1/2 -z-10 size-[34rem] -translate-x-[85%] rounded-full blur-3xl"
                    aria-hidden="true"
                />
                <div
                    class="bg-secondary/15 absolute top-40 left-1/2 -z-10 size-[30rem] translate-x-[15%] rounded-full blur-3xl"
                    aria-hidden="true"
                />

                <div
                    class="mx-auto grid max-w-7xl items-center gap-16 lg:grid-cols-12 lg:gap-10"
                >
                    <div class="lg:col-span-6 xl:col-span-5">
                        <p
                            class="border-primary/20 bg-primary/8 text-primary inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-semibold tracking-wide sm:text-sm"
                        >
                            <Sparkles class="size-4" aria-hidden="true" />
                            {{ $t('Built for the Ireland AI Challenge') }}
                        </p>

                        <h1
                            class="mt-7 max-w-3xl text-5xl leading-[0.96] font-bold tracking-[-0.055em] text-balance sm:text-6xl lg:text-7xl"
                        >
                            {{ $t('Find the house.') }}
                            <span class="text-primary block">
                                {{ $t('Then find out what is there.') }}
                            </span>
                        </h1>

                        <p
                            class="text-muted-foreground mt-7 max-w-xl text-lg leading-relaxed sm:text-xl"
                        >
                            {{
                                $t(
                                    'Every home we have is already on the map. Say what you are looking for and it narrows down — then pick one and see the schools, shops and transport around it.',
                                )
                            }}
                        </p>

                        <div
                            class="mt-9 flex flex-col items-start gap-4 sm:flex-row sm:items-center"
                        >
                            <Link
                                :href="primaryHref"
                                class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring inline-flex items-center justify-center gap-2 rounded-full px-6 py-3.5 text-base font-semibold shadow-lg shadow-black/10 transition-all hover:-translate-y-0.5 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                data-testid="landing-primary-cta"
                            >
                                {{ $t(primaryLabel) }}
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                            <Link
                                v-if="canRegister"
                                :href="route('login')"
                                class="text-muted-foreground hover:text-foreground focus-visible:ring-ring rounded-full px-3 py-2 text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                data-testid="landing-login"
                            >
                                {{ $t('Already have an account? Sign in') }}
                            </Link>
                        </div>

                        <ul
                            class="text-muted-foreground mt-9 flex flex-wrap gap-x-5 gap-y-2 text-sm"
                            aria-label="Whatsthere highlights"
                        >
                            <li
                                v-for="point in proofPoints"
                                :key="point"
                                class="flex items-center gap-1.5"
                            >
                                <Check
                                    class="text-secondary size-4"
                                    aria-hidden="true"
                                />
                                {{ $t(point) }}
                            </li>
                        </ul>
                    </div>

                    <div class="relative lg:col-span-6 lg:col-start-7">
                        <div
                            class="border-border/70 bg-card/90 relative overflow-hidden rounded-[2rem] border p-2 shadow-2xl shadow-black/15 backdrop-blur-xl sm:p-3"
                            data-testid="landing-product-preview"
                        >
                            <div
                                class="border-border/70 bg-background overflow-hidden rounded-[1.5rem] border"
                            >
                                <div
                                    class="border-border/70 flex items-center justify-between border-b px-4 py-3"
                                >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="bg-destructive/70 size-2.5 rounded-full"
                                        />
                                        <span
                                            class="bg-secondary/70 size-2.5 rounded-full"
                                        />
                                        <span
                                            class="bg-primary/70 size-2.5 rounded-full"
                                        />
                                    </div>
                                    <div
                                        class="text-muted-foreground flex items-center gap-2 text-sm font-medium"
                                    >
                                        <MapPin
                                            class="text-primary size-3.5"
                                            aria-hidden="true"
                                        />
                                        {{ $t('Cork property search') }}
                                    </div>
                                    <div class="w-12" />
                                </div>

                                <div
                                    class="grid min-h-[460px] sm:grid-cols-[0.92fr_1.08fr]"
                                >
                                    <div
                                        class="border-border/70 flex flex-col gap-4 border-b p-4 sm:border-r sm:border-b-0 sm:p-5"
                                    >
                                        <div
                                            class="bg-muted ml-6 rounded-2xl rounded-tr-sm px-3.5 py-3 text-sm leading-relaxed"
                                        >
                                            {{
                                                $t(
                                                    'A 3 bed house in Cork under €400,000.',
                                                )
                                            }}
                                        </div>

                                        <div class="flex gap-2.5">
                                            <div
                                                class="bg-primary/12 text-primary flex size-7 shrink-0 items-center justify-center rounded-full"
                                            >
                                                <Sparkles
                                                    class="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            </div>
                                            <div class="min-w-0">
                                                <p
                                                    class="text-sm font-semibold tracking-wide uppercase"
                                                >
                                                    {{ $t('Whatsthere') }}
                                                </p>
                                                <p
                                                    class="text-muted-foreground mt-1 text-sm leading-relaxed"
                                                >
                                                    {{
                                                        $t(
                                                            '56 matches. Pick one and I’ll show you what’s around it.',
                                                        )
                                                    }}
                                                </p>
                                            </div>
                                        </div>

                                        <div
                                            class="border-border bg-card rounded-2xl border p-3 shadow-sm"
                                        >
                                            <div
                                                class="flex items-center justify-between gap-2"
                                            >
                                                <p
                                                    class="text-sm font-semibold"
                                                >
                                                    {{
                                                        $t(
                                                            'Properties for sale',
                                                        )
                                                    }}
                                                </p>
                                                <span
                                                    class="bg-secondary/15 text-secondary rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                                >
                                                    {{ $t('56 matches') }}
                                                </span>
                                            </div>
                                            <ol
                                                class="text-muted-foreground mt-3 flex flex-col gap-2.5 text-sm"
                                            >
                                                <li class="flex gap-2">
                                                    <span
                                                        class="bg-primary mt-1 size-1.5 shrink-0 rounded-full"
                                                    />
                                                    {{
                                                        $t(
                                                            '€265,000 · 3 bed · Fermoy',
                                                        )
                                                    }}
                                                </li>
                                                <li class="flex gap-2">
                                                    <span
                                                        class="bg-secondary mt-1 size-1.5 shrink-0 rounded-full"
                                                    />
                                                    {{
                                                        $t(
                                                            '€285,000 · 3 bed · Mallow',
                                                        )
                                                    }}
                                                </li>
                                                <li class="flex gap-2">
                                                    <span
                                                        class="bg-primary mt-1 size-1.5 shrink-0 rounded-full"
                                                    />
                                                    {{
                                                        $t(
                                                            '€295,000 · 4 bed · Carrigaline',
                                                        )
                                                    }}
                                                </li>
                                            </ol>
                                        </div>

                                        <div
                                            class="text-muted-foreground mt-auto flex items-center gap-2 font-mono text-[10px]"
                                        >
                                            <Bot
                                                class="text-secondary size-3.5"
                                                aria-hidden="true"
                                            />
                                            <span
                                                class="border-border bg-muted rounded-md border px-1.5 py-1"
                                                >ask_this_assistant</span
                                            >
                                            <ArrowRight
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            <span
                                                class="border-border bg-muted rounded-md border px-1.5 py-1"
                                                >show_place_on_map</span
                                            >
                                        </div>
                                    </div>

                                    <div
                                        class="bg-muted relative min-h-72 overflow-hidden sm:min-h-full"
                                        aria-label="Illustrated map preview with three property pins"
                                    >
                                        <div
                                            class="absolute inset-0 opacity-60 dark:opacity-35"
                                            style="
                                                background-image:
                                                    linear-gradient(
                                                        32deg,
                                                        transparent 44%,
                                                        var(--border) 45%,
                                                        var(--border) 47%,
                                                        transparent 48%
                                                    ),
                                                    linear-gradient(
                                                        118deg,
                                                        transparent 57%,
                                                        var(--border) 58%,
                                                        var(--border) 60%,
                                                        transparent 61%
                                                    ),
                                                    radial-gradient(
                                                        circle at 30% 30%,
                                                        color-mix(
                                                                in oklch,
                                                                var(--secondary)
                                                                    18%,
                                                                transparent
                                                            )
                                                            0 18%,
                                                        transparent 19%
                                                    );
                                                background-size:
                                                    115px 115px,
                                                    145px 145px,
                                                    100% 100%;
                                            "
                                        />
                                        <div
                                            class="bg-secondary/15 absolute -right-10 bottom-0 h-44 w-40 rounded-tl-[5rem]"
                                        />
                                        <div
                                            class="bg-card/95 absolute top-4 left-4 flex items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold shadow-md"
                                        >
                                            <MapPin
                                                class="text-primary size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{ $t('56 homes in view') }}
                                        </div>
                                        <div
                                            class="bg-primary text-primary-foreground absolute top-[28%] left-[23%] flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white px-2.5 py-1 text-xs font-bold shadow-lg dark:border-slate-800"
                                        >
                                            €265k
                                        </div>
                                        <div
                                            class="bg-secondary text-secondary-foreground absolute top-[51%] left-[76%] flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white px-2.5 py-1 text-xs font-bold shadow-lg dark:border-slate-800"
                                        >
                                            €285k
                                        </div>
                                        <div
                                            class="bg-primary text-primary-foreground absolute top-[84%] left-[57%] flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white px-2.5 py-1 text-xs font-bold shadow-lg dark:border-slate-800"
                                        >
                                            €295k
                                        </div>
                                        <div
                                            class="bg-card/95 absolute bottom-2 left-2 rounded-sm border p-2 px-3 shadow-lg"
                                        >
                                            <p
                                                class="text-[10px] font-semibold tracking-widest uppercase"
                                            >
                                                {{ $t('Map context') }}
                                            </p>
                                            <p
                                                class="text-muted-foreground mt-1 text-sm"
                                            >
                                                {{
                                                    $t(
                                                        'Your agent reads this view.',
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            class="border-primary/20 bg-card absolute -bottom-12 left-1/2 flex -translate-x-1/2 items-center gap-2.5 rounded-full border px-6 py-3.5 text-base font-semibold shadow-xl"
                        >
                            <span class="relative flex size-2">
                                <span
                                    class="bg-secondary absolute inline-flex size-full animate-ping rounded-full opacity-60 motion-reduce:animate-none"
                                />
                                <span
                                    class="bg-secondary relative inline-flex size-2 rounded-full"
                                />
                            </span>
                            {{ $t('WebMCP tools available') }}
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="examples"
                class="border-border/70 border-y bg-white/30 px-6 py-24 lg:px-8 dark:bg-white/[0.02]"
                aria-labelledby="examples-heading"
            >
                <div class="mx-auto max-w-7xl">
                    <div class="max-w-2xl">
                        <p
                            class="text-primary text-sm font-bold tracking-[0.18em] uppercase"
                        >
                            {{ $t('Ask in plain language') }}
                        </p>
                        <h2
                            id="examples-heading"
                            class="mt-3 text-3xl font-bold tracking-tight text-balance sm:text-5xl"
                        >
                            {{ $t('One sentence is enough.') }}
                            <span class="text-muted-foreground">
                                {{ $t('No forms, no funnel, no waiting.') }}
                            </span>
                        </h2>
                    </div>

                    <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="example in journeyExamples"
                            :key="example.id"
                            :href="primaryHref"
                            class="border-border/70 bg-card group focus-visible:ring-ring relative flex min-h-80 flex-col overflow-hidden rounded-3xl border p-6 shadow-sm transition-all hover:-translate-y-1 hover:shadow-xl focus-visible:ring-2 focus-visible:outline-none"
                            :data-testid="`journey-example-${example.id}`"
                        >
                            <div
                                class="bg-primary/10 text-primary flex size-11 items-center justify-center rounded-2xl transition-transform group-hover:scale-105 group-hover:rotate-3"
                            >
                                <component
                                    :is="example.icon"
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </div>
                            <p
                                class="text-muted-foreground mt-8 text-sm font-bold tracking-[0.16em] uppercase"
                            >
                                {{ $t(example.eyebrow) }}
                            </p>
                            <h3 class="mt-2 text-2xl font-bold tracking-tight">
                                {{ $t(example.title) }}
                            </h3>
                            <p
                                class="text-muted-foreground mt-3 text-sm leading-relaxed"
                            >
                                “{{ $t(example.prompt) }}”
                            </p>
                            <ul
                                class="mt-5 mb-2 flex flex-wrap gap-2"
                                :aria-label="$t('Search filters')"
                            >
                                <li
                                    v-for="tag in example.tags"
                                    :key="tag"
                                    class="border-primary bg-primary/5 text-primary mb-1 rounded-full border px-2.5 py-1 text-sm font-semibold"
                                >
                                    {{ $t(tag) }}
                                </li>
                            </ul>
                            <div
                                class="border-border/70 mt-auto flex items-end justify-between gap-3 border-t pt-5"
                            >
                                <p class="text-sm leading-relaxed font-medium">
                                    {{ $t(example.result) }}
                                </p>
                                <ArrowRight
                                    class="text-primary size-5 shrink-0 transition-transform group-hover:translate-x-1"
                                    aria-hidden="true"
                                />
                            </div>
                        </Link>
                    </div>
                </div>
            </section>

            <section
                class="px-6 py-24 sm:py-32 lg:px-8"
                aria-labelledby="workflow-heading"
            >
                <div class="mx-auto max-w-7xl">
                    <div class="mx-auto max-w-3xl text-center">
                        <p
                            class="text-secondary text-sm font-bold tracking-[0.18em] uppercase"
                        >
                            {{ $t('How it works') }}
                        </p>
                        <h2
                            id="workflow-heading"
                            class="mt-3 text-3xl font-bold tracking-tight text-balance sm:text-5xl"
                        >
                            {{
                                $t(
                                    'From the whole county to one street, in three steps.',
                                )
                            }}
                        </h2>
                    </div>

                    <ol class="mt-16 grid gap-10 md:grid-cols-3 md:gap-16">
                        <li
                            v-for="(step, index) in workflow"
                            :key="step.id"
                            class="relative text-center"
                            :data-testid="`workflow-${step.id}`"
                        >
                            <div
                                v-if="index < workflow.length - 1"
                                class="border-border absolute top-10 left-[calc(50%+2.5rem)] hidden w-[calc(100%-1rem)] border-t-2 border-dashed md:block"
                                aria-hidden="true"
                            />
                            <div
                                class="bg-card border-border relative z-10 mx-auto flex size-14 items-center justify-center rounded-2xl border shadow-sm"
                            >
                                <component
                                    :is="step.icon"
                                    class="text-primary size-6"
                                    aria-hidden="true"
                                />
                            </div>
                            <p
                                class="text-muted-foreground mt-7 font-mono text-sm font-bold tracking-widest"
                            >
                                {{ step.number }}
                            </p>
                            <h3 class="mt-2 text-xl font-bold">
                                {{ $t(step.title) }}
                            </h3>
                            <p
                                class="text-muted-foreground mx-auto mt-3 max-w-sm text-sm leading-relaxed"
                            >
                                {{ $t(step.description) }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <section
                class="px-6 py-10 lg:px-8"
                aria-labelledby="webmcp-heading"
                data-testid="webmcp-story"
            >
                <div
                    class="bg-foreground text-background relative mx-auto max-w-7xl overflow-hidden rounded-[2rem] px-6 py-16 shadow-2xl sm:px-10 lg:px-16 lg:py-20"
                >
                    <div
                        class="bg-primary/35 absolute -top-40 -right-32 size-96 rounded-full blur-3xl"
                        aria-hidden="true"
                    />
                    <div
                        class="grid items-center gap-14 lg:grid-cols-[0.85fr_1.15fr]"
                    >
                        <div class="relative z-10">
                            <div
                                class="text-background/70 flex items-center gap-2 text-sm font-bold tracking-[0.16em] uppercase"
                            >
                                <Bot class="size-5" aria-hidden="true" />
                                {{ $t('WebMCP native') }}
                            </div>
                            <h2
                                id="webmcp-heading"
                                class="mt-4 text-4xl font-bold tracking-tight text-balance sm:text-5xl"
                            >
                                {{ $t('Your agent doesn’t have to guess.') }}
                            </h2>
                            <p
                                class="text-background/70 mt-6 max-w-xl text-lg leading-relaxed"
                            >
                                {{
                                    $t(
                                        'Whatsthere exposes safe, purpose-built actions to browser agents. The available tools change with authentication and with what is on screen.',
                                    )
                                }}
                            </p>
                            <div class="mt-8 flex flex-wrap gap-3">
                                <span
                                    class="border-background/15 bg-background/8 rounded-full border px-3 py-1.5 font-mono text-sm"
                                >
                                    {{ $t('18 imperative tools') }}
                                </span>
                                <span
                                    class="border-background/15 bg-background/8 rounded-full border px-3 py-1.5 font-mono text-sm"
                                >
                                    {{ $t('Context-aware') }}
                                </span>
                                <span
                                    class="border-background/15 bg-background/8 rounded-full border px-3 py-1.5 font-mono text-sm"
                                >
                                    {{ $t('User-controlled') }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="relative z-10 grid items-stretch gap-3 sm:grid-cols-[1fr_auto_1.25fr]"
                        >
                            <div
                                class="border-background/15 bg-background/7 rounded-2xl border p-4"
                            >
                                <div class="flex items-center gap-2">
                                    <div
                                        class="bg-background/10 flex size-8 items-center justify-center rounded-lg"
                                    >
                                        <MousePointer2
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold">
                                            {{ $t('Signed out') }}
                                        </p>
                                        <p
                                            class="text-background/55 text-[11px]"
                                        >
                                            {{ $t('Entry points only') }}
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-5 flex flex-col gap-2">
                                    <code
                                        v-for="tool in guestTools"
                                        :key="tool"
                                        class="border-background/10 bg-background/8 rounded-lg border px-3 py-2 text-sm"
                                    >
                                        {{ tool }}
                                    </code>
                                </div>
                            </div>

                            <div
                                class="text-background/50 flex items-center justify-center"
                            >
                                <ArrowRight
                                    class="size-5 rotate-90 sm:rotate-0"
                                    aria-hidden="true"
                                />
                            </div>

                            <div
                                class="border-secondary/30 bg-secondary/8 rounded-2xl border p-4"
                            >
                                <div class="flex items-center gap-2">
                                    <div
                                        class="bg-secondary/15 text-secondary flex size-8 items-center justify-center rounded-lg"
                                    >
                                        <Sparkles
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold">
                                            {{ $t('Signed in') }}
                                        </p>
                                        <p
                                            class="text-background/55 text-[11px]"
                                        >
                                            {{
                                                $t(
                                                    'Tools for the current phase',
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-5 grid gap-2 sm:grid-cols-2">
                                    <code
                                        v-for="tool in planningTools"
                                        :key="tool"
                                        class="border-background/10 bg-background/8 truncate rounded-lg border px-3 py-2 text-sm"
                                    >
                                        {{ tool }}
                                    </code>
                                    <span
                                        class="text-background/60 flex items-center px-3 py-2 font-mono text-sm"
                                    >
                                        {{ $t('+ 11 more') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="px-6 py-24 sm:py-32 lg:px-8"
                aria-labelledby="collaboration-heading"
            >
                <div
                    class="mx-auto grid max-w-7xl items-center gap-14 lg:grid-cols-2"
                >
                    <div class="max-w-xl">
                        <p
                            class="text-primary text-sm font-bold tracking-[0.18em] uppercase"
                        >
                            {{ $t('A shared canvas') }}
                        </p>
                        <h2
                            id="collaboration-heading"
                            class="mt-3 text-3xl font-bold tracking-tight text-balance sm:text-5xl"
                        >
                            {{ $t('Move the map. Your agent keeps up.') }}
                        </h2>
                        <p
                            class="text-muted-foreground mt-6 text-lg leading-relaxed"
                        >
                            {{
                                $t(
                                    'Whatsthere keeps the conversation, the saved filters, and the visible map area together. You can explore naturally while your agent works with the same context.',
                                )
                            }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div
                            class="border-border bg-card rounded-2xl border p-5 shadow-sm"
                        >
                            <MousePointer2
                                class="text-primary size-5"
                                aria-hidden="true"
                            />
                            <p class="mt-5 text-sm font-bold">
                                {{ $t('You explore') }}
                            </p>
                            <p
                                class="text-muted-foreground mt-2 text-sm leading-relaxed"
                            >
                                {{
                                    $t(
                                        'Pan, zoom, and inspect the places yourself.',
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            class="border-border bg-card rounded-2xl border p-5 shadow-sm sm:translate-y-6"
                        >
                            <Bot
                                class="text-secondary size-5"
                                aria-hidden="true"
                            />
                            <p class="mt-5 text-sm font-bold">
                                {{ $t('Your agent understands') }}
                            </p>
                            <p
                                class="text-muted-foreground mt-2 text-sm leading-relaxed"
                            >
                                {{
                                    $t(
                                        'Read the viewport and ask Whatsthere for local options.',
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            class="border-border bg-card rounded-2xl border p-5 shadow-sm"
                        >
                            <MapPin
                                class="text-secondary size-5"
                                aria-hidden="true"
                            />
                            <p class="mt-5 text-sm font-bold">
                                {{ $t('Whatsthere responds') }}
                            </p>
                            <p
                                class="text-muted-foreground mt-2 text-sm leading-relaxed"
                            >
                                {{
                                    $t(
                                        'Get suggestions grounded in the area on screen.',
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            class="border-border bg-card rounded-2xl border p-5 shadow-sm sm:translate-y-6"
                        >
                            <Compass
                                class="text-primary size-5"
                                aria-hidden="true"
                            />
                            <p class="mt-5 text-sm font-bold">
                                {{ $t('The search narrows') }}
                            </p>
                            <p
                                class="text-muted-foreground mt-2 text-sm leading-relaxed"
                            >
                                {{
                                    $t(
                                        'Every answer sharpens the filters, and the map keeps up.',
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="px-6 pb-24 sm:pb-32 lg:px-8">
                <div
                    class="border-primary/20 bg-primary/8 relative mx-auto max-w-5xl overflow-hidden rounded-[2rem] border px-6 py-14 text-center sm:px-12 sm:py-20"
                >
                    <div
                        class="bg-secondary/25 absolute -top-20 left-1/2 -z-10 size-64 -translate-x-1/2 rounded-full blur-3xl"
                        aria-hidden="true"
                    />
                    <MapPin
                        class="text-secondary mx-auto size-16"
                        aria-hidden="true"
                    />
                    <h2
                        class="mt-5 text-3xl font-bold tracking-tight sm:text-5xl"
                    >
                        {{ $t('Ready to find your next home?') }}
                    </h2>
                    <p
                        class="text-muted-foreground mx-auto mt-4 max-w-xl text-lg"
                    >
                        {{
                            $t(
                                'Start with a sentence. Leave knowing the street, not just the price.',
                            )
                        }}
                    </p>
                    <Link
                        :href="primaryHref"
                        class="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring mt-8 inline-flex items-center justify-center gap-2 rounded-full px-6 py-3.5 text-base font-semibold shadow-lg transition-all hover:-translate-y-0.5 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        data-testid="landing-final-cta"
                    >
                        {{ $t(primaryLabel) }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </section>
        </main>
    </SiteLayout>
</template>
