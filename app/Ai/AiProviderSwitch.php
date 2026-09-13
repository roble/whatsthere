<?php

namespace App\Ai;

use Modules\Chat\Ai\ChatAgent;

/**
 * One place to resolve which AI provider and model each feature uses.
 *
 * Switch globally with AI_PROVIDER=openai|manus. Override chat alone with
 * CHAT_AI_PROVIDER when needed. Manus cannot call Laravel tools, so when chat
 * runs on Manus the tool steps are routed to CHAT_AI_TOOLS_PROVIDER (OpenAI by
 * default) while prose can still use Manus on steps without tools.
 */
final class AiProviderSwitch
{
    /**
     * The application-wide default provider from config/ai.php.
     */
    public static function appProvider(): string
    {
        return (string) config('ai.default');
    }

    /**
     * The provider used for chat streaming.
     */
    public static function chatProvider(): string
    {
        return (string) config('chat.ai_provider');
    }

    /**
     * The model or agent profile for chat on the given provider.
     */
    public static function chatModel(?string $provider = null): string
    {
        $provider ??= self::chatProvider();

        $configured = config("chat.models.{$provider}");

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return match ($provider) {
            'manus' => (string) config('ai.providers.manus.agent_profile', 'lite'),
            default => ChatAgent::MODEL,
        };
    }

    /**
     * Whether the provider can invoke Laravel AI tools natively.
     */
    public static function supportsToolCalling(string $provider): bool
    {
        return $provider !== 'manus';
    }

    /**
     * When chat runs on Manus, which provider should execute tool steps.
     */
    public static function chatToolsProvider(): ?string
    {
        if (self::supportsToolCalling(self::chatProvider())) {
            return null;
        }

        $fallback = (string) config('chat.ai_tools_provider', 'openai');

        if ($fallback === '' || $fallback === self::chatProvider()) {
            return null;
        }

        if (blank(config("ai.providers.{$fallback}.key"))) {
            return null;
        }

        return $fallback;
    }

    /**
     * Provider and model arguments for ChatAgent::stream().
     *
     * @return array{provider: string, model: string}
     */
    public static function chatStreamOptions(): array
    {
        $provider = self::chatProvider();

        return [
            'provider' => $provider,
            'model' => self::chatModel($provider),
        ];
    }
}
