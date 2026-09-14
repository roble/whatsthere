<?php

namespace Tests\Unit\Ai;

use App\Ai\Manus\ManusClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManusClientTest extends TestCase
{
    public function test_it_returns_the_newest_assistant_reply_when_messages_are_newest_first(): void
    {
        Http::fake([
            'https://api.manus.ai/v2/task.listMessages*' => Http::response([
                'ok' => true,
                'messages' => [
                    [
                        'type' => 'status_update',
                        'status_update' => ['agent_status' => 'stopped'],
                    ],
                    [
                        'type' => 'assistant_message',
                        'assistant_message' => ['content' => 'newest reply'],
                    ],
                    [
                        'type' => 'assistant_message',
                        'assistant_message' => ['content' => 'oldest draft'],
                    ],
                ],
            ]),
        ]);

        $reply = (new ManusClient('test-key', pollIntervalMs: 1))
            ->waitForAssistantReply('task-1', 2);

        $this->assertSame('newest reply', $reply);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'task.listMessages')
                && $request['limit'] === 200
                && $request['order'] === 'desc';
        });
    }
}
