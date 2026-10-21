"""Append-only audit trail.

Each row stores the hash of the previous row, so any edit made outside the app (the database triggers
block edits through it) breaks the chain and shows up in `flask --app backend verify-audit`.
Never put passwords, tokens, authentication codes or document contents in `detail`.
"""
import hashlib
import json

from flask import g, has_request_context, request

from .db import execute, now, query

GENESIS = "0" * 64
_FORBIDDEN = ("password", "token", "secret", "code", "otp")


def _clean(detail):
    clean = {}
    for k, v in (detail or {}).items():
        if any(word in k.lower() for word in _FORBIDDEN):
            continue  # belt and braces: secrets never reach the log even if a caller passes one
        clean[k] = v if isinstance(v, (int, float, bool)) or v is None else str(v)[:300]
    return json.dumps(clean, sort_keys=True, ensure_ascii=False)


def sha256_text(value):
    return hashlib.sha256(str(value).encode()).hexdigest()


def _raw(prev, created_at, actor_id, event, target_type, target_id, detail):
    return "|".join("" if x is None else str(x) for x in (prev, created_at, actor_id, event, target_type, target_id, detail))


def _row_hash(*parts):
    return sha256_text(_raw(*parts))


def client_ip():
    return (request.remote_addr or "")[:64] if has_request_context() else ""


def log(event, target_type="", target_id=None, actor=None, **detail):
    if actor is None and has_request_context():
        actor = g.get("user")
    actor_id = actor["id"] if actor else None
    actor_role = actor["role"] if actor else ""
    org = g.get("org")
    org_id = org["id"] if org is not None else (actor["org_id"] if actor is not None and "org_id" in actor.keys() else None)
    if org_id is not None:
        detail = {"org": org_id, **detail}  # inside the hashed detail, so the organisation can't be altered silently
    stamp = now()
    body = _clean(detail)
    # One statement reads the previous hash and writes the new row, so concurrent requests can't fork the chain.
    execute(
        "INSERT INTO audit_log (org_id, created_at, actor_id, actor_role, event, target_type, target_id, detail, ip, prev_hash, row_hash) "
        "SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, p.h, lmd_sha256(p.h || '|' || ? || '|' || ? || '|' || ? || '|' || ? || '|' || ? || '|' || ?) "
        "FROM (SELECT COALESCE((SELECT row_hash FROM audit_log ORDER BY id DESC LIMIT 1), ?) AS h) p",
        (org_id, stamp, actor_id, actor_role, event, target_type, target_id, body, client_ip(),
         stamp, "" if actor_id is None else actor_id, event, target_type, "" if target_id is None else target_id, body,
         GENESIS))


def verify_chain():
    """Return (ok, number_checked, first_bad_id)."""
    prev = GENESIS
    rows = query("SELECT * FROM audit_log ORDER BY id")
    for r in rows:
        expected = _row_hash(prev, r["created_at"], r["actor_id"], r["event"], r["target_type"], r["target_id"], r["detail"])
        if r["prev_hash"] != prev or r["row_hash"] != expected:
            return False, len(rows), r["id"]
        prev = r["row_hash"]
    return True, len(rows), None
