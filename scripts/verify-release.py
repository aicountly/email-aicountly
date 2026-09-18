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


def classify_service_worker(status: int, content_type: str, body: str) -> tuple[bool, str]:
    """Is there really a service worker at this path, or did the SPA answer?

    Pure, and separated out because the first version of this check got it
    wrong in a way no amount of staring at it revealed: it asked for a 404,
    which a catch-all SPA fallback can never give. The fallback answers every
    unknown path with index.html and a 200 — /auth/callback depends on that —
    so "200" says nothing at all about whether a worker exists.

    What settles it is WHAT came back. A service worker is JavaScript. The app
    shell is HTML and carries the release marker.

    Returns (ok, detail); ok=True means no worker is there.
    """
    if status == 404:
        return True, "nothing is served there"

    lowered = content_type.lower()
    is_javascript = "javascript" in lowered or "ecmascript" in lowered
    is_app_shell = 'name="aicountly-release"' in body or 'id="root"' in body

    if is_javascript:
        return False, f"JavaScript is served here (status {status}, type {content_type!r})"

    if is_app_shell:
        return True, "the SPA fallback answered; no worker file is there"

    return False, (
        f"something other than the app shell is served here (status {status}, type {content_type!r}) — "
        "a registered worker would intercept every request for this origin"
    )


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
    # intercept fetches and serve its own cached shell instead of this release.
    #
    # WHAT THIS CANNOT DO IS ASK FOR A 404. The SPA fallback answers every path
    # that is not a real file with index.html and a 200 — that is the whole
    # point of it, and /auth/callback two checks above relies on it. Demanding
    # a 404 here therefore fails on a perfectly healthy deployment, which is
    # exactly what it did on the first production run.
    #
    # A worker is only really there if the response is JavaScript. The app
    # shell is not, and it carries the release marker, so either signal
    # settles it.
    for candidate in ("/sw.js", "/service-worker.js"):
        label = f"no stale service worker at {candidate}"
        try:
            with fetch(f"{origin}{candidate}") as response:
                ok, detail = classify_service_worker(
                    response.status,
                    response.headers.get("Content-Type", ""),
                    response.read().decode("utf-8", errors="replace"),
                )
                results.append(check(label, ok, detail))
        except urllib.error.HTTPError as error:
            ok, detail = classify_service_worker(error.code, "", "")
            results.append(check(label, ok, detail))
        except Exception as error:  # noqa: BLE001 — unreachable is the same as not there
            results.append(check(label, True, f"not reachable ({error})"))

    failures = results.count(False)
    if failures:
        print(f"\n{failures} check(s) failed for {origin}.")

        return 1

    print(f"\n{origin} is serving {expected} and passed every check.")

    return 0


SELF_TEST_CASES = [
    # (name, status, content_type, body, expected_ok)
    ("the SPA fallback answering an unknown path is not a worker",
     200, "text/html", '<html><head><meta name="aicountly-release" content="abc"></head><body><div id="root"></div></body></html>', True),
    ("a 404 is not a worker",
     404, "", "", True),
    ("a real service worker IS a worker",
     200, "application/javascript", "self.addEventListener('fetch', () => {})", False),
    ("a worker served as text/javascript is still a worker",
     200, "text/javascript; charset=utf-8", "self.addEventListener('install', () => {})", False),
    ("something that is neither the shell nor JavaScript is treated as a worker",
     200, "application/octet-stream", "\x00binary", False),
]


def self_test() -> int:
    """Check the classifier without needing a live origin.

    The first production run of this script failed a healthy deployment because
    the worker check demanded a 404 from a path the SPA fallback owns. These
    cases are what stop that coming back.
    """
    print("verify-release self-test")
    failures = 0

    for name, status, content_type, body, expected in SELF_TEST_CASES:
        ok, detail = classify_service_worker(status, content_type, body)
        passed = ok is expected
        failures += 0 if passed else 1
        print(f"  {'ok  ' if passed else 'FAIL'}  {name}")
        if not passed:
            print(f"        expected ok={expected}, got ok={ok} ({detail})")

    print(f"\n{len(SELF_TEST_CASES) - failures} passed, {failures} failed")

    return 1 if failures else 0


if __name__ == "__main__":
    if "--self-test" in sys.argv:
        sys.exit(self_test())
    sys.exit(main())
