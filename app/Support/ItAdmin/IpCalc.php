<?php

namespace App\Support\ItAdmin;

/**
 * IPv4 subnetting math for IT Admin (ported from HTD-IT's IpCalculator, 2026-11-28): network / broadcast / usable range /
 * host counts / class. Network and broadcast addresses are not assignable (usable = 2^(32-cidr) - 2; /31 = 2, /32 = 1).
 */
class IpCalc
{
    public static function toLong(?string $ip): ?int
    {
        $l = $ip !== null ? ip2long($ip) : false;

        return $l === false ? null : $l;
    }

    public static function fromLong(int $l): string
    {
        return long2ip($l & 0xFFFFFFFF);
    }

    public static function mask(int $cidr): string
    {
        return self::fromLong($cidr <= 0 ? 0 : (-1 << (32 - $cidr)));
    }

    public static function cidrFromMask(string $mask): ?int
    {
        $l = self::toLong($mask);

        return $l === null ? null : 32 - (int) log((~$l & 0xFFFFFFFF) + 1, 2);
    }

    public static function network(?string $ip, ?int $cidr): ?string
    {
        $l = self::toLong($ip);

        return $l === null || $cidr === null ? null : self::fromLong($l & self::toLong(self::mask($cidr)));
    }

    public static function broadcast(?string $ip, ?int $cidr): ?string
    {
        $l = self::toLong($ip);
        if ($l === null || $cidr === null) {
            return null;
        }
        $m = self::toLong(self::mask($cidr));

        return self::fromLong(($l & $m) | (~$m & 0xFFFFFFFF));
    }

    public static function totalHosts(int $cidr): int
    {
        return (int) 2 ** (32 - $cidr);
    }

    public static function usableHosts(int $cidr): int
    {
        return $cidr >= 32 ? 1 : ($cidr === 31 ? 2 : self::totalHosts($cidr) - 2);
    }

    public static function firstHost(?string $ip, ?int $cidr): ?string
    {
        $n = self::network($ip, $cidr);

        return $n === null ? null : ($cidr >= 31 ? $n : self::fromLong(self::toLong($n) + 1));
    }

    public static function lastHost(?string $ip, ?int $cidr): ?string
    {
        $b = self::broadcast($ip, $cidr);

        return $b === null ? null : ($cidr >= 31 ? $b : self::fromLong(self::toLong($b) - 1));
    }

    public static function classOf(string $ip): string
    {
        $o = (int) explode('.', $ip)[0];

        return match (true) {
            $o <= 127 => 'A', $o <= 191 => 'B', $o <= 223 => 'C', $o <= 239 => 'D (Multicast)', default => 'E (Reserved)',
        };
    }

    public static function isPrivate(string $ip): bool
    {
        $l = self::toLong($ip);
        foreach ([['10.0.0.0', '10.255.255.255'], ['172.16.0.0', '172.31.255.255'], ['192.168.0.0', '192.168.255.255']] as [$a, $b]) {
            if ($l !== null && $l >= self::toLong($a) && $l <= self::toLong($b)) {
                return true;
            }
        }

        return false;
    }

    public static function contains(?string $networkIp, ?int $cidr, ?string $ip): bool
    {
        $n = self::network($networkIp, $cidr);
        $t = self::toLong($ip);

        return $n !== null && $t !== null && ($t & self::toLong(self::mask($cidr))) === self::toLong($n);
    }

    /** "10.10.30.0/24", "10.10.30.0 / 24" or "10.10.30.0 255.255.255.0" => [ip, cidr]; null when not valid. */
    public static function parse(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})\s*/\s*(\d{1,2})$#', $text, $m) && (int) $m[2] <= 32 && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [$m[1], (int) $m[2]];
        }
        if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})\s+(\d{1,3}(?:\.\d{1,3}){3})$#', $text, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $c = self::cidrFromMask($m[2]);

            return $c !== null && self::mask($c) === $m[2] ? [$m[1], $c] : null;
        }

        return null;
    }

    public static function summary(string $ip, int $cidr): array
    {
        return [
            'network' => self::network($ip, $cidr), 'broadcast' => self::broadcast($ip, $cidr), 'mask' => self::mask($cidr),
            'first' => self::firstHost($ip, $cidr), 'last' => self::lastHost($ip, $cidr), 'total' => self::totalHosts($cidr),
            'usable' => self::usableHosts($cidr), 'class' => self::classOf($ip), 'private' => self::isPrivate($ip),
        ];
    }
}
