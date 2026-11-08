<?php
// Database schema. Version 2 replaces the old name-only `participants` table (its rows are dropped).
// Version 3: a vehicle is first planned, then booked (booked_by/booked_at NULL = only planned).
defined('BUGGY') or exit;

const SCHEMA_VERSION = 3;

const VEHICLES_TABLE = 'CREATE TABLE IF NOT EXISTS vehicles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ride_date TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN (\'buggy\', \'hilux\')),
    price_cents INTEGER NOT NULL CHECK (price_cents >= 0),
    position INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER NOT NULL REFERENCES users(id),
    booked_by INTEGER REFERENCES users(id),
    booked_at TEXT,
    paid_by INTEGER REFERENCES users(id),
    paid_at TEXT
)';

function migrate(PDO $db) {
    $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
    if ($version >= SCHEMA_VERSION) {
        return;
    }
    $db->beginTransaction();
    try {
        if ($version === 2) {
            // v2 vehicles were always booked; keep them, but allow planned (unbooked) ones from now on.
            $db->exec('ALTER TABLE vehicles RENAME TO vehicles_v2');
            $db->exec(VEHICLES_TABLE);
            $db->exec('INSERT INTO vehicles (id, ride_date, type, price_cents, position, created_by, booked_by, booked_at, paid_by, paid_at)
                       SELECT id, ride_date, type, price_cents, position, booked_by, booked_by, booked_at, paid_by, paid_at FROM vehicles_v2');
            $db->exec('DROP TABLE vehicles_v2');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_vehicles_date ON vehicles (ride_date, position)');
            $db->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
            $db->commit();
            return;
        }

        $db->exec('DROP TABLE IF EXISTS participants');

        $db->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            display_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            default_pickup TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL
        )');

        // guest_name NULL = the user's own seat; otherwise a guest the user brought (and pays for).
        $db->exec('CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ride_date TEXT NOT NULL,
            user_id INTEGER NOT NULL REFERENCES users(id),
            guest_name TEXT,
            pickup TEXT NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bookings_date ON bookings (ride_date, position)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_bookings_self ON bookings (ride_date, user_id) WHERE guest_name IS NULL');

        $db->exec(VEHICLES_TABLE);
        $db->exec('CREATE INDEX IF NOT EXISTS idx_vehicles_date ON vehicles (ride_date, position)');

        $db->exec('CREATE TABLE IF NOT EXISTS settlements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_user INTEGER NOT NULL REFERENCES users(id),
            to_user INTEGER NOT NULL REFERENCES users(id),
            amount_cents INTEGER NOT NULL CHECK (amount_cents > 0),
            note TEXT NOT NULL DEFAULT \'\',
            created_by INTEGER NOT NULL REFERENCES users(id),
            created_at TEXT NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_key TEXT NOT NULL,
            attempted_at TEXT NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts (attempt_key)');

        $db->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}
