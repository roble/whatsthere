<?php

namespace Modules\Chat\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Models\OnboardingState;
use Stringable;

class SaveMapReadyPlan implements Tool
{
    public const string NAME = 'save_map_ready_plan';

    public function __construct(protected OnboardingState $state) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): Stringable|string
    {
        return 'Save or update the visitor\'s map-ready plan. Always pass the complete plan: goal, a location, and only the details that matter for this request (for example timing, interests, budget, companions, accessibility) as short "Label": "value" pairs. The location must be a single place a map can find, written as "neighbourhood, city, country" or "city, country", e.g. "City Bowl, Cape Town, South Africa". Never join alternatives with slashes or dashes; put descriptive area notes in details instead.';
    }

    public function handle(Request $request): Stringable|string
    {
        $location = trim((string) $request['location']);

        // The location drives every search once the map opens. A wording the
        // geocoder cannot resolve ("Cape Town - Table Mountain / City Bowl")
        // would leave the map empty, so it is refused here, where the model
        // can still rephrase it.
        $view = json_decode((string) (new ShowOnMap)->handle(new Request(['place' => $location])), true);

        if (! is_array($view) || ! isset($view['bbox'])) {
            return "The map cannot find [{$location}]. Call save_map_ready_plan again with the location as one findable place, formatted like \"City Bowl, Cape Town, South Africa\", and move any area notes into details.";
        }

        $plan = [
            'goal' => trim((string) $request['goal']),
            'location' => $location,
            'details' => (array) ($request['details'] ?? []),
        ];

        // Once the map is open the plan is only refreshed; the visitor is never
        // sent back to the review card.
        $this->state->update([
            'plan' => $plan,
            'current_question' => null,
            'phase' => $this->state->phase === 'mapping' ? 'mapping' : 'reviewing',
        ]);

        return json_encode($plan, JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()->required(),
            'location' => $schema->string()->required(),
            'details' => $schema->object(),
        ];
    }
}
