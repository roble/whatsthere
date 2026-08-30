<?php

namespace Modules\Chat\Filament\Pages;

use App\Filament\Pages\SettingsPage;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Modules\Chat\Ai\AvailableModels;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Settings\ChatSettings as ChatSettingsData;

class ChatSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string $settings = ChatSettingsData::class;

    /**
     * Last in the Settings group, after General, Authentication and
     * Localization. The group itself comes from the SettingsPage base class,
     * which is what keeps every module's settings in one place rather than
     * scattered beside the feature they configure.
     */
    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        // Qualified, because "Pricing" alone says nothing next to "General".
        return __('Chat pricing');
    }

    public function getTitle(): string
    {
        return __('Chat pricing');
    }

    public function getSubheading(): string
    {
        return __('Rates used to estimate spend on the insights page. Nothing here changes what the assistant does.');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Model rates'))
                ->description(__('US dollars per million tokens, as your provider bills them. A model with no row reports its tokens and no cost.'))
                ->icon(Heroicon::OutlinedCurrencyDollar)
                ->schema([
                    Repeater::make('model_pricing')
                        ->hiddenLabel()
                        ->addActionLabel(__('Add a model'))
                        ->itemLabel(fn (array $state): ?string => $state['model'] ?? null)
                        ->collapsible()
                        ->defaultItems(0)
                        ->columns(4)
                        ->schema([
                            TextInput::make('model')
                                ->label(__('Model'))
                                ->placeholder(ChatAgent::MODEL)
                                // Suggestions, not a fixed list: a model the
                                // provider shipped this morning must still be
                                // priceable without a deploy.
                                ->datalist(AvailableModels::names())
                                ->helperText(__('Exactly as the provider reports it. A name that does not match is silently left out of the estimate.'))
                                ->required()
                                ->columnSpan(2),

                            TextInput::make('input')
                                ->label(__('Input'))
                                ->helperText(__('per 1M tokens'))
                                ->numeric()
                                ->minValue(0)
                                ->step(0.001)
                                ->prefix('$')
                                ->default(0)
                                ->required(),

                            TextInput::make('output')
                                ->label(__('Output'))
                                ->helperText(__('per 1M tokens'))
                                ->numeric()
                                ->minValue(0)
                                ->step(0.001)
                                ->prefix('$')
                                ->default(0)
                                ->required(),

                            TextInput::make('cached')
                                ->label(__('Cached input'))
                                // Usually a fraction of the input rate, and this
                                // app reads far more cached tokens than fresh
                                // ones, so getting it wrong skews the whole total.
                                ->helperText(__('per 1M tokens, usually far cheaper than input'))
                                ->numeric()
                                ->minValue(0)
                                ->step(0.001)
                                ->prefix('$')
                                ->default(0)
                                ->required()
                                ->columnSpan(2),
                        ]),
                ]),
        ]);
    }
}
