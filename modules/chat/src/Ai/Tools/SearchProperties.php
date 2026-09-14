<?php

namespace Modules\Chat\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Models\OnboardingState;
use Modules\Properties\PropertyPreferences;
use Modules\Properties\PropertySearch;
use Stringable;

class SearchProperties implements Tool
{
    public const string NAME = 'search_properties';

    public function __construct(protected OnboardingState $state) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): Stringable|string
    {
        return 'Search properties for sale using exactly the confirmed preferences, with maximum asking price in euro cents. Returns at most 20 results and the total match count. Do not change filters silently. Empty results mean no database matches, not no properties in the real world.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->state->refresh();
        if ($this->state->flow !== 'property' || $this->state->phase !== 'mapping') {
            return 'Ask the visitor to confirm the preferences using Search properties first.';
        }
        try {
            $preferences = PropertyPreferences::validate($request->all());
        } catch (ValidationException $exception) {
            return json_encode(['errors' => $exception->errors()], JSON_THROW_ON_ERROR);
        }
        if ($preferences !== ($this->state->plan['preferences'] ?? null)) {
            return 'These filters differ from the confirmed preferences. Save the updated preferences for review first.';
        }
        $view = (new PropertySearch)->search($preferences);
        $this->state->update(['property_result_ids' => array_column($view['markers'], 'id')]);

        return (new PropertySearch)->compactToolResponse($view);
    }

    public function schema(JsonSchema $schema): array
    {
        return (new SavePropertyPreferences($this->state))->schema($schema);
    }
}
