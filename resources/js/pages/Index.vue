<script setup lang="ts">
import SiteLayout from '@/layouts/SiteLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Accessibility,
    ArrowRight,
    Bot,
    Check,
    Clock3,
    CloudRain,
    Disc3,
    Map,
    MapPin,
    MessageCircle,
    MousePointer2,
    Route,
    Sparkles,
    UtensilsCrossed,
    UsersRound,
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
    canRegister.value ? 'Start planning' : 'Sign in to continue',
);

const proofPoints = ['18 WebMCP tools', 'Open map data', 'Live map context'];

const journeyExamples = [
    {
        id: 'porto',
        icon: CloudRain,
        eyebrow: 'Family day',
        title: 'A rainy Sunday in Porto',
        prompt: 'Two kids, indoor stops, short walks, and an affordable lunch.',
        tags: ['Rain-safe', 'Family', 'Low budget'],
        result: 'A three-stop itinerary that keeps everyone dry.',
    },
    {
        id: 'tokyo',
        icon: Disc3,
        eyebrow: 'Night out',
        title: 'Tokyo records after dinner',
        prompt: 'Independent shops, one neighbourhood and somewhere for a late drink.',
        tags: ['Vinyl', 'Walkable', 'Late night'],
        result: 'A focused evening without crossing the whole city.',
    },
    {
        id: 'paris',
        icon: Accessibility,
        eyebrow: 'Accessible culture',
        title: 'A step-free art afternoon',
        prompt: 'Paris galleries, accessible routes and a quiet place to take a break.',
        tags: ['Step-free', 'Art', 'Quiet breaks'],
        result: 'An art route shaped around access and energy.',
    },
    {
        id: 'dublin',
        icon: Clock3,
        eyebrow: 'Short layover',
        title: 'Four useful hours in Dublin',
        prompt: 'One landmark, a casual meal, and good coffee near the station.',
        tags: ['4 hours', 'Carry-on', 'Near transit'],
        result: 'A timed loop with enough margin to catch the train.',
    },
    {
        id: 'barcelona',
        icon: UtensilsCrossed,
        eyebrow: 'Food constraints',
        title: 'Gluten-free tapas in Gràcia',
        prompt: 'Three relaxed stops with outdoor seating and no tourist traps.',
        tags: ['Gluten-free', 'Local', 'Outdoor seats'],
        result: 'A neighbourhood food trail built around the constraint.',
    },
    {
        id: 'lisbon',
        icon: UsersRound,
        eyebrow: 'Three generations',
        title: 'An easy morning in Lisbon',
        prompt: 'Grandparents, a toddler, fewer hills, and somewhere shady to pause.',
        tags: ['Low walking', 'Toddler', 'Rest stops'],
        result: 'A gentler route that works for the whole group.',
    },
];

const workflow = [
    {
        id: 'describe',
        number: '01',
        icon: MessageCircle,
        title: 'Describe the experience',
        description:
            'Start with a rough idea, a mood, or the constraints that matter to you.',
    },
    {
        id: 'refine',
        number: '02',
        icon: Sparkles,
        title: 'Refine the details',
        description:
            'Whatsthere asks a short set of useful questions and turns the answers into a plan.',
    },
    {
        id: 'explore',
        number: '03',
        icon: Map,
        title: 'Explore it on the map',
        description:
            'See every suggestion in context, move the map, and ask for better alternatives.',
    },
];

const guestTools = ['open_login', 'open_signup'];
const planningTools = [
    'start_trip',
    'answer_question',
    'open_map',
    'read_map_location',
    'show_place_on_map',
];
</script>

