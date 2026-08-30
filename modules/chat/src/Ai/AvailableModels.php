<?php

namespace Modules\Chat\Ai;

use Laravel\Ai\AiManager;
use Modules\Chat\Models\ChatMessage;
use Throwable;

/**
 * The models worth offering, and why each one is on the list.
 *
 * Deliberately not an enum. An enum here would be a hand-copied catalogue of
 * somebody else's product line: it goes stale the day a provider ships a model,
 * every addition costs a deploy, and it would have to grow a case per provider
 * to stay useful. Everything below is instead derived from things that are
 * already true --
 *
 *  - `ChatAgent::MODEL`, the one this application actually runs on;
 *  - the configured provider's own cheapest / default / smartest tiers, which
 *    are what `UseCheapestModel` and friends resolve through;
 *  - whatever appears in stored traffic, so a model that has since been
 *    switched away from can still be given a rate.
 *
 * -- none of which needs maintaining when the market moves.
 */
class AvailableModels
{
    /**
     * Model name to a label explaining where it came from.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [ChatAgent::MODEL => ChatAgent::MODEL.' — '.__('in use')];

        foreach (static::tiers() as $model => $tier) {
            $options[$model] ??= $model.' — '.$tier;
        }

        foreach (static::seen() as $model) {
            $options[$model] ??= $model.' — '.__('seen in past replies');
        }

        return $options;
    }

    /**
     * Just the names, for a datalist.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(static::options());
    }

    /**
     * What the configured provider calls its three text tiers.
     *
     * Read from the provider rather than hardcoded, because each tier is
     * overridable per provider in `config/ai.php` and the SDK's own defaults
     * move between releases.
     *
     * @return array<string, string>
     */
    protected static function tiers(): array
    {
        try {
            $provider = app(AiManager::class)->textProvider();
        } catch (Throwable) {
            // No key configured, or a provider this build cannot construct.
            // Suggestions are a convenience; never take the form down for them.
            return [];
        }

        return array_filter([
            $provider->cheapestTextModel() => __('cheapest'),
            $provider->defaultTextModel() => __('default'),
            $provider->smartestTextModel() => __('smartest'),
        ]);
    }

    /**
     * Models that actually appear in stored replies.
     *
     * @return array<int, string>
     */
    protected static function seen(): array
    {
        return ChatMessage::query()
            ->where('agent', ChatAgent::class)
            ->whereNotNull('meta')
            ->latest('created_at')
            // Bounded: this only feeds a suggestion list, and the set of models
            // in play is tiny however long the history is.
            ->limit(500)
            ->pluck('meta')
            ->map(fn (mixed $meta): mixed => is_array($meta) ? ($meta['model'] ?? null) : null)
            ->filter(fn (mixed $model): bool => is_string($model) && $model !== '')
            ->unique()
            ->values()
            ->all();
    }
}
