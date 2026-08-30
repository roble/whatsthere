<?php

namespace Modules\Chat\Models;

use Illuminate\Support\Carbon;
use Laravel\Ai\Models\ConversationMessage;

/**
 * The SDK's stored message, with the columns spelled out.
 *
 * `Laravel\Ai\Models\ConversationMessage` guards nothing and annotates nothing,
 * so every attribute on it is invisible to static analysis and reads back as an
 * undefined property. Naming them here once means the insights code and the
 * transcript page are both typed, instead of each of them reaching through
 * `getAttribute()` and re-checking what came out.
 *
 * Behaviour is inherited untouched -- this only describes what is already there.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string $agent
 * @property string $role
 * @property string $content
 * @property int|null $participant_id
 * @property string|null $participant_type
 *                                         The JSON columns are cast with Laravel's `array` cast, which decodes to null
 *                                         rather than throwing when a row holds something malformed -- hence nullable,
 *                                         and hence the `is_array()` guards where they are read.
 * @property array<string, mixed>|null $usage
 * @property array<string, mixed>|null $meta
 * @property array<int, array<string, mixed>>|null $tool_calls
 * @property array<int, array<string, mixed>>|null $tool_results
 * @property array<int, array<string, mixed>>|null $attachments
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ChatMessage extends ConversationMessage
{
    //
}
