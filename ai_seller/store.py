from pathlib import Path
import json
import sqlite3
from datetime import datetime, timezone


DB_PATH = Path('data/ai_seller.sqlite3')


def connect():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    db = sqlite3.connect(DB_PATH)
    db.row_factory = sqlite3.Row
    db.execute('''CREATE TABLE IF NOT EXISTS conversations (
        id TEXT PRIMARY KEY, state_json TEXT NOT NULL, updated_at TEXT NOT NULL
    )''')
    db.execute('''CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id TEXT NOT NULL,
        role TEXT NOT NULL, text TEXT NOT NULL, metadata_json TEXT NOT NULL,
        created_at TEXT NOT NULL
    )''')
    db.commit()
    return db


def load_state(conversation_id: str) -> dict:
    with connect() as db:
        row = db.execute('SELECT state_json FROM conversations WHERE id=?', (conversation_id,)).fetchone()
        return json.loads(row['state_json']) if row else {}


def save_state(conversation_id: str, state: dict) -> None:
    now = datetime.now(timezone.utc).isoformat()
    with connect() as db:
        db.execute('''INSERT INTO conversations(id,state_json,updated_at) VALUES(?,?,?)
            ON CONFLICT(id) DO UPDATE SET state_json=excluded.state_json, updated_at=excluded.updated_at''',
            (conversation_id, json.dumps(state, ensure_ascii=False), now))
        db.commit()


def add_message(conversation_id: str, role: str, text: str, metadata: dict | None = None) -> None:
    now = datetime.now(timezone.utc).isoformat()
    with connect() as db:
        db.execute('INSERT INTO messages(conversation_id,role,text,metadata_json,created_at) VALUES(?,?,?,?,?)',
                   (conversation_id, role, text, json.dumps(metadata or {}, ensure_ascii=False), now))
        db.commit()


def recent_messages(conversation_id: str, limit: int = 20) -> list[dict]:
    with connect() as db:
        rows = db.execute('SELECT role,text,metadata_json FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT ?',
                          (conversation_id, limit)).fetchall()
    return [dict(role=r['role'], text=r['text'], metadata=json.loads(r['metadata_json'])) for r in reversed(rows)]

