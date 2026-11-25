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
 * for every message to keep all of them tagged "dynamic-homenet".
 */
function dynamichomenet_log(int $priority, string $message): void
{
    openlog('dynamic-homenet', LOG_ODELAY | LOG_PID, LOG_USER);
    syslog($priority, $message);
}

/* the plugin's sync state as the plain array HomeNet works on */
function dynamichomenet_state(DynamicHomenet $mdl): array
{
    $state = [];
    foreach (['ipv6', 'ipv4'] as $family) {
        foreach (['observed', 'owned'] as $kind) {
            $state["{$kind}_{$family}"] = (string)$mdl->state->{"{$kind}_{$family}"};
        }
    }

    return $state;
}

/**
 * @return bool true when values were synced and Suricata needs its configuration rendered and reloaded
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

        /*
         * each family has its own slot in the list and is followed when its option is switched on,
         * ifctl clears the prefix during a reconfigure so an empty value keeps what we have
         */
        $observed = [];
        if ((string)$mdl->sync_ipv6 === '1') {
            $observed['ipv6'] = HomeNet::observedIPv6($interface);
        }
        if ((string)$mdl->sync_ipv4 === '1') {
            $observed['ipv4'] = HomeNet::observedIPv4($interface);
        }

        list ($homenet, $state, $synced) = HomeNet::syncFamilies($before, dynamichomenet_state($mdl), $observed, $force);
        if (empty($synced)) {
            dynamichomenet_log(LOG_DEBUG, "nothing new on {$interface}, nothing to do");
            return false;
        }

        foreach ($synced as $family => $last) {
            $mdl->state->{"observed_{$family}"} = $state["observed_{$family}"];
            $mdl->state->{"owned_{$family}"} = $state["owned_{$family}"];
            $what = $family === 'ipv6' ? 'prefix' : 'address';
            dynamichomenet_log(
                LOG_NOTICE,
                "{$interface} {$family} {$what} now {$state["observed_{$family}"]}, was '{$last}'"
            );
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

        /* also when the list text came out the same: a retry or Sync now must still reach Suricata */
        return (string)$ids->general->enabled === '1';
    } catch (\Exception $e) {
        dynamichomenet_log(LOG_ERR, 'unable to update configuration: ' . $e->getMessage());
        return false;
    } finally {
        $config->unlock();
    }
}

/*
 * The new values are saved before Suricata is rendered and reloaded, so after a failure every later
 * run would find nothing changed and never try again. Forgetting them makes the next address event
 * (or Sync now) sync, render and reload again. Called under our lock, and Config::lock() re-reads
 * config.xml, so only these values are cleared and nothing newer is overwritten.
 */
function dynamichomenet_retry_later(string $failed): void
{
    dynamichomenet_log(LOG_ERR, "unable to {$failed}, will retry on the next address event or with Sync now");
    $config = Config::getInstance()->lock();

    try {
        $mdl = new DynamicHomenet();
        $state = HomeNet::forgetObserved(dynamichomenet_state($mdl));
        foreach (['ipv6', 'ipv4'] as $family) {
            $mdl->state->{"observed_{$family}"} = $state["observed_{$family}"];
        }
        $mdl->serializeToConfig();
        $config->save();
    } catch (\Exception $e) {
        dynamichomenet_log(LOG_ERR, 'unable to reset the sync state: ' . $e->getMessage());
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
        dynamichomenet_retry_later('render the Intrusion Detection template');
        exit(1);
    }
    /*
     * Installing the rules can take longer than configd's default 120 s on a big ruleset, which would
     * read as a failure. Suricata reloads in the background after this returns, a reload started by
     * anyone else in the next minutes can still overlap with it (the same holds for core's Apply).
     */
    if (strpos($backend->configdRun('ids status'), 'is running') > 0) {
        if (trim($backend->configdRun('ids reload', false, 600)) !== 'OK') {
            dynamichomenet_retry_later('reload the Suricata rules');
            exit(1);
        }
        dynamichomenet_log(LOG_NOTICE, 'HOME_NET updated, Suricata rules reloaded');
    } else {
        dynamichomenet_log(LOG_NOTICE, 'HOME_NET updated, Suricata is not running so no reload needed');
    }
}

flock($lock, LOCK_UN);
