<?php

namespace Modules\Chat\Filament\Resources\Conversations\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Chat\Filament\Resources\Conversations\ConversationResource;

class ListConversations extends ListRecords
{
    protected static string $resource = ConversationResource::class;
}
