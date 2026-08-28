<?php

namespace Modules\Chat\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Modules\Chat\Ai\ConversationTitleAgent;
use Throwable;

class GenerateConversationTitle implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * User-message counts at which the title is (re)generated.
     *
     * A conversation drifts: what it was about after three turns is often not
     * what it is about after twenty. Re-titling at widening intervals keeps the
     * sidebar honest without paying for a model call on every message.
     *
     * @var list<int>
     */
    public const array RETITLE_AT = [3, 10, 25, 60];

    public const int USER_MESSAGE_THRESHOLD = 3;

    /** How many recent messages to summarise. */
    protected const int TRANSCRIPT_SIZE = 12;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    public function __construct(
        public string $conversationId,
        public int $atMessageCount = self::USER_MESSAGE_THRESHOLD,
    ) {}

    public function handle(ConversationTitleAgent $agent): void
    {
        $conversation = Conversation::query()->find($this->conversationId);

        if ($conversation === null || $this->userMessageCount($conversation) < self::USER_MESSAGE_THRESHOLD) {
            return;
        }

        // Read oldest-first and take the tail, rather than ordering DESC and
        // reversing: timestamps have second precision, so same-second messages
        // have no tiebreaker and a DESC query returns them in insertion order,
        // which reversing then scrambles.
        $transcript = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->oldest()
            ->get(['role', 'content'])
            ->slice(-self::TRANSCRIPT_SIZE)
            ->map(function (ConversationMessage $message): string {
                $role = $message->getAttribute('role');
                $content = $message->getAttribute('content');

                return Str::headline(is_string($role) ? $role : '')
                    .': '.(is_string($content) ? $content : '');
            })
            ->implode("\n\n");

        $response = $agent->prompt($transcript);
        $title = Str::of($response->text)
            ->squish()
            ->trim('"\'')
            ->limit(100)
            ->toString();

        if ($title !== '') {
            // Renaming is not activity. The sidebar orders by updated_at, which
            // laravel/ai touches on every stored message, so bumping it here
            // would shove a silent conversation to the top of the list.
            $conversation->timestamps = false;
            $conversation->update(['title' => $title]);
        }
    }

    public function uniqueId(): string
    {
        // Includes the milestone: keyed on the conversation alone, the run at
        // three messages would swallow the run at ten for a whole $uniqueFor.
        return $this->conversationId.':'.$this->atMessageCount;
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Conversation title generation failed.', [
            'conversation_id' => $this->conversationId,
            'exception' => $exception,
        ]);
    }

    protected function userMessageCount(Conversation $conversation): int
    {
        return $conversation->messages()->where('role', 'user')->count();
    }
}