<template>
    <SiteLayout
        title="Agent-native trip planning"
        description="Plan days and trips through conversation, then explore every recommendation on a live map with an AI agent that can use Whatsthere through WebMCP."
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
                        <a
                            href="https://webmcp.devpost.com/"
                            target="_blank"
                            rel="noreferrer"
                            class="border-primary/20 bg-primary/8 text-primary hover:bg-primary/12 inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-semibold tracking-wide transition-colors sm:text-sm"
                        >
                            <Sparkles class="size-4" aria-hidden="true" />
                            {{ $t('Built for the WebMCP Challenge') }}
                        </a>

                        <h1
                            class="mt-7 max-w-3xl text-5xl leading-[0.96] font-bold tracking-[-0.055em] text-balance sm:text-6xl lg:text-7xl"
                        >
                            {{ $t('Plan the experience.') }}
                            <span class="text-primary block">
                                {{ $t('Whatsthere finds the places.') }}
                            </span>
                        </h1>

                        <p
                            class="text-muted-foreground mt-7 max-w-xl text-lg leading-relaxed sm:text-xl"
                        >
                            {{
                                $t(
                                    'Describe the day you want. Whatsthere asks a few useful questions, creates a map-ready plan, and lets your browser agent help at every step.',
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
                                        {{ $t('São Paulo day plan') }}
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
                                                    'A rainy Sunday in São Paulo with two kids.',
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
                                                            'I’ll keep the walks short and the stops indoors.',
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
                                                    {{ $t('Your Sunday') }}
                                                </p>
                                                <span
                                                    class="bg-secondary/15 text-secondary rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                                >
                                                    {{ $t('Ready') }}
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
                                                        $t('10:00 · Pinacoteca')
                                                    }}
                                                </li>
                                                <li class="flex gap-2">
                                                    <span
                                                        class="bg-secondary mt-1 size-1.5 shrink-0 rounded-full"
                                                    />
                                                    {{ $t('12:30 · Café') }}
                                                </li>
                                                <li class="flex gap-2">
                                                    <span
                                                        class="bg-primary mt-1 size-1.5 shrink-0 rounded-full"
                                                    />
                                                    {{ $t('14:00 · Mercadão') }}
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
                                                >start_trip</span
                                            >
                                            <ArrowRight
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            <span
                                                class="border-border bg-muted rounded-md border px-1.5 py-1"
                                                >open_map</span
                                            >
                                        </div>
                                    </div>

                                    <div
                                        class="bg-muted relative min-h-72 overflow-hidden sm:min-h-full"
                                        aria-label="Illustrated map preview with three itinerary stops"
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
                                            <Route
                                                class="text-primary size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{ $t('3 stops · 2.4 km') }}
                                        </div>
                                        <svg
                                            viewBox="0 0 100 100"
                                            preserveAspectRatio="none"
                                            class="text-primary absolute inset-0 size-full opacity-70"
                                            fill="none"
                                            aria-hidden="true"
                                        >
                                            <path
                                                d="M23 28C40 41 54 42 76 51C67 66 54 68 57 84"
                                                stroke="currentColor"
                                                stroke-width="3"
                                                stroke-linecap="round"
                                                stroke-dasharray="6 8"
                                                vector-effect="non-scaling-stroke"
                                            />
                                        </svg>
                                        <div
                                            class="bg-primary text-primary-foreground absolute top-[28%] left-[23%] flex size-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-4 border-white text-sm font-bold shadow-lg dark:border-slate-800"
                                        >
                                            1
                                        </div>
                                        <div
                                            class="bg-secondary text-secondary-foreground absolute top-[51%] left-[76%] flex size-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-4 border-white text-sm font-bold shadow-lg dark:border-slate-800"
                                        >
                                            2
                                        </div>
                                        <div
                                            class="bg-primary text-primary-foreground absolute top-[84%] left-[57%] flex size-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-4 border-white text-sm font-bold shadow-lg dark:border-slate-800"
                                        >
                                            3
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
                            {{ $t('Start with an idea') }}
                        </p>
                        <h2
                            id="examples-heading"
                            class="mt-3 text-3xl font-bold tracking-tight text-balance sm:text-5xl"
                        >
                            {{ $t('Specific enough to be useful.') }}
                            <span class="text-muted-foreground">
                                {{ $t('Flexible enough to feel like you.') }}
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
                                :aria-label="$t('Trip constraints')"
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
                                    'From a half-formed idea to a day you can follow.',
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
                                        'Whatsthere exposes safe, purpose-built actions to browser agents. The available tools change with authentication and with each phase of your trip.',
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
                                    {{ $t('Phase-aware') }}
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
                                    'Whatsthere keeps the conversation, trip plan, and visible map area together. You can explore naturally while your agent works with the same context.',
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
                            <Route
                                class="text-primary size-5"
                                aria-hidden="true"
                            />
                            <p class="mt-5 text-sm font-bold">
                                {{ $t('The plan improves') }}
                            </p>
                            <p
                                class="text-muted-foreground mt-2 text-sm leading-relaxed"
                            >
                                {{
                                    $t(
                                        'Keep the useful discoveries in one coherent itinerary.',
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
                        {{ $t('Ready to find your way?') }}
                    </h2>
                    <p
                        class="text-muted-foreground mx-auto mt-4 max-w-xl text-lg"
                    >
                        {{
                            $t(
                                'Bring a rough idea. Leave with a plan you can see, question, and explore.',
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
