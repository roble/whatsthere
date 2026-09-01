<?php

namespace Modules\Chat\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chat\Testing\CannedReplies;
use Tests\TestCase;

class CannedRepliesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Collect one scenario's frames.
     *
     * @return list<array<string, mixed>>
     */
    protected function framesFor(string $scenario): array
    {
        return iterator_to_array((new CannedReplies('mock-1'))->frames($scenario), false);
    }

    /**
     * Every frame type the installed package can actually emit.
     *
     * Read out of the event classes rather than restated here: the whole risk
     * with canned frames is that the package renames a type and they go on
     * describing a protocol nobody speaks any more.
     *
     * @return list<string>
     */
    protected function protocolTypes(): array
    {
        $types = [];

        foreach (glob(base_path('vendor/laravel/ai/src/Streaming/Events/*.php')) ?: [] as $file) {
            preg_match_all("/'type' => '([a-z-]+)'/", (string) file_get_contents($file), $matches);
            $types = array_merge($types, $matches[1]);
        }

        return array_values(array_unique($types));
    }

    public function test_every_scenario_speaks_the_protocol_the_package_emits(): void
    {
        $known = $this->protocolTypes();

        $this->assertContains('tool-input-available', $known, 'the vendor scan found nothing, so this test proves nothing');

        foreach (array_keys(CannedReplies::SCENARIOS) as $scenario) {
            foreach ($this->framesFor($scenario) as $frame) {
                $this->assertContains(
                    $frame['type'],
                    $known,
                    "Scenario [{$scenario}] emits [{$frame['type']}], which Laravel\\Ai no longer sends.",
                );
            }
        }
    }

    public function test_every_scenario_opens_with_a_start_frame(): void
    {
        foreach (array_keys(CannedReplies::SCENARIOS) as $scenario) {
            $this->assertSame('start', $this->framesFor($scenario)[0]['type'], $scenario);
        }
    }

    public function test_only_the_failure_scenario_ends_without_finishing(): void
    {
        foreach (array_keys(CannedReplies::SCENARIOS) as $scenario) {
            $frames = $this->framesFor($scenario);
            $last = end($frames)['type'];

            $scenario === 'failure'
                ? $this->assertSame('error', $last)
                : $this->assertSame('finish', $last, $scenario);
        }
    }

    public function test_the_map_scenarios_hand_back_views_the_map_can_read(): void
    {
        foreach (['place' => 'marker', 'eircode' => 'marker', 'places' => 'markers'] as $scenario => $pins) {
            $output = collect($this->framesFor($scenario))
                ->firstWhere('type', 'tool-output-available')['output'];

            $view = json_decode((string) $output, true);

            $this->assertIsArray($view, $scenario);
            $this->assertCount(4, $view['bbox'], $scenario);
            $this->assertArrayHasKey($pins, $view, $scenario);
        }
    }

    public function test_an_empty_search_answers_in_prose_so_the_step_reads_as_a_miss(): void
    {
        // toMapView() returning null is the only signal the route of thought
        // has for "finished, but found nothing".
        foreach (['places_empty', 'not_found'] as $scenario) {
            $output = collect($this->framesFor($scenario))
                ->firstWhere('type', 'tool-output-available')['output'];

            $this->assertNull(json_decode((string) $output, true), $scenario);
        }
    }

    public function test_a_named_scenario_is_honoured_and_anything_else_is_random(): void
    {
        $this->assertSame('places', CannedReplies::pick('places'));
        $this->assertArrayHasKey(CannedReplies::pick('nonsense'), CannedReplies::SCENARIOS);
        $this->assertArrayHasKey(CannedReplies::pick(null), CannedReplies::SCENARIOS);
    }

    public function test_it_streams_canned_frames_instead_of_calling_the_model(): void
    {
        config(['chat.test_mode' => true]);

        $response = $this->actingAs($this->createUser())
            ->post(route('chat.stream'), ['message' => 'Pubs in Galway?']);

        $response->assertOk();
        $response->assertHeader('x-vercel-ai-ui-message-stream', 'v1');

        $scenario = $response->headers->get('X-Chat-Test-Scenario');
        $this->assertArrayHasKey($scenario, CannedReplies::SCENARIOS);

        $body = $response->streamedContent();
        $this->assertStringContainsString('data: {"type":"start"', $body);
        $this->assertStringContainsString("data: [DONE]\n\n", $body);
    }

    public function test_the_failure_scenario_never_leaks_the_provider_wording(): void
    {
        config(['chat.test_mode' => true]);

        $response = $this->actingAs($this->createUser())
            ->post(route('chat.stream').'?scenario=failure', ['message' => 'Pubs in Galway?']);

        $body = $response->streamedContent();

        $this->assertStringNotContainsString('org-EXAMPLE0000', $body);
        $this->assertStringNotContainsString('Rate limit reached', $body);
        $this->assertStringContainsString('The assistant could not be reached', $body);
    }

    public function test_test_mode_is_refused_in_production(): void
    {
        config(['chat.test_mode' => true]);
        $this->app['env'] = 'production';

        $this->assertTrue($this->app->isProduction());

        // Reaching the real agent is the proof it did not take the canned path;
        // with no provider configured for tests that surfaces as a failure
        // frame rather than a scenario header.
        $response = $this->actingAs($this->createUser())
            ->post(route('chat.stream'), ['message' => 'Pubs in Galway?']);

        $this->assertNull($response->headers->get('X-Chat-Test-Scenario'));
    }
}
