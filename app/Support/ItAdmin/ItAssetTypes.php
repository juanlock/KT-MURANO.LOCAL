<?php

namespace App\Support\ItAdmin;

/**
 * IT asset types and their own fields (it_assets.details JSON), and the categories of the menu -- HTD-IT's AssetTypes, same
 * order (the network flow): Firewalls, Network devices, Servers, Computers, Printers, Phones, Mobiles, Devices (every other
 * type). A new type is a change here only. Field type = the form input: text | number | date | select (options).
 */
class ItAssetTypes
{
    private const NET = [
        'hostname' => ['Hostname', 'text'], 'subnet_vlan' => ['Subnet / VLAN', 'text'], 'ports_capacity' => ['Ports / Capacity', 'text'],
        'firmware_os' => ['Firmware / OS', 'text'], 'physical_location' => ['Physical Location', 'text'], 'provider_vendor' => ['Provider / Vendor', 'text'],
        'support_contract' => ['Support Contract #', 'text'], 'credentials_ref' => ['Credentials Ref', 'text'],
        'backup_config_date' => ['Backup Config Date', 'date'], 'support_status' => ['Support Status', 'text'],
    ];

    public const NETWORK_DEVICES = ['Firewall', 'Router', 'Switch', 'Access Point', 'Server'];

    /** Types whose LAN IP is required (not Switch: an unmanaged switch has no IP). */
    public const REQUIRES_IP = ['Firewall', 'Router', 'Access Point'];

    public const STATUSES = ['Active', 'Spare', 'In Storage', 'In Repair', 'Retired'];

    public const WORK_MODES = ['Office', 'Mobile', 'Home'];

    public const UPLINK_MEDIA = ['Copper', 'Fiber', 'Stack', 'Wireless'];

    public static function types(): array
    {
        return [
            'Computer' => ['subtype' => ['Subtype', 'select', ['Desktop', 'Laptop']], 'hostname' => ['Hostname', 'text'], 'cpu' => ['CPU', 'text'],
                'ram_gb' => ['RAM (GB)', 'number'], 'storage' => ['Storage', 'text'], 'os' => ['Operating System', 'text'], 'os_build' => ['OS Build', 'text'],
                'ip_type' => ['IP Type', 'select', ['DHCP', 'Static']], 'antivirus' => ['Antivirus', 'text'], 'last_patched' => ['Last Patched', 'date'],
                'physical_location' => ['Physical Location (Desk / IDF)', 'text']],
            'Server' => self::NET,
            'Firewall' => self::NET,
            'Router' => self::NET,
            'Switch' => ['eth_ports' => ['ETH ports (copper)', 'number'], 'fx_ports' => ['FX ports (fiber / SFP)', 'number']] + self::NET,
            'Access Point' => self::NET,
            'Phone' => ['phone_number' => ['Phone Number', 'text'], 'extension' => ['Extension', 'text'], 'carrier' => ['Carrier', 'text'], 'imei' => ['IMEI / Serial', 'text']],
            'Mobile Phone' => ['phone_number' => ['Phone Number', 'text'], 'carrier' => ['Carrier', 'text'], 'imei' => ['IMEI', 'text'], 'storage' => ['Storage', 'text']],
            'Tablet' => ['storage' => ['Storage', 'text'], 'model_number' => ['Model Number', 'text'], 'model_name' => ['Model Name / SKU', 'text']],
            'Printer' => ['hostname' => ['Hostname', 'text'], 'physical_location' => ['Physical Location', 'text']],
            'TV / Display' => ['display_use' => ['Used for', 'text'], 'screen_size' => ['Screen size', 'text'], 'hostname' => ['Hostname', 'text'], 'physical_location' => ['Physical Location', 'text']],
            'Accessory' => ['accessory_type' => ['Accessory Type', 'text']],
            'Other' => ['device_kind' => ['Device Kind', 'text'], 'hostname' => ['Hostname', 'text'], 'physical_location' => ['Physical Location', 'text']],
        ];
    }

    public const CATEGORIES = [
        'firewalls' => ['Firewalls', ['Firewall']],
        'network' => ['Network devices', ['Router', 'Switch', 'Access Point']],
        'servers' => ['Servers', ['Server']],
        'computers' => ['Computers', ['Computer']],
        'printers' => ['Printers', ['Printer']],
        'phones' => ['Phones', ['Phone']],
        'mobiles' => ['Mobiles', ['Tablet', 'Mobile Phone']],
        'devices' => ['Devices', []],                                          // every type not listed above
    ];

    public static function listed(): array
    {
        return collect(self::CATEGORIES)->except('devices')->pluck(1)->flatten()->all();
    }

    public static function applyCategory($query, ?string $category, string $col = 'type'): void
    {
        if (! $category || ! isset(self::CATEGORIES[$category])) {
            return;
        }
        $category === 'devices' ? $query->whereNotIn($col, self::listed()) : $query->whereIn($col, self::CATEGORIES[$category][1]);
    }

    public static function categoryOf(?string $type): string
    {
        foreach (self::CATEGORIES as $k => [, $types]) {
            if (in_array($type, $types, true)) {
                return $k;
            }
        }

        return 'devices';
    }

    /** For the screen: types with their fields as {key, label, type, options}. */
    public static function forScreen(): array
    {
        $out = [];
        foreach (self::types() as $type => $fields) {
            $out[] = ['type' => $type, 'category' => self::categoryOf($type), 'fields' => array_map(fn ($k, $f) => ['key' => $k, 'label' => $f[0], 'type' => $f[1], 'options' => $f[2] ?? null],
                array_keys($fields), $fields)];
        }

        return $out;
    }
}
