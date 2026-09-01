<?php

namespace Modules\Chat\Tests\Feature;

use Modules\Chat\Http\Controllers\ChatController;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class StreamFailureTest extends TestCase
{
    /**
     * Run a stream through the controller's decorator and capture what the
     * browser would receive.
     */
    protected function streamThrough(callable $body): string
    {
        $controller = new class extends ChatController
        {
            public function decorate(StreamedResponse $response): StreamedResponse
            {
                /** @var StreamedResponse */
                return $this->keepFailuresInTheStream($response);
            }
        };

        $response = $controller->decorate(new StreamedResponse($body));

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_it_keeps_the_provider_wording_out_of_the_browser(): void
    {
        $secret = 'Rate limit reached for gpt-5.4-mini in organization org-9cCpgIsPSDoihTAM13Ripuy3';

        $sent = $this->streamThrough(function () use ($secret): void {
            echo 'data: '.json_encode(['type' => 'start', 'messageId' => 'abc'])."\n\n";
            echo 'data: '.json_encode(['type' => 'error', 'errorText' => $secret])."\n\n";
            echo "data: [DONE]\n\n";
        });

        $this->assertStringNotContainsString('org-9cCpgIsPSDoihTAM13Ripuy3', $sent);
        $this->assertStringNotContainsString('Rate limit reached', $sent);
        $this->assertStringContainsString('The assistant could not be reached', $sent);

        // The frame itself has to survive: the browser reads it to know the
        // reply failed, and dropping it would put the silence back.
        $this->assertStringContainsString('"type":"error"', $sent);
    }

    public function test_it_leaves_every_other_frame_alone(): void
    {
        $sent = $this->streamThrough(function (): void {
            echo 'data: '.json_encode(['type' => 'start', 'messageId' => 'abc'])."\n\n";
            echo 'data: '.json_encode(['type' => 'text-delta', 'delta' => 'Kinsale is lovely'])."\n\n";
            echo "data: [DONE]\n\n";
        });

        $this->assertStringContainsString('Kinsale is lovely', $sent);
        $this->assertStringContainsString('"messageId":"abc"', $sent);
        $this->assertStringContainsString("data: [DONE]\n\n", $sent);
    }

    public function test_a_frame_split_across_writes_is_still_cleaned(): void
    {
        // The rewriter holds a partial frame back rather than passing it
        // through unread, which is the one case that would leak.
        $sent = $this->streamThrough(function (): void {
            echo 'data: {"type":"error","errorText":"Rate limit reached for ';
            echo 'org-9cCpgIsPSDoihTAM13Ripuy3"}'."\n\n";
            echo "data: [DONE]\n\n";
        });

        $this->assertStringNotContainsString('org-9cCpgIsPSDoihTAM13Ripuy3', $sent);
        $this->assertStringContainsString('The assistant could not be reached', $sent);
    }

    public function test_an_escaping_exception_becomes_an_error_frame_and_a_terminator(): void
    {
        $sent = $this->streamThrough(function (): void {
            echo 'data: '.json_encode(['type' => 'start', 'messageId' => 'abc'])."\n\n";

            throw new RuntimeException('sk-secret-key rejected');
        });

        $this->assertStringNotContainsString('sk-secret-key', $sent);
        $this->assertStringContainsString('The assistant could not be reached', $sent);
        $this->assertStringContainsString("data: [DONE]\n\n", $sent);
    }
}
