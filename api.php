<?php
// Buggy Trip Sign-Up — API (Azure App Service version, SQLite storage, no database server needed)
// Every booking belongs to one ride date. Each action works on a single date only:
//   GET  api.php?action=state&date=YYYY-MM-DD                      -> bookings for that date
//   POST {"action":"join","date":"YYYY-MM-DD","name":"...","pickup":"..."}
//   POST {"action":"cancel","id":123}
//   POST {"action":"reorder","date":"YYYY-MM-DD","order":[ids...]}
//
// Storage: one SQLite file, created automatically on the first request.
//   On Azure App Service: /home/data/buggy-trip.sqlite (persistent, outside the web root, so it
//   cannot be downloaded). Elsewhere: a "buggy-trip-data" folder next to the web root.
//   Override with the app setting BUGGY_DB_PATH.

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

function sendJson($data, $statusCode) {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

set_exception_handler(function ($e) {
    error_log('Buggy Trip API exception: ' . $e->getMessage());
    sendJson(['error' => 'Server error — check the application log for details.'], 500);
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log('Buggy Trip API PHP error: ' . $errstr . ' in ' . $errfile . ' on line ' . $errline);
    return true; // keep warnings out of the JSON response
});

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!extension_loaded('pdo_sqlite')) {
    sendJson(['error' => 'The PHP pdo_sqlite extension is not available on this server.'], 500);
}

function databasePath() {
    $custom = getenv('BUGGY_DB_PATH');
    if ($custom) {
        return $custom;
    }
    if (getenv('WEBSITE_SITE_NAME')) { // set by Azure App Service
        return '/home/data/buggy-trip.sqlite';
    }
    return dirname(__DIR__) . '/buggy-trip-data/buggy-trip.sqlite';
}

$dbPath = databasePath();
$dbDir = dirname($dbPath);
if (!is_dir($dbDir) && !mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
    error_log('Buggy Trip API: cannot create data folder ' . $dbDir);
    sendJson(['error' => 'Cannot create the data folder on the server.'], 500);
}

// Azure keeps /home on a network share whose file locks SQLite cannot rely on. So every request
// takes an exclusive lock on a local file instead (one request at a time — each takes a few
// milliseconds), and SQLite's own locking is switched off with the "unix-none" VFS.
$lockHandle = fopen(sys_get_temp_dir() . '/buggy-trip-' . md5($dbPath) . '.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {
    sendJson(['error' => 'Server is busy — try again.'], 503);
}

try {
    $db = new PDO('sqlite:file:' . $dbPath . '?vfs=unix-none');
} catch (PDOException $e) {
    $db = new PDO('sqlite:' . $dbPath); // PHP versions without URI support
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA journal_mode = DELETE');
$db->exec('PRAGMA synchronous = FULL');

$db->exec('CREATE TABLE IF NOT EXISTS participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ride_date TEXT NOT NULL,
    name TEXT NOT NULL,
    pickup TEXT NOT NULL,
    joined_at TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_ride_date_position ON participants (ride_date, position)');

// Returns the value if it is a real calendar date in YYYY-MM-DD form, otherwise null.
function parseDate($value) {
    if (!is_string($value)) {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

function getParticipants($db, $date) {
    $stmt = $db->prepare('SELECT id, name, pickup, joined_at FROM participants WHERE ride_date = ? ORDER BY position ASC, id ASC');
    $stmt->execute([$date]);
    $list = [];
    foreach ($stmt->fetchAll() as $row) {
        $list[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'pickup' => $row['pickup'],
            'joinedAt' => $row['joined_at'],
        ];
    }
    return $list;
}

function getIdsForDate($db, $date) {
    $stmt = $db->prepare('SELECT id FROM participants WHERE ride_date = ?');
    $stmt->execute([$date]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function sendState($db, $date, $statusCode = 200) {
    sendJson([
        'date' => $date,
        'participants' => getParticipants($db, $date),
    ], $statusCode);
}

function sendError($message, $statusCode) {
    sendJson(['error' => $message], $statusCode);
}

// Cancellations and seat moves for a date close at 22:00 on the day before that date.
function isCancellationLocked($rideDate) {
    $ride = DateTime::createFromFormat('!Y-m-d', $rideDate);
    if (!$ride) {
        return false;
    }
    $cutoff = clone $ride;
    $cutoff->modify('-1 day');
    $cutoff->setTime(22, 0, 0);
    return new DateTime() >= $cutoff;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = [];

if ($method === 'POST') {
    $decoded = json_decode(file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : $_POST;
    $action = isset($input['action']) ? $input['action'] : '';
} else {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
}

switch ($action) {

    case 'state':
        $date = parseDate($_GET['date'] ?? '');
        if ($date === null) {
            sendError('A valid date (YYYY-MM-DD) is required.', 400);
        }
        sendState($db, $date);
        break;

    case 'join':
        $date = parseDate($input['date'] ?? '');
        $name = trim((string) ($input['name'] ?? ''));
        $pickup = trim((string) ($input['pickup'] ?? ''));

        if ($date === null) {
            sendError('A valid date (YYYY-MM-DD) is required.', 400);
        }
        if ($name === '' || $pickup === '') {
            sendError('Name and pickup location are required.', 400);
        }
        if (strlen($name) > 100 || strlen($pickup) > 150) {
            sendError('Name or pickup location is too long.', 400);
        }

        $stmt = $db->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM participants WHERE ride_date = ?');
        $stmt->execute([$date]);
        $nextPos = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('INSERT INTO participants (ride_date, name, pickup, joined_at, position) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$date, $name, $pickup, date('Y-m-d H:i:s'), $nextPos]);
        if ($stmt->rowCount() < 1) {
            sendError('The join did not save — try again.', 500);
        }

        sendState($db, $date, 201);
        break;

    case 'cancel':
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            sendError('A valid participant id is required.', 400);
        }

        $stmt = $db->prepare('SELECT ride_date FROM participants WHERE id = ?');
        $stmt->execute([$id]);
        $bookingDate = $stmt->fetchColumn();

        if ($bookingDate === false) {
            sendError('This booking no longer exists.', 404);
        }
        if (isCancellationLocked($bookingDate)) {
            sendError('Cancellations are closed for this date.', 403);
        }

        $db->prepare('DELETE FROM participants WHERE id = ?')->execute([$id]);
        sendState($db, $bookingDate);
        break;

    case 'reorder':
        $date = parseDate($input['date'] ?? '');
        $order = isset($input['order']) && is_array($input['order']) ? array_map('intval', $input['order']) : null;
        if ($date === null) {
            sendError('A valid date (YYYY-MM-DD) is required.', 400);
        }
        if ($order === null || count($order) === 0) {
            sendError('An order array is required.', 400);
        }
        if (isCancellationLocked($date)) {
            sendError('Seating is locked for this date.', 403);
        }

        $sortedCurrent = getIdsForDate($db, $date);
        $sortedOrder = $order;
        sort($sortedCurrent);
        sort($sortedOrder);
        if ($sortedCurrent !== $sortedOrder) {
            sendError('The seating list changed — refresh and try again.', 409);
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE participants SET position = ? WHERE id = ? AND ride_date = ?');
            foreach ($order as $index => $id) {
                $stmt->execute([$index, $id, $date]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Buggy Trip API reorder failed: ' . $e->getMessage());
            sendError('Could not save the new seating.', 500);
        }

        sendState($db, $date);
        break;

    default:
        sendError('Unknown action.', 400);
}
