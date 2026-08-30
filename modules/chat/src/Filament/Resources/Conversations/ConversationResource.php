<?php

namespace Modules\Chat\Filament\Resources\Conversations;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Models\Conversation;
use Modules\Chat\Filament\Resources\Conversations\Pages\ListConversations;
use Modules\Chat\Filament\Resources\Conversations\Pages\ViewConversation;
use UnitEnum;

/**
 * Read-only browsing of what people actually asked.
 *
 * Deliberately has no create, edit or delete: these rows are somebody's
 * conversation, and the panel exists to understand the feature, not to edit
 * anyone's history.
 */
class ConversationResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Chat';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('conversation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('conversations');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title'];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('messages'))
            ->recordUrl(fn (Conversation $record): string => ViewConversation::getUrl(['record' => $record]))
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading(__('No conversations yet'))
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->weight('medium')
                    ->wrap()
                    ->searchable()
                    // The opening message until a queued job names it properly,
                    // so an untouched title says the rename never ran.
                    ->description(fn (Conversation $record): string => $record->getKey()),

                TextColumn::make('participant.name')
                    ->label(__('Person'))
                    ->default('—')
                    ->searchable(),

                TextColumn::make('messages_count')
                    ->label(__('Messages'))
                    ->badge()
                    // Empty means the reply never landed: the row is created
                    // before the first byte, messages only once the stream ends.
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : 'gray')
                    ->tooltip(fn (int $state): ?string => $state === 0 ? __('The reply never landed') : null)
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('created_at')
                    ->label(__('Started'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label(__('Last message'))
                    ->since()
                    ->tooltip(fn ($state) => $state?->toDayDateTimeString())
                    ->sortable(),
            ])
            ->filters([
                Filter::make('substantial')
                    ->label(__('More than one exchange'))
                    // A conversation of two messages is someone trying it once;
                    // the interesting ones are where people kept going.
                    //
                    // has(), not having('messages_count'): that column is a
                    // withCount() select alias, and Postgres cannot resolve a
                    // select alias in HAVING. SQLite accepts it, so the test
                    // suite would never have caught this.
                    ->query(fn (Builder $query): Builder => $query->has('messages', '>', 2)),

                Filter::make('unanswered')
                    ->label(__('Never got a reply'))
                    ->query(fn (Builder $query): Builder => $query->doesntHave('messages')),

                Filter::make('today')
                    ->label(__('Active today'))
                    ->query(fn (Builder $query): Builder => $query->whereDate('updated_at', today())),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view' => ViewConversation::route('/{record}'),
        ];
    }
}
