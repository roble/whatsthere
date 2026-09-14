<?php

namespace Modules\Chat\Console\Commands;

use Illuminate\Console\Command;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Properties\Models\Property;

/**
 * Ask Overpass about every property up front, so nobody waits on it live.
 *
 * "What's there?" is one query to a donated service that sheds load by
 * answering 504 in a few seconds, and no amount of failing over changes the
 * fact that the visitor is sitting in front of a spinner while we find out.
 * The answers are cached for a month and schools do not move, so the honest
 * fix is to have asked already.
 *
 * Run it before anything that matters -- a demo, a release -- and the button
 * becomes a cache read. Safe to re-run: anything already known is skipped, so
 * a run that was interrupted picks up where it stopped.
 */
class WarmNearbyPlaces extends Command
{
    protected $signature = 'chat:warm-nearby
                            {--limit= : Stop after this many properties.}
                            {--pause=1 : Seconds to wait between requests.}
                            {--force : Ask again for properties already cached.}';

    protected $description = 'Cache what is near each property, so "What\'s there?" never waits on Overpass.';

    /**
     * The categories the property dialog asks for.
     *
     * Must match NEARBY_CATEGORIES in the chat page: the cache is keyed on the
     * list, so warming a different set warms nothing the button will ever read.
     *
     * @var list<string>
     */
    public const array CATEGORIES = [
        'school',
        'supermarket',
        'cafe',
        'restaurant',
        'park',
        'pharmacy',
        'train_station',
    ];

    public function handle(): int
    {
        $properties = Property::query()
            ->where('status', 'for_sale')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->when($this->option('limit'), fn ($query, $limit) => $query->limit((int) $limit))
            ->get(['id', 'address', 'latitude', 'longitude']);

        if ($properties->isEmpty()) {
            $this->components->warn('No geocoded properties to warm.');

            return self::SUCCESS;
        }

        $places = new FindPlaces;
        $pause = max(0.0, (float) $this->option('pause'));
        $force = (bool) $this->option('force');

        $warmed = 0;
        $skipped = 0;
        $failed = 0;

        $this->components->info("Warming {$properties->count()} properties.");
        $bar = $this->output->createProgressBar($properties->count());

        foreach ($properties as $property) {
            $latitude = (float) $property->latitude;
            $longitude = (float) $property->longitude;

            if (! $force && $places->hasNearby($latitude, $longitude, self::CATEGORIES)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $markers = $places->aroundMany($latitude, $longitude, self::CATEGORIES);

            if ($markers === null) {
                $failed++;
            } else {
                $warmed++;
            }

            $bar->advance();

            // Every miss is a real request to somebody else's server. Pacing
            // them is both the polite thing and the thing that stops us being
            // throttled halfway through the run.
            if ($pause > 0) {
                usleep((int) ($pause * 1_000_000));
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->components->info("Warmed {$warmed}, already known {$skipped}, failed {$failed}.");

        if ($failed > 0) {
            $this->components->warn('Re-run to retry the failures; nothing that failed was cached.');
        }

        return self::SUCCESS;
    }
}
