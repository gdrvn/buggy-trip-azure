<?php
// Vehicle types and the automatic fleet proposal.
defined('BUGGY') or exit;

const VEHICLE_TYPES = [
    'buggy' => ['label' => 'Buggy', 'seats' => 4, 'defaultPriceCents' => 37000],
    'hilux' => ['label' => 'Hilux', 'seats' => 7, 'defaultPriceCents' => 40000],
];

// Up to 4 riders: a buggy. Up to 7: a hilux. Then hilux + buggy, two hiluxes, and so on:
// fill hiluxes, and cover a remainder of 1–4 with a buggy, 5–6 with another hilux.
function proposeFleet($riders) {
    $riders = max(0, (int) $riders);
    if ($riders === 0) {
        return [];
    }
    $hiluxes = intdiv($riders, 7);
    $rest = $riders % 7;
    $fleet = array_fill(0, $hiluxes, 'hilux');
    if ($rest >= 5) {
        $fleet[] = 'hilux';
    } elseif ($rest >= 1) {
        $fleet[] = 'buggy';
    }
    return $fleet;
}

function fleetSeats(array $types) {
    $seats = 0;
    foreach ($types as $t) {
        $seats += VEHICLE_TYPES[$t]['seats'];
    }
    return $seats;
}
