<?php

namespace App\Ai;

use Modules\Chat\Ai\ChatAgent;

/**
 * Chat always streams on OpenAI.
 *
 * A leftover AI_PROVIDER=manus in an old .env is ignored so removing the
 * Manus gateway does not break an existing checkout.
 */
final class AiProviderSwitch
{
    /**
     * The application-wide default provider from config/ai.php.
     */
    public static function appProvider(): string
    {
        $provider = (string) config('ai.default');

        return $provider === 'manus' || $provider === '' ? 'openai' : $provider;
    }

    /**
     * The provider used for chat streaming.
     */
    public static function chatProvider(): string
    {
        return 'openai';
    }

    /**
     * The model for chat on OpenAI.
     */
    public static function chatModel(?string $provider = null): string
    {
        $configured = config('chat.models.openai');

        return is_string($configured) && $configured !== ''
            ? $configured
            : ChatAgent::MODEL;
    }

    /**
     * Whether the provider can invoke Laravel AI tools natively.
     */
    public static function supportsToolCalling(string $provider): bool
    {
        return true;
    }

    /**
     * Chat is OpenAI, so tool steps do not need a second provider.
     */
    public static function chatToolsProvider(): ?string
    {
        return null;
    }

    /**
     * Provider and model arguments for ChatAgent::stream().
     *
     * @return array{provider: string, model: string}
     */
    public static function chatStreamOptions(): array
    {
        return [
            'provider' => self::chatProvider(),
            'model' => self::chatModel(),
        ];
    }
}
