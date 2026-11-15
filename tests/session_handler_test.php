<?php
// Run: docker compose run --rm web php tests/session_handler_test.php
PHP_SAPI === 'cli' or exit;
define('BUGGY', true);
require __DIR__ . '/../lib/session.php';

ob_start(); // keep PHP warnings out of the way of session_start(); they are reported at the end

$fails = 0;
$messages = [];
function check($label, $actual, $expected) {
    global $fails, $messages;
    if ($actual !== $expected) {
        $fails++;
        $messages[] = "FAIL $label\n  expected " . json_encode($expected) . "\n  got      " . json_encode($actual);
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$h = new SqliteSessionHandler($db, 86400);

// Handler on its own
check('read of an unknown id', $h->read('nope'), '');
check('validateId of an unknown id', $h->validateId('nope'), false);
$h->write('abc', 'csrf|s:1:"x";');
check('read after write', $h->read('abc'), 'csrf|s:1:"x";');
check('validateId of a known id', $h->validateId('abc'), true);
$h->write('abc', 'second');
check('write replaces the data', $h->read('abc'), 'second');
check('destroy succeeds', $h->destroy('abc'), true);
check('read after destroy', $h->read('abc'), '');

// Expiry and cleanup
$h->write('old', 'data');
$db->exec('UPDATE sessions SET expires = ' . (time() - 10) . " WHERE id = 'old'");
check('an expired session is not readable', $h->read('old'), '');
check('an expired session is not valid', $h->validateId('old'), false);
$h->write('fresh', 'data');
check('gc removes only the expired session', $h->gc(0), 1);
check('a fresh session survives gc', $h->read('fresh'), 'data');

// updateTimestamp renews a session that is close to expiring, and skips the write otherwise
$expires = function ($id) use ($db) {
    return (int) $db->query("SELECT expires FROM sessions WHERE id = '$id'")->fetchColumn();
};
$db->exec('UPDATE sessions SET expires = ' . (time() + 100) . " WHERE id = 'fresh'");
$h->updateTimestamp('fresh', 'data');
check('updateTimestamp renews a nearly expired session', $expires('fresh') >= time() + 86400 - 5, true);
$renewed = $expires('fresh');
$h->updateTimestamp('fresh', 'data');
check('updateTimestamp skips the write while the session is fresh', $expires('fresh'), $renewed);

// Through PHP's own session functions, in the order the app uses them:
// first visit, then the correct group password (session_regenerate_id(true)), then reloads.
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.cache_limiter', '');
session_set_save_handler($h, true);

function visit($id, callable $fn) {
    if ($id !== null) {
        session_id($id);
    }
    session_start();
    $fn();
    $id = session_id();
    session_write_close();
    $_SESSION = [];
    return $id;
}

$id1 = visit(null, function () { $_SESSION['csrf'] = 'token'; });
$id2 = visit($id1, function () { $_SESSION['gate_ok'] = true; session_regenerate_id(true); });
check('session_regenerate_id(true) issues a new id', $id2 !== $id1, true);

$seen = null;
visit($id2, function () use (&$seen) { $seen = $_SESSION; });
check('data set before and after regenerating is kept', $seen, ['csrf' => 'token', 'gate_ok' => true]);

$old = null;
$id3 = visit($id1, function () use (&$old) { $old = $_SESSION; });
check('the old id no longer holds the session', $old, []);
check('an unknown id is replaced by a new one', $id3 !== $id1 && $id3 !== $id2, true);

$noise = ob_get_clean();
if ($noise !== '') {
    $fails++;
    $messages[] = "Unexpected PHP output:\n" . $noise;
}
echo $messages ? implode("\n", $messages) . "\n" : '';
echo $fails ? "$fails failure(s)\n" : "All tests passed\n";
exit($fails ? 1 : 0);
