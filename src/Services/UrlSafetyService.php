<?php
// src/Services/UrlSafetyService.php
// Anti-SSRF guard for every outbound request whose URL is supplied by a
// merchant (order notify_url, account webhook_url, manual test pushes...).
//
// The guard does three things:
//   1. Whitelists the scheme (http/https only, no file/gopher/dict/ftp...).
//   2. Resolves the host to real IP addresses and rejects loopback, private,
//      link-local, cloud metadata, multicast and reserved ranges.
//   3. Hands back a curl handle that is pinned to the exact IP that was
//      checked (CURLOPT_RESOLVE), with redirects disabled and timeouts set,
//      so a DNS rebinding or a 302 to an internal host cannot slip through.
//
// Validate at write time (so bad values never reach the database) AND right
// before the request is fired (so a tampered row or a re-resolved name is
// caught as well).

class UrlSafetyService {

    // Set to ['https'] to force TLS-only callbacks. 'http' is kept for now so
    // existing merchants running plain HTTP endpoints are not cut off.
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private const MAX_URL_LENGTH = 2048;

    // Hostname suffixes that never belong to the public internet.
    private const BLOCKED_HOST_SUFFIXES = [
        '.local', '.localhost', '.localdomain', '.internal', '.intranet',
        '.lan', '.home', '.home.arpa', '.corp', '.private', '.test',
        '.example', '.invalid', '.onion',
    ];

    private const BLOCKED_HOSTS = [
        'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback',
        'metadata', 'metadata.google.internal', 'instance-data',
    ];

    // IPv4 ranges that must never be reachable from a merchant supplied URL.
    private const BLOCKED_IPV4 = [
        '0.0.0.0/8',          // "this" network
        '10.0.0.0/8',         // RFC1918 private
        '100.64.0.0/10',      // CGNAT, also covers Alibaba Cloud metadata 100.100.100.100
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, also covers cloud metadata 169.254.169.254
        '172.16.0.0/12',      // RFC1918 private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.88.99.0/24',     // deprecated 6to4 relay anycast
        '192.168.0.0/16',     // RFC1918 private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved + 255.255.255.255 broadcast
    ];

    // IPv6 ranges. IPv4-mapped / NAT64 / 6to4 forms are unwrapped first, so
    // ::ffff:127.0.0.1 is rejected as loopback rather than as "some IPv6".
    private const BLOCKED_IPV6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '100::/64',           // discard-only
        '2001:db8::/32',      // documentation
        'fc00::/7',           // unique local addresses
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    /**
     * Full inspection of a merchant supplied URL.
     *
     * @return array{ok:bool,error:string,scheme:string,host:string,port:int,ips:array}
     */
    public static function inspect($url) {
        $result = ['ok' => false, 'error' => '', 'scheme' => '', 'host' => '', 'port' => 0, 'ips' => []];

        $url = trim((string)$url);
        if ($url === '') {
            $result['error'] = 'URL is empty';
            return $result;
        }
        if (strlen($url) > self::MAX_URL_LENGTH) {
            $result['error'] = 'URL is too long';
            return $result;
        }
        // Control characters allow request splitting / header injection.
        if (preg_match('/[\x00-\x1F\x7F\s]/', $url)) {
            $result['error'] = 'URL contains control characters';
            return $result;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $result['error'] = 'URL is malformed';
            return $result;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            $result['error'] = 'URL has no host';
            return $result;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $result['error'] = 'Scheme not allowed, use ' . implode(' or ', self::ALLOWED_SCHEMES);
            return $result;
        }
        $result['scheme'] = $scheme;

        // Credentials in the authority are a classic parser-confusion trick.
        if (isset($parts['user']) || isset($parts['pass'])) {
            $result['error'] = 'URL must not contain credentials';
            return $result;
        }

        $host = self::normalizeHost((string)$parts['host']);
        if ($host === '') {
            $result['error'] = 'URL has no host';
            return $result;
        }
        $result['host'] = $host;

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            $result['error'] = 'Invalid port';
            return $result;
        }
        $result['port'] = $port;

        if (!self::isHostAllowed($host)) {
            $result['error'] = 'Host is not a public internet host';
            return $result;
        }

        $ips = self::resolveHost($host);
        if (empty($ips)) {
            $result['error'] = 'Host does not resolve to any IP address';
            return $result;
        }

