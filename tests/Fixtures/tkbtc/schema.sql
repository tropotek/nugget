-- The tables of a tkbtc database that the import reads, as SQLite, for the
-- import's tests. The columns are tkbtc's own (its src/config/sql/migrate,
-- and tk-base's for auth), with dates and decimals as text.
-- Every row a test puts in is made up.

CREATE TABLE user (
    user_id INTEGER PRIMARY KEY,
    type TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '',
    given_name TEXT NOT NULL DEFAULT '',
    family_name TEXT NOT NULL DEFAULT '',
    modified TEXT,
    created TEXT
);

CREATE TABLE auth (
    auth_id INTEGER PRIMARY KEY,
    uid TEXT NOT NULL DEFAULT '',
    fkey TEXT NOT NULL DEFAULT '',
    fid INTEGER NOT NULL DEFAULT 0,
    permissions INTEGER NOT NULL DEFAULT 0,
    username TEXT NOT NULL DEFAULT '',
    password TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    timezone TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    external TEXT NOT NULL DEFAULT '',
    session_id TEXT NOT NULL DEFAULT '',
    last_login TEXT,
    modified TEXT,
    created TEXT
);

CREATE TABLE exchange (
    exchange_id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    driver TEXT NOT NULL DEFAULT '',
    username TEXT NOT NULL DEFAULT '',
    api_key TEXT NOT NULL DEFAULT '',
    secret TEXT NOT NULL DEFAULT '',
    icon TEXT NOT NULL DEFAULT '',
    credit TEXT NOT NULL DEFAULT '0',
    description TEXT,
    active INTEGER NOT NULL DEFAULT 0,
    options TEXT,
    modified TEXT,
    created TEXT
);

CREATE TABLE market (
    market_id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL DEFAULT 0,
    exchange_id INTEGER,
    name TEXT NOT NULL DEFAULT '',
    api_id TEXT NOT NULL DEFAULT '',
    symbol TEXT NOT NULL DEFAULT '',
    base TEXT NOT NULL DEFAULT '',
    quote TEXT NOT NULL DEFAULT 'AUD',
    bid TEXT NOT NULL DEFAULT '0',
    ask TEXT NOT NULL DEFAULT '0',
    active INTEGER NOT NULL DEFAULT 0,
    modified TEXT,
    created TEXT
);

CREATE TABLE asset (
    asset_id INTEGER PRIMARY KEY,
    market_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    units TEXT NOT NULL DEFAULT '0',
    in_total INTEGER NOT NULL DEFAULT 0,
    auto_update INTEGER NOT NULL DEFAULT 0,
    notes TEXT,
    modified TEXT,
    created TEXT
);

CREATE TABLE asset_log (
    asset_id INTEGER NOT NULL,
    units TEXT NOT NULL DEFAULT '0',
    total TEXT NOT NULL DEFAULT '0',
    created TEXT NOT NULL,
    PRIMARY KEY (asset_id, created)
);

CREATE TABLE tick (
    market_id INTEGER NOT NULL,
    bid TEXT NOT NULL DEFAULT '0',
    ask TEXT NOT NULL DEFAULT '0',
    created TEXT NOT NULL,
    PRIMARY KEY (market_id, created)
);
