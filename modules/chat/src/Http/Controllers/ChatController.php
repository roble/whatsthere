<?php

namespace Modules\Chat\Http\Controllers;

use App\Ai\AiProviderSwitch;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Tools\Request as ToolRequest;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Ai\Tools\CompareListingAmenities;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\SaveItinerary;
use Modules\Chat\Ai\Tools\SavePropertyPreferences;
use Modules\Chat\Ai\Tools\SearchProperties;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Modules\Chat\Ai\Tools\UpdatePropertySearchPreferences;
use Modules\Chat\Jobs\GenerateConversationTitle;
use Modules\Chat\Models\OnboardingState;
use Modules\Chat\Testing\CannedReplies;
use Modules\Properties\PropertyPreferences;
use Modules\Properties\PropertySearch;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController
{
    /**
     * The tools whose results move the map.
     *
     * Kept here rather than checked one name at a time: a second map-moving
     * tool that is not listed reopens a conversation on the wrong place, and
     * the failure is silent.
     */
    public const array MAP_TOOLS = [
        ShowOnMap::NAME,
        FindPlaces::NAME,
        SaveItinerary::NAME,
        SearchProperties::NAME,
        UpdatePropertySearchPreferences::NAME,
        CompareListingAmenities::NAME,
    ];

    /**
     * Show a blank chat, already looking at the home area's properties.
     *
     * No conversation row exists until the first message is sent, but the map
     * is never allowed to open empty: a visitor who lands here sees what is for
     * sale before they have typed anything, and the opening message narrows it
     * rather than starting it.
     */
    public function index(): Response
    {
        $preferences = PropertyPreferences::defaults();

        return Inertia::render('Chat::Index', [
            'conversationId' => null,
            'initialMessages' => [],
            'initialMapView' => $this->propertyMapView($preferences),
            'onboarding' => [
                'phase' => 'mapping',
                'question_count' => 0,
                'current_question' => null,
                'answers' => [],
                'plan' => SavePropertyPreferences::plan($preferences),
                'flow' => 'property',
            ],
            'flow' => 'property',
        ]);
    }

    /**
     * Show an existing conversation belonging to the authenticated user.
     */
    public function show(Request $request, string $conversation): Response
    {
        $owned = $this->ownedConversation($request, $conversation);

        $messages = (new ChatAgent)
            ->continue($owned->id, $request->user())
            ->messages();

        $onboarding = OnboardingState::find($owned->id);

        return Inertia::render('Chat::Index', [
            'conversationId' => $owned->id,
            'initialMessages' => collect($messages)
                // Tool rows carry no prose, and an assistant turn that only
                // asked a question would otherwise reopen as an empty bubble.
                ->reject(fn (Message $message): bool => $message instanceof ToolResultMessage || trim((string) $message->content) === '')
                ->values()
                ->map(fn (Message $message, int $index): array => [
                    'id' => 'history-'.$index,
                    'role' => $message->role->value,
                    'parts' => [
                        ['type' => 'text', 'text' => $message->content ?? ''],
                    ],
                ])
                ->all(),
            // Always search from the saved preferences, not the frozen id list.
            // The id list caps how many pins a reopen can ever show; the map
            // should reflect every current match up to the configured limit.
            'initialMapView' => $onboarding?->flow === 'property'
                ? $this->propertyMapView($onboarding->plan['preferences'] ?? null)
                : $this->lastMapView($messages),
            'onboarding' => $onboarding?->only(['phase', 'question_count', 'current_question', 'answers', 'plan', 'flow']),
            'flow' => $onboarding?->flow ?? 'trip',
        ]);
    }

    /**
     * Move the onboarding phase forward without going through the model.
     *
     * "Skip questions", "Show my map" and "Back to planning" must be certain,
     * so they are recorded here rather than sent as chat messages the
     * assistant may misread. A skip before any plan exists saves the opening
     * message as a minimal plan.
     */
    public function onboarding(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->ownedConversation($request, $conversation);

        $phase = $request->validate(['phase' => ['required', 'in:mapping,interviewing']])['phase'];

        $state = OnboardingState::firstOrCreate(['conversation_id' => $owned->id]);

        if ($state->flow === 'property') {
            if ($phase === 'mapping') {
                abort_unless(in_array($state->phase, ['reviewing', 'mapping'], true), 422, 'Review your preferences before searching.');
                $preferences = $this->validatedPreferences($state->plan['preferences'] ?? null);
                $view = DB::transaction(function () use ($state, $preferences): array {
                    $state->update(['phase' => 'mapping', 'current_question' => null]);

                    return json_decode((string) (new SearchProperties($state))->handle(new ToolRequest($preferences)), true, flags: JSON_THROW_ON_ERROR);
                });

                return response()->json($state->only(['phase', 'question_count', 'current_question', 'answers', 'plan', 'flow']) + ['map_view' => $view]);
            }
            $state->update(['phase' => 'interviewing', 'current_question' => null, 'question_count' => 0]);

            return response()->json($state->only(['phase', 'question_count', 'current_question', 'answers', 'plan', 'flow']));
        }

        // Going back to the interview keeps the plan and the answers: the
        // visitor wants more questions, not a fresh start.
        $state->update([
            'phase' => $phase,
            'current_question' => null,
            'plan' => $phase === 'mapping'
                ? ($state->plan ?? ['goal' => (string) $owned->getAttribute('title'), 'location' => '', 'details' => []])
                : $state->plan,
        ]);

        return response()->json($state->only(['phase', 'question_count', 'current_question', 'answers', 'plan', 'flow']));
    }

    /**
     * Save an explicit filter edit from the property-results interface and
     * return its new, already-searched map view. Chat uses the matching tool,
     * so both entry points persist the same preference shape.
     */
    public function updatePropertyPreferences(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->ownedConversation($request, $conversation);
        $state = OnboardingState::findOrFail($owned->id);

        abort_unless($state->flow === 'property', 404);

        $changes = $request->validate(PropertyPreferences::partialRules());
        $preferences = PropertyPreferences::merge(
            $this->validatedPreferences($state->plan['preferences'] ?? null),
            $changes,
        );
        $view = (new PropertySearch)->search($preferences);

        $state->update([
            'plan' => SavePropertyPreferences::plan($preferences),
            'phase' => 'mapping',
            'current_question' => null,
            'property_result_ids' => array_column($view['markers'], 'id'),
        ]);

        return response()->json($state->only(['phase', 'question_count', 'current_question', 'answers', 'plan', 'flow']) + ['map_view' => $view]);
    }

    /**
     * Search properties without a conversation to save the preferences to.
     *
     * The landing page opens on results, so its filters must work before the
     * visitor has typed anything. Nothing is persisted: the browser holds the
     * preferences until a first message creates the conversation that owns
     * them.
     */
    public function propertySearch(Request $request): JsonResponse
    {
        $preferences = PropertyPreferences::validate($request->all());

        return response()->json([
            'plan' => SavePropertyPreferences::plan($preferences),
            'map_view' => (new PropertySearch)->search($preferences),
        ]);
    }

    /**
     * What is around a property: schools, cafes, transport, and the rest.
     *
     * Runs without the model. The visitor has already pointed at a property, so
     * there is nothing to interpret: each category is one cached Overpass
     * search around its coordinates, and the results are pooled into a single
     * map view the way a multi-search reply already pools them.
     */
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'label' => ['nullable', 'string', 'max:200'],
            // Bounded so one click cannot fan out into a dozen calls to a
            // donated service.
            'categories' => ['required', 'array', 'min:1', 'max:12'],
            'categories.*' => ['string', Rule::in(array_keys(FindPlaces::CATEGORIES))],
        ]);

        $finder = new FindPlaces;
        $latitude = (float) $validated['lat'];
        $longitude = (float) $validated['lon'];
        $markers = $finder->aroundPoints(
            [['lat' => $latitude, 'lon' => $longitude]],
            array_values($validated['categories']),
        );

        if ($markers === null) {
            return response()->json(['message' => __('The map data service could not be reached.')], 503);
        }

        $markers = array_map(function (array $marker) use ($finder, $latitude, $longitude): array {
            $marker['distance_m'] = $finder->distanceMetres(
                $latitude,
                $longitude,
                (float) $marker['lat'],
                (float) $marker['lon'],
            );

            return $marker;
        }, $markers);

        usort(
            $markers,
            fn (array $left, array $right): int => ($left['distance_m'] ?? PHP_INT_MAX) <=> ($right['distance_m'] ?? PHP_INT_MAX),
        );

        $kept = [];
        $perCategory = [];

        foreach ($markers as $marker) {
            $category = (string) ($marker['categoryKey'] ?? '');
            $perCategory[$category] = ($perCategory[$category] ?? 0) + 1;

            if ($perCategory[$category] <= 5) {
                $kept[] = $marker;
            }
        }

        $around = $validated['label'] ?? __('this property');

        return response()->json([
            'label' => __('Around :place', ['place' => $around]),
            'category' => __('nearby places'),
            'total' => count($kept),
            'markers' => $kept,
            'bbox' => $this->boxAround((float) $validated['lat'], (float) $validated['lon'], $markers),
        ]);
    }

    /**
     * A bounding box holding the property and everything found around it.
     *
     * Anchored on the property rather than on the results alone, so the pin the
     * visitor clicked is always inside the view they get back.
     *
     * @param  list<array<string, mixed>>  $markers
     * @return list<string>
     */
    protected function boxAround(float $latitude, float $longitude, array $markers): array
    {
        $latitudes = [$latitude, ...array_map(fn (array $marker): float => (float) $marker['lat'], $markers)];
        $longitudes = [$longitude, ...array_map(fn (array $marker): float => (float) $marker['lon'], $markers)];

        return [
            (string) (min($longitudes) - 0.002),
            (string) (min($latitudes) - 0.002),
            (string) (max($longitudes) + 0.002),
            (string) (max($latitudes) + 0.002),
        ];
    }

    /**
     * Search from stored preferences, or the home-area defaults if they are gone.
     *
     * Reopening a conversation must not 500 because an older plan is missing a
     * field the current rules require.
     *
     * @param  array<string, mixed>|null  $preferences
     * @return array<string, mixed>
     */
    protected function propertyMapView(?array $preferences): array
    {
        return (new PropertySearch)->search($this->validatedPreferences($preferences));
    }

    /**
     * @param  array<string, mixed>|null  $preferences
     * @return array{location: string, location_type: string, county: ?string, max_price: int, min_bedrooms: ?int, property_type: ?string, minimum_ber_rating: ?string, sort: string}
     */
    protected function validatedPreferences(?array $preferences): array
    {
        try {
            return PropertyPreferences::validate($preferences ?? []);
        } catch (ValidationException) {
            return PropertyPreferences::defaults();
        }
    }

    /**
     * Listing facts the model may treat as selected, loaded from the database.
     *
     * @return array<string, mixed>|null
     */
    protected function selectedListingForAgent(mixed $id): ?array
    {
        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        $marker = (new PropertySearch)->listing((int) $id);

        if ($marker === null) {
            return null;
        }

        $description = $marker['details']['description'] ?? $marker['description'] ?? '';

        return [
            'id' => $marker['id'],
            'name' => $marker['name'],
            'town' => $marker['town'] ?? null,
            'lat' => $marker['lat'],
            'lon' => $marker['lon'],
            'price' => PropertySearch::euroLabel($marker['asking_price'] ?? null),
            'currency' => $marker['currency'] ?? null,
            'bedrooms' => $marker['bedrooms'] ?? null,
            'bathrooms' => $marker['bathrooms'] ?? null,
            'floor_area_sqm' => $marker['floor_area_sqm'] ?? null,
            'plot_area_sqm' => $marker['plot_area_sqm'] ?? null,
            'rate' => PropertySearch::rateLabel($marker['price_per_sqm'] ?? null),
            'size_label' => $marker['size_label'] ?? null,
            'ber_rating' => $marker['ber_rating'] ?? null,
            'property_type' => $marker['property_type'] ?? null,
            'details' => [
                'address' => $marker['details']['address'] ?? $marker['address'] ?? null,
                'description' => is_string($description) ? Str::limit($description, 400) : null,
            ],
        ];
    }

    /**
     * Find what the map was last showing in this conversation.
     *
     * The transcript is rebuilt as plain text, so the tool calls that moved the
     * map are dropped on the way to the browser. Without this, reopening a
     * conversation snaps the map back to its default while the messages beside
     * it still discuss somewhere else.
     *
     * Views are grouped per reply, and searches in the last reply are pooled
     * the same way the browser pools them live, so a refresh shows the same
     * pins the visitor was just looking at.
     *
     * @param  iterable<Message>  $messages
     * @return array<string, mixed>|null
     */
    protected function lastMapView(iterable $messages): ?array
    {
        $replies = [];
        $current = [];

        foreach ($messages as $message) {
            // Matched on the role: the store rehydrates history as generic
            // messages, so an instanceof check on UserMessage never fires.
            if ($message->role === MessageRole::User) {
                if ($current !== []) {
                    $replies[] = $current;
                }
                $current = [];

                continue;
            }

            if (! $message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->toolResults->all() as $result) {
                if (! in_array($result->name, self::MAP_TOOLS, true)) {
                    continue;
                }

                $view = json_decode((string) $result->result, true);

                if (is_array($view) && isset($view['bbox'])) {
                    $current[] = $view;
                }
            }
        }

        if ($current !== []) {
            $replies[] = $current;
        }

        return $replies === [] ? null : $this->mergeViews(end($replies));
    }

    /**
     * Pool the searches of one reply into a single view. Mirrors `mergeViews()`
     * in `map.ts`: searches win over a bare placement, and their pins are combined.
     *
     * @param  list<array<string, mixed>>  $views
     * @return array<string, mixed>
     */
    protected function mergeViews(array $views): array
    {
        // An itinerary is the deliberate answer of the whole reply, not one
        // search among several, so it wins outright over the lookups that fed it.
        $itineraries = array_values(array_filter($views, fn (array $view): bool => ! empty($view['stops'])));

        if ($itineraries !== []) {
            return end($itineraries);
        }

        $searches = array_values(array_filter($views, fn (array $view): bool => ! empty($view['markers'])));

        if (count($searches) <= 1) {
            return $searches[0] ?? end($views);
        }

        $markers = [];
        $lons = [];
        $lats = [];

        foreach ($searches as $view) {
            foreach ($view['markers'] as $marker) {
                $markers[] = $marker + ['categoryKey' => $view['categoryKey'] ?? null];
            }

            array_push($lons, (float) $view['bbox'][0], (float) $view['bbox'][2]);
            array_push($lats, (float) $view['bbox'][1], (float) $view['bbox'][3]);
        }

        $area = Str::after($searches[0]['label'], ' in ');
        $categories = implode(' and ', array_map(fn (array $view): string => $view['category'] ?? $view['label'], $searches));

        return [
            'label' => $area === $searches[0]['label'] ? $categories : "{$categories} in {$area}",
            'category' => $categories,
            'bbox' => [(string) min($lons), (string) min($lats), (string) max($lons), (string) max($lats)],
            'markers' => $markers,
        ];
    }

    /**
     * Resolve a place name to a map view.
     *
     * Lets a visitor's own agent move the map without going through the
     * assistant, reusing the same geocoder and cache the ShowOnMap tool uses so
     * there is one place where a place name becomes coordinates.
     */
    public function place(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'place' => ['required', 'string', 'max:200'],
        ]);

        $result = (string) (new ShowOnMap)->handle(
            new ToolRequest(['place' => $validated['place']])
        );

        $view = json_decode($result, true);

        // The tool answers in prose when it cannot place somewhere, which is
        // the same signal the assistant gets.
        return is_array($view)
            ? response()->json($view)
            : response()->json(['message' => $result], 404);
    }

    /**
     * Return one conversation's transcript as JSON.
     *
     * Lets an agent read a saved conversation without navigating the visitor
     * away from the one they are looking at.
     */
    public function messages(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->ownedConversation($request, $conversation);

        $messages = (new ChatAgent)
            ->continue($owned->id, $request->user())
            ->messages();

        return response()->json([
            'id' => $owned->id,
            'title' => $owned->getAttribute('title'),
            'messages' => collect($messages)
                ->values()
                ->map(fn (Message $message): array => [
                    'role' => $message->role->value,
                    'text' => $message->content ?? '',
                ])
                ->all(),
        ]);
    }

    /**
     * Stream an assistant reply, creating the conversation on first use.
     */
    public function stream(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            // Where the visitor's map is pointing. It reaches the model, so it
            // is bounded here rather than trusted as the browser sent it.
            'map' => ['nullable', 'array'],
            'map.label' => ['required_with:map', 'string', 'max:200'],
            'map.center' => ['required_with:map', 'array', 'size:2'],
            'map.center.0' => ['required_with:map', 'numeric', 'between:-90,90'],
            'map.center.1' => ['required_with:map', 'numeric', 'between:-180,180'],
            'map.zoom' => ['required_with:map', 'numeric', 'between:0,24'],
            'map.moved' => ['required_with:map', 'boolean'],
            // What the landing page's filters were set to, for a first
            // message. Ignored once the conversation owns its own.
            'preferences' => [
                'nullable',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_array($value)) {
                        return;
                    }

                    try {
                        PropertyPreferences::validate($value);
                    } catch (ValidationException $exception) {
                        $fail($exception->validator->errors()->first() ?? 'Invalid preferences.');
                    }
                },
            ],
            // Only the id is trusted. Listing facts are loaded from the
            // database so a rewritten payload cannot invent a price or address.
            'selected_property' => ['nullable', 'array'],
            'selected_property.id' => ['required_with:selected_property', 'integer', 'min:1'],
        ]);

        $conversation = isset($validated['conversation_id'])
            ? $this->ownedConversation($request, $validated['conversation_id'])
            : $this->startConversation($request, $validated['message'], $validated['preferences'] ?? null);

        $onboarding = OnboardingState::firstOrCreate(['conversation_id' => $conversation->id]);

        // Whatever is typed while a question is open answers that question, so
        // the row stops advertising it and the model can see it was answered.
        if ($onboarding->current_question !== null) {
            $onboarding->update([
                'answers' => [...($onboarding->answers ?? []), ['question' => $onboarding->current_question['question'], 'answer' => $validated['message']]],
                'current_question' => null,
            ]);
        }

        if ($this->inTestMode()) {
            return $this->cannedStream($request, $conversation->id, $onboarding);
        }

        $ai = AiProviderSwitch::chatStreamOptions();

        $stream = (new ChatAgent(
            $validated['map'] ?? null,
            $onboarding,
            $this->selectedListingForAgent(data_get($validated, 'selected_property.id')),
        ))
            ->continue($conversation->id, $request->user())
            ->stream($validated['message'], provider: $ai['provider'], model: $ai['model'])
            ->then(function () use ($conversation): void {
                $userMessageCount = $conversation->messages()
                    ->where('role', 'user')
                    ->count();

                if (in_array($userMessageCount, GenerateConversationTitle::RETITLE_AT, true)) {
                    GenerateConversationTitle::dispatch(
                        $conversation->id,
                        $userMessageCount,
                    );
                }
            });

        $response = $stream
            ->usingVercelDataProtocol()
            ->toResponse($request);

        // A brand new chat has to move onto its own URL, so the browser needs
        // the id. It cannot ride in the stream body: the id must be known
        // before the first byte, and the protocol encoder exposes no hook for
        // extra frames.
        $response->headers->set('X-Conversation-Id', $conversation->id);

        return $this->keepFailuresInTheStream($response);
    }

    /**
     * Should replies be invented rather than generated?
     *
     * Two conditions, not one. The flag is what a developer turns on, and the
     * environment check is what stops it ever mattering if that flag reaches
     * production in a `.env` -- serving made-up answers to real visitors would
     * be a worse failure than the outage it looks like.
     */
    protected function inTestMode(): bool
    {
        return config('chat.test_mode') === true && ! app()->isProduction();
    }

    /**
     * Stream a canned reply, picked at random unless one was named.
     *
     * `?scenario=` is honoured so a particular state can be returned to while
     * it is being worked on, rather than refreshing until it comes up. The
     * property workflow is the exception to the otherwise stateless canned
     * replies: it records only the onboarding state needed to exercise the
     * real review and database-search steps.
     */
    protected function cannedStream(Request $request, string $conversationId, OnboardingState $onboarding): SymfonyResponse
    {
        $scenario = $request->query('scenario')
            ?? ($onboarding->flow === 'property' ? 'property_workflow' : CannedReplies::pick());
        $frameScenario = $scenario;

        // The property scenario is the exception to the otherwise stateless
        // canned replies: the search it streams is real, run against the real
        // database, so test mode puts the same pins on the map as a live turn.
        [$input, $output] = $scenario === 'property_workflow'
            ? $this->realPropertySearch($onboarding)
            : [[], '{}'];

        $replies = $scenario === 'property_workflow'
            ? new CannedReplies($conversationId, $input, $output)
            : new CannedReplies($conversationId);

        $response = new StreamedResponse(function () use ($replies, $frameScenario): void {
            foreach ($replies->frames($frameScenario) as $frame) {
                $this->writeFrame($frame);

                // Slow enough to watch the reply build, which is the point of
                // looking at it at all.
                usleep(40_000);
            }

            $this->writeFrame('[DONE]');
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'x-vercel-ai-ui-message-stream' => 'v1',
            'X-Conversation-Id' => $conversationId,
            // So it is obvious in the network tab that none of this is real.
            'X-Chat-Test-Scenario' => $scenario,
        ]);

        return $this->keepFailuresInTheStream($response);
    }

    /**
     * Run the real search tool so test mode is only pretending about the prose.
     *
     * Nothing is narrowed here: with no model to read the message, the widest
     * saved preferences are searched again, which is exactly what an unchanged
     * filter set should do.
     *
     * @return array{array<string, mixed>, string}
     */
    protected function realPropertySearch(OnboardingState $onboarding): array
    {
        $preferences = PropertyPreferences::validate(
            $onboarding->plan['preferences'] ?? PropertyPreferences::defaults()
        );

        $output = (string) (new UpdatePropertySearchPreferences($onboarding))
            ->handle(new ToolRequest($preferences));

        return [$preferences, $output];
    }

    /**
     * Report a mid-stream failure as a protocol frame rather than an exception.
     *
     * The Vercel encoder iterates the provider with no try/catch, so anything
     * the provider raises -- an expired key, a rate limit -- escapes after the
     * response headers have gone out. Laravel then renders an entire HTML error
     * page and sends *its* headers on top of the ones already written, which is
     * what produced nginx's "upstream sent duplicate header line: Date" warning,
     * a merged Cache-Control, and a 37KB error document delivered as
     * text/event-stream.
     *
     * Caught here it stays one well-formed stream: an error part the browser can
     * show, then the terminator the protocol requires. The status is already 200
     * by this point -- headers are sent before the callback runs -- which is why
     * the protocol carries errors in band rather than in the status line.
     */
    protected function keepFailuresInTheStream(SymfonyResponse $response): SymfonyResponse
    {
        if (! $response instanceof StreamedResponse || ($stream = $response->getCallback()) === null) {
            return $response;
        }

        return $response->setCallback(function () use ($stream): void {
            $buffer = $this->hideProviderErrors();

            try {
                $stream();
            } catch (Throwable $e) {
                // Still an error worth paging over; it just must not escape.
                report($e);

                $buffer['discard']();
                $this->writeFrame(['type' => 'error', 'errorText' => $this->failureMessage()]);
                $this->writeFrame('[DONE]');
            } finally {
                $buffer['stop']();
            }
        });
    }

    /**
     * Replace the text of any error frame on its way to the browser.
     *
     * A provider that fails mid-stream reports it *as an event* before the
     * exception is raised, and the encoder writes that event's message out
     * verbatim -- which for OpenAI means the organisation id, the account's
     * limits and how long until they reset. Catching the exception above is
     * therefore too late: the raw text has already gone out ahead of it.
     *
     * The frame itself is kept rather than dropped, because the browser needs
     * it to know the reply failed at all; only the wording is ours. Errors
     * still reach the log intact through `report()`.
     *
     * @return array{discard: Closure(): void, stop: Closure(): void}
     */
    protected function hideProviderErrors(): array
    {
        $partial = '';

        // Frames are written one at a time and separated by a blank line, so a
        // chunk size of 1 hands this whole frames. It carries the remainder
        // anyway: a frame split down the middle would otherwise slip through
        // unread, which is the one case that must not leak.
        ob_start(function (string $chunk) use (&$partial): string {
            $partial .= $chunk;
            $complete = '';

            while (($end = strpos($partial, "\n\n")) !== false) {
                $complete .= $this->withoutProviderDetail(substr($partial, 0, $end + 2));
                $partial = substr($partial, $end + 2);
            }

            return $complete;
        }, 1);

        return [
            'discard' => function () use (&$partial): void {
                $partial = '';
            },
            'stop' => function () use (&$partial): void {
                // An incomplete frame is either a split error (must not leak)
                // or a truncated token. Drop it rather than flushing raw text.
                $partial = '';

                ob_end_flush();
            },
        ];
    }

    /**
     * Swap the provider's wording out of one server-sent event.
     */
    protected function withoutProviderDetail(string $frame): string
    {
        if (! str_starts_with($frame, 'data: ')) {
            return $frame;
        }

        $payload = json_decode(substr($frame, 6, -2), true);

        if (! is_array($payload)) {
            // A split error glued to the next write is not valid JSON. Passing
            // it through would leak the provider wording the rewriter exists
            // to hide.
            return str_contains($frame, 'error') ? '' : $frame;
        }

        if (($payload['type'] ?? null) !== 'error') {
            return $frame;
        }

        $payload['errorText'] = $this->failureMessage();

        return 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
    }

    /**
     * What the visitor is told when a reply does not arrive.
     */
    protected function failureMessage(): string
    {
        return __('The assistant could not be reached. Please try again.');
    }

    /**
     * Write one server-sent event, flushing as the streamed response does.
     *
     * @param  array<string, mixed>|string  $frame
     */
    protected function writeFrame(array|string $frame): void
    {
        echo 'data: '.(is_string($frame) ? $frame : json_encode($frame, JSON_THROW_ON_ERROR))."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * Create the conversation up front so its id is known before streaming.
     *
     * Laravel\Ai would otherwise create it mid-stream, which is too late to
     * report back to the browser. Creating it here also means the package skips
     * its own title generation, so the title is the opening message.
     */
    /** @param array<string, mixed>|null $preferences What the visitor had already filtered to. */
    protected function startConversation(Request $request, string $message, ?array $preferences = null): Conversation
    {
        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'participant_type' => Conversation::participantType($request->user()),
            'participant_id' => Conversation::participantKey($request->user()),
            'title' => Str::limit(trim($message), 50, preserveWords: true) ?: __('New chat'),
        ]);
        // Straight into mapping with the widest search already saved. There is
        // no interview to pass through: the opening message narrows this, and
        // anything it does not mention simply stays wide open.
        OnboardingState::create([
            'conversation_id' => $conversation->id,
            'flow' => 'property',
            'phase' => 'mapping',
            'plan' => SavePropertyPreferences::plan($this->openingPreferences($preferences)),
        ]);

        return $conversation;
    }

    /**
     * The filters a new conversation starts from.
     *
     * Whatever the visitor narrowed the landing page to carries into the
     * conversation, so a first message refines the search in front of them
     * rather than silently widening it back to everything. Anything the browser
     * sends that does not validate falls back to the defaults rather than
     * failing the message.
     *
     * @param  array<string, mixed>|null  $preferences
     * @return array{location: string, location_type: string, county: ?string, max_price: int, min_bedrooms: ?int, property_type: ?string, minimum_ber_rating: ?string, sort: string}
     */
    protected function openingPreferences(?array $preferences): array
    {
        if ($preferences === null) {
            return PropertyPreferences::defaults();
        }

        try {
            return PropertyPreferences::validate($preferences);
        } catch (ValidationException) {
            return PropertyPreferences::defaults();
        }
    }

    /**
     * Delete one conversation and everything the chat module stored for it.
     */
    public function destroy(Request $request, string $conversation): RedirectResponse
    {
        $owned = $this->ownedConversation($request, $conversation);
        $this->deleteOwnedConversation($owned);

        $path = parse_url((string) url()->previous(), PHP_URL_PATH) ?? '';

        if ($path === '/chat/'.$owned->id) {
            return redirect()->route('chat.index');
        }

        return back();
    }

    /**
     * Delete every conversation belonging to the authenticated user.
     */
    public function destroyAll(Request $request): RedirectResponse
    {
        $conversations = $this->userConversations($request)->get();

        DB::transaction(function () use ($conversations): void {
            foreach ($conversations as $conversation) {
                $this->deleteOwnedConversation($conversation);
            }
        });

        return back();
    }

    /**
     * Resolve a conversation the authenticated user owns.
     *
     * Laravel\Ai's continue() performs no ownership check of its own, so every
     * path that accepts an id from the client must come through here first.
     */
    protected function ownedConversation(Request $request, string $id): Conversation
    {
        return $this->userConversations($request)
            ->where('id', $id)
            ->firstOrFail();
    }

    /** @return Builder<Conversation> */
    protected function userConversations(Request $request)
    {
        return Conversation::query()
            ->where('participant_type', Conversation::participantType($request->user()))
            ->where('participant_id', Conversation::participantKey($request->user()));
    }

    protected function deleteOwnedConversation(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            $conversation->messages()->delete();
            OnboardingState::where('conversation_id', $conversation->id)->delete();
            $conversation->delete();
        });
    }
}
