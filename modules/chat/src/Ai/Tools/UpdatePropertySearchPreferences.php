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

class UpdatePropertySearchPreferences implements Tool
{
    public const string NAME = 'update_property_search_preferences';

    public function __construct(protected OnboardingState $state) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): Stringable|string
    {
        return 'Apply only the property filters the visitor explicitly changed, preserving every other saved filter, then search immediately. Use this in the property results view instead of starting a new interview. max_price is integer euro cents. minimum_ber_rating means that rating or better.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->state->refresh();

        try {
            $preferences = PropertyPreferences::merge($this->state->plan['preferences'] ?? [], $request->all());
        } catch (ValidationException $exception) {
            return json_encode(['errors' => $exception->errors()], JSON_THROW_ON_ERROR);
        }

        $plan = SavePropertyPreferences::plan($preferences);
        $view = (new PropertySearch)->search($preferences);

        $this->state->update([
            'plan' => $plan,
            'phase' => 'mapping',
            'current_question' => null,
            'property_result_ids' => array_column($view['markers'], 'id'),
        ]);

        return json_encode($view, JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'location' => $schema->string()->nullable(),
            'location_type' => $schema->string()->enum(['town', 'county'])->nullable(),
            'county' => $schema->string()->nullable(),
            'max_price' => $schema->integer()->min(1)->nullable(),
            'min_bedrooms' => $schema->integer()->min(0)->nullable(),
            'property_type' => $schema->string()->enum(['house', 'apartment', 'bungalow'])->nullable(),
            'minimum_ber_rating' => $schema->string()->enum(PropertyPreferences::BER_RATINGS)->nullable(),
        ];
    }
}
