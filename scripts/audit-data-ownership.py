#!/usr/bin/env python3
"""Fail the build if Email has started keeping another product's data.

The architecture rests on one rule: business data owned by another AICOUNTLY
product is READ LIVE over its API and never copied into Email's database. That
rule is easy to state, easy to agree with, and easy to break six months later
with a table called `email_contacts_cache` that somebody added to make a screen
faster.

This is the check that catches that. It runs in CI on every build and it looks
for the four shapes the breakage actually takes:

  1. a schema table that mirrors another product's domain
  2. a second database connection, or a DSN pointing somewhere that is not
     Email's own schema
  3. a scheduled job that walks another product's data
  4. a stored payload — a column that would hold a fetched business record

It also checks the built bundle, when there is one, for the two things that
must never ship: a secret, and a development fixture.

  python3 scripts/audit-data-ownership.py [--dist web/dist]
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# --- 1. Tables Email must not own -------------------------------------------
#
# Each name here belongs to another product. A CREATE TABLE for one of them in
# Email's schema is a mirror, whatever it is called.
FORBIDDEN_TABLES = {
    "contact": "Contacts owns the party directory.",
    "contacts": "Contacts owns the party directory.",
    "customer": "Contacts owns parties; Books owns their ledger.",
    "customers": "Contacts owns parties; Books owns their ledger.",
    "supplier": "Contacts owns parties; Purchase owns the procurement profile.",
    "suppliers": "Contacts owns parties; Purchase owns the procurement profile.",
    "calendar_event": "Calendar owns events.",
    "calendar_events": "Calendar owns events.",
    "event": "Calendar owns events.",
    "events": "Calendar owns events.",
    "item": "Inventory owns items.",
    "items": "Inventory owns items.",
    "stock": "Inventory owns stock.",
    "stock_balance": "Inventory owns stock.",
    "stock_balances": "Inventory owns stock.",
    "invoice": "Books owns invoices and their totals.",
    "invoices": "Books owns invoices and their totals.",
    "voucher": "Books owns vouchers.",
    "vouchers": "Books owns vouchers.",
    "ledger": "Books owns the ledger.",
    "purchase_order": "Purchase owns purchase orders.",
    "purchase_orders": "Purchase owns purchase orders.",
    "sales_order": "Sales owns sales orders.",
    "sales_orders": "Sales owns sales orders.",
    "company": "Manage owns companies.",
    "companies": "Manage owns companies.",
    "branch": "Manage owns branches.",
    "branches": "Manage owns branches.",
    "financial_year": "Manage owns financial years.",
    "payment": "Pay owns payment orchestration.",
    "payments": "Pay owns payment orchestration.",
}

# Columns that would hold a copy of somebody else's record rather than a
# reference to it.
FORBIDDEN_COLUMN_PATTERNS = [
    (re.compile(r"\b(cached|mirrored|replicated|synced|snapshot)_\w+", re.I), "a cached copy of external data"),
    (re.compile(r"\b\w*_(cache|mirror|replica|snapshot)\b", re.I), "a cache of external data"),
    (re.compile(r"\bexternal_(record|payload|document|body)\b", re.I), "a stored external payload"),
]

# --- 3. Scheduled work that would be synchronisation --------------------------
SYNC_PATTERNS = [
    (re.compile(r"\bcron\b.*\b(sync|import|pull|replicate|mirror)\b", re.I), "a cron that synchronises"),
    (re.compile(r"\b(sync|import|replicate|mirror)(All|Contacts|Items|Invoices|Calendar|Companies)\b"), "a bulk import of another product's data"),
    (re.compile(r"\bCDC\b|\blogical replication\b|\bpg_dump\b.*\b(books|inventory|manage|contacts)\b", re.I), "database replication"),
]

# --- 2. A second database -------------------------------------------------------
FOREIGN_DB_PATTERNS = [
    (re.compile(r"(pgsql|mysql):[^'\"]*dbname=(?!\$)[^'\";]*(books|inventory|manage|contacts|sales|purchase|billing|pay)", re.I),
     "a DSN pointing at another product's database"),
    (re.compile(r"\bpostgres_fdw\b|\bdblink\b|\bCREATE\s+SERVER\b", re.I), "a foreign data wrapper"),
]

SCHEMA_FILES = sorted((ROOT / "server-php" / "database" / "migrations").glob("*.sql"))
CODE_FILES = [
    *sorted((ROOT / "server-php").rglob("*.php")),
    *sorted((ROOT / "web" / "src").rglob("*.ts")),
    *sorted((ROOT / "web" / "src").rglob("*.tsx")),
]

findings: list[str] = []


def audit_schema() -> None:
    create_table = re.compile(r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[\"']?([a-z0-9_]+)[\"']?", re.I)

    for path in SCHEMA_FILES:
        text = path.read_text(encoding="utf-8")

        for match in create_table.finditer(text):
            table = match.group(1).lower()
            line = text[: match.start()].count("\n") + 1

            if not table.startswith("email_"):
                findings.append(
                    f"{path.relative_to(ROOT)}:{line}: table `{table}` is not prefixed `email_`. "
                    "Every table Email owns says so in its name."
                )
                continue

            bare = table[len("email_"):]
            if bare in FORBIDDEN_TABLES:
                findings.append(
                    f"{path.relative_to(ROOT)}:{line}: table `{table}` mirrors another product. "
                    f"{FORBIDDEN_TABLES[bare]} Read it live instead."
                )

        for pattern, description in FORBIDDEN_COLUMN_PATTERNS:
            for match in pattern.finditer(text):
                line = text[: match.start()].count("\n") + 1
                findings.append(
                    f"{path.relative_to(ROOT)}:{line}: `{match.group(0)}` looks like {description}."
                )


def audit_code() -> None:
    for path in CODE_FILES:
        text = path.read_text(encoding="utf-8")
        # This file names every pattern it forbids, so auditing it finds itself.
        if path.name == "audit-data-ownership.py":
            continue

        for pattern, description in FOREIGN_DB_PATTERNS + SYNC_PATTERNS:
            for match in pattern.finditer(text):
                line = text[: match.start()].count("\n") + 1
                snippet = match.group(0).strip()[:80]
                # A comment explaining why something is forbidden is not the
                # thing being forbidden — this file's own rules are full of
                # those words, and so are the ones in src/ that say why.
                lines = text.splitlines()
                context = lines[line - 1] if line - 1 < len(lines) else ""
                if context.lstrip().startswith(("*", "//", "#", "/*")):
                    continue
                findings.append(f"{path.relative_to(ROOT)}:{line}: {description} — `{snippet}`")


def audit_bundle(dist: Path) -> None:
    if not dist.is_dir():
        print(f"  (no build at {dist} — skipping the bundle checks)")

        return

    secret_patterns = [
        (re.compile(r"\bAKIA[0-9A-Z]{16}\b"), "an AWS access key id"),
        (re.compile(r"\bAIza[0-9A-Za-z_\-]{35}\b"), "a Google API key"),
        (re.compile(r"\bsk-[A-Za-z0-9]{32,}\b"), "a model provider key"),
        (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"), "a private key"),
        (re.compile(r"\b(MAIL_SMTP_PASSWORD|MAIL_CREDENTIAL_KEY|EMAIL_AI_API_KEY|SERVICE_KEYS|DB_PASS)\b"),
         "the NAME of a server-only setting, which means server config reached the bundle"),
    ]
    fixture_patterns = [
        (re.compile(r"Illustrative data"), "a design-reference marker"),
        (re.compile(r"preview-auth-token"), "a test auth token"),
        (re.compile(r"\bApex Components\b|\bPO-1048\b|\bMeera Shah\b"), "mockup fixture data"),
    ]

    for path in sorted(dist.rglob("*")):
        if not path.is_file() or path.suffix not in {".js", ".css", ".html", ".json"}:
            continue
        text = path.read_text(encoding="utf-8", errors="replace")

        for pattern, description in secret_patterns + fixture_patterns:
            match = pattern.search(text)
            if match:
                findings.append(f"{path.relative_to(ROOT)}: {description} found in the built bundle (`{match.group(0)[:40]}`).")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dist", default="web/dist")
    args = parser.parse_args()

    print("Auditing Email for cross-application data replication…")
    audit_schema()
    audit_code()
    print("Auditing the built bundle for secrets and fixtures…")
    audit_bundle(ROOT / args.dist)

    if findings:
        print("\nFAILED — Email must not hold another product's data:\n")
        for finding in findings:
            print(f"  {finding}")
        print(
            "\nRead docs/EMAIL_DATA_OWNERSHIP.md. If one of these is a false positive, the fix is to "
            "make the code obviously compliant, not to widen the audit."
        )

        return 1

    print(f"\nOK — {len(SCHEMA_FILES)} migration(s) and {len(CODE_FILES)} source file(s) audited, nothing found.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
