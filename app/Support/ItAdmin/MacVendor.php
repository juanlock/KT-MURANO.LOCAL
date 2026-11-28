<?php

namespace App\Support\ItAdmin;

/**
 * Maker of a network card from the first 3 bytes of its MAC (from HTD-IT). resources/data/oui.tsv ("286FB9<tab>Nokia…") is built
 * from the public IEEE list https://standards-oui.ieee.org/oui/oui.csv (MA-L rows). Randomized / locally administered MACs
 * (phones, some Wi-Fi) have no maker.
 */
class MacVendor
{
    private static ?array $map = null;

    public static function name(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));
        if (strlen($hex) !== 12) {
            return null;
        }
        if (self::$map === null) {
            self::$map = [];
            foreach (@file(resource_path('data/oui.tsv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                [$prefix, $name] = array_pad(explode("\t", $line, 2), 2, null);
                self::$map[$prefix] = $name;
            }
        }

        return self::$map[substr($hex, 0, 6)] ?? null;
    }

    /** "aa:bb:cc:dd:ee:ff", "aabb.ccdd.eeff"... => "AA-BB-CC-DD-EE-FF"; null when it is not a MAC. */
    public static function normalize(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

        return strlen($hex) === 12 ? implode('-', str_split($hex, 2)) : null;
    }

    /** A real device's MAC: not broadcast, not multicast. */
    public static function isDevice(?string $mac): bool
    {
        return $mac !== null && preg_match('/^[0-9A-F]{2}(-[0-9A-F]{2}){5}$/', $mac) && $mac !== 'FF-FF-FF-FF-FF-FF' && ! (hexdec(substr($mac, 0, 2)) & 1);
    }
}
