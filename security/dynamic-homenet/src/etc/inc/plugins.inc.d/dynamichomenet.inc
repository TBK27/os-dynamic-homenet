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
 * Two hooks are needed. A changed DHCPv6-PD prefix arrives via rc.newwanipv6 which only runs the
 * "newwanip" hook (with the address family), "updateip" is only called on interface reconfigures.
 * The ":N" suffix is the argument count handed to the handler, see plugins_configure().
 */
function dynamichomenet_configure()
{
    return [
        'newwanip' => ['dynamichomenet_newwanip:3'],
        'updateip' => ['dynamichomenet_updateip:2'],
    ];
}

/* args: $verbose, [$interfaces], $family (inet / inet6), only the IPv6 run is of interest */
function dynamichomenet_newwanip($verbose = false, $interfaces = null, $family = null)
{
    if ($family !== 'inet6' || !plugins_argument_map($interfaces)) {
        return;
    }

    dynamichomenet_dispatch($interfaces);
}

/* args: $verbose, [$interfaces], no address family is given */
function dynamichomenet_updateip($verbose = false, $interfaces = null)
{
    if (!plugins_argument_map($interfaces)) {
        return;
    }

    dynamichomenet_dispatch($interfaces);
}

/*
 * Cheap pre-filter so unrelated invocations cost nothing: opted in, our interface and a delegated
 * prefix that differs from the last sync. The sync script repeats the comparison under its own
 * lock, this only decides whether it is worth starting it (detached, the caller is a network
 * event handler and must not wait for Suricata).
 */
function dynamichomenet_dispatch($interfaces)
{
    global $config;

    $cfg = $config['OPNsense']['DynamicHomenet'] ?? [];
    $interface = $cfg['interface'] ?? '';

    if (($cfg['enabled'] ?? '0') !== '1' || $interface === '' || !in_array($interface, $interfaces)) {
        return;
    }

    $observed = \OPNsense\DynamicHomenet\HomeNet::observedIPv6($interface);
    $last = $cfg['state']['observed_ipv6'] ?? '';

    if (empty($observed) || !\OPNsense\DynamicHomenet\HomeNet::hasChanged($observed, $last)) {
        log_msg("dynamichomenet: prefix on {$interface} unchanged, nothing to do", LOG_DEBUG);
        return;
    }

    log_msg("dynamichomenet: prefix on {$interface} changed, starting sync", LOG_INFO);
    configd_run('dynamichomenet sync', true);
}
