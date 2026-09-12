<?php

namespace Modules\Chat\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Modules\Chat\Models\OnboardingState;
use Modules\Properties\PropertyPreferences;
use Stringable;

class SavePropertyPreferences implements Tool
{
    public const string NAME = 'save_property_preferences';

    public function __construct(protected OnboardingState $state) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): Stringable|string
    {
        return 'Save the complete buying preferences for the visitor to review. Ask for missing preferences first. Resolve town versus county with the visitor. max_price is the maximum asking price in integer euro cents (350000 euros = 35000000). Pass null for any bedrooms or property type. This always requires fresh confirmation before a search.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $preferences = PropertyPreferences::validate($request->all());
        } catch (ValidationException $exception) {
            return json_encode(['errors' => $exception->errors()], JSON_THROW_ON_ERROR);
        }

        $plan = [
            'goal' => 'Buy a property',
            'location' => $preferences['location'].($preferences['location_type'] === 'county' ? ' county' : '').($preferences['county'] ? ', '.$preferences['county'] : ''),
            'details' => [
                'Maximum asking price' => '€'.number_format($preferences['max_price'] / 100, 0),
                'Minimum bedrooms' => $preferences['min_bedrooms'] === null ? 'Any' : (string) $preferences['min_bedrooms'],
                'Property type' => ucfirst($preferences['property_type'] ?? 'any'),
            ],
            'preferences' => $preferences,
        ];
        $this->state->update(['plan' => $plan, 'phase' => 'reviewing', 'current_question' => null, 'property_result_ids' => null]);

        return json_encode($plan, JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'location' => $schema->string()->required(),
            'location_type' => $schema->string()->enum(['town', 'county'])->required(),
            'county' => $schema->string()->nullable()->required(),
            'max_price' => $schema->integer()->min(1)->required(),
            'min_bedrooms' => $schema->integer()->min(0)->nullable()->required(),
            'property_type' => $schema->string()->enum(['house', 'apartment', 'bungalow'])->nullable()->required(),
        ];
    }
}
