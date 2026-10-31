<?php

namespace App\Services\Waha;

/**
 * Normalizes and guards WAHA base URLs.
 *
 * Rules (spec section E): scheme limited to HTTP/HTTPS, no userinfo, query or
 * fragment, cloud-metadata and link-local targets always rejected, private
 * networks only allowed through explicit configuration. Self-hosted WAHA often
 * lives on a LAN or in a container network, so private ranges are allowed by
 * default via config and never blanket-blocked.
 */
final class WahaUrl
{
    /** Hostnames that resolve to cloud instance-metadata services. */
    private const METADATA_HOSTS = [
        'metadata.google.internal',
        'metadata.goog',
        'instance-data.ec2.internal',
        'metadata.azure.internal',
    ];

    /**
     * Normalize a candidate base URL to "origin + optional path prefix,
     * no trailing slash", or null when the URL must be rejected.
     */
    public static function normalize(?string $input, bool $allowPrivateNetworks = true): ?string
    {
        if ($input === null) {
            return null;
        }

        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        $parts = parse_url($trimmed);
        if ($parts === false) {
            return null;
        }

        if (($parts['scheme'] ?? null) === null || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        foreach (['user', 'pass', 'query', 'fragment'] as $forbidden) {
            if (array_key_exists($forbidden, $parts)) {
                return null;
            }
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return null;
        }

        if (self::isMetadataHost($host) || self::isLinkLocalHost($host)) {
            return null;
        }

        if (! $allowPrivateNetworks && self::isPrivateHost($host)) {
            return null;
        }

        $normalized = strtolower($parts['scheme']).'://'.$host;

        if (isset($parts['port']) && $parts['port'] !== 80 && $parts['port'] !== 443) {
            $normalized .= ':'.$parts['port'];
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return $normalized.$path;
    }

    /**
     * Encode a session name as exactly one URL path segment.
     */
    public static function sessionSegment(string $session): string
    {
        return rawurlencode($session);
    }

    public static function isMetadataHost(string $host): bool
    {
        $lower = strtolower($host);

        if (in_array($lower, self::METADATA_HOSTS, true)) {
            return true;
        }

        if ($lower === '169.254.169.254' || $lower === '[fd00:ec2::254]') {
            return true;
        }

        return false;
    }

    public static function isLinkLocalHost(string $host): bool
    {
        $bare = trim($host, '[]');

        // IPv6 zone identifiers (fe80::1%eth0) are always suspect.
        if (str_contains($bare, '%')) {
            return true;
        }

        $ip = filter_var($bare, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '169.254.');
        }

        $hex = bin2hex(inet_pton($ip) ?: '');

        // IPv6 link-local fe80::/10.
        return str_starts_with($hex, 'fe8')
            || str_starts_with($hex, 'fe9')
            || str_starts_with($hex, 'fea')
            || str_starts_with($hex, 'feb');
    }

    /**
     * RFC1918 / ULA / CGNAT ranges (loopback excluded — localhost setups are
     * legitimate for same-host WAHA installations).
     */
    public static function isPrivateHost(string $host): bool
    {
        $bare = trim($host, '[]');

        if ($bare === 'localhost') {
            return false;
        }

        $ip = filter_var($bare, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long === false) {
                return false;
            }

            return self::ipv4InRange($long, '10.0.0.0', '10.255.255.255')
                || self::ipv4InRange($long, '172.16.0.0', '172.31.255.255')
                || self::ipv4InRange($long, '192.168.0.0', '192.168.255.255')
                || self::ipv4InRange($long, '100.64.0.0', '100.127.255.255');
        }

        $hex = bin2hex(inet_pton($ip) ?: '');

        // IPv6 unique-local fc00::/7.
        return str_starts_with($hex, 'fc') || str_starts_with($hex, 'fd');
    }

    private static function ipv4InRange(int $ip, string $start, string $end): bool
    {
        return $ip >= ip2long($start) && $ip <= ip2long($end);
    }
}
