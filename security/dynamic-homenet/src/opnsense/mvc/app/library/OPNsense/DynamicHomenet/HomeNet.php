<?php

/*
 * Copyright (C) 2026 Logan Galloway
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\DynamicHomenet;

/**
 * Helpers shared by the hook handler and the sync script. Everything above the
 * "system access" marker is pure (no OPNsense runtime needed) so it can be unit tested.
 */
class HomeNet
{
    /**
     * Canonical form of an address or CIDR: host bits cleared, lowercase compressed IPv6,
     * always with an explicit prefix length. Returns null when the entry is not valid.
     */
    public static function normalize(string $entry): ?string
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }

        $parts = explode('/', $entry, 2);
        $packed = @inet_pton($parts[0]);
        if ($packed === false) {
            return null;
        }

        $maxbits = strlen($packed) * 8;
        if (count($parts) === 2) {
            if (!ctype_digit($parts[1])) {
                return null;
            }
            $bits = (int)$parts[1];
            if ($bits > $maxbits) {
                return null;
            }
        } else {
            $bits = $maxbits;
        }

        $masked = '';
        for ($i = 0; $i < strlen($packed); $i++) {
            $keep = max(0, min(8, $bits - 8 * $i));
            $mask = $keep === 0 ? 0 : ((0xFF << (8 - $keep)) & 0xFF);
            $masked .= chr(ord($packed[$i]) & $mask);
        }

        return inet_ntop($masked) . '/' . $bits;
    }

    /**
     * Split a comma separated list into trimmed, non-empty entries (order preserved).
     */
    public static function parseList(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));
    }

    /**
     * Sorted, de-duplicated set of the valid entries in $entries, normalized.
     */
    public static function normalizeSet(array $entries): array
    {
        $set = [];
        foreach ($entries as $entry) {
            $norm = self::normalize((string)$entry);
            if ($norm !== null) {
                $set[$norm] = true;
            }
        }
        $set = array_keys($set);
        sort($set, SORT_STRING);

        return $set;
    }

    /**
     * The debounce: true when what we observe on the interface differs from what we
     * recorded the last time we synced (as comma separated list).
     */
    public static function hasChanged(array $observed, string $lastObserved): bool
    {
        return self::normalizeSet($observed) !== self::normalizeSet(self::parseList($lastObserved));
    }

    /**
     * Swap the entries we previously inserted for the newly observed ones.
     *
     * Only entries listed in $previouslyOwned are ever removed, administrator entries are kept
     * verbatim and in order. A new entry the administrator already has in the list is not added
     * again and is not marked as owned, so it can never be removed by us later on.
     *
     * @return array [new comma separated list, entries now owned by us]
     */
    public static function replaceOwned(string $homenet, array $previouslyOwned, array $observed): array
    {
        $drop = array_flip(self::normalizeSet($previouslyOwned));

        $kept = [];
        $present = [];
        foreach (self::parseList($homenet) as $entry) {
            $norm = self::normalize($entry);
            if ($norm !== null && isset($drop[$norm])) {
                continue;
            }
            $kept[] = $entry;
            if ($norm !== null) {
                $present[$norm] = true;
            }
        }

        $owned = [];
        foreach (self::normalizeSet($observed) as $norm) {
            if (isset($present[$norm])) {
                continue;
            }
            $kept[] = $norm;
            $owned[] = $norm;
        }

        return [implode(',', $kept), $owned];
    }

    /* ---- system access (needs the OPNsense legacy includes loaded) ---- */

    /**
     * Delegated prefix(es) currently held for an interface, e.g. ["2001:db8:1200::/56"].
     * dhcp6c_script.sh stores them via ifctl (/tmp/<device>_prefixv6), and ifctl clears
     * them during a reconfigure, so an empty result means "unknown right now", not "gone".
     */
    public static function observedIPv6(string $interface): array
    {
        $device = get_real_interface($interface, 'inet6');
        if (empty($device)) {
            return [];
        }

        $output = trim((string)shell_safe('/usr/local/sbin/ifctl -6pi %s', $device));
        if ($output === '') {
            return [];
        }

        $prefixes = [];
        foreach (self::normalizeSet(preg_split('/\s+/', $output, -1, PREG_SPLIT_NO_EMPTY)) as $norm) {
            if (strpos($norm, ':') !== false) {
                $prefixes[] = $norm;
            }
        }

        return $prefixes;
    }

    /**
     * Primary IPv4 address of an interface as /32, or nothing while it has none.
     */
    public static function observedIPv4(string $interface): array
    {
        list ($ip) = interfaces_primary_address($interface);

        return is_ipaddrv4($ip) ? self::normalizeSet([$ip]) : [];
    }
}
