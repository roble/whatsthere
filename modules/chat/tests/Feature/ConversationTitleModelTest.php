<?php

namespace Modules\Chat\Tests\Feature;

use Laravel\Ai\AiManager;
use Tests\TestCase;

/**
 * The title agent pins no model of its own: it asks for whichever one the
 * configured provider calls cheapest. Not every driver can answer that. The
 * `openai-compatible` one ships no model list and throws instead, which is
 * what broke re-titling while it was standing in for the Responses API -- and
 * the only visible symptom was a sidebar where every conversation kept its
 * opening message as a title forever, a long way from the exception behind it.
 *
 * Asserting against the configured provider rather than a fixed list is the
 * point: this has to keep holding whenever the application is pointed at a
 * different one.
 */
class ConversationTitleModelTest extends TestCase
{
    public function test_the_configured_provider_can_name_a_title_model(): void
    {
        $provider = config('ai.default');

        config()->set("ai.providers.{$provider}.key", 'test-key');

        $model = app(AiManager::class)->textProvider($provider)->cheapestTextModel();

        $this->assertNotEmpty($model);
    }
}
