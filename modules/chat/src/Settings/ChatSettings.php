<?php

namespace Modules\Chat\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * What the chat costs to run.
 *
 * Rates live here rather than in config because they are a business fact that
 * changes without a deploy: providers reprice, and the SDK can resolve
 * `UseCheapestModel` somewhere new between releases. Somebody watching the bill
 * should be able to correct them without opening an editor.
 */
class ChatSettings extends Settings
{
    /**
     * US dollars per million tokens, keyed by model.
     *
     * Shaped as a list of rows rather than a map so the admin form can repeat
     * them. A model with no row reports tokens and no cost, which is the point:
     * an unpriced model should say nothing rather than imply it is free.
     *
     * Each row is `['model' => string, 'input' => float, 'cached' => float,
     * 'output' => float]`.
     *
     * Deliberately carries no `@var`: Spatie resolves property docblocks at
     * runtime through phpDocumentor's TypeResolver to pick a caster, and it
     * rejects both an `array{...}` shape (it cannot parse the syntax) and an
     * `array<int, array<string, mixed>>` (it cannot resolve `mixed` to a
     * caster). Either one throws while the container builds this object, so the
     * native `array` type is the only one that survives, and the shape is
     * described above instead.
     */
    public array $model_pricing;

    public static function group(): string
    {
        return 'chat';
    }

    /**
     * The configured rate for a model, or null when it has none.
     *
     * @return array{input: float, cached: float, output: float}|null
     */
    public function rateFor(?string $model): ?array
    {
        if (blank($model)) {
            return null;
        }

        foreach ($this->model_pricing as $row) {
            if (($row['model'] ?? null) === $model) {
                return [
                    'input' => (float) ($row['input'] ?? 0),
                    'cached' => (float) ($row['cached'] ?? 0),
                    'output' => (float) ($row['output'] ?? 0),
                ];
            }
        }

        return null;
    }

    public function hasPricing(): bool
    {
        return $this->model_pricing !== [];
    }
}
