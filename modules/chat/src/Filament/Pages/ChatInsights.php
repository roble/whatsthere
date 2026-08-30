<?php

namespace Modules\Chat\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Chat\Filament\Widgets\ChatActivity;
use Modules\Chat\Filament\Widgets\ChatOverview;
use Modules\Chat\Filament\Widgets\PopularPlaces;
use Modules\Chat\Filament\Widgets\ToolHealth;
use Modules\Chat\Filament\Widgets\UnresolvedRequests;
use Modules\Chat\Insights\ChatInsights as Insights;
use UnitEnum;

class ChatInsights extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Chat';

    protected static ?int $navigationSort = 1;

    protected string $view = 'chat::filament.pages.chat-insights';

    public static function getNavigationLabel(): string
    {
        return __('Insights');
    }

    public function getTitle(): string
    {
        return __('Chat insights');
    }

    public function getSubheading(): string
    {
        return __('The last :days days of conversation, tool use, and spend.', [
            'days' => Insights::make()->days,
        ]);
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            ChatOverview::class,
            ChatActivity::class,
            UnresolvedRequests::class,
            ToolHealth::class,
            PopularPlaces::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
