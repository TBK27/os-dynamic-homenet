<?php

/*
 * Plain-PHP tests for the pure parts of HomeNet (no OPNsense runtime or phpunit needed).
 * Run: php tests/run_tests.php
 */

require_once __DIR__ . '/../security/dynamic-homenet/src/opnsense/mvc/app/library/OPNsense/DynamicHomenet/HomeNet.php';

use OPNsense\DynamicHomenet\HomeNet;

$failures = 0;
$count = 0;

function check(string $name, $expected, $actual): void
{
    global $failures, $count;
    $count++;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$name}\n  expected: " . json_encode($expected) . "\n  actual:   " . json_encode($actual) . "\n";
    }
}

/* normalize */
check('v6 prefix canonical', '2001:db8:1200::/56', HomeNet::normalize('2001:0DB8:1200:0000::/56'));
check('v6 host bits cleared', '2001:db8:1200::/56', HomeNet::normalize('2001:db8:1200:ab::1/56'));
check('v6 net bits kept', '2001:db8:12ff::/56', HomeNet::normalize('2001:db8:12ff::/56'));
check('v6 /60 partial byte', '2001:db8:0:1230::/60', HomeNet::normalize('2001:db8:0:123f::/60'));
check('v6 bare address is /128', '2001:db8::1/128', HomeNet::normalize('2001:db8::1'));
check('v4 bare address is /32', '203.0.113.7/32', HomeNet::normalize('203.0.113.7'));
check('v4 cidr host bits cleared', '192.168.0.0/16', HomeNet::normalize('192.168.5.9/16'));
check('v4 /0', '0.0.0.0/0', HomeNet::normalize('1.2.3.4/0'));
check('whitespace trimmed', '10.0.0.0/8', HomeNet::normalize('  10.0.0.0/8 '));
check('empty invalid', null, HomeNet::normalize(''));
check('garbage invalid', null, HomeNet::normalize('not-an-ip'));
check('v4 prefix too long invalid', null, HomeNet::normalize('10.0.0.0/33'));
check('v6 prefix too long invalid', null, HomeNet::normalize('2001:db8::/129'));
check('negative prefix invalid', null, HomeNet::normalize('10.0.0.0/-1'));
check('non numeric prefix invalid', null, HomeNet::normalize('10.0.0.0/abc'));

/* parseList / normalizeSet */
check('parseList', ['a', 'b'], HomeNet::parseList(' a, ,b ,'));
check('parseList empty', [], HomeNet::parseList(''));
check(
    'normalizeSet sorted unique, drops invalid',
    ['10.0.0.0/8', '2001:db8::/32'],
    HomeNet::normalizeSet(['2001:DB8::/32', '10.0.0.0/8', '10.0.0.0/8', 'bogus'])
);

/* hasChanged: the debounce */
check('unchanged identical', false, HomeNet::hasChanged(['2001:db8:1200::/56'], '2001:db8:1200::/56'));
check('unchanged different notation', false, HomeNet::hasChanged(['2001:db8:1200::/56'], '2001:DB8:1200:0::/56'));
check('unchanged different order', false, HomeNet::hasChanged(['2001:db8:2::/56', '2001:db8:1::/56'], '2001:db8:1::/56,2001:db8:2::/56'));
check('changed prefix', true, HomeNet::hasChanged(['2001:db8:9900::/56'], '2001:db8:1200::/56'));
check('changed prefix length', true, HomeNet::hasChanged(['2001:db8:1200::/60'], '2001:db8:1200::/56'));
check('first sync (no last value)', true, HomeNet::hasChanged(['2001:db8:1200::/56'], ''));

/* replaceOwned */
$admin = '192.168.0.0/16,10.0.0.0/8,172.16.0.0/12';

list ($list, $owned) = HomeNet::replaceOwned($admin, [], ['2001:db8:1200::/56']);
check('first sync appends', $admin . ',2001:db8:1200::/56', $list);
check('first sync owns new entry', ['2001:db8:1200::/56'], $owned);

list ($list2, $owned2) = HomeNet::replaceOwned($list, $owned, ['2001:db8:9900::/56']);
check('renumbering replaces, no accumulation', $admin . ',2001:db8:9900::/56', $list2);
check('renumbering owns only the new entry', ['2001:db8:9900::/56'], $owned2);

list ($list3, $owned3) = HomeNet::replaceOwned($list2, $owned2, ['2001:db8:9900::/56']);
check('re-sync same value is stable', $list2, $list3);
check('re-sync same value keeps ownership', ['2001:db8:9900::/56'], $owned3);

list ($list4, $owned4) = HomeNet::replaceOwned('10.0.0.0/8,2001:db8:1200::/56', [], ['2001:db8:1200::/56']);
check('entry the admin already has is not duplicated', '10.0.0.0/8,2001:db8:1200::/56', $list4);
check('entry the admin already has is not owned', [], $owned4);

list ($list5, $owned5) = HomeNet::replaceOwned($list4, $owned4, ['2001:db8:9900::/56']);
check('admin entry survives when the prefix moves on', '10.0.0.0/8,2001:db8:1200::/56,2001:db8:9900::/56', $list5);
check('only the new entry is owned afterwards', ['2001:db8:9900::/56'], $owned5);

list ($list6, $owned6) = HomeNet::replaceOwned('10.0.0.0/8,2001:db8:1200:0::/56', ['2001:db8:1200::/56'], ['2001:db8:9900::/56']);
check('owned entry removed regardless of notation', '10.0.0.0/8,2001:db8:9900::/56', $list6);

list ($list7, $owned7) = HomeNet::replaceOwned($admin . ',2001:db8:1::/56', ['2001:db8:1::/56'], ['2001:db8:a::/56', '2001:db8:b::/56']);
check('multiple delegated prefixes', $admin . ',2001:db8:a::/56,2001:db8:b::/56', $list7);
check('multiple prefixes all owned', ['2001:db8:a::/56', '2001:db8:b::/56'], $owned7);

/* the two families keep independent slots in the same list */
list ($v6list, $v6owned) = HomeNet::replaceOwned($admin, [], ['2001:db8:1200::/56']);
list ($bothlist, $v4owned) = HomeNet::replaceOwned($v6list, [], ['203.0.113.7']);
list ($v4moved, $v4owned2) = HomeNet::replaceOwned($bothlist, $v4owned, ['203.0.113.99']);
check('v4 change leaves v6 slot alone', $admin . ',2001:db8:1200::/56,203.0.113.99/32', $v4moved);
list ($v6moved) = HomeNet::replaceOwned($v4moved, $v6owned, ['2001:db8:9900::/56']);
check('v6 change leaves v4 slot alone', $admin . ',203.0.113.99/32,2001:db8:9900::/56', $v6moved);

list ($untouched) = HomeNet::replaceOwned($admin, ['192.168.0.0/16'], []);
check('previously owned entry removed even when nothing new', '10.0.0.0/8,172.16.0.0/12', $untouched);

echo $failures === 0 ? "OK: {$count} checks passed\n" : "{$failures} of {$count} checks FAILED\n";
exit($failures === 0 ? 0 : 1);
