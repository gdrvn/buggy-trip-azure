<?php
// Splitwise-style ledger. Pure functions over plain arrays so they can be tested without a database.
//
// Only paid vehicles are expenses. A day's paid vehicle costs are split equally across every seat
// booked that day (members and guests); a guest's share is charged to the member who brought them.
// Leftover cents go to the earliest bookings, one cent each, so shares always add up exactly.
defined('BUGGY') or exit;

// $bookings: [['id','ride_date','user_id'], ...]
// $vehicles: paid vehicles only: [['id','ride_date','price_cents','paid_by'], ...]
// $settlements: [['id','from_user','to_user','amount_cents'], ...]
// Returns ['users' => [userId => [paid, share, sent, received, net]], 'days' => [...], 'unsplit' => [dates]]
function computeLedger(array $bookings, array $vehicles, array $settlements) {
    $users = [];
    $touch = function ($uid) use (&$users) {
        if (!isset($users[$uid])) {
            $users[$uid] = ['paid' => 0, 'share' => 0, 'sent' => 0, 'received' => 0, 'net' => 0];
        }
    };

    $ridersByDate = [];
    foreach ($bookings as $b) {
        $ridersByDate[$b['ride_date']][] = $b;
    }
    $vehiclesByDate = [];
    foreach ($vehicles as $v) {
        $vehiclesByDate[$v['ride_date']][] = $v;
    }
    ksort($vehiclesByDate);

    $days = [];
    $unsplit = [];
    foreach ($vehiclesByDate as $date => $list) {
        $total = 0;
        foreach ($list as $v) {
            $total += (int) $v['price_cents'];
        }
        $riders = $ridersByDate[$date] ?? [];
        if (count($riders) === 0) {
            $unsplit[] = $date; // paid, but nobody rode: not split until someone is on the day
            continue;
        }
        foreach ($list as $v) {
            $touch((int) $v['paid_by']);
            $users[(int) $v['paid_by']]['paid'] += (int) $v['price_cents'];
        }
        usort($riders, function ($a, $b) { return $a['id'] - $b['id']; });
        $n = count($riders);
        $base = intdiv($total, $n);
        $rest = $total % $n;
        $shares = [];
        foreach ($riders as $i => $r) {
            $amount = $base + ($i < $rest ? 1 : 0);
            $uid = (int) $r['user_id'];
            $touch($uid);
            $users[$uid]['share'] += $amount;
            $shares[$uid] = ($shares[$uid] ?? 0) + $amount;
        }
        $days[] = ['date' => $date, 'totalCents' => $total, 'riders' => $n, 'sharesByUser' => $shares];
    }

    foreach ($settlements as $s) {
        $from = (int) $s['from_user'];
        $to = (int) $s['to_user'];
        $touch($from);
        $touch($to);
        $users[$from]['sent'] += (int) $s['amount_cents'];
        $users[$to]['received'] += (int) $s['amount_cents'];
    }

    foreach ($users as $uid => $u) {
        $users[$uid]['net'] = $u['paid'] - $u['share'] + $u['sent'] - $u['received'];
    }
    return ['users' => $users, 'days' => $days, 'unsplit' => $unsplit];
}

// Fewest-ish transfers: repeatedly match the largest debtor with the largest creditor.
function suggestTransfers(array $users) {
    $debtors = [];
    $creditors = [];
    foreach ($users as $uid => $u) {
        if ($u['net'] < 0) {
            $debtors[$uid] = -$u['net'];
        } elseif ($u['net'] > 0) {
            $creditors[$uid] = $u['net'];
        }
    }
    $transfers = [];
    while ($debtors && $creditors) {
        arsort($debtors);
        arsort($creditors);
        $d = array_key_first($debtors);
        $c = array_key_first($creditors);
        $amount = min($debtors[$d], $creditors[$c]);
        $transfers[] = ['from' => $d, 'to' => $c, 'amountCents' => $amount];
        $debtors[$d] -= $amount;
        $creditors[$c] -= $amount;
        if ($debtors[$d] === 0) {
            unset($debtors[$d]);
        }
        if ($creditors[$c] === 0) {
            unset($creditors[$c]);
        }
    }
    return $transfers;
}
