#!/usr/local/bin/php
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
 * Compare the delegated IPv6 prefix (and, when asked for, the IPv4 address) of the selected
 * interface with what was synced last and, only on a real change, replace our entries in the
 * Suricata Home networks list and reload (never restart) Suricata. This comparison is the only
 * debounce, the hooks that call us fire on any interface reconfigure.
 *
 * Usage: sync_homenet.php [--force]    (--force syncs even if nothing changed)
 */

require_once('config.inc');
require_once('util.inc');
require_once('interfaces.inc');

use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\DynamicHomenet\DynamicHomenet;
use OPNsense\DynamicHomenet\HomeNet;
use OPNsense\IDS\IDS;

/*
 * The configuration models re-open the syslog connection under their own name, so open ours again
 * for every message to keep all of them tagged "dynamichomenet".
 */
function dynamichomenet_log(int $priority, string $message): void
{
    openlog('dynamichomenet', LOG_ODELAY | LOG_PID, LOG_USER);
    syslog($priority, $message);
}

/**
 * @return bool true when the Home networks list was changed and Suricata needs a rule reload
 */
function dynamichomenet_update_config(bool $force): bool
{
    $config = Config::getInstance()->lock();

    try {
        $mdl = new DynamicHomenet();
        if ((string)$mdl->enabled !== '1' || (string)$mdl->interface === '') {
            dynamichomenet_log(LOG_DEBUG, 'disabled or no interface selected, nothing to do');
            return false;
        }

        $interface = (string)$mdl->interface;
        $ids = new IDS();
        $before = (string)$ids->general->homenet;
        $homenet = $before;
        $dirty = false;

        /* each family has its own slot in the list and is followed when its option is switched on */
        $families = [
            'ipv6' => [(string)$mdl->sync_ipv6 === '1', 'observedIPv6', 'prefix'],
            'ipv4' => [(string)$mdl->sync_ipv4 === '1', 'observedIPv4', 'address'],
        ];

        foreach ($families as $family => [$enabled, $fetch, $what]) {
            if (!$enabled) {
                continue;
            }

            $observed = HomeNet::$fetch($interface);
            if (empty($observed)) {
                /* ifctl clears the prefix during a reconfigure, keep what we have until a new one shows up */
                dynamichomenet_log(LOG_DEBUG, "{$family}: nothing observed on {$interface}, keeping current entries");
                continue;
            }

            $last = (string)$mdl->state->{"observed_{$family}"};
            if (!$force && !HomeNet::hasChanged($observed, $last)) {
                dynamichomenet_log(LOG_DEBUG, "{$family}: {$what} on {$interface} unchanged, nothing to do");
                continue;
            }

            list ($homenet, $owned) = HomeNet::replaceOwned(
                $homenet,
                HomeNet::parseList((string)$mdl->state->{"owned_{$family}"}),
                $observed
            );

            $mdl->state->{"observed_{$family}"} = implode(',', $observed);
            $mdl->state->{"owned_{$family}"} = implode(',', $owned);
            $dirty = true;
            dynamichomenet_log(
                LOG_NOTICE,
                "{$interface} {$family} {$what} now " . implode(',', $observed) . ", was '{$last}'"
            );
        }

        if (!$dirty) {
            return false;
        }

        $homenetChanged = $homenet !== $before;
        if ($homenetChanged) {
            $ids->general->homenet = $homenet;
        }

        /* validates both models, throws on failure so no half-written state is saved */
        $mdl->serializeToConfig();
        if ($homenetChanged) {
            $ids->serializeToConfig();
        }
        $config->save();

        return $homenetChanged && (string)$ids->general->enabled === '1';
    } catch (\Exception $e) {
        dynamichomenet_log(LOG_ERR, 'unable to update configuration: ' . $e->getMessage());
        return false;
    } finally {
        $config->unlock();
    }
}

/* one run at a time, a queued run finds nothing left to do which coalesces bursts of hook calls */
$lock = fopen('/var/run/dynamichomenet.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    dynamichomenet_log(LOG_ERR, 'unable to acquire lock');
    exit(1);
}

if (dynamichomenet_update_config(in_array('--force', $argv ?? [], true))) {
    $backend = new Backend();
    /* same sequence as the Apply button on the Intrusion Detection rules page: render, then reload */
    if (trim($backend->configdRun('template reload OPNsense/IDS')) !== 'OK') {
        dynamichomenet_log(LOG_ERR, 'unable to render the Intrusion Detection template');
        exit(1);
    }
    if (strpos($backend->configdRun('ids status'), 'is running') > 0) {
        $backend->configdRun('ids reload');
        dynamichomenet_log(LOG_NOTICE, 'HOME_NET updated, Suricata rules reloaded');
    } else {
        dynamichomenet_log(LOG_NOTICE, 'HOME_NET updated, Suricata is not running so no reload needed');
    }
}

flock($lock, LOCK_UN);
