<?php

namespace Modules\Chat\Ai;

use Illuminate\Support\Str;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\ToolChoice;
use Modules\Chat\Ai\Tools\CompareListingAmenities;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\InterviewVisitor;
use Modules\Chat\Ai\Tools\SaveItinerary;
use Modules\Chat\Ai\Tools\SaveMapReadyPlan;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Modules\Chat\Ai\Tools\UpdatePropertySearchPreferences;
use Modules\Chat\Models\OnboardingState;
use Modules\Properties\PropertySearch;
use Stringable;

/**
 * The model is pinned so provider SDK updates cannot silently change the
 * quality, latency, or cost of the application's central experience.
 */
#[Model(self::MODEL)]
#[MaxSteps(8)]
class ChatAgent implements Agent, HasProviderOptions, HasTools, RemembersConversationsContract
{
    use Promptable, RemembersConversations;

    /**
     * The model this assistant runs on.
     *
     * A constant rather than a literal in the attribute so there is one place
     * to change it, and so the admin pricing form can offer it as the rate
     * that actually matters.
     */
    public const string MODEL = 'gpt-4o-mini';

    /**
     * Whether the pinned model reasons.
     *
     * Kept beside the model constant so the two move together: changing one
     * without the other either loses the route of thought or breaks every
     * request.
     */
    public const bool REASONS = false;

    /**
     * Questions the interview always asks before a plan can be saved.
     */
    public const int MIN_QUESTIONS = 2;

    /**
     * @param  array{label: string, center: array{float, float}, zoom: float, moved: bool}|null  $mapViewport
     *                                                                                                         Where the visitor's map is pointing as this message is sent.
     */
    /** @param array<string, mixed>|null $selectedProperty */
    public function __construct(protected ?array $mapViewport = null, protected ?OnboardingState $onboarding = null, protected ?array $selectedProperty = null) {}

