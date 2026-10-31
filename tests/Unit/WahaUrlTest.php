<?php

namespace Tests\Unit;

use App\Services\Waha\WahaUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WahaUrlTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'plain https' => ['https://waha.example.com', 'https://waha.example.com'],
            'trailing slash stripped once' => ['https://waha.example.com/', 'https://waha.example.com'],
            'path prefix preserved' => ['https://example.com/waha/', 'https://example.com/waha'],
            'port kept' => ['http://192.168.1.10:3000/', 'http://192.168.1.10:3000'],
            'localhost allowed' => ['http://localhost:3333', 'http://localhost:3333'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'empty' => [''],
            'not a url' => ['waha.example.com'],
            'bad scheme ftp' => ['ftp://waha.example.com'],
            'userinfo rejected' => ['http://user:pass@waha.example.com'],
            'query rejected' => ['http://waha.example.com?x=1'],
            'fragment rejected' => ['http://waha.example.com#top'],
            'cloud metadata ipv4' => ['http://169.254.169.254/latest'],
            'cloud metadata ipv6 link local' => ['http://[fe80::1]/'],
            'aws metadata name' => ['http://instance-data.ec2.internal/'],
            'gce metadata name' => ['http://metadata.google.internal/'],
            'ipv6 zone id' => ['http://[fe80::1%eth0]/'],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function test_accepts_and_normalizes(string $input, string $expected): void
    {
        $this->assertSame($expected, WahaUrl::normalize($input));
    }

    #[DataProvider('rejectedProvider')]
    public function test_rejects_unsafe_urls(string $input): void
    {
        $this->assertNull(WahaUrl::normalize($input));
    }

    public function test_private_ranges_depend_on_configuration(): void
    {
        $private = 'http://10.0.0.5:3333/';

        $this->assertNotNull(WahaUrl::normalize($private, allowPrivateNetworks: true));
        $this->assertNull(WahaUrl::normalize($private, allowPrivateNetworks: false));
    }

    public function test_encodes_session_path_segment(): void
    {
        $this->assertSame('my%20session', WahaUrl::sessionSegment('my session'));
        $this->assertSame('default', WahaUrl::sessionSegment('default'));
        $this->assertSame('a%2Fb', WahaUrl::sessionSegment('a/b'), 'Slash must never become a second path segment.');
    }
}
