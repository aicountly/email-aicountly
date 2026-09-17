#!/usr/bin/env python3
"""Stamp the built index.html with the commit it came from.

The marker is what makes a deploy verifiable: without it, "the site is up" and
"the site is running what I just built" are the same observation, and they are
not the same fact. scripts/verify-release.py reads it back from the live origin
after the deploy and fails the job when it does not match.

  RELEASE_ID=<40-hex commit sha> python3 scripts/stamp-release.py [dist-dir]
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

release = os.environ.get("RELEASE_ID", "").strip()
if not re.fullmatch(r"[0-9a-f]{40}", release):
    raise SystemExit("RELEASE_ID must be a 40-character lowercase commit sha")

dist = Path(sys.argv[1] if len(sys.argv) > 1 else "web/dist")
index = dist / "index.html"

if not index.is_file():
    raise SystemExit(f"{index} does not exist — the build produced no output")

html = index.read_text(encoding="utf-8")

if "</head>" not in html:
    raise SystemExit("The built index.html has no closing </head> to stamp")

# Re-stamping the same file would leave two markers and the parser would read
# whichever came last. Removing any existing one first makes this idempotent.
html = re.sub(r'\s*<meta name="aicountly-release" content="[^"]*">', "", html)

tag = f'<meta name="aicountly-release" content="{release}">'
index.write_text(html.replace("</head>", f"  {tag}\n  </head>", 1), encoding="utf-8")

print(f"Stamped {index} with release {release}")
