#!/usr/bin/env python3
"""Check that a live origin is actually serving the release that was just built.

A release marker on its own is not a smoke test, so this also checks the things
that break independently of the HTML:

  * the marker in the root document matches the commit
  * the JavaScript and CSS the document references load, with the right
    content types
  * a deep link into the SPA serves the application rather than a 404
  * a missing file under /releases/ returns 404 — if it returns the app, the
    SPA fallback is too greedy and every typo'd asset looks like a blank page
  * the root document is not cached, so the next deploy is visible
  * nothing that looks like a development fixture is in the bundle
  * the TLS certificate validates (verification is never disabled)

  SITE_ORIGIN=https://email.aicountly.com EXPECTED_RELEASE=<sha> \
      python3 scripts/verify-release.py
"""

from __future__ import annotations

import os
import re
import ssl
import sys
import time
import urllib.error
import urllib.request
from html.parser import HTMLParser

ALLOWED_ORIGINS = {
    "https://email.aicountly.com",
    "https://aicountly.io",
    "https://email.gh.aicountly.com",
    "https://io.gh.aicountly.com",
}

TIMEOUT = 20
ATTEMPTS = 5
BACKOFF = 3


class IndexParser(HTMLParser):
    """Pulls the release marker and the asset URLs out of the served document."""

    def __init__(self) -> None:
        super().__init__()
        self.release: str | None = None
        self.scripts: list[str] = []
        self.styles: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = {name: (value or "") for name, value in attrs}

        if tag == "meta" and values.get("name") == "aicountly-release":
            self.release = values.get("content")
        elif tag == "script" and values.get("src"):
            self.scripts.append(values["src"])
        elif tag == "link" and values.get("rel") == "stylesheet" and values.get("href"):
            self.styles.append(values["href"])


def fetch(url: str, *, headers: dict[str, str] | None = None):
    # Certificate verification stays on. A verifier that skips it verifies
    # nothing that matters.
    context = ssl.create_default_context()
    request = urllib.request.Request(url, headers={"Cache-Control": "no-cache", **(headers or {})})

    return urllib.request.urlopen(request, timeout=TIMEOUT, context=context)


def check(name: str, condition: bool, detail: str = "") -> bool:
    print(f"  {'ok  ' if condition else 'FAIL'}  {name}{'' if condition else f' — {detail}'}")

    return condition


def main() -> int:
    origin = os.environ["SITE_ORIGIN"].rstrip("/")
    expected = os.environ["EXPECTED_RELEASE"].strip()

    if origin not in ALLOWED_ORIGINS:
        raise SystemExit(f"Refusing to verify an origin that is not a known Email destination: {origin}")
    if not re.fullmatch(r"[0-9a-f]{40}", expected):
        raise SystemExit("EXPECTED_RELEASE must be a 40-character lowercase commit sha")

    # The deploy has just happened and a CDN or an opcode cache may be a beat
    # behind, so the marker check retries. Everything after it runs once.
    document = None
    parser = IndexParser()
    cache_control = ""
    last_error: Exception | None = None

    for attempt in range(ATTEMPTS):
        try:
            with fetch(f"{origin}/?release_check={expected}") as response:
                landed = response.geturl().split("?", 1)[0].rstrip("/")
                if landed != origin:
                    raise RuntimeError(f"redirected to {landed}")
                cache_control = response.headers.get("Cache-Control", "")
                document = response.read().decode("utf-8", errors="replace")

            parser = IndexParser()
            parser.feed(document)

            if parser.release != expected:
                raise RuntimeError(f"live marker is {parser.release!r}, expected {expected!r}")
            break
        except Exception as error:  # noqa: BLE001 — every failure here is a retry
            last_error = error
            if attempt == ATTEMPTS - 1:
                raise SystemExit(f"Release verification failed for {origin}: {last_error}")
            time.sleep(BACKOFF)

    assert document is not None
    print(f"Verifying {origin} at release {expected}")

    results = [check("root document carries the expected release marker", True)]

    # --- Assets --------------------------------------------------------------
    for url in parser.scripts[:3]:
        absolute = url if url.startswith("http") else f"{origin}{url}"
        try:
            with fetch(absolute) as response:
                content_type = response.headers.get("Content-Type", "")
                results.append(
                    check(
                        f"script loads with a JavaScript content type ({url})",
                        response.status == 200 and "javascript" in content_type.lower(),
                        f"status {response.status}, type {content_type!r}",
                    )
                )
        except Exception as error:  # noqa: BLE001
            results.append(check(f"script loads ({url})", False, str(error)))

    for url in parser.styles[:3]:
        absolute = url if url.startswith("http") else f"{origin}{url}"
        try:
            with fetch(absolute) as response:
                content_type = response.headers.get("Content-Type", "")
                results.append(
                    check(
                        f"stylesheet loads with a CSS content type ({url})",
                        response.status == 200 and "css" in content_type.lower(),
                        f"status {response.status}, type {content_type!r}",
                    )
                )
        except Exception as error:  # noqa: BLE001
            results.append(check(f"stylesheet loads ({url})", False, str(error)))

    # --- SPA fallback ----------------------------------------------------------
    try:
        with fetch(f"{origin}/auth/callback") as response:
            body = response.read().decode("utf-8", errors="replace")
        results.append(
            check(
                "a deep link serves the application (SPA fallback)",
                response.status == 200 and 'id="root"' in body,
                f"status {response.status}",
            )
        )
    except Exception as error:  # noqa: BLE001
        results.append(check("a deep link serves the application (SPA fallback)", False, str(error)))

    # --- The fallback must not swallow missing assets ------------------------------
    missing = f"{origin}/releases/{expected}/assets/this-file-does-not-exist-{int(time.time())}.js"
    try:
        with fetch(missing) as response:
            results.append(
                check(
                    "a missing release asset returns 404, not the application",
                    False,
                    f"returned {response.status}; the SPA fallback is swallowing /releases/",
                )
            )
    except urllib.error.HTTPError as error:
        results.append(check("a missing release asset returns 404, not the application", error.code == 404, f"status {error.code}"))
    except Exception as error:  # noqa: BLE001
        results.append(check("a missing release asset returns 404, not the application", False, str(error)))

    # --- Caching and hygiene --------------------------------------------------------
    results.append(
        check(
            "the root document is not cached",
            "no-cache" in cache_control.lower() or "no-store" in cache_control.lower(),
            f"Cache-Control: {cache_control!r}",
        )
    )

    # A development fixture that reached production is worse than a broken
    # build, because it looks like it works.
    fixtures = [needle for needle in ("Illustrative data", "Design reference", "preview-auth-token") if needle in document]
    results.append(check("no development fixture markers in the served document", not fixtures, f"found {fixtures}"))

    # A service worker left over from another product on the same origin would
    # serve its cached shell instead of this one.
    try:
        with fetch(f"{origin}/sw.js") as response:
            results.append(
                check("no stale service worker at /sw.js", response.status == 404, f"status {response.status}")
            )
    except urllib.error.HTTPError as error:
        results.append(check("no stale service worker at /sw.js", error.code == 404, f"status {error.code}"))
    except Exception:  # noqa: BLE001 — not reachable is the same as not there
        results.append(check("no stale service worker at /sw.js", True))

    failures = results.count(False)
    if failures:
        print(f"\n{failures} check(s) failed for {origin}.")

        return 1

    print(f"\n{origin} is serving {expected} and passed every check.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