    /**
     * Get the instructions that the agent should follow.
     *
     * The map position rides here rather than on the user's message, so the
     * transcript the visitor reads back stays exactly what they typed.
     */
    public function instructions(): Stringable|string
    {
        if ($this->onboarding?->flow === 'property') {
            $preferences = json_encode(
                $this->onboarding->plan['details'] ?? [],
                JSON_THROW_ON_ERROR,
            );
            $selectedProperty = $this->selectedPropertyContext();
            $listings = $this->currentListings();

            return <<<TEXT
            You help visitors buy a home or a plot of land from our database. A map of matching listings sits beside the conversation and is already showing results.
            Saved preferences: {$preferences}. Selected property: {$selectedProperty}.
            {$listings}
            Never interview the visitor. Never ask for a budget, bedroom count, property type or BER rating they have not mentioned.
            Never say you will inspect, analyze, review, determine next steps, or work on the request. Reply with concrete listings, prices, and addresses — not planning language.
            On every message that expresses or changes what they are looking for, call update_property_search_preferences. A new or broader search — any housing, just homes, a new town, or a new budget without repeating the old type and bedrooms — must set replace to true, or leftover apartment and bedroom filters hide every match. Tightening the same search (cheaper, more beds, only apartments) omits replace and passes only the filters they stated. "Any" or "no preference" passes null for that filter.
            When they mention Cork city centre, city center, downtown, or "in the centre", set location to Cork and location_type to town before advising.
            Quote every listing price exactly as written below or in a marker's price field — those are already euro. Never convert asking_price, price_per_sqm or max_price: those are integer cents for the search tool only and must not appear in the reply. €90,000 is ninety thousand euros.
            max_price is the maximum asking price in integer euro cents (100000 euros is 10000000). "B3 or better" sets minimum_ber_rating to B3. "Any" or "no preference" for a filter means pass null for it.
            Site, plot, building land, agricultural land or "just land" sets property_type to land. A house, cottage or home sets house. An apartment, flat, duplex or studio sets apartment. A bungalow or dormer sets bungalow. Land has no bedrooms and no BER: do not pass those filters with land. If they ask for bedrooms after a land search, pass min_bedrooms and omit property_type so homes can match.
            Ask a question only when a stated filter is genuinely ambiguous and you cannot search without resolving it, above all city versus county for Cork, Galway and Limerick. Ask it as one short sentence in your reply. Search with your best reading first whenever you can; do not hold results back waiting for an answer.
            sort is price for cheapest asking price first, or price_per_sqm for cheapest euro per square metre first. Use price_per_sqm when they ask for the best value, cheapest per metre, most space for the money, or the lowest price per square metre. For land that rate uses plot size, not floor area. A home or plot with no area has no rate: do not invent one.
            After a search, advise. Pick two or three standouts from the listings below and name each by its exact address so the map can link them. Compare homes with listed facts only: price, euro per m2, beds, type, town, BER, floor area, highlight, photo count. Compare land with price, euro per m2 of plot, plot size, town, highlight and photo count. Never invent planning permission, services, road frontage or soil. Green value pins are the cheaper matches on the active sort; blue match pins hit the bedroom filter exactly; amber premium pins sit near the top of that sort; purple typical pins sit in the middle. A red pin is the listing the visitor selected in chat or on the map.
            When a visitor has selected a listing, answer from the selected-property facts first, then compare it briefly to one other listed home or plot if that helps. When they ask which listing has the best, closest or most convenient hospital, school, clinic, bus stop, train station, park or shop, call compare_listing_amenities once with those kinds. Never use find_places or a listing address for that comparison: the tool already walks from each pin. Name the winner by its exact address, give each nearest place with the distance the tool returned, and say when a kind is missing. Do not invent a hospital, school or stop.
            Only describe facts from the listings below, the selected property, or a compare_listing_amenities result. Do not invent addresses, prices, features, photos, or availability. If photos are 0, do not claim a listing has pictures. No results means no matches in our database; say so plainly and offer to widen a filter.
            Do not dump the whole list or repeat every filter. Three or four short sentences is enough.
            {$this->languageRule()}
            TEXT;
        }

        $instructions = <<<INSTRUCTIONS
        You are a helpful assistant who answers questions about places anywhere
        in the world. Focus on towns, streets, addresses, landmarks,
        neighbourhoods, and what is in or near them. If a request is not about a
        place, explain that you specialize in location-based questions and offer
        to help the visitor explore somewhere.
        {$this->languageRule()}
        INSTRUCTIONS;

        if ($this->onboarding === null || $this->onboarding->phase === 'mapping') {
            $instructions .= <<<'INSTRUCTIONS'


            A map sits beside the conversation. Whenever your answer is about a place
            the visitor could look at, call show_on_map so the map follows along, then
            answer normally. Do not mention the map or the tool in your reply, and do
            not read coordinates out loud: the visitor can already see it.

            When they ask what is in or around somewhere rather than where one place
            is, use the find_places tool so the map shows up to 40 results at once.
            Treat them as a selection, not a complete inventory. The map already
            shows every returned pin, so summarize the selection and mention only
            the places worth singling out.
            INSTRUCTIONS;
        }

        $instructions .= $this->onboardingContext();

        $viewport = $this->viewportContext();

        return $viewport === '' ? $instructions : $instructions."\n\n".$viewport;
    }

