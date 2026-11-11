<?php

namespace App\Services\Media;

/**
 * Raised when code asks for the s3 disk while storage is not usable
 * (disabled, incomplete credentials, or unreadable ciphertext). Callers
 * must catch Throwable at every call site and treat it as not-configured
 * (spec 3.2 contract).
 */
final class MediaStorageException extends \RuntimeException {}
