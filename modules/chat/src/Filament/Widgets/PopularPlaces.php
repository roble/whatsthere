<?php

namespace Modules\Chat\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Modules\Chat\Insights\ChatInsights;

/**
 * Where the map actually went.
 *
 * Names come from the geocoder rather than from what was typed, so the same
 * place asked for three different ways collapses to one row -- which is what
 * makes this a picture of demand rather than a list of phrasings.
 */
class PopularPlaces extends TableWidget
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
            ->heading(__('Places people look for'))
            ->emptyStateHeading(__('Nothing placed yet'))
            ->emptyStateIcon('heroicon-o-map-pin')
            ->records(fn (): array => ChatInsights::make()
                ->popularPlaces()
                ->take(10)
                ->mapWithKeys(fn (array $row, int $i): array => [$i => $row])
                ->all())
            ->columns([
                TextColumn::make('place')
                    ->label(__('Place'))
                    ->wrap()
                    ->searchable(),

                TextColumn::make('times')
                    ->label(__('Times'))
                    ->badge()
                    ->color('success')
                    ->alignEnd(),
            ]);
    }
}