    /**
     * Steer the assistant through discovery, review, and the open map.
     *
     * The phase lives in the onboarding row, so the model is told plainly what
     * it may and may not do rather than left to infer it from the transcript.
     */
    protected function onboardingContext(): string
    {
        if ($this->onboarding === null) {
            return '';
        }

        $plan = json_encode($this->onboarding->plan ?? [], JSON_THROW_ON_ERROR);
        $answers = json_encode($this->onboarding->answers ?? [], JSON_THROW_ON_ERROR);

        return match ($this->onboarding->phase) {
            'interviewing' => <<<TEXT


            The visitor is in a short discovery interview before the map opens. Do not answer the request, recommend anything, or list places yet: that happens on the map afterwards.
            Answers so far: {$answers}. Questions asked so far: {$this->onboarding->question_count} of a hard maximum of 10.
            Each turn do exactly one of these:
            1. If at least two questions have been asked, the goal and a named location are known, and nothing important is missing, call save_map_ready_plan.
            2. Otherwise call interview_visitor once with the single most useful missing question. Prioritise location, purpose, timing, companions, interests, and constraints such as budget or accessibility. Never ask something the visitor already answered. Give 2 to 5 options with the recommended one first; the interface adds "Other" itself. Write the question and every option in the visitor's language.
            A location is required before saving the plan. Stop as soon as you have enough detail: three or four questions are usually plenty.
            Saved plan so far: {$plan}. If a plan already exists, the visitor came back for more questions: ask at least one new question about something the plan does not cover before saving it again.
            If the visitor asks to skip or says they want the map, call save_map_ready_plan immediately with what you know.
            After calling a tool, do not repeat the question or the plan in prose and do not give suggestions. Reply with one short friendly sentence at most, or nothing.
            TEXT,
            'reviewing' => <<<TEXT


            The visitor is reviewing their saved plan before opening the map: {$plan}. Do not ask more questions and do not list places yet.
            If they change or add anything, call save_map_ready_plan with the complete updated plan and confirm in one sentence in the visitor's language.
            TEXT,
            default => <<<TEXT


            The visitor's plan: {$plan}. Use it to guide every search and suggestion. If they change their goal, location, or an important detail, call save_map_ready_plan with the complete updated plan as well as helping them.
            When they ask for a day plan, an itinerary, a route, or what to do first, call save_itinerary with the stops in order, drawing on places you have already found with find_places. It replaces the whole list, so pass every stop each time, including the ones that are not changing.
            Order the stops geographically, as one continuous route across the area, so the day never doubles back past a stop already visited. A meal stop belongs between the stops on either side of it: pick somewhere to eat near them rather than moving the route to reach it.
            TEXT,
        };
    }

    protected function currentListings(): string
    {
        $preferences = $this->onboarding?->plan['preferences'] ?? null;

        if (! is_array($preferences)) {
            return 'No listings are on the map yet.';
        }

        try {
            $view = (new PropertySearch)->search($preferences);
        } catch (\Throwable) {
            return 'The listing search could not be read.';
        }

        $markers = $view['markers'] ?? [];

        if ($markers === []) {
            return 'The map has no matching homes or land from our database right now. Offer to widen a filter.';
        }

        $lines = [];

        foreach (array_slice($markers, 0, 10) as $marker) {
            $price = PropertySearch::euroLabel($marker['asking_price'] ?? null) ?? 'price unlisted';
            $type = $marker['property_type'] ?? 'home';
            $highlight = $marker['highlight'] ?? 'typical';
            $name = $marker['name'] ?? 'Listing';
            $town = $marker['town'] ?? '';
            $photos = count($marker['images'] ?? []);

            $rate = PropertySearch::rateLabel($marker['price_per_sqm'] ?? null) ?? 'rate unlisted';

            if ($type === 'land') {
                $plot = $marker['size_label'] ?? 'plot size unlisted';
                $lines[] = "- {$name} | {$town} | {$price} | {$rate} | land | {$plot} | {$photos} photos | pin {$highlight}";

                continue;
            }

            $beds = $marker['bedrooms'] ?? 'unlisted';
            $ber = $marker['ber_rating'] ?? 'unlisted';
            $area = isset($marker['floor_area_sqm'])
                ? ((int) round((float) $marker['floor_area_sqm'])).' m2'
                : 'area unlisted';
            $lines[] = "- {$name} | {$town} | {$price} | {$rate} | {$beds} beds | {$type} | BER {$ber} | {$area} | {$photos} photos | pin {$highlight}";
        }

        $shown = count($markers);
        $total = $view['total'] ?? $shown;

        return "Listings currently on the map ({$shown} of {$total}):\n".implode("\n", $lines);
    }

    protected function selectedPropertyContext(): string
    {
        if ($this->selectedProperty === null) {
            return 'none';
        }

        $property = $this->selectedProperty;

        if (isset($property['details']['description']) && is_string($property['details']['description'])) {
            $property['details']['description'] = Str::limit(
                $property['details']['description'],
                400,
            );
        }

        if (isset($property['asking_price'])) {
            $property['price'] = PropertySearch::euroLabel($property['asking_price']);
            unset($property['asking_price']);
        }

        if (isset($property['price_per_sqm'])) {
            $property['rate'] = PropertySearch::rateLabel($property['price_per_sqm']);
            unset($property['price_per_sqm']);
        }

        return json_encode($property, JSON_THROW_ON_ERROR);
    }

