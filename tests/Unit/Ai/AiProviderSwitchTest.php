<?php

namespace Tests\Unit\Ai;

use App\Ai\AiProviderSwitch;
use Modules\Chat\Ai\ChatAgent;
use Tests\TestCase;

class AiProviderSwitchTest extends TestCase
{
    public function test_chat_always_uses_openai(): void
    {
        config([
            'ai.default' => 'openai',
            'chat.ai_provider' => 'openai',
            'chat.models.openai' => ChatAgent::MODEL,
        ]);

        $this->assertSame('openai', AiProviderSwitch::chatProvider());
        $this->assertSame(ChatAgent::MODEL, AiProviderSwitch::chatModel());
        $this->assertNull(AiProviderSwitch::chatToolsProvider());
        $this->assertSame(
            ['provider' => 'openai', 'model' => ChatAgent::MODEL],
            AiProviderSwitch::chatStreamOptions(),
        );
    }

    public function test_a_leftover_manus_env_does_not_select_a_removed_provider(): void
    {
        config([
            'ai.default' => 'manus',
            'chat.ai_provider' => 'manus',
            'chat.models.openai' => ChatAgent::MODEL,
        ]);

        $this->assertSame('openai', AiProviderSwitch::appProvider());
        $this->assertSame('openai', AiProviderSwitch::chatProvider());
        $this->assertSame(ChatAgent::MODEL, AiProviderSwitch::chatModel());
        $this->assertNull(AiProviderSwitch::chatToolsProvider());
    }

    public function test_chat_can_use_a_configured_openai_model(): void
    {
        config([
            'chat.models.openai' => 'gpt-4.1-mini',
        ]);

        $this->assertSame('gpt-4.1-mini', AiProviderSwitch::chatModel());
    }
}
