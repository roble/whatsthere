<?php

namespace Modules\Chat\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Turns an Eircode into a point on the map.
 *
 * Answers in the same shape as ShowOnMap so the browser treats both the same
 * way: one JSON view moves the map, anything else leaves it where it was.
 *
 * ponytail: the coordinates are fabricated. Real resolution needs the Eircode
 * Address Database, which is licensed and paid (eircode.ie / autoaddress.ie).
 * Swapping this for the real thing means replacing pointFor() and nothing else.
 */
class EircodeToGeoLocation implements Tool
{
    /**
     * The tool's name as the model, the browser, and the transcript all see it.
     */
    public const string NAME = 'eircode_to_geolocation';

    /**
     * Eircodes are drawn from an alphabet with no vowels and no B, G, I, J, L,
     * M, O, Q, S, U or Z, which is what keeps them unambiguous when read aloud.
     * D6W is the one routing key with a letter in third position.
     */
    private const PATTERN = '/^(D6W|[ACDEFHKNPRTVWXY]\d{2})\s?([0-9ACDEFHKNPRTVWXY]{4})$/i';

    /**
     * Routing key to the centre of the district it covers, as [latitude, longitude].
     *
     * @var array<string, array{float, float}>
     */
    private const ROUTING_KEYS = [
        'A94' => [53.3006, -6.1780], // Blackrock, Dublin
        'A96' => [53.2770, -6.1350], // Dún Laoghaire
        'D01' => [53.3540, -6.2600], // Dublin 1
        'D02' => [53.3370, -6.2490], // Dublin 2
        'D04' => [53.3270, -6.2280], // Ballsbridge, Dublin 4
        'D08' => [53.3370, -6.2900], // Dublin 8
        'D6W' => [53.3110, -6.2960], // Dublin 6W
        'E91' => [52.5100, -7.9500], // Tipperary town
        'F91' => [54.2760, -8.4760], // Sligo
        'H91' => [53.2720, -9.0490], // Galway
        'N91' => [53.5250, -7.3390], // Mullingar
        'P31' => [51.7060, -8.5230], // Kinsale
        'R95' => [52.6540, -7.2520], // Kilkenny
        'T12' => [51.8930, -8.4860], // Cork city
        'T23' => [51.8500, -8.2940], // Cobh
        'V94' => [52.6640, -8.6270], // Limerick
        'X91' => [52.2590, -7.1100], // Waterford
        'Y35' => [52.8360, -6.9110], // Carlow
    ];

    /**
     * How far a single address may sit from its routing key's centre, in degrees.
     *
     * Roughly a kilometre, which is about right for a postal district and small
     * enough that two addresses in the same district do not land on each other.
     */
    private const SPREAD = 0.012;

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): Stringable|string
    {
        return 'Find where an Eircode is. Call this whenever the visitor gives an Irish postcode such as "D02 X285" or "T12 XY89", instead of guessing at the address it belongs to.';
    }

    public function handle(Request $request): Stringable|string
    {
        $eircode = strtoupper(trim((string) $request['eircode']));

        if (! preg_match(self::PATTERN, $eircode, $matches)) {
            return "[{$eircode}] is not a valid Eircode, so the map was left where it was. Eircodes are a three-character routing key then four more characters, like D02 X285. Ask the visitor to check it.";
        }

        [, $routingKey, $identifier] = $matches;

        $centre = self::ROUTING_KEYS[strtoupper($routingKey)] ?? null;

        if ($centre === null) {
            return "The routing key [{$routingKey}] is not one this assistant can place, so the map was left where it was. Tell the visitor you could not locate that Eircode, and offer to find the town or street instead.";
        }

        [$latitude, $longitude] = $this->pointFor($centre, strtoupper($identifier));

        // A tight box: an Eircode is a single delivery point, not an area.
        return json_encode([
            'label' => strtoupper($routingKey).' '.strtoupper($identifier),
            'bbox' => [
                (string) round($longitude - 0.004, 6),
                (string) round($latitude - 0.0025, 6),
                (string) round($longitude + 0.004, 6),
                (string) round($latitude + 0.0025, 6),
            ],
            'marker' => [(string) round($latitude, 6), (string) round($longitude, 6)],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Place one address within its routing key.
     *
     * Derived from the identifier rather than randomised, so the same Eircode
     * always lands on the same point -- a map that moves a little every time
     * you ask about the same address looks broken.
     *
     * @param  array{float, float}  $centre
     * @return array{float, float}
     */
    protected function pointFor(array $centre, string $identifier): array
    {
        $hash = crc32($identifier);

        // Two independent halves of the hash, mapped from 0..1 onto -1..1.
        $north = ((($hash >> 16) & 0xFFFF) / 0xFFFF) * 2 - 1;
        $east = (($hash & 0xFFFF) / 0xFFFF) * 2 - 1;

        return [
            $centre[0] + $north * self::SPREAD,
            $centre[1] + $east * self::SPREAD,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'eircode' => $schema->string()
                ->description('The Eircode to locate, with or without the space, e.g. "D02 X285".')
                ->required(),
        ];
    }
}
