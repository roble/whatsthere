<?php

namespace Tests\Unit\Ai;

use App\Ai\AiProviderSwitch;
use Modules\Chat\Ai\ChatAgent;
use Tests\TestCase;

class AiProviderSwitchTest extends TestCase
{
    public function test_chat_follows_global_provider_when_not_overridden(): void
    {
        config([
            'ai.default' => 'manus',
            'chat.ai_provider' => 'manus',
            'chat.models.manus' => 'lite',
        ]);

        $this->assertSame('manus', AiProviderSwitch::chatProvider());
        $this->assertSame('lite', AiProviderSwitch::chatModel());
    }

    public function test_chat_can_be_overridden_independently(): void
    {
        config([
            'ai.default' => 'manus',
            'chat.ai_provider' => 'openai',
            'chat.models.openai' => ChatAgent::MODEL,
        ]);

        $this->assertSame('openai', AiProviderSwitch::chatProvider());
        $this->assertSame(ChatAgent::MODEL, AiProviderSwitch::chatModel());
    }

    public function test_manus_chat_routes_tools_to_openai_when_key_is_set(): void
    {
        config([
            'chat.ai_provider' => 'manus',
            'chat.ai_tools_provider' => 'openai',
            'ai.providers.openai.key' => 'sk-test',
        ]);

        $this->assertSame('openai', AiProviderSwitch::chatToolsProvider());
    }

    public function test_openai_chat_does_not_need_tools_fallback(): void
    {
        config([
            'chat.ai_provider' => 'openai',
            'chat.ai_tools_provider' => 'openai',
            'ai.providers.openai.key' => 'sk-test',
        ]);

        $this->assertNull(AiProviderSwitch::chatToolsProvider());
    }
}
