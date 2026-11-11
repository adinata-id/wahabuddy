<?php

namespace Tests;

use App\Services\Media\MediaStorage;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Storage::fake alone does NOT intercept MediaStorage's Storage::build
     * path — swap the service with a factory-backed instance instead
     * (spec 3.2 test seam).
     */
    protected function swapMediaStorageWithFake(string $disk = 's3'): void
    {
        Storage::fake($disk);
        $this->app->instance(
            MediaStorage::class,
            new MediaStorage(fn () => Storage::disk($disk)),
        );
    }
}
