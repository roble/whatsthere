<?php

namespace Modules\Properties;

use Illuminate\Support\Facades\Validator;

class PropertyPreferences
{
    /** @var list<string> */
    public const array BER_RATINGS = [
        'A1', 'A2', 'A3', 'B1', 'B2', 'B3', 'C1', 'C2', 'C3', 'D1', 'D2', 'E1', 'E2', 'F', 'G',
    ];

    /**
     * The widest possible search: the home area, and no other constraint.
     *
     * A new conversation opens on results rather than on a questionnaire, so
     * there must be a complete, valid preference set before the visitor has
     * said anything. Everything the visitor does narrow it with is merged on
     * top of this.
     *
     * @return array{location: string, location_type: string, county: ?string, max_price: int, min_bedrooms: ?int, property_type: ?string, minimum_ber_rating: ?string}
     */
    public static function defaults(): array
    {
        return self::validate([
            'location' => (string) config('properties.home.location'),
            'location_type' => (string) config('properties.home.location_type'),
            'county' => null,
            'max_price' => (int) config('properties.max_price'),
            'min_bedrooms' => null,
            'property_type' => null,
            'minimum_ber_rating' => null,
        ]);
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'location' => ['required', 'string', 'max:100'],
            'location_type' => ['required', 'in:town,county'],
            'county' => ['present', 'nullable', 'string', 'max:100'],
            'max_price' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'min_bedrooms' => ['present', 'nullable', 'integer', 'min:0', 'max:100'],
            'property_type' => ['present', 'nullable', 'in:house,apartment,bungalow'],
            'minimum_ber_rating' => ['present', 'nullable', 'in:'.implode(',', self::BER_RATINGS)],
        ];
    }

    /** @return array<string, list<string>> */
    public static function partialRules(): array
    {
        return collect(self::rules())
            ->map(fn (array $rules): array => ['sometimes', ...$rules])
            ->all();
    }

    /** @param array<string, mixed> $input
     * @return array{location: string, location_type: string, county: ?string, max_price: int, min_bedrooms: ?int, property_type: ?string, minimum_ber_rating: ?string}
     */
    public static function validate(array $input): array
    {
        $data = Validator::make(['minimum_ber_rating' => null, ...$input], self::rules())->validate();

        return [
            'location' => trim($data['location']),
            'location_type' => $data['location_type'],
            'county' => filled($data['county']) ? trim($data['county']) : null,
            'max_price' => (int) $data['max_price'],
            'min_bedrooms' => $data['min_bedrooms'] === null ? null : (int) $data['min_bedrooms'],
            'property_type' => $data['property_type'],
            'minimum_ber_rating' => $data['minimum_ber_rating'],
        ];
    }

    /**
     * Apply only the filters the visitor changed, then restore the complete,
     * canonical preference shape used by searches and persisted conversations.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     * @return array{location: string, location_type: string, county: ?string, max_price: int, min_bedrooms: ?int, property_type: ?string, minimum_ber_rating: ?string}
     */
    public static function merge(array $current, array $changes): array
    {
        $validatedChanges = Validator::make($changes, self::partialRules())->validate();

        return self::validate([...self::validate($current), ...$validatedChanges]);
    }

    /** @return list<string> */
    public static function ratingsAtOrAbove(string $minimum): array
    {
        $index = array_search($minimum, self::BER_RATINGS, true);

        return $index === false ? [] : array_slice(self::BER_RATINGS, 0, $index + 1);
    }
}
