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
        return 'Search listings with the filters the visitor just stated. max_price is integer euro cents (200000 euros is 20000000); a euro amount is also accepted. Pass replace=true for a new or broader search — any housing, just homes, a new town, or a new budget without repeating the old type and bedrooms — so leftover apartment or bedroom filters are cleared. Pass null to clear one filter. property_type land is sites and plots, not a house. Setting land clears bedrooms and BER. Asking for bedrooms while land is selected switches the type back to any. sort is price or price_per_sqm.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->state->refresh();

        $changes = $request->all();
        $replace = filter_var($changes['replace'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || $this->startsFresh($changes);
        unset($changes['replace']);

        try {
            $preferences = PropertyPreferences::merge(
                $this->basePreferences($replace),
                $changes,
            );
        } catch (ValidationException $exception) {
            return json_encode(['errors' => $exception->errors()], JSON_THROW_ON_ERROR);
        }

        $view = (new PropertySearch)->searchOrWiden($preferences);
        $preferences = $view['preferences'] ?? $preferences;
        $plan = SavePropertyPreferences::plan($preferences);

        $this->state->update([
            'plan' => $plan,
            'phase' => 'mapping',
            'current_question' => null,
            'property_result_ids' => array_column($view['markers'], 'id'),
        ]);

        return (new PropertySearch)->compactToolResponse($view);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'location' => $schema->string()->nullable(),
            'location_type' => $schema->string()->enum(['town', 'county'])->nullable(),
            'county' => $schema->string()->nullable(),
            'max_price' => $schema->integer()->min(1)->nullable(),
            'min_bedrooms' => $schema->integer()->min(0)->nullable(),
            'property_type' => $schema->string()->enum(['house', 'apartment', 'bungalow', 'land'])->nullable(),
            'minimum_ber_rating' => $schema->string()->enum(PropertyPreferences::BER_RATINGS)->nullable(),
            'sort' => $schema->string()->enum(['price', 'price_per_sqm'])->nullable(),
            'replace' => $schema->boolean()
                ->description('True when this is a new or broader search, not a tightening of the current filters.')
                ->nullable(),
        ];
    }

    /**
     * A new place without restating type or beds is a new search, not a
     * refinement. Keeping the last apartment filter is how follow-ups go
     * empty after the first hit.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function startsFresh(array $changes): bool
    {
        return array_key_exists('location', $changes)
            && $changes['location'] !== null
            && $changes['location'] !== ''
            && ! array_key_exists('property_type', $changes)
            && ! array_key_exists('min_bedrooms', $changes);
    }

    /**
     * @return array<string, mixed>
     */
    protected function basePreferences(bool $replace): array
    {
        $current = $this->state->plan['preferences'] ?? [];

        if (! $replace) {
            return is_array($current) ? $current : [];
        }

        $defaults = PropertyPreferences::defaults();

        return [
            ...$defaults,
            'location' => $current['location'] ?? $defaults['location'],
            'location_type' => $current['location_type'] ?? $defaults['location_type'],
            'county' => $current['county'] ?? null,
        ];
    }
}
