# os-dynamichomenet

An OPNsense plugin that keeps the **Home networks** (`HOME_NET`) list of Intrusion Detection (Suricata)
in step with the IPv6 prefix your provider delegates to your firewall.

## The problem

Suricata decides which addresses are "yours" from the Home networks list, and many rules only match
when traffic is to or from `HOME_NET`. With IPv4 you enter your ranges once and they stay put. With
IPv6 the provider can hand you a different delegated prefix (DHCPv6 prefix delegation) at any time,
for example after a reconnect. When that happens and the list does not follow, your whole IPv6 LAN
is treated as outside traffic and those rules silently stop matching. Nothing warns you.

## What it does

When enabled, the plugin reads the delegated prefix of the interface you choose, adds it to the Home
networks list, and replaces it when the prefix changes.

- **Opt-in.** Nothing happens until you enable it.
- **Reload, never restart.** Suricata gets a rule reload, the same as the Apply button on the Intrusion
  Detection page. It is never restarted.
- **Your entries are safe.** Only the entries the plugin added are ever replaced. Entries you added are
  left alone, and an entry you already have is never duplicated.
- **Only on a real change.** Ordinary interface reconfigures and DHCP renewals that keep the same
  prefix cause no config save and no reload. While the interface briefly has no prefix (during a
  reconnect), nothing is touched.

You choose what to follow: the **IPv6 prefix** (on by default, recommended) and/or the **IPv4 address** of
the interface (off by default). The IPv4 address is meant for providers that give the WAN a private address
that changes from time to time, so that the firewall itself is still recognised as yours.

### Recommended setup for the best security

The default Home networks list contains the whole private IPv4 ranges `192.168.0.0/16`, `10.0.0.0/8` and
`172.16.0.0/12`. They are far broader than most networks. For example `10.0.0.0/8` also covers addresses on
your provider side of the connection, so everything there is treated as your own network and skipped by the
rules that look for attacks coming from outside.

We suggest removing all the default IPv4 entries from the Home networks list and adding only the subnets you
really use by hand. Let this plugin cover the rest: the IPv6 prefix and, with the IPv4 option, the address of
the firewall itself, which usually changes and cannot be entered by hand.

## Requirements

- OPNsense with Intrusion Detection enabled
- An interface that receives a delegated IPv6 prefix (DHCPv6-PD) and/or an IPv4 address to follow

Tested on OPNsense 26.7.6 with Suricata 8.0.7.

## Install

Install the package `os-dynamichomenet` from **System > Firmware > Plugins**, then reload the web
interface and open **Services > Intrusion Detection > Dynamic Home Network**.

This applies once the plugin is published in a package repository. Until then it can only be installed
by copying the files by hand, see below.

### Manual install (development and testing)

Copy the contents of `security/dynamic-homenet/src/etc` into `/usr/local/etc` and the contents of
`security/dynamic-homenet/src/opnsense` into `/usr/local/opnsense` on the firewall, keeping the
directory layout, owned by root. Then:

```sh
chmod +x /usr/local/opnsense/scripts/OPNsense/DynamicHomenet/sync_homenet.php
service configd restart
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
```

and reload the web interface.

## Use

1. Tick **Enable** and choose the interface that receives the delegated prefix (usually WAN).
2. Choose what to follow: **Follow the IPv6 prefix** (on by default, recommended) and, if you want it,
   **Follow the IPv4 address**.
3. Press **Apply**.

The prefix (and address) now appear on the page and in **Services > Intrusion Detection > Administration >
Settings > Home networks**. From then on the plugin follows the prefix by itself.

**Sync now** updates the list even when nothing seems to have changed, for example to restore an entry
you removed by hand.

Activity is logged under the name `dynamichomenet` (System > Log Files > General). A sync looks like:

```
dynamichomenet: wan ipv6 prefix now 2001:db8:1200::/56, was '2001:db8:3400::/56'
dynamichomenet: HOME_NET updated, Suricata rules reloaded
```

Turning the plugin off stops updates but does not remove the entries it added. Remove it from the Home
networks list if you no longer want it.

## Uninstall

Disable the plugin and remove its entry from the Home networks list, then remove the package from
**System > Firmware > Plugins**. If you installed it by hand:

```sh
rm -rf /usr/local/opnsense/mvc/app/controllers/OPNsense/DynamicHomenet \
       /usr/local/opnsense/mvc/app/library/OPNsense/DynamicHomenet \
       /usr/local/opnsense/mvc/app/models/OPNsense/DynamicHomenet \
       /usr/local/opnsense/mvc/app/views/OPNsense/DynamicHomenet \
       /usr/local/opnsense/scripts/OPNsense/DynamicHomenet \
       /usr/local/opnsense/service/conf/actions.d/actions_dynamichomenet.conf \
       /usr/local/etc/inc/plugins.inc.d/dynamichomenet.inc
service configd restart
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
```

## How it works

A changed delegated prefix reaches the firewall through `rc.newwanipv6` (a changed IPv4 address through
`rc.newwanip`), which run the `newwanip`
plugin hook. The plugin also listens on `updateip`, which runs on interface reconfigures. Both go
through a cheap check (enabled, the right interface, a prefix or address that differs from the last one
synced). Only then is the sync script started, detached, through configd.

The sync script takes a lock, compares again, replaces the plugin's own entries in the Home networks
list (one slot for IPv6 and one for IPv4), saves, and runs `template reload OPNsense/IDS` followed by
`ids reload`. The last synced
prefix and address and the entries the plugin added are stored in its own section of `config.xml`, and that stored
value is the only debounce.

## Development

```sh
sh tests/check.sh    # php -l on every file, XML check, unit tests (needs php on the PATH)
```

The plugin sits in `security/dynamic-homenet/`, laid out the way it would be inside the
[opnsense/plugins](https://github.com/opnsense/plugins) repository.

## License

BSD 2-Clause, see [LICENSE](LICENSE).
