<?php

namespace Modules\Properties;

use Illuminate\Support\Facades\Validator;

class PropertyPreferences
{
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
        ];
    }

    /** @param array<string, mixed> $input
     * @return array{location: string, location_type: string, county: ?string, max_price: int, min_bedrooms: ?int, property_type: ?string}
     */
    public static function validate(array $input): array
    {
        $data = Validator::make($input, self::rules())->validate();

        return [
            'location' => trim($data['location']),
            'location_type' => $data['location_type'],
            'county' => filled($data['county']) ? trim($data['county']) : null,
            'max_price' => (int) $data['max_price'],
            'min_bedrooms' => $data['min_bedrooms'] === null ? null : (int) $data['min_bedrooms'],
            'property_type' => $data['property_type'],
        ];
    }
}
