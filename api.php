<?php
// Buggy Trip — JSON API.
//   GET  api.php?action=me | state&date=YYYY-MM-DD | calendar&from=..&to=.. | balances
//   POST {"action": "..."} with header X-CSRF-Token (from "me"):
//        register, login, logout, join, add_guest, cancel, reorder,
//        apply_suggestion, add_vehicle, update_vehicle, remove_vehicle, book_vehicle, unbook_vehicle,
//        pay_vehicle, unpay_vehicle (vehicle actions take {id}, or {date, index} for a suggested vehicle),
//        settle_add, settle_delete
// Everything requires the site password (index.php sets it in the session); all but
// me/register/login require a signed-in member.

define('BUGGY', true);
ob_start();
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/fleet.php';
require __DIR__ . '/lib/ledger.php';

function sendJson($data, $statusCode = 200) {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data);
    exit;
}

function fail($message, $statusCode = 400, $extra = []) {
    sendJson(array_merge(['error' => $message], $extra), $statusCode);
}

set_exception_handler(function ($e) {
    error_log('Buggy Trip API exception: ' . $e->getMessage());
    fail('Server error — check the application log for details.', 500);
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log('Buggy Trip API PHP error: ' . $errstr . ' in ' . $errfile . ' on line ' . $errline);
    return true;
});

date_default_timezone_set(getenv('BUGGY_TIMEZONE') ?: 'America/Sao_Paulo');
startAppSession();

if (sitePassword() === null) {
    fail('The site password is not configured.', 503);
}
if (!gatePassed()) {
    fail('Enter the site password first.', 401, ['gate' => true]);
}

$db = openDatabase();
$method = $_SERVER['REQUEST_METHOD'];
$input = [];
if ($method === 'POST') {
    $decoded = json_decode(file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];
    $action = (string) ($input['action'] ?? '');
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        fail('Your session expired — reload the page.', 403, ['reload' => true]);
    }
} else {
    $action = (string) ($_GET['action'] ?? '');
}

// ---------- helpers ----------

function parseDate($value) {
    if (!is_string($value)) {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

function requireDate($value) {
    $date = parseDate($value);
    if ($date === null) {
        fail('A valid date (YYYY-MM-DD) is required.');
    }
    return $date;
}

// Cancellations and seat moves for a date close at 22:00 on the day before that date.
// Leaving and seat moves close at 22:00 (BUGGY_TIMEZONE) the evening before the ride.
function lockTimestamp($rideDate) {
    $cutoff = DateTime::createFromFormat('!Y-m-d', $rideDate);
    $cutoff->modify('-1 day');
    $cutoff->setTime(22, 0, 0);
    return $cutoff->getTimestamp();
}

function isLocked($rideDate) {
    return time() >= lockTimestamp($rideDate);
}

function currentUser($db) {
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $stmt = $db->prepare('SELECT id, username, display_name, default_pickup FROM users WHERE id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $user = ['id' => (int) $row['id'], 'username' => $row['username'], 'name' => $row['display_name'], 'defaultPickup' => $row['default_pickup']];
        }
    }
    return $user;
}

function requireUser($db) {
    $u = currentUser($db);
    if (!$u) {
        fail('Sign in first.', 401, ['login' => true]);
    }
    return $u;
}

function cleanText($value, $max, $label, $required = true) {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $value));
    if ($required && $text === '') {
        fail($label . ' is required.');
    }
    if (mb_strlen($text) > $max) {
        fail($label . ' is too long (max ' . $max . ' characters).');
    }
    return $text;
}

function parsePriceCents($value) {
    if (!is_numeric($value)) {
        fail('Enter the price as a number.');
    }
    $cents = (int) round(((float) $value) * 100);
    if ($cents < 0 || $cents > 10000000) {
        fail('That price looks wrong.');
    }
    return $cents;
}

function userNames($db) {
    $names = [];
    foreach ($db->query('SELECT id, display_name FROM users') as $row) {
        $names[(int) $row['id']] = $row['display_name'];
    }
    return $names;
}

function vehicleRow($db, $id) {
    $stmt = $db->prepare('SELECT * FROM vehicles WHERE id = ?');
    $stmt->execute([(int) $id]);
    $v = $stmt->fetch();
    if (!$v) {
        fail('This vehicle no longer exists — refresh.', 404);
    }
    return $v;
}

