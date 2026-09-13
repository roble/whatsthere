<?php

namespace Modules\Chat\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Modules\Chat\Insights\ChatInsights;

/**
 * What the assistant was asked to place and could not.
 *
 * The most directly actionable panel here. A repeated miss usually means a
 * place Nominatim spells differently or OpenStreetMap lacks the requested
 * category in that area.
 */
class UnresolvedRequests extends TableWidget
{
    /**
     * Filament's Dashboard renders every discovered widget, so without this
     * these five would also turn up on the panel's front page -- uninvited, and
     * repeating the same scan of the message table a second time.
     * `ChatInsights::getWidgets()` names them explicitly instead.
     */
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Could not be placed'))
            ->description(__('Lookups that came back empty, most repeated first.'))
            ->emptyStateHeading(__('Everything asked for was found'))
            ->emptyStateDescription(__('No lookup in this window came back empty.'))
            ->emptyStateIcon('heroicon-o-check-circle')
            ->records(fn (): array => ChatInsights::make()
                ->unresolvedRequests()
                // The key becomes the row id, and Filament needs it stable.
                ->mapWithKeys(fn (array $row, int $i): array => [$i => $row])
                ->all())
            ->columns([
                TextColumn::make('input')
                    ->label(__('Asked for'))
                    ->weight('medium')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('tool')
                    ->label(__('Tool'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'show_on_map' ? 'info' : 'warning')
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString()),

                TextColumn::make('attempts')
                    ->label(__('Attempts'))
                    ->badge()
                    // Once is noise; repeatedly is a queue of work.
                    ->color(fn (int $state): string => $state > 1 ? 'danger' : 'gray')
                    ->alignEnd(),

                TextColumn::make('last_seen')
                    ->label(__('Last seen'))
                    ->since()
                    ->tooltip(fn ($state) => $state?->toDayDateTimeString()),

                TextColumn::make('reason')
                    ->label(__('What the assistant was told'))
                    ->color('gray')
                    ->wrap()
                    ->limit(90)
                    ->toggleable(),
            ]);
    }
}
