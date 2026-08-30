<?php

namespace Modules\Chat\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Chat\Filament\Pages\ChatSettings;
use Modules\Chat\Insights\ChatInsights;

class ChatOverview extends StatsOverviewWidget
{
    /**
     * Filament's Dashboard renders every discovered widget, so without this
     * these five would also turn up on the panel's front page -- uninvited, and
     * repeating the same scan of the message table a second time.
     * `ChatInsights::getWidgets()` names them explicitly instead.
     */
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 3;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $insights = ChatInsights::make();
        $totals = $insights->totals();
        $activity = $insights->dailyActivity();

        return [
            Stat::make(__('Conversations'), number_format($totals['conversations']))
                ->description(trans_choice(':count person asking|:count people asking', $totals['people'], ['count' => $totals['people']]))
                ->descriptionIcon('heroicon-m-users')
                ->chart(array_map(floatval(...), $activity['questions']))
                ->color('primary'),

            $this->answeredStat($totals, $activity),

            $this->toolStat($totals, $activity),
            $this->tokenStat($totals),
            $this->reasoningStat($totals),
            $this->costStat($totals, $insights),
        ];
    }

    /**
     * Replies, and the questions that never got one.
     *
     * @param  array<string, int|float|null>  $totals
     * @param  array<string, list<int>|list<string>>  $activity
     */
    protected function answeredStat(array $totals, array $activity): Stat
    {
        $failed = (int) $totals['failed_starts'];

        return Stat::make(__('Questions answered'), number_format($totals['replies']))
            ->description($failed === 0
                ? __(':count asked', ['count' => number_format($totals['questions'])])
                : __(':count never got a reply', ['count' => $failed]))
            ->descriptionIcon($failed === 0 ? 'heroicon-m-chat-bubble-left-right' : 'heroicon-m-x-circle')
            ->chart(array_map(floatval(...), $activity['replies']))
            ->color($failed === 0 ? 'info' : 'danger');
    }

    /**
     * @param  array<string, int|float|null>  $totals
     * @param  array<string, list<int>|list<string>>  $activity
     */
    protected function toolStat(array $totals, array $activity): Stat
    {
        $calls = (int) $totals['tool_calls'];
        $failures = (int) $totals['tool_failures'];

        return Stat::make(__('Tool calls'), number_format($calls))
            ->description($failures === 0
                ? __('all resolved')
                : __(':count came back empty', ['count' => $failures]))
            ->descriptionIcon($failures === 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
            ->chart(array_map(floatval(...), $activity['tool_calls']))
            // Amber rather than red: a miss is a place to look at, not an outage.
            ->color($failures === 0 ? 'success' : 'warning');
    }

    /**
     * @param  array<string, int|float|null>  $totals
     */
    protected function tokenStat(array $totals): Stat
    {
        $tokens = (int) $totals['tokens'];
        $cached = (int) $totals['cached_tokens'];

        return Stat::make(__('Tokens'), $this->compact($tokens))
            // Cached input is billed at a fraction of the normal rate, so a high
            // share here is the prompt cache earning its keep.
            ->description(__(':percent% served from cache', [
                'percent' => $tokens > 0 ? round($cached / $tokens * 100) : 0,
            ]))
            ->descriptionIcon('heroicon-m-cpu-chip')
            ->color('gray');
    }

    /**
     * @param  array<string, int|float|null>  $totals
     */
    protected function reasoningStat(array $totals): Stat
    {
        $reasoning = (int) $totals['reasoning_tokens'];
        $replies = (int) $totals['replies'];

        return Stat::make(__('Reasoning tokens'), $this->compact($reasoning))
            ->description(__(':count per reply on average', [
                'count' => $replies > 0 ? number_format($reasoning / $replies) : 0,
            ]))
            ->descriptionIcon('heroicon-m-light-bulb')
            ->color('gray');
    }

    /**
     * @param  array<string, int|float|null>  $totals
     */
    protected function costStat(array $totals, ChatInsights $insights): Stat
    {
        $cost = $totals['cost'];

        $unpriced = $insights->unpricedModels();

        return Stat::make(
            __('Estimated spend'),
            $cost === null ? '—' : '$'.number_format((float) $cost, 2),
        )
            // Naming the model is the difference between "this is broken" and
            // "add a row for gpt-5.4-mini and this starts working".
            ->description(match (true) {
                $cost === null => __('No rate set for :model', ['model' => $unpriced[0] ?? __('this model')]),
                $unpriced !== [] => __('excludes :model', ['model' => implode(', ', $unpriced)]),
                default => __('last :days days', ['days' => $insights->days]),
            })
            ->descriptionIcon($cost === null || $unpriced !== [] ? 'heroicon-m-information-circle' : 'heroicon-m-banknotes')
            ->color($cost === null ? 'gray' : 'success')
            ->url($cost === null ? ChatSettings::getUrl() : null);
    }

    /**
     * 1.2M rather than 1,204,882: the magnitude is the point, not the digits.
     */
    protected function compact(int $value): string
    {
        return match (true) {
            $value >= 1_000_000 => round($value / 1_000_000, 1).'M',
            $value >= 1_000 => round($value / 1_000, 1).'k',
            default => (string) $value,
        };
    }
}
