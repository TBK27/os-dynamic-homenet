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

/*
 * Two hooks are needed. A changed DHCPv6-PD prefix arrives via rc.newwanipv6 and a changed IPv4
 * address via rc.newwanip, both only run the "newwanip" hook (with the address family), "updateip"
 * is only called on interface reconfigures.
 * The ":N" suffix is the argument count handed to the handler, see plugins_configure().
 */
function dynamichomenet_configure()
{
    return [
        'newwanip' => ['dynamichomenet_newwanip:3'],
        'updateip' => ['dynamichomenet_updateip:2'],
    ];
}

/* args: $verbose, [$interfaces], $family (inet / inet6) */
function dynamichomenet_newwanip($verbose = false, $interfaces = null, $family = null)
{
    if (!in_array($family, ['inet', 'inet6'], true) || !plugins_argument_map($interfaces)) {
        return;
    }

    dynamichomenet_dispatch($interfaces, [$family]);
}

/* args: $verbose, [$interfaces], no address family is given */
function dynamichomenet_updateip($verbose = false, $interfaces = null)
{
    if (!plugins_argument_map($interfaces)) {
        return;
    }

    dynamichomenet_dispatch($interfaces, ['inet6', 'inet']);
}

/*
 * Cheap pre-filter so unrelated invocations cost nothing: opted in, our interface, a family we
 * track and a value that differs from the last sync. The sync script repeats the comparison under
 * its own lock, this only decides whether it is worth starting it (detached, the caller is a
 * network event handler and must not wait for Suricata). The IPv6 prefix is followed unless it was
 * switched off (an installation from before the option existed has nothing saved, which means on),
 * the IPv4 address only when it was asked for.
 */
function dynamichomenet_dispatch($interfaces, $families)
{
    global $config;

    $cfg = $config['OPNsense']['DynamicHomenet'] ?? [];
    $interface = $cfg['interface'] ?? '';

    if (($cfg['enabled'] ?? '0') !== '1' || $interface === '' || !in_array($interface, $interfaces)) {
        return;
    }

    foreach ($families as $family) {
        if ($family === 'inet6' && ($cfg['sync_ipv6'] ?? '1') !== '0') {
            $observed = \OPNsense\DynamicHomenet\HomeNet::observedIPv6($interface);
            $last = $cfg['state']['observed_ipv6'] ?? '';
        } elseif ($family === 'inet' && ($cfg['sync_ipv4'] ?? '0') === '1') {
            $observed = \OPNsense\DynamicHomenet\HomeNet::observedIPv4($interface);
            $last = $cfg['state']['observed_ipv4'] ?? '';
        } else {
            continue;
        }

        if (empty($observed) || !\OPNsense\DynamicHomenet\HomeNet::hasChanged($observed, $last)) {
            log_msg("dynamic-homenet: {$family} on {$interface} unchanged, nothing to do", LOG_DEBUG);
            continue;
        }

        log_msg("dynamic-homenet: {$family} on {$interface} changed, starting sync", LOG_INFO);
        configd_run('dynamichomenet sync', true);
        return;
    }
}
