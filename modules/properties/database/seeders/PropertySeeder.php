<?php

namespace Modules\Properties\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Properties\Models\Property;

class PropertySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $homes = [
            ['12 Hazel Grove', 'Mallow', 'Cork', 52.132, -8.649, 'house', 3, 285000, 'for_sale'],
            ['4 Willow Court', 'Mallow', 'Cork', 52.137, -8.635, 'apartment', 2, 195000, 'for_sale'],
            ['8 Meadow Rise', 'Mallow', 'Cork', 52.124, -8.657, 'bungalow', 4, 350000, 'for_sale'],
            ['17 Rowan Gardens', 'Cork', 'Cork', 51.905, -8.49, 'house', 3, 350000, 'for_sale'],
            ['6 Harbour View', 'Cork', 'Cork', 51.898, -8.461, 'apartment', 2, 275000, 'for_sale'],
            ['9 Orchard Walk', 'Midleton', 'Cork', 51.919, -8.174, 'house', 4, 425000, 'for_sale'],
            ['3 Cedar Lane', 'Fermoy', 'Cork', 52.139, -8.276, 'house', 3, 265000, 'for_sale'],
            ['21 Ash Crescent', 'Galway', 'Galway', 53.282, -9.045, 'house', 3, 390000, 'for_sale'],
            ['5 Riverside Court', 'Limerick', 'Limerick', 52.659, -8.628, 'apartment', 2, 220000, 'for_sale'],
            ['14 Oak Avenue', 'Mallow', 'Cork', 52.131, -8.643, 'house', 3, 260000, 'sold'],
            ['2 Elm Terrace', 'Cork', 'Cork', 51.901, -8.477, 'house', 2, 310000, 'sold'],
            ['11 Birch Close', 'Midleton', 'Cork', 51.915, -8.166, 'bungalow', 3, 295000, 'withdrawn'],
            ['24 Marina Park', 'Cork', 'Cork', 51.894, -8.474, 'house', 4, 465000, 'for_sale'],
            ['18 Lee Quay', 'Cork', 'Cork', 51.899, -8.47, 'apartment', 1, 225000, 'for_sale'],
            ['7 Shandon Terrace', 'Cork', 'Cork', 51.902, -8.473, 'house', 2, 315000, 'for_sale'],
            ['31 Blackrock Avenue', 'Cork', 'Cork', 51.9, -8.421, 'bungalow', 3, 410000, 'for_sale'],
            ['10 Wellington Road', 'Cork', 'Cork', 51.907, -8.481, 'apartment', 3, 385000, 'for_sale'],
            ['42 Douglas Road', 'Cork', 'Cork', 51.889, -8.469, 'house', 5, 520000, 'for_sale'],
            ['15 Sunday’s Well', 'Cork', 'Cork', 51.904, -8.487, 'bungalow', 2, 330000, 'for_sale'],
            ['28 Victoria Cross', 'Cork', 'Cork', 51.893, -8.5, 'apartment', 2, 295000, 'for_sale'],
        ];

        DB::transaction(function () use ($homes): void {
            foreach ($homes as $index => [$address, $town, $county, $lat, $lon, $type, $beds, $euros, $status]) {
                $property = Property::updateOrCreate(['reference' => 'demo-home-'.($index + 1)], [
                    'address' => $address, 'town' => $town, 'county' => $county, 'country' => 'IE',
                    'latitude' => $lat, 'longitude' => $lon, 'property_type' => $type,
                    'bedrooms' => $beds, 'status' => $status,
                    'description' => "A {$beds}-bedroom {$type} in {$town} with a separate living area and practical storage.",
                ]);
                $property->priceRecords()->updateOrCreate(['source_reference' => 'demo-initial-asking'], [
                    'record_type' => 'asking_price', 'amount' => $euros * 100,
                    'currency' => 'EUR', 'effective_date' => '2026-08-01',
                ]);
                if ($status === 'sold') {
                    $property->priceRecords()->updateOrCreate(['source_reference' => 'demo-completed-sale'], [
                        'record_type' => 'sale', 'amount' => ($euros + 10000) * 100,
                        'currency' => 'EUR', 'effective_date' => '2026-09-01',
                    ]);
                } else {
                    $property->priceRecords()
                        ->where('source_reference', 'demo-completed-sale')
                        ->delete();
                }
            }
        });
    }
}
