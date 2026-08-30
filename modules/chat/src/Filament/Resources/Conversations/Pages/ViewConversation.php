<?php

namespace Modules\Chat\Filament\Resources\Conversations\Pages;

use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Chat\Filament\Resources\Conversations\ConversationResource;
use Modules\Chat\Http\Controllers\ChatController;
use Modules\Chat\Models\ChatMessage;

/**
 * The whole exchange, including the parts the chat page cannot show.
 *
 * `ChatController::show()` flattens a replayed transcript to plain text, so a
 * visitor reopening a conversation sees answers with no route of thought behind
 * them. Nothing is lost in the database though, which makes this the only place
 * the reasoning and the tool calls can be read back after the fact.
 */
class ViewConversation extends ViewRecord
{
    protected static string $resource = ConversationResource::class;

    protected string $view = 'chat::filament.pages.view-conversation';

    public function getTitle(): string
    {
        return (string) $this->record->getAttribute('title');
    }

    public function getSubheading(): string
    {
        return __(':messages messages · started :started', [
            'messages' => $this->messages()->count(),
            'started' => $this->record->getAttribute('created_at')?->diffForHumans() ?? '—',
        ]);
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function messages(): Collection
    {
        // Ordered by id, not created_at: timestamps have second precision and
        // the key is a UUID, so same-second rows have no tiebreaker.
        return ChatMessage::query()
            ->where('conversation_id', $this->record->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * What one message cost, for the gutter beside it.
     *
     * @return array<string, int>
     */
    public function usageFor(ChatMessage $message): array
    {
        $usage = is_array($message->usage) ? $message->usage : [];

        return [
            'prompt' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion' => (int) ($usage['completion_tokens'] ?? 0),
            'reasoning' => (int) ($usage['reasoning_tokens'] ?? 0),
            'cached' => (int) ($usage['cache_read_input_tokens'] ?? 0),
        ];
    }

    /**
     * A message body as HTML.
     *
     * `html_input: escape` is the load-bearing option: this is model output, so
     * any HTML in it is untrusted and must be shown rather than run. Without it
     * the markdown renderer would pass a `<script>` straight through into the
     * panel of whoever is reading the transcript.
     */
    public function bodyFor(ChatMessage $message): string
    {
        return Str::markdown((string) $message->content, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Pair each tool call with its result so both read as one step.
     *
     * @return array<int, array{name: string, input: string, result: string, succeeded: bool, reasoning: string|null}>
     */
    public function stepsFor(ChatMessage $message): array
    {
        $calls = is_array($message->tool_calls) ? $message->tool_calls : [];
        $results = collect(is_array($message->tool_results) ? $message->tool_results : [])->keyBy('id');

        return array_map(function (array $call) use ($results): array {
            $result = (string) ($results[$call['id'] ?? '']['result'] ?? '');
            $view = json_decode($result, true);

            return [
                'name' => (string) ($call['name'] ?? 'unknown'),
                'input' => (string) ($call['arguments']['place'] ?? $call['arguments']['eircode'] ?? json_encode($call['arguments'] ?? [])),
                'result' => $result,
                // Same rule the browser uses: the map tools answer in prose when
                // they find nothing, so a parsed view is the only proof of a hit.
                'succeeded' => ! in_array($call['name'] ?? '', ChatController::MAP_TOOLS, true)
                    || (is_array($view) && isset($view['bbox'])),
                'reasoning' => $this->reasoningFrom($call),
            ];
        }, $calls);
    }

    /**
     * The model's own summary of why it made this call, when it gave one.
     *
     * @param  array<string, mixed>  $call
     */
    protected function reasoningFrom(array $call): ?string
    {
        $summary = $call['reasoning_summary'] ?? null;

        if (blank($summary)) {
            return null;
        }

        return collect((array) $summary)
            ->map(fn (mixed $part): string => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part)
            ->filter()
            ->implode("\n\n") ?: null;
    }
}
