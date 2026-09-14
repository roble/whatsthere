<?php

namespace App\Ai\Manus;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ManusClient
{
    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = 'https://api.manus.ai/v2',
        protected int $pollIntervalMs = 1500,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTask(array $payload): array
    {
        return $this->request('post', 'task.create', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function listMessages(string $taskId, string $order = 'desc', int $limit = 200): array
    {
        return $this->request('get', 'task.listMessages', [
            'task_id' => $taskId,
            'order' => $order,
            'limit' => $limit,
        ]);
    }

    /**
     * Wait for Manus to finish and return the latest assistant reply.
     */
    public function waitForAssistantReply(string $taskId, ?int $timeoutSeconds = null): string
    {
        $timeoutSeconds ??= 120;
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $messages = ($this->listMessages($taskId, 'desc', 200))['messages'] ?? [];

            if ($this->taskHasStopped($messages)) {
                $reply = $this->latestAssistantReply($messages);

                if ($reply !== null) {
                    return $reply;
                }

                throw new RuntimeException('Manus finished without an assistant reply.');
            }

            usleep($this->pollIntervalMs * 1000);
        }

        throw new RuntimeException('Manus did not finish within the configured timeout.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function taskHasStopped(array $messages): bool
    {
        foreach ($messages as $message) {
            if (($message['type'] ?? null) !== 'status_update') {
                continue;
            }

            if (($message['status_update']['agent_status'] ?? null) === 'stopped') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function latestAssistantReply(array $messages): ?string
    {
        // listMessages is requested newest-first. The first non-empty assistant
        // message is the final reply; walking to the last one returned the draft.
        foreach ($messages as $message) {
            if (($message['type'] ?? null) !== 'assistant_message') {
                continue;
            }

            $content = trim((string) ($message['assistant_message']['content'] ?? ''));

            if ($content !== '') {
                return $content;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $payload = []): array
    {
        try {
            $response = match ($method) {
                'get' => $this->http()->get($this->endpoint($path), $payload),
                'post' => $this->http()->post($this->endpoint($path), $payload),
                default => throw new RuntimeException("Unsupported Manus HTTP method [{$method}]."),
            };
        } catch (RequestException $exception) {
            $body = $exception->response?->json();
            $message = is_array($body) ? ($body['message'] ?? $exception->getMessage()) : $exception->getMessage();

            throw new RuntimeException('Manus API request failed: '.$message, 0, $exception);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Manus API returned an invalid response.');
        }

        if (($data['ok'] ?? true) === false) {
            $message = $data['message'] ?? $data['error'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);

            throw new RuntimeException('Manus API request failed: '.$message);
        }

        return $data;
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->throw()
            ->withHeaders([
                'x-manus-api-key' => $this->apiKey,
            ]);
    }

    protected function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