function dayVehicleRows($db, $date) {
    $stmt = $db->prepare('SELECT * FROM vehicles WHERE ride_date = ? ORDER BY position, id');
    $stmt->execute([$date]);
    return $stmt->fetchAll();
}

function riderCount($db, $date) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM bookings WHERE ride_date = ?');
    $stmt->execute([$date]);
    return (int) $stmt->fetchColumn();
}

function insertPlanned($db, $date, $type, $me) {
    $db->prepare('INSERT INTO vehicles (ride_date, type, price_cents, position, created_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$date, $type, VEHICLE_TYPES[$type]['defaultPriceCents'], nextPosition($db, 'vehicles', $date), $me['id']]);
}

// While a day has no stored vehicles, the plan is the automatic suggestion. The first edit turns
// that suggestion into stored, planned (not yet booked) vehicles, so the edit applies on top of it.
function materializePlan($db, $date, $me) {
    if (dayVehicleRows($db, $date)) {
        return;
    }
    foreach (proposeFleet(riderCount($db, $date)) as $type) {
        insertPlanned($db, $date, $type, $me);
    }
}

// What "match riders" produces: booked/paid vehicles stay, the rest is re-suggested for whoever
// the booked vehicles can't seat.
function replanTypes(array $rows, $riders) {
    $kept = [];
    foreach ($rows as $v) {
        if ($v['booked_at'] !== null || $v['paid_by'] !== null) {
            $kept[] = $v['type'];
        }
    }
    return array_merge($kept, proposeFleet(max(0, $riders - fleetSeats($kept))));
}

// A vehicle action targets a stored vehicle by id, or a suggested one by its index in the plan.
function targetVehicle($db, array $input, $me) {
    if (!empty($input['id'])) {
        return vehicleRow($db, $input['id']);
    }
    $date = requireDate($input['date'] ?? '');
    materializePlan($db, $date, $me);
    $rows = dayVehicleRows($db, $date);
    $index = (int) ($input['index'] ?? -1);
    if (!isset($rows[$index])) {
        fail('This vehicle no longer exists — refresh.', 404);
    }
    return $rows[$index];
}

// After the 22:00 lock nobody can leave, but late riders may still take a free seat in the booked
// vehicles. Returns the number of free seats, or null when nothing limits joining (before the lock,
// or while no vehicle is booked yet).
function lateFreeSeats($db, $date) {
    if (!isLocked($date)) {
        return null;
    }
    $booked = [];
    foreach (dayVehicleRows($db, $date) as $v) {
        if ($v['booked_at'] !== null || $v['paid_by'] !== null) {
            $booked[] = $v['type'];
        }
    }
    return $booked ? max(0, fleetSeats($booked) - riderCount($db, $date)) : null;
}

function requireSeatAfterLock($db, $date) {
    if (lateFreeSeats($db, $date) === 0) {
        fail('Planning closed at 22:00 and the booked vehicles are full.', 409);
    }
}

function nextPosition($db, $table, $date) {
    $stmt = $db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM $table WHERE ride_date = ?");
    $stmt->execute([$date]);
    return (int) $stmt->fetchColumn();
}

function dayState($db, $date) {
    $me = currentUser($db);
    $names = userNames($db);

    $stmt = $db->prepare('SELECT id, user_id, guest_name, pickup FROM bookings WHERE ride_date = ? ORDER BY position, id');
    $stmt->execute([$date]);
    $riders = [];
    foreach ($stmt->fetchAll() as $r) {
        $uid = (int) $r['user_id'];
        $isGuest = $r['guest_name'] !== null;
        $riders[] = [
            'id' => (int) $r['id'],
            'userId' => $uid,
            'name' => $isGuest ? $r['guest_name'] : ($names[$uid] ?? '?'),
            'guestOf' => $isGuest ? ($names[$uid] ?? '?') : null,
            'pickup' => $r['pickup'],
            'mine' => $uid === $me['id'],
        ];
    }

    $rows = dayVehicleRows($db, $date);
    $vehicles = [];
    $planTotal = 0;
    $paidTotal = 0;
    foreach ($rows as $v) {
        $paidBy = $v['paid_by'] !== null ? (int) $v['paid_by'] : null;
        $bookedBy = $v['booked_by'] !== null ? (int) $v['booked_by'] : null;
        $vehicles[] = [
            'id' => (int) $v['id'],
            'type' => $v['type'],
            'seats' => VEHICLE_TYPES[$v['type']]['seats'],
            'priceCents' => (int) $v['price_cents'],
            'status' => $paidBy ? 'paid' : ($v['booked_at'] !== null ? 'booked' : 'planned'),
            'bookedBy' => $bookedBy ? ['id' => $bookedBy, 'name' => $names[$bookedBy] ?? '?'] : null,
            'paidBy' => $paidBy ? ['id' => $paidBy, 'name' => $names[$paidBy] ?? '?'] : null,
            'paidAt' => $v['paid_at'],
        ];
        $planTotal += (int) $v['price_cents'];
        if ($paidBy) {
            $paidTotal += (int) $v['price_cents'];
        }
    }

    $n = count($riders);
    $suggestion = proposeFleet($n);
    $suggestionTotal = 0;
    foreach ($suggestion as $t) {
        $suggestionTotal += VEHICLE_TYPES[$t]['defaultPriceCents'];
    }
    $estimate = $vehicles ? $planTotal : $suggestionTotal;
    // Offer "match riders" only when it would actually change something.
    $replan = $rows ? replanTypes($rows, $n) : null;
    if ($replan !== null && $replan === array_column($rows, 'type')) {
        $replan = null;
    }

    return [
        'date' => $date,
        'locked' => isLocked($date),
        'lockAt' => lockTimestamp($date),
        'serverNow' => time(),
        'freeSeats' => lateFreeSeats($db, $date),
        'riders' => $riders,
        'vehicles' => $vehicles,
        'suggestion' => $suggestion,
        'replan' => $replan,
        'summary' => [
            'riders' => $n,
            'seats' => $vehicles ? array_sum(array_column($vehicles, 'seats')) : fleetSeats($suggestion),
            'totalCents' => $estimate,
            'paidCents' => $paidTotal,
            'perPersonCents' => $n ? (int) ceil($estimate / $n) : 0,
            'estimated' => !$vehicles || $paidTotal < $planTotal,
        ],
    ];
}

function sendDay($db, $date, $status = 200) {
    sendJson(dayState($db, $date), $status);
}

function mePayload($db) {
    return ['user' => currentUser($db), 'csrf' => $_SESSION['csrf'], 'types' => VEHICLE_TYPES];
}

// ---------- actions ----------

switch ($action) {

    case 'me':
        sendJson(mePayload($db));

    case 'register':
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $name = cleanText($input['name'] ?? '', 40, 'Your name');
        $password = (string) ($input['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
            fail('Usernames are 3–32 characters: letters, numbers, dot, dash or underscore.');
        }
        if (strlen($password) < 8) {
            fail('Use at least 8 characters for your password.');
        }
        $stmt = $db->prepare('SELECT 1 FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) {
            fail('That username is taken.', 409);
        }
        $db->prepare('INSERT INTO users (username, display_name, password_hash, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$username, $name, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $db->lastInsertId();
        sendJson(mePayload($db), 201);

    case 'login':
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $key = 'login:' . clientIp();
        if (isThrottled($db, $key)) {
            fail('Too many attempts — wait 15 minutes.', 429);
        }
        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            recordFailure($db, $key);
            fail('Wrong username or password.', 401);
        }
        clearFailures($db, $key);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        sendJson(mePayload($db));

    case 'logout':
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        sendJson(['ok' => true]);

    case 'state':
        requireUser($db);
        sendDay($db, requireDate($_GET['date'] ?? ''));

    case 'calendar':
        requireUser($db);
        $from = requireDate($_GET['from'] ?? '');
        $to = requireDate($_GET['to'] ?? '');
        $days = [];
        $stmt = $db->prepare('SELECT ride_date, COUNT(*) AS n, SUM(user_id = ? AND guest_name IS NULL) AS mine FROM bookings WHERE ride_date BETWEEN ? AND ? GROUP BY ride_date');
        $stmt->execute([currentUser($db)['id'], $from, $to]);
        foreach ($stmt->fetchAll() as $r) {
            $days[$r['ride_date']] = ['riders' => (int) $r['n'], 'mine' => (int) $r['mine'] > 0, 'vehicles' => 0, 'paid' => 0];
        }
        $stmt = $db->prepare('SELECT ride_date, COUNT(*) AS n, COUNT(paid_by) AS paid FROM vehicles WHERE ride_date BETWEEN ? AND ? GROUP BY ride_date');
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $r) {
            $days[$r['ride_date']] = ($days[$r['ride_date']] ?? ['riders' => 0, 'mine' => false])
                + ['vehicles' => 0, 'paid' => 0];
            $days[$r['ride_date']]['vehicles'] = (int) $r['n'];
            $days[$r['ride_date']]['paid'] = (int) $r['paid'];
        }
        sendJson(['days' => (object) $days]);

    case 'join':
        $me = requireUser($db);
        $date = requireDate($input['date'] ?? '');
        $pickup = cleanText($input['pickup'] ?? '', 80, 'Pickup location');
        $stmt = $db->prepare('SELECT 1 FROM bookings WHERE ride_date = ? AND user_id = ? AND guest_name IS NULL');
        $stmt->execute([$date, $me['id']]);
        if ($stmt->fetchColumn()) {
            fail('You are already on this day.', 409);
        }
        requireSeatAfterLock($db, $date);
        $db->prepare('INSERT INTO bookings (ride_date, user_id, guest_name, pickup, position, created_at) VALUES (?, ?, NULL, ?, ?, ?)')
            ->execute([$date, $me['id'], $pickup, nextPosition($db, 'bookings', $date), date('Y-m-d H:i:s')]);
        $db->prepare('UPDATE users SET default_pickup = ? WHERE id = ?')->execute([$pickup, $me['id']]);
        sendDay($db, $date, 201);

    case 'add_guest':
        $me = requireUser($db);
        $date = requireDate($input['date'] ?? '');
        $guest = cleanText($input['name'] ?? '', 40, 'Guest name');
        $pickup = cleanText($input['pickup'] ?? '', 80, 'Pickup location');
        requireSeatAfterLock($db, $date);
        $db->prepare('INSERT INTO bookings (ride_date, user_id, guest_name, pickup, position, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$date, $me['id'], $guest, $pickup, nextPosition($db, 'bookings', $date), date('Y-m-d H:i:s')]);
        sendDay($db, $date, 201);

    case 'cancel':
        $me = requireUser($db);
        $stmt = $db->prepare('SELECT ride_date, user_id FROM bookings WHERE id = ?');
        $stmt->execute([(int) ($input['id'] ?? 0)]);
        $b = $stmt->fetch();
        if (!$b) {
            fail('This booking no longer exists.', 404);
        }
        if ((int) $b['user_id'] !== $me['id']) {
            fail('You can only cancel your own seat or your guests.', 403);
        }
        if (isLocked($b['ride_date'])) {
            fail('Cancellations closed at 22:00 the evening before.', 403);
        }
        $db->prepare('DELETE FROM bookings WHERE id = ?')->execute([(int) $input['id']]);
        sendDay($db, $b['ride_date']);

    case 'reorder':
        requireUser($db);
        $date = requireDate($input['date'] ?? '');
        $order = isset($input['order']) && is_array($input['order']) ? array_map('intval', $input['order']) : [];
        if (isLocked($date)) {
            fail('Seating is locked for this date.', 403);
        }
        $stmt = $db->prepare('SELECT id FROM bookings WHERE ride_date = ?');
        $stmt->execute([$date]);
        $current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $sorted = $order;
        sort($current);
        sort($sorted);
        if (!$order || $current !== $sorted) {
            fail('The seating list changed — refresh and try again.', 409);
        }
        $db->beginTransaction();
        $stmt = $db->prepare('UPDATE bookings SET position = ? WHERE id = ? AND ride_date = ?');
        foreach ($order as $i => $id) {
            $stmt->execute([$i, $id, $date]);
        }
        $db->commit();
        sendDay($db, $date);

    case 'apply_suggestion':
        $me = requireUser($db);
        $date = requireDate($input['date'] ?? '');
        $rows = dayVehicleRows($db, $date);
        $types = $rows ? replanTypes($rows, riderCount($db, $date)) : proposeFleet(riderCount($db, $date));
        $db->beginTransaction();
        $db->prepare('DELETE FROM vehicles WHERE ride_date = ? AND booked_at IS NULL AND paid_by IS NULL')->execute([$date]);
        $kept = count(dayVehicleRows($db, $date));
        foreach (array_slice($types, $kept) as $type) {
            insertPlanned($db, $date, $type, $me);
        }
        $db->commit();
        sendDay($db, $date);

    case 'add_vehicle':
        $me = requireUser($db);
        $date = requireDate($input['date'] ?? '');
        $type = (string) ($input['type'] ?? '');
        if (!isset(VEHICLE_TYPES[$type])) {
            fail('Unknown vehicle type.');
        }
        $db->beginTransaction();
        materializePlan($db, $date, $me);
        insertPlanned($db, $date, $type, $me);
        $db->commit();
        sendDay($db, $date, 201);

    case 'update_vehicle':
        $me = requireUser($db);
        $db->beginTransaction();
        $v = targetVehicle($db, $input, $me);
        if ($v['paid_by'] !== null && (int) $v['paid_by'] !== $me['id']) {
            fail('Only ' . (userNames($db)[(int) $v['paid_by']] ?? 'the payer') . ' can change a paid vehicle.', 403);
        }
        $type = (string) ($input['type'] ?? $v['type']);
        if (!isset(VEHICLE_TYPES[$type])) {
            fail('Unknown vehicle type.');
        }
        $price = array_key_exists('price', $input) ? parsePriceCents($input['price']) : (int) $v['price_cents'];
        if ($type !== $v['type'] && !array_key_exists('price', $input)) {
            $price = VEHICLE_TYPES[$type]['defaultPriceCents'];
        }
        $db->prepare('UPDATE vehicles SET type = ?, price_cents = ? WHERE id = ?')->execute([$type, $price, (int) $v['id']]);
        $db->commit();
        sendDay($db, $v['ride_date']);

    case 'remove_vehicle':
        $me = requireUser($db);
        $db->beginTransaction();
        $v = targetVehicle($db, $input, $me);
        if ($v['paid_by'] !== null) {
            fail('A paid vehicle can’t be removed — undo the payment first.', 403);
        }
        $db->prepare('DELETE FROM vehicles WHERE id = ?')->execute([(int) $v['id']]);
        $db->commit();
        sendDay($db, $v['ride_date']);

    case 'book_vehicle':
        $me = requireUser($db);
        $db->beginTransaction();
        $v = targetVehicle($db, $input, $me);
        if ($v['booked_at'] === null) {
            $db->prepare('UPDATE vehicles SET booked_by = ?, booked_at = ? WHERE id = ?')->execute([$me['id'], date('Y-m-d H:i:s'), (int) $v['id']]);
        }
        $db->commit();
        sendDay($db, $v['ride_date']);

    case 'unbook_vehicle':
        requireUser($db);
        $v = vehicleRow($db, $input['id'] ?? 0);
        if ($v['paid_by'] !== null) {
            fail('This vehicle is paid — undo the payment first.', 409);
        }
        $db->prepare('UPDATE vehicles SET booked_by = NULL, booked_at = NULL WHERE id = ?')->execute([(int) $v['id']]);
        sendDay($db, $v['ride_date']);

    case 'pay_vehicle':
        $me = requireUser($db);
        $db->beginTransaction();
        $v = targetVehicle($db, $input, $me);
        if ($v['paid_by'] !== null) {
            fail('Already marked paid by ' . (userNames($db)[(int) $v['paid_by']] ?? 'someone') . '.', 409);
        }
        if (array_key_exists('price', $input)) {
            $db->prepare('UPDATE vehicles SET price_cents = ? WHERE id = ?')->execute([parsePriceCents($input['price']), (int) $v['id']]);
        }
        $now = date('Y-m-d H:i:s');
        // Paying for a vehicle means it is booked, too.
        $db->prepare('UPDATE vehicles SET paid_by = ?, paid_at = ?, booked_by = COALESCE(booked_by, ?), booked_at = COALESCE(booked_at, ?) WHERE id = ?')
            ->execute([$me['id'], $now, $me['id'], $now, (int) $v['id']]);
        $db->commit();
        sendDay($db, $v['ride_date']);

    case 'unpay_vehicle':
        $me = requireUser($db);
        $v = vehicleRow($db, $input['id'] ?? 0);
        if ($v['paid_by'] === null) {
            sendDay($db, $v['ride_date']);
        }
        if ((int) $v['paid_by'] !== $me['id']) {
            fail('Only the person who paid can undo it.', 403);
        }
        $db->prepare('UPDATE vehicles SET paid_by = NULL, paid_at = NULL WHERE id = ?')->execute([(int) $v['id']]);
        sendDay($db, $v['ride_date']);

    case 'balances':
        $me = requireUser($db);
        sendJson(balancesPayload($db, $me));

    case 'settle_add':
        $me = requireUser($db);
        $from = (int) ($input['from'] ?? $me['id']);
        $to = (int) ($input['to'] ?? 0);
        $names = userNames($db);
        if (!isset($names[$from]) || !isset($names[$to]) || $from === $to) {
            fail('Pick who paid whom.');
        }
        if ($from !== $me['id'] && $to !== $me['id']) {
            fail('You can only record payments you sent or received.', 403);
        }
        $amount = parsePriceCents($input['amount'] ?? '');
        if ($amount <= 0) {
            fail('Enter an amount above zero.');
        }
        $note = cleanText($input['note'] ?? '', 80, 'Note', false);
        $db->prepare('INSERT INTO settlements (from_user, to_user, amount_cents, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$from, $to, $amount, $note, $me['id'], date('Y-m-d H:i:s')]);
        sendJson(balancesPayload($db, $me), 201);

    case 'settle_delete':
        $me = requireUser($db);
        $stmt = $db->prepare('SELECT created_by FROM settlements WHERE id = ?');
        $stmt->execute([(int) ($input['id'] ?? 0)]);
        $creator = $stmt->fetchColumn();
        if ($creator === false) {
            fail('That payment no longer exists.', 404);
        }
        if ((int) $creator !== $me['id']) {
            fail('Only the person who recorded it can delete it.', 403);
        }
        $db->prepare('DELETE FROM settlements WHERE id = ?')->execute([(int) $input['id']]);
        sendJson(balancesPayload($db, $me));

    default:
        fail('Unknown action.');
}

function balancesPayload($db, $me) {
    $names = userNames($db);
    $bookings = $db->query('SELECT id, ride_date, user_id FROM bookings')->fetchAll();
    $vehicles = $db->query('SELECT id, ride_date, type, price_cents, paid_by FROM vehicles WHERE paid_by IS NOT NULL')->fetchAll();
    $settlements = $db->query('SELECT * FROM settlements ORDER BY created_at, id')->fetchAll();
    $ledger = computeLedger($bookings, $vehicles, $settlements);

    $users = [];
    foreach ($names as $id => $name) {
        $u = $ledger['users'][$id] ?? ['paid' => 0, 'share' => 0, 'sent' => 0, 'received' => 0, 'net' => 0];
        $users[] = ['id' => $id, 'name' => $name] + $u;
    }
    usort($users, function ($a, $b) { return $b['net'] - $a['net']; });

    $transfers = [];
    foreach (suggestTransfers($ledger['users']) as $t) {
        $transfers[] = $t + ['fromName' => $names[$t['from']] ?? '?', 'toName' => $names[$t['to']] ?? '?'];
    }

    $vehiclesByDate = [];
    foreach ($vehicles as $v) {
        $vehiclesByDate[$v['ride_date']][] = [
            'type' => $v['type'],
            'priceCents' => (int) $v['price_cents'],
            'paidBy' => $names[(int) $v['paid_by']] ?? '?',
        ];
    }
    $history = [];
    foreach ($ledger['days'] as $d) {
        $history[] = [
            'kind' => 'day',
            'date' => $d['date'],
            'totalCents' => $d['totalCents'],
            'riders' => $d['riders'],
            'myShareCents' => $d['sharesByUser'][$me['id']] ?? 0,
            'vehicles' => $vehiclesByDate[$d['date']],
            'sortKey' => $d['date'] . ' 99',
        ];
    }
    foreach ($settlements as $s) {
        $history[] = [
            'kind' => 'settlement',
            'id' => (int) $s['id'],
            'from' => $names[(int) $s['from_user']] ?? '?',
            'to' => $names[(int) $s['to_user']] ?? '?',
            'amountCents' => (int) $s['amount_cents'],
            'note' => $s['note'],
            'date' => substr($s['created_at'], 0, 10),
            'canDelete' => (int) $s['created_by'] === $me['id'],
            'sortKey' => $s['created_at'],
        ];
    }
    usort($history, function ($a, $b) { return strcmp($b['sortKey'], $a['sortKey']); });

    return [
        'me' => $me['id'],
        'users' => $users,
        'transfers' => $transfers,
        'history' => $history,
        'unsplit' => $ledger['unsplit'],
    ];
}
