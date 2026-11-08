<?php
// Run: docker compose run --rm web php tests/fleet_ledger_test.php
PHP_SAPI === 'cli' or exit;
define('BUGGY', true);
require __DIR__ . '/../lib/fleet.php';
require __DIR__ . '/../lib/ledger.php';

$fails = 0;
function check($label, $actual, $expected) {
    global $fails;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL $label\n  expected " . json_encode($expected) . "\n  got      " . json_encode($actual) . "\n";
    }
}

// Fleet proposal
$expect = [
    0 => [], 1 => ['buggy'], 4 => ['buggy'], 5 => ['hilux'], 7 => ['hilux'],
    8 => ['hilux', 'buggy'], 11 => ['hilux', 'buggy'], 12 => ['hilux', 'hilux'], 14 => ['hilux', 'hilux'],
    15 => ['hilux', 'hilux', 'buggy'], 18 => ['hilux', 'hilux', 'buggy'], 19 => ['hilux', 'hilux', 'hilux'],
];
foreach ($expect as $n => $fleet) {
    check("proposeFleet($n)", proposeFleet($n), $fleet);
}
for ($n = 1; $n <= 40; $n++) {
    if (fleetSeats(proposeFleet($n)) < $n) { $fails++; echo "FAIL seats for $n\n"; }
}

// Ledger: user 1 pays a buggy (R$370) on day A for users 1, 2 and 2's guest -> 3 seats.
$bookings = [
    ['id' => 1, 'ride_date' => '2026-11-08', 'user_id' => 1],
    ['id' => 2, 'ride_date' => '2026-11-08', 'user_id' => 2],
    ['id' => 3, 'ride_date' => '2026-11-08', 'user_id' => 2], // guest of 2
    ['id' => 4, 'ride_date' => '2026-11-09', 'user_id' => 3],
];
$vehicles = [
    ['id' => 1, 'ride_date' => '2026-11-08', 'price_cents' => 37000, 'paid_by' => 1],
    ['id' => 2, 'ride_date' => '2026-11-10', 'price_cents' => 40000, 'paid_by' => 3], // nobody rode
];
$l = computeLedger($bookings, $vehicles, []);
check('share user1', $l['users'][1]['share'], 12334); // 37000/3 = 12333.33 -> first booking gets the cent
check('share user2', $l['users'][2]['share'], 24666);
check('net user1', $l['users'][1]['net'], 37000 - 12334);
check('net user2', $l['users'][2]['net'], -24666);
check('unsplit', $l['unsplit'], ['2026-11-10']);
check('payer of unsplit day not credited', isset($l['users'][3]) ? $l['users'][3]['paid'] : 0, 0);
check('zero-sum', array_sum(array_column($l['users'], 'net')), 0);

$t = suggestTransfers($l['users']);
check('transfer', $t, [['from' => 2, 'to' => 1, 'amountCents' => 24666]]);

$l2 = computeLedger($bookings, $vehicles, [['id' => 1, 'from_user' => 2, 'to_user' => 1, 'amount_cents' => 24666]]);
check('settled user2', $l2['users'][2]['net'], 0);
check('settled user1', $l2['users'][1]['net'], 0);
check('no transfers after settling', suggestTransfers($l2['users']), []);

echo $fails ? "$fails failure(s)\n" : "All tests passed\n";
exit($fails ? 1 : 0);