    protected function languageRule(): string
    {
        return 'Reply in the same language the visitor is writing in. Follow their latest message: Portuguese stays Portuguese, English stays English, and any other language they use is the reply language. Tool names and filter keys stay in English. Place names, addresses and listing facts stay written as stored. Interview questions and option labels must also be in the visitor\'s language.';
    }

    /**
     * Describe where the map is pointing, if the browser told us.
     */
    protected function viewportContext(): string
    {
        if ($this->mapViewport === null) {
            return '';
        }

        [$latitude, $longitude] = $this->mapViewport['center'];
        $label = $this->placeLabel($latitude, $longitude);
        $point = round($latitude, 5).', '.round($longitude, 5);

        return "The map beside the conversation is showing {$label}, centred on {$point}. When the visitor says \"here\", \"there\" or \"this area\" without naming a place, they mean {$label}.";
    }

    /**
     * Name what the map is centred on.
     *
     * The browser's label is whatever the conversation last put on the map, so
     * once the visitor drags the camera elsewhere it describes the wrong place.
     * The centre is then named afresh, because coordinates on their own tell
     * the model nothing it can answer with.
     */
    protected function placeLabel(float $latitude, float $longitude): string
    {
        if (! $this->mapViewport['moved']) {
            return $this->mapViewport['label'];
        }

        return (new ShowOnMap)->placeAt($latitude, $longitude)
            ?? $this->mapViewport['label'];
    }

    /**
     * Get the tools available to the agent.
     *
     * Both map tools are local so their calls and results are visible in the
     * streamed route of thought. Web search remains provider-hosted.
     *
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        // Search updates filters and results in one call. Nearby comparison is
        // a second tool because find_places geocodes an address and cannot
        // rank the pins already on the map.
        if ($this->onboarding?->flow === 'property') {
            return [
                new UpdatePropertySearchPreferences($this->onboarding),
                new CompareListingAmenities($this->onboarding),
            ];
        }

        // During discovery the map tools are withheld outright rather than
        // forbidden in prose: the model reliably reached for find_places the
        // moment a place was named, whatever the instructions said.
        if ($this->onboarding !== null && $this->onboarding->phase !== 'mapping') {
            // The plan tool is withheld until two questions have been asked, so
            // a well-worded opening message cannot skip the interview outright.
            return [
                ...($this->onboarding->question_count >= self::MIN_QUESTIONS ? [new SaveMapReadyPlan($this->onboarding)] : []),
                ...($this->onboarding->phase === 'interviewing' ? [new InterviewVisitor($this->onboarding)] : []),
            ];
        }

        return [
            new ShowOnMap,
            new FindPlaces($this->onboarding === null ? null : (string) $this->onboarding->getKey()),
            new WebSearch,
            ...($this->onboarding === null ? [] : [new SaveMapReadyPlan($this->onboarding), new SaveItinerary($this->onboarding)]),
        ];
    }

    /**
     * Force a tool call on the first step while the visitor is in discovery.
     *
     * With only the interview and plan tools on offer, "required" means the
     * turn always produces a question or a plan. The SDK releases the choice
     * on the next step so the model can still add a short sentence.
     */
    public function toolChoice(): ?string
    {
        return $this->onboarding !== null
            && $this->onboarding->flow !== 'property'
            && $this->onboarding->phase !== 'mapping'
            ? ToolChoice::required
            : null;
    }

    /**
     * Get provider-specific generation options.
     *
     * OpenAI streams no reasoning summaries unless they are asked for, so
     * without this the route of thought beside the reply has nothing to show
     * but the tool calls.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return match (true) {
            // Both halves are load-bearing. Without `summary` OpenAI reasons
            // silently and streams nothing to summarise; without `effort` the
            // model does not reason at all, so `summary` has nothing to say.
            // Keep the visible route useful without letting it compete with the reply.
            //
            // Only the reasoning models accept them at all: sent to any other
            // OpenAI model the request is rejected outright with a 400, so the
            // whole chat fails rather than merely losing its route of thought.
            $provider === Lab::OpenAI && self::REASONS => ['reasoning' => ['effort' => 'low', 'summary' => 'concise']],
            default => [],
        };
    }
}
