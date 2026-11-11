<?php

namespace App\Services\Media;

use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

/**
 * Whether the S3 disk can actually be used for media. WAHA downloads media
 * itself, so uploads are S3-only (presigned URLs); this gate keeps every
 * caller reading the same definition of "configured".
 */
final class S3Availability
{
    public static function adapterInstalled(): bool
    {
        return class_exists(AwsS3V3Adapter::class);
    }

    public static function credentialsConfigured(): bool
    {
        return filled(config('filesystems.disks.s3.key'))
            && filled(config('filesystems.disks.s3.secret'))
            && filled(config('filesystems.disks.s3.bucket'));
    }

    public static function configured(): bool
    {
        return self::adapterInstalled() && self::credentialsConfigured();
    }
}
