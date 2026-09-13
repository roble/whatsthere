<?php

namespace App\Ai\Manus;

use App\Ai\AiProviderSwitch;
use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use RuntimeException;

class ManusGateway implements StepTextGateway
{
    protected ?OpenAiGateway $openAiGateway = null;

    public function __construct(protected Dispatcher $events) {}

    /**
     * Generate text for a single step in a conversation.
     *
     * @param  Message[]  $messages
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        if ($delegated = $this->delegateToolStep($tools, $model, $instructions, $messages, $schema, $options, $timeout, $stepContext)) {
            return $delegated;
        }

        $client = $this->client($provider);
        $taskId = $this->createTask($client, $provider, $model, $instructions, $messages);
        $text = $client->waitForAssistantReply($taskId, $timeout);

        return new StepResponse(
            text: $text,
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(0, 0),
            meta: new Meta($provider->name(), $this->resolveAgentProfile($provider, $model)),
        );
    }

    /**
     * Stream text for a single step in a conversation.
     *
     * @param  Message[]  $messages
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        if ($tools !== [] && ($toolsProvider = AiProviderSwitch::chatToolsProvider())) {
            return yield from $this->openAiGateway()->generateStreamStep(
                $invocationId,
                $this->textProvider($toolsProvider),
                AiProviderSwitch::chatModel($toolsProvider),
                $instructions,
                $messages,
                $tools,
                $schema,
                $options,
                $timeout,
                $stepContext,
            );
        }

        $client = $this->client($provider);
        $agentProfile = $this->resolveAgentProfile($provider, $model);
        $taskId = $this->createTask($client, $provider, $model, $instructions, $messages);

        yield (new StreamStart(
            $this->eventId(),
            $provider->name(),
            $agentProfile,
            time(),
        ))->withInvocationId($invocationId);

        $text = $client->waitForAssistantReply($taskId, $timeout);
        $messageId = $this->eventId();

        yield (new TextStart(
            $this->eventId(),
            $messageId,
            time(),
        ))->withInvocationId($invocationId);

        foreach ($this->chunkText($text) as $chunk) {
            yield (new TextDelta(
                $this->eventId(),
                $messageId,
                $chunk,
                time(),
            ))->withInvocationId($invocationId);
        }

        yield (new TextEnd(
            $this->eventId(),
            $messageId,
            time(),
        ))->withInvocationId($invocationId);

        return new StepResponse(
            text: $text,
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(0, 0),
            meta: new Meta($provider->name(), $agentProfile),
        );
    }

    /**
     * @param  Message[]  $messages
     */
    protected function delegateToolStep(
        array $tools,
        string $model,
        ?string $instructions,
        array $messages,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): ?StepResponse {
        if ($tools === [] || ($toolsProvider = AiProviderSwitch::chatToolsProvider()) === null) {
            return null;
        }

        return $this->openAiGateway()->generateTextStep(
            $this->textProvider($toolsProvider),
            AiProviderSwitch::chatModel($toolsProvider),
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );
    }

    protected function openAiGateway(): OpenAiGateway
    {
        return $this->openAiGateway ??= new OpenAiGateway($this->events);
    }

    protected function textProvider(string $name): TextProvider
    {
        return app(AiManager::class)->textProvider($name);
    }

    protected function client(TextProvider $provider): ManusClient
    {
        $config = $provider->additionalConfiguration();
        $credentials = $provider->providerCredentials();

        return new ManusClient(
            apiKey: (string) ($credentials['key'] ?? ''),
            baseUrl: (string) ($config['url'] ?? 'https://api.manus.ai/v2'),
            pollIntervalMs: (int) ($config['poll_interval_ms'] ?? 1500),
        );
    }

    /**
     * @param  Message[]  $messages
     */
    protected function createTask(
        ManusClient $client,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
    ): string {
        $response = $client->createTask([
            'message' => [
                'content' => [
                    ['type' => 'text', 'text' => $this->buildPrompt($instructions, $messages)],
                ],
            ],
            'agent_profile' => $this->resolveAgentProfile($provider, $model),
            'hide_in_task_list' => (bool) ($provider->additionalConfiguration()['hide_in_task_list'] ?? true),
            'interactive_mode' => false,
        ]);

        $taskId = $response['task_id'] ?? null;

        if (! is_string($taskId) || $taskId === '') {
            throw new RuntimeException('Manus did not return a task id.');
        }

        return $taskId;
    }

    /**
     * @param  Message[]  $messages
     */
    protected function buildPrompt(?string $instructions, array $messages): string
    {
        $parts = [];

        if (filled($instructions)) {
            $parts[] = "System instructions:\n{$instructions}";
        }

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            $parts[] = match ($message->role) {
                MessageRole::User => 'User: '.($message->content ?? ''),
                MessageRole::Assistant => 'Assistant: '.($this->formatAssistantMessage($message)),
                MessageRole::ToolResult => 'Tool results: '.$this->formatToolResults($message),
            };
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    protected function formatAssistantMessage(Message $message): string
    {
        if (! $message instanceof AssistantMessage) {
            return $message->content ?? '';
        }

        $content = $message->content ?? '';

        if ($message->toolCalls->isEmpty()) {
            return $content;
        }

        $toolSummary = $message->toolCalls
            ->map(fn ($toolCall): string => $toolCall->name.'('.json_encode($toolCall->arguments, JSON_THROW_ON_ERROR).')')
            ->implode(', ');

        return trim($content."\n[Called tools: {$toolSummary}]");
    }

    protected function formatToolResults(Message $message): string
    {
        if (! $message instanceof ToolResultMessage) {
            return $message->content ?? '';
        }

        return $message->toolResults
            ->map(fn ($result): string => json_encode($result->result, JSON_THROW_ON_ERROR))
            ->implode("\n");
    }

    protected function resolveAgentProfile(TextProvider $provider, string $model): string
    {
        $allowed = ['lite', 'standard', 'max'];

        if (in_array($model, $allowed, true)) {
            return $model;
        }

        if (str_starts_with($model, 'manus-')) {
            $profile = substr($model, strlen('manus-'));

            if (in_array($profile, $allowed, true)) {
                return $profile;
            }
        }

        return (string) ($provider->additionalConfiguration()['agent_profile']
            ?? $provider->defaultTextModel());
    }

    /**
     * @return array<int, string>
     */
    protected function chunkText(string $text): array
    {
        if ($text === '') {
            return [''];
        }

        return str_split($text, 24);
    }

    protected function eventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
