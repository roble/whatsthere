<?php

namespace Modules\Chat\Tests\Feature;

use Laravel\Ai\Tools\Request;
use Modules\Chat\Ai\Tools\EircodeToGeoLocation;
use Tests\TestCase;

class EircodeToGeoLocationTest extends TestCase
{
    public function test_it_places_an_eircode_inside_its_routing_key(): void
    {
        $view = $this->locate('T12 XY89');

        $this->assertSame('T12 XY89', $view['label']);

        // Cork city, give or take the spread a single address may sit within.
        [$latitude, $longitude] = array_map('floatval', $view['marker']);
        $this->assertEqualsWithDelta(51.8930, $latitude, 0.02);
        $this->assertEqualsWithDelta(-8.4860, $longitude, 0.02);
    }

    public function test_the_box_is_the_map_order_and_surrounds_the_marker(): void
    {
        $view = $this->locate('D02 X285');

        [$west, $south, $east, $north] = array_map('floatval', $view['bbox']);
        [$latitude, $longitude] = array_map('floatval', $view['marker']);

        // west, south, east, north -- the order the map wants. Getting it
        // wrong still renders a map, just of the wrong part of the world.
        $this->assertGreaterThan($west, $longitude);
        $this->assertLessThan($east, $longitude);
        $this->assertGreaterThan($south, $latitude);
        $this->assertLessThan($north, $latitude);
    }

    public function test_the_same_eircode_always_lands_on_the_same_point(): void
    {
        // A map that shifts a little every time you ask about the same address
        // looks broken, so the offset is derived rather than randomised.
        $this->assertSame(
            $this->locate('D02 X285')['marker'],
            $this->locate('D02 X285')['marker'],
        );
    }

    public function test_two_addresses_in_one_routing_key_do_not_share_a_point(): void
    {
        $this->assertNotSame(
            $this->locate('D02 X285')['marker'],
            $this->locate('D02 RT59')['marker'],
        );
    }

    public function test_it_accepts_lowercase_and_a_missing_space(): void
    {
        $spaced = $this->locate('T12 XY89');

        $this->assertSame($spaced, $this->locate('t12xy89'));
        $this->assertSame($spaced, $this->locate('  T12XY89 '));
    }

    public function test_the_one_routing_key_with_a_letter_in_it_is_accepted(): void
    {
        // D6W, Dublin 6W, is the only routing key that is not a letter and two
        // digits. A tighter pattern rejects a real Eircode.
        $this->assertSame('D6W F620', $this->locate('D6W F620')['label']);
    }

    public function test_a_malformed_eircode_leaves_the_map_alone(): void
    {
        $result = $this->handle('not an eircode');

        $this->assertStringContainsString('not a valid Eircode', $result);
        $this->assertNull(json_decode($result, true));
    }

    public function test_letters_outside_the_eircode_alphabet_are_rejected(): void
    {
        // B, G and O are excluded so a code cannot be misheard for another.
        foreach (['B12 XY89', 'T12 XYB9', 'T12 XG89', 'T12 XO89'] as $eircode) {
            $this->assertStringContainsString(
                'not a valid Eircode',
                $this->handle($eircode),
                "[{$eircode}] should not be a valid Eircode.",
            );
        }
    }

    public function test_an_unknown_routing_key_leaves_the_map_alone(): void
    {
        // Valid shape, real routing key, just not one in the table.
        $result = $this->handle('W23 KKTT');

        $this->assertStringContainsString('could not locate', $result);
        $this->assertNull(json_decode($result, true));
    }

    protected function handle(string $eircode): string
    {
        return (string) (new EircodeToGeoLocation)->handle(
            new Request(['eircode' => $eircode])
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function locate(string $eircode): array
    {
        $view = json_decode($this->handle($eircode), true);

        $this->assertIsArray($view, "[{$eircode}] should have been located.");

        return $view;
    }
}
