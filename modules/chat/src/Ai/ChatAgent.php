<?php

namespace Modules\Chat\Ai;

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
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\InterviewVisitor;
use Modules\Chat\Ai\Tools\SaveItinerary;
use Modules\Chat\Ai\Tools\SaveMapReadyPlan;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Modules\Chat\Ai\Tools\UpdatePropertySearchPreferences;
use Modules\Chat\Models\OnboardingState;
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
    public const string MODEL = 'gpt-5.4-mini';

    /**
     * Whether the pinned model reasons.
     *
     * Kept beside the model constant so the two move together: changing one
     * without the other either loses the route of thought or breaks every
     * request.
     */
    public const bool REASONS = true;

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
            $plan = json_encode($this->onboarding->plan ?? [], JSON_THROW_ON_ERROR);
            $selectedProperty = $this->selectedProperty === null
                ? 'none'
                : json_encode($this->selectedProperty, JSON_THROW_ON_ERROR);

            return <<<TEXT
            You help visitors buy a home. A map of matching properties sits beside the conversation and is already showing results.
            Saved preferences: {$plan}. Selected property: {$selectedProperty}.
            Never interview the visitor. Never ask for a budget, bedroom count, property type or BER rating they have not mentioned: anything unstated stays as it is and the search simply stays wide on that filter.
            On every message that expresses or changes what they are looking for, call update_property_search_preferences with only the filters they actually stated, and nothing else. It preserves every other filter and searches immediately.
            max_price is the maximum asking price in integer euro cents (350000 euros is 35000000). "B3 or better" sets minimum_ber_rating to B3. "Any" or "no preference" for a filter means pass null for it.
            Ask a question only when a stated filter is genuinely ambiguous and you cannot search without resolving it, above all city versus county for Cork, Galway and Limerick. Ask it as one short sentence in your reply. Search with your best reading first whenever you can; do not hold results back waiting for an answer.
            When a visitor has selected a property, answer questions about that property from the selected-property facts only. For questions about schools, parks, transport or amenities around it, use find_places with the property's full address as area.
            Only describe property facts returned by the search. Do not invent addresses, prices, features, photos, or availability. No results means no matches in our database; say so plainly and offer to widen a filter.
            No renovation, itinerary, financial advice, valuation, comparisons, or external research in this version. Do not claim to search every listing in an area.
            Do not repeat the filters or the results list back in prose: the visitor can see both. One or two short sentences at most.
            TEXT;
        }

        $instructions = <<<'INSTRUCTIONS'
        You are a helpful assistant who answers questions about places anywhere
        in the world. Focus on towns, streets, addresses, landmarks,
        neighbourhoods, and what is in or near them. If a request is not about a
        place, explain that you specialize in location-based questions and offer
        to help the visitor explore somewhere.
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
            2. Otherwise call interview_visitor once with the single most useful missing question. Prioritise location, purpose, timing, companions, interests, and constraints such as budget or accessibility. Never ask something the visitor already answered. Give 2 to 5 options with the recommended one first; the interface adds "Other" itself.
            A location is required before saving the plan. Stop as soon as you have enough detail: three or four questions are usually plenty.
            Saved plan so far: {$plan}. If a plan already exists, the visitor came back for more questions: ask at least one new question about something the plan does not cover before saving it again.
            If the visitor asks to skip or says they want the map, call save_map_ready_plan immediately with what you know.
            After calling a tool, do not repeat the question or the plan in prose and do not give suggestions. Reply with one short friendly sentence at most, or nothing.
            TEXT,
            'reviewing' => <<<TEXT


            The visitor is reviewing their saved plan before opening the map: {$plan}. Do not ask more questions and do not list places yet.
            If they change or add anything, call save_map_ready_plan with the complete updated plan and confirm in one sentence.
            TEXT,
            default => <<<TEXT


            The visitor's plan: {$plan}. Use it to guide every search and suggestion. If they change their goal, location, or an important detail, call save_map_ready_plan with the complete updated plan as well as helping them.
            When they ask for a day plan, an itinerary, a route, or what to do first, call save_itinerary with the stops in order, drawing on places you have already found with find_places. It replaces the whole list, so pass every stop each time, including the ones that are not changing.
            Order the stops geographically, as one continuous route across the area, so the day never doubles back past a stop already visited. A meal stop belongs between the stops on either side of it: pick somewhere to eat near them rather than moving the route to reach it.
            TEXT,
        };
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
        // One search tool, not three. It merges a partial change into the saved
        // filters and searches in the same call, so there is no way to reach a
        // state where preferences and results disagree, and no confirmation
        // step between the visitor asking and the map answering.
        if ($this->onboarding?->flow === 'property') {
            return [
                new UpdatePropertySearchPreferences($this->onboarding),
                new FindPlaces((string) $this->onboarding->getKey()),
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