        // Every answer must be public: one private record is enough to make
        // the target unsafe, because curl may pick any of them.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                $result['error'] = 'Host resolves to a non-public address (' . $ip . ')';
                return $result;
            }
        }

        $result['ips'] = $ips;
        $result['ok'] = true;
        return $result;
    }

    /**
     * Convenience boolean wrapper, for use in write-time form validation.
     */
    public static function isSafeUrl($url) {
        $check = self::inspect($url);
        return $check['ok'] === true;
    }

    /**
     * Human readable reason a URL was rejected, or '' when it is fine.
     */
    public static function rejectReason($url) {
        $check = self::inspect($url);
        return $check['ok'] ? '' : $check['error'];
    }

    /**
     * Build a curl handle that can only reach the validated public target.
     *
     * Returns false and fills $error when the URL is unsafe. The caller still
     * owns the handle and must curl_close() it.
     *
     * @param  string      $url
     * @param  int         $timeout   total transfer timeout in seconds
     * @param  string|null $error     filled with the rejection reason
     * @return resource|\CurlHandle|false
     */
    public static function createCurlHandle($url, $timeout = 10, &$error = null) {
        $check = self::inspect($url);
        if (!$check['ok']) {
            $error = $check['error'];
            return false;
        }
        $error = null;

        $ch = curl_init($url);
        if ($ch === false) {
            $error = 'Failed to initialise curl';
            return false;
        }

        self::hardenCurlHandle($ch, $check, $timeout);
        return $ch;
    }

    /**
     * Apply the transport level hardening to an existing handle.
     * $check must be the array returned by inspect() for the same URL.
     */
    public static function hardenCurlHandle($ch, array $check, $timeout = 10) {
        $timeout = (int)$timeout;
        if ($timeout < 1) $timeout = 1;
        if ($timeout > 60) $timeout = 60;

        // No redirects: a 302 to http://169.254.169.254/ would otherwise walk
        // straight past every check above.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);

        // Only speak HTTP(S); blocks file://, gopher://, dict://, scp:// ...
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'http,https');
        } elseif (defined('CURLPROTO_HTTP')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));

        if (($check['scheme'] ?? '') === 'https') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }

        // Pin the hostname to the addresses we just verified. Without this a
        // second DNS lookup by curl could return an internal IP (rebinding).
        $host = (string)($check['host'] ?? '');
        $port = (int)($check['port'] ?? 0);
        $ips  = $check['ips'] ?? [];
        if ($host !== '' && $port > 0 && !empty($ips) && !filter_var($host, FILTER_VALIDATE_IP)) {
            $pinned = [];
            foreach ($ips as $ip) {
                // curl wants IPv6 literals in brackets inside CURLOPT_RESOLVE.
                $pinned[] = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : $ip;
            }
            curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . implode(',', $pinned)]);
        }
    }

    /**
     * Lowercase the host, strip the root label dot and IPv6 brackets.
     */
    private static function normalizeHost($host) {
        $host = strtolower(trim($host));
        if ($host !== '' && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }
        return rtrim($host, '.');
    }

    /**
     * Reject hosts that cannot be a public name: internal suffixes, bare
     * single labels ("intranet"), and numeric forms such as
     * http://2130706433/ or http://0177.0.0.1/ that decode to loopback.
     */
    private static function isHostAllowed($host) {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true; // literal IP, range check happens in isPublicIp()
        }
        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return false;
        }
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return false;
            }
        }
        // A public name always has a dot and a non numeric TLD.
        if (strpos($host, '.') === false) {
            return false;
        }
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return false;
        }
        return true;
    }

    /**
     * Resolve a host (or pass through a literal IP) to the list of addresses
     * curl could end up connecting to.
     */
    private static function resolveHost($host) {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) $ips[] = $ip;
            }
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $rec) {
                    if (!empty($rec['ipv6']) && filter_var($rec['ipv6'], FILTER_VALIDATE_IP)) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * True only for globally routable unicast addresses.
     */
    public static function isPublicIp($ip) {
        $ip = trim((string)$ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_IPV4 as $cidr) {
                if (self::ipInCidr($ip, $cidr)) return false;
            }
            return true;
        }

        // IPv6: unwrap the embedded IPv4 forms before range matching.
        $embedded = self::embeddedIpv4($ip);
        if ($embedded !== null) {
            return self::isPublicIp($embedded);
        }
        foreach (self::BLOCKED_IPV6 as $cidr) {
            if (self::ipInCidr($ip, $cidr)) return false;
        }
        return true;
    }

    /**
     * Extract the IPv4 address carried by ::ffff:0:0/96 (mapped),
     * ::/96 (compat), 64:ff9b::/96 (NAT64) and 2002::/16 (6to4).
     */
    private static function embeddedIpv4($ip) {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return null;
        }

        if (self::ipInCidr($ip, '2002::/16')) {
            return inet_ntop(substr($bin, 2, 4));
        }
        if (self::ipInCidr($ip, '::ffff:0:0/96') || self::ipInCidr($ip, '64:ff9b::/96')) {
            return inet_ntop(substr($bin, 12, 4));
        }
        // ::a.b.c.d (deprecated IPv4-compatible), but not :: or ::1
        if (substr($bin, 0, 12) === str_repeat("\0", 12) && substr($bin, 12, 4) !== "\0\0\0\0" && $bin !== inet_pton('::1')) {
            return inet_ntop(substr($bin, 12, 4));
        }
        return null;
    }

    /**
     * Binary prefix match, works for both IPv4 and IPv6.
     */
    private static function ipInCidr($ip, $cidr) {
        $slash = strrpos($cidr, '/');
        if ($slash === false) {
            return false;
        }
        $network = substr($cidr, 0, $slash);
        $bits    = (int)substr($cidr, $slash + 1);

        $ipBin  = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $restBits  = $bits % 8;

        if ($fullBytes > 0 && strncmp($ipBin, $netBin, $fullBytes) !== 0) {
            return false;
        }
        if ($restBits === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $restBits)) - 1) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }
}
