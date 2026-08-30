<?php

namespace Modules\Chat\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Modules\Chat\Insights\ChatInsights;

/**
 * How each tool is doing.
 *
 * Reads next to "Could not be placed": this says which tool is struggling, that
 * one says what it struggled with.
 */
class ToolHealth extends TableWidget
{
    /**
     * Filament's Dashboard renders every discovered widget, so without this
     * these five would also turn up on the panel's front page -- uninvited, and
     * repeating the same scan of the message table a second time.
     * `ChatInsights::getWidgets()` names them explicitly instead.
     */
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Tools'))
            ->emptyStateHeading(__('No tool has run yet'))
            ->emptyStateIcon('heroicon-o-wrench-screwdriver')
            ->records(fn (): array => ChatInsights::make()
                ->toolBreakdown()
                ->mapWithKeys(fn (array $row, int $i): array => [$i => $row])
                ->all())
            ->columns([
                TextColumn::make('tool')
                    ->label(__('Tool'))
                    ->weight('medium')
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString())
                    ->description(fn (array $record): string => $record['tool']),

                TextColumn::make('calls')
                    ->label(__('Calls'))
                    ->alignEnd(),

                TextColumn::make('failure_rate')
                    ->label(__('Empty'))
                    ->badge()
                    ->formatStateUsing(fn (float $state): string => round($state).'%')
                    // A geocoder that misses one in five is worth a look; one in
                    // fifty is just the long tail of how people type place names.
                    ->color(fn (float $state): string => match (true) {
                        $state >= 20.0 => 'danger',
                        $state > 0.0 => 'warning',
                        default => 'success',
                    })
                    ->alignEnd(),
            ]);
    }
}
