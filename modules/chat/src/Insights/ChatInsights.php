<?php

namespace Modules\Chat\Insights;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Ai\Models\Conversation;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Http\Controllers\ChatController;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Settings\ChatSettings;

/**
 * Everything the insights page knows, read once and answered many times.
 *
 * The SDK stores usage, tool calls and tool results as JSON *text* rather than
 * native JSON columns, so none of it can be aggregated in SQL without writing
 * Postgres-only expressions that the SQLite test suite could not run. The window
 * is therefore pulled into memory once per request and every figure is derived
 * from that one pass.
 */
class ChatInsights
{
    /**
     * The window, loaded lazily and shared by every figure below.
     *
     * @var Collection<int, ChatMessage>|null
     */
    protected ?Collection $messages = null;

    public function __construct(
        public readonly int $days,
        public readonly int $maxMessages,
    ) {}

    public static function make(): self
    {
        return new self(
            days: max(1, (int) config('chat.insights.days', 30)),
            maxMessages: max(1, (int) config('chat.insights.max_messages', 20000)),
        );
    }

    public function since(): Carbon
    {
        return now()->subDays($this->days)->startOfDay();
    }

    /**
     * The messages this agent produced inside the window.
     *
     * Scoped to ChatAgent so the conversation-title agent's own traffic does not
     * inflate the chat's numbers.
     *
     * @return Collection<int, ChatMessage>
     */
    public function messages(): Collection
    {
        return $this->messages ??= ChatMessage::query()
            ->where('agent', ChatAgent::class)
            ->where('created_at', '>=', $this->since())
            // `content` and `tool_calls` are the two big columns -- a whole
            // reply, and every reasoning summary behind it -- and nothing on
            // this page reads either. The transcript page loads them separately
            // for one conversation, which is the only place they are wanted.
            ->select(['id', 'conversation_id', 'participant_id', 'role', 'created_at', 'usage', 'meta', 'tool_results'])
            // Newest first *then* reversed: ordering ascending under a limit
            // keeps the oldest rows and silently drops the activity the page
            // exists to show, which is the wrong half to lose.
            ->latest('created_at')
            ->limit($this->maxMessages)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Headline counts for the window.
     *
     * @return array<string, int|float|null>
     */
    public function totals(): array
    {
        $messages = $this->messages();
        $replies = $messages->where('role', 'assistant');
        $tokens = $this->tokens();
        $calls = $this->toolCalls();

        return [
            'conversations' => $messages->pluck('conversation_id')->unique()->count(),
            'people' => $messages->pluck('participant_id')->filter()->unique()->count(),
            'questions' => $messages->where('role', 'user')->count(),
            'replies' => $replies->count(),
            'tool_calls' => $calls->count(),
            'tool_failures' => $calls->where('succeeded', false)->count(),
            'failed_starts' => $this->failedStarts()->count(),
            'tokens' => $tokens['total'],
            'reasoning_tokens' => $tokens['reasoning'],
            'cached_tokens' => $tokens['cached'],
            'cost' => $this->cost(),
        ];
    }

    /**
     * Token counts across the window, by kind.
     *
     * `reasoning` is a *subset* of the completion tokens rather than an extra
     * charge, so it is reported as a share and never added to the total; adding
     * it would bill the thinking twice.
     *
     * @return array<string, int>
     */
    public function tokens(): array
    {
        $usage = $this->messages()->pluck('usage')->filter(fn ($u) => is_array($u) && $u !== []);

        $sum = fn (string $key): int => (int) $usage->sum(fn (array $u): int => (int) ($u[$key] ?? 0));

        $prompt = $sum('prompt_tokens');
        $completion = $sum('completion_tokens');
        $cached = $sum('cache_read_input_tokens');

        return [
            'prompt' => $prompt,
            'completion' => $completion,
            'cached' => $cached,
            'reasoning' => $sum('reasoning_tokens'),
            'total' => $prompt + $completion + $cached,
        ];
    }

    /**
     * Estimated spend, or null when nothing in the window has a rate.
     *
     * Rates come from settings rather than config: providers reprice without
     * asking, and whoever is watching the bill should be able to correct them
     * from the panel. A model nobody has priced contributes nothing rather than
     * a confident wrong number.
     */
    public function cost(): ?float
    {
        $settings = app(ChatSettings::class);

        if (! $settings->hasPricing()) {
            return null;
        }

        $cost = 0.0;
        $priced = false;

        foreach ($this->messages() as $message) {
            $rates = $settings->rateFor($message->meta['model'] ?? null);
            $usage = is_array($message->usage) ? $message->usage : [];

            if ($rates === null || $usage === []) {
                continue;
            }

            $priced = true;
            $cost += ((int) ($usage['prompt_tokens'] ?? 0)) / 1e6 * $rates['input']
                + ((int) ($usage['cache_read_input_tokens'] ?? 0)) / 1e6 * $rates['cached']
                + ((int) ($usage['completion_tokens'] ?? 0)) / 1e6 * $rates['output'];
        }

        return $priced ? $cost : null;
    }

    /**
     * Which models ran in the window but have no rate set.
     *
     * Named so the insights page can point at the gap instead of silently
     * under-reporting the bill.
     *
     * @return array<int, string>
     */
    public function unpricedModels(): array
    {
        $settings = app(ChatSettings::class);

        $unpriced = [];

        foreach ($this->messages() as $message) {
            $model = $message->meta['model'] ?? null;

            if (is_string($model) && $model !== '' && $settings->rateFor($model) === null) {
                $unpriced[$model] = $model;
            }
        }

        return array_values($unpriced);
    }

    /**
     * Questions, replies and tool calls per day, oldest first.
     *
     * Every day in the window appears, including the quiet ones -- a chart that
     * skips empty days makes a gap look like steady use.
     *
     * @return array{labels: list<string>, questions: list<int>, replies: list<int>, tool_calls: list<int>}
     */
    public function dailyActivity(): array
    {
        $byDay = $this->messages()->groupBy(fn (ChatMessage $m): string => $m->created_at->toDateString());
        $callsByDay = $this->toolCalls()->groupBy(fn (array $c): string => $c['at']->toDateString());

        $labels = $questions = $replies = $calls = [];

        for ($day = $this->since()->copy(); $day->lte(now()); $day->addDay()) {
            $key = $day->toDateString();
            $messages = $byDay->get($key, collect());

            $labels[] = $day->format('j M');
            $questions[] = $messages->where('role', 'user')->count();
            $replies[] = $messages->where('role', 'assistant')->count();
            $calls[] = $callsByDay->get($key, collect())->count();
        }

        return ['labels' => $labels, 'questions' => $questions, 'replies' => $replies, 'tool_calls' => $calls];
    }

    /**
     * Every tool call in the window, flattened out of its message.
     *
     * @return Collection<int, array{tool: string, input: string, result: string, succeeded: bool, at: Carbon, conversation_id: string}>
     */
    public function toolCalls(): Collection
    {
        return $this->messages()->flatMap(function (ChatMessage $message): array {
            $results = is_array($message->tool_results) ? $message->tool_results : [];

            return array_map(fn (array $result): array => [
                'tool' => (string) ($result['name'] ?? 'unknown'),
                'input' => $this->describeInput($result['arguments'] ?? []),
                'result' => (string) ($result['result'] ?? ''),
                'succeeded' => $this->succeeded($result),
                'at' => $message->created_at,
                'conversation_id' => $message->conversation_id,
            ], $results);
        });
    }

    /**
     * How often each tool ran, and how often it came back with nothing.
     *
     * @return Collection<int, array{tool: string, calls: int, failures: int, failure_rate: float}>
     */
    public function toolBreakdown(): Collection
    {
        return $this->toolCalls()
            ->groupBy('tool')
            ->map(function (Collection $calls, string $tool): array {
                $failures = $calls->where('succeeded', false)->count();

                return [
                    'tool' => $tool,
                    'calls' => $calls->count(),
                    'failures' => $failures,
                    'failure_rate' => $calls->count() > 0 ? round($failures / $calls->count() * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('calls')
            ->values();
    }

    /**
     * What people asked for that the tools could not place.
     *
     * The most directly actionable thing on the page: a repeated miss is either
     * a place Nominatim spells differently, a routing key missing from the
     * Eircode table, or somewhere outside Ireland that people keep asking for.
     *
     * @return Collection<int, array{tool: string, input: string, attempts: int, last_seen: Carbon, reason: string}>
     */
    public function unresolvedRequests(): Collection
    {
        return $this->toolCalls()
            ->where('succeeded', false)
            ->groupBy(fn (array $call): string => $call['tool'].'|'.$call['input'])
            ->map(fn (Collection $calls): array => [
                'tool' => $calls->first()['tool'],
                'input' => $calls->first()['input'],
                'attempts' => $calls->count(),
                'last_seen' => $calls->sortByDesc('at')->first()['at'],
                'reason' => str($calls->first()['result'])->limit(140)->toString(),
            ])
            ->sortByDesc('attempts')
            ->values();
    }

    /**
     * The places the map was actually moved to, most requested first.
     *
     * @return Collection<int, array{place: string, times: int}>
     */
    public function popularPlaces(): Collection
    {
        return $this->toolCalls()
            ->where('succeeded', true)
            ->filter(fn (array $call): bool => in_array($call['tool'], ChatController::MAP_TOOLS, true))
            ->map(fn (array $call): string => (string) (json_decode($call['result'], true)['label'] ?? $call['input']))
            ->countBy()
            ->map(fn (int $times, string $place): array => ['place' => $place, 'times' => $times])
            ->sortByDesc('times')
            ->values();
    }

    /**
     * Conversations that were opened and never got a reply.
     *
     * `ChatController::stream()` creates the row before the first byte so the
     * browser can be told its id, and messages are only persisted once the
     * stream completes. A conversation still holding no messages therefore means
     * the reply never landed -- a provider error, a dropped connection, or
     * someone closing the tab mid-answer. It is the one failure signal that
     * survives in the database rather than only in the log.
     *
     * @return Collection<int, Conversation>
     */
    public function failedStarts(): Collection
    {
        return Conversation::query()
            ->doesntHave('messages')
            ->where('created_at', '>=', $this->since())
            ->latest('created_at')
            ->get();
    }

    /**
     * Conversations in the window with their size, newest first.
     *
     * @return Collection<int, Conversation>
     */
    public function recentConversations(int $limit = 10): Collection
    {
        return Conversation::query()
            ->withCount('messages')
            ->where('updated_at', '>=', $this->since())
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Did the call achieve what it was for?
     *
     * The map tools answer in prose when they come up empty, so the call still
     * "succeeds" as far as the model is concerned. A parsed view is the only
     * proof it landed anywhere -- the same rule the browser applies when it
     * decides whether to move the map.
     *
     * @param  array<string, mixed>  $result
     */
    protected function succeeded(array $result): bool
    {
        if (! in_array($result['name'] ?? '', ChatController::MAP_TOOLS, true)) {
            return true;
        }

        $view = json_decode((string) ($result['result'] ?? ''), true);

        return is_array($view) && isset($view['bbox']);
    }

    /**
     * Render a tool's arguments as the one string worth reading.
     *
     * @param  array<string, mixed>|string  $arguments
     */
    protected function describeInput(array|string $arguments): string
    {
        if (is_string($arguments)) {
            return $arguments;
        }

        return (string) ($arguments['place'] ?? $arguments['eircode'] ?? json_encode($arguments) ?: '');
    }
}
