CREATE TABLE IF NOT EXISTS users (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    token        TEXT UNIQUE NOT NULL,
    display_name TEXT NOT NULL,
    bio          TEXT DEFAULT '',
    avatar_data  BLOB DEFAULT NULL,
    avatar_mime  TEXT DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen    DATETIME DEFAULT CURRENT_TIMESTAMP
)
CREATE TABLE IF NOT EXISTS forums (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT UNIQUE,
    admin_id   INTEGER DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    forum_id   INTEGER,
    author     TEXT,
    user_id    INTEGER,
    content    TEXT,
    image_data BLOB DEFAULT NULL,
    image_mime TEXT DEFAULT NULL,
    votes      INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
CREATE TABLE IF NOT EXISTS votes (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    voter   TEXT,
    value   INTEGER,
    UNIQUE(post_id, voter)
)
CREATE TABLE IF NOT EXISTS comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER,
    parent_id  INTEGER DEFAULT NULL,
    author     TEXT,
    user_id    INTEGER,
    content    TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)