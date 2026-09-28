#!/usr/bin/env python3
"""Push the App Review notes in docs/apple-review-notes/ to App Store Connect.

    python scripts/asc-review-notes.py          # show what is live vs the files
    python scripts/asc-review-notes.py --push   # write the files, then read back

── Why a script ─────────────────────────────────────────────────

The notes are what Apple's reviewer reads about what the app does, and they go
stale silently: for three weeks they told Apple the app "requests no device
permissions" and "uses no ... microphone" while every build since 0.0.0138
asked for the microphone. Nothing failed. A reviewer meeting a prompt the notes
denied is a Guideline 2.1 rejection written in our own words.

Keeping the text in the repo (docs/apple-review-notes/ios.txt, macos.txt) means
it is diffable and reviewable like code, and a feature that changes what the
app asks for can change the notes in the SAME commit. This pushes them.

── What it writes to ────────────────────────────────────────────

The review detail of the newest app store version on each platform. Apple
copies it forward into the next version when one is created, so updating the
newest is what makes the next submission carry the text. Released versions
accept the edit (measured 2026-09-25 on 0.0.0222).

── It reads back, because a 200 is not the fact ─────────────────

The stored value is compared to the file after every write. A PATCH that
returned 200 and stored something else - truncated, normalised, or ignored -
would look identical to success otherwise.

Uses the App Store Connect API key via asc-build-states.token(); no browser, no
password.
"""

import argparse
import importlib.util
import io
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
NOTES = HERE.parent / 'docs' / 'apple-review-notes'
PLATFORMS = {'IOS': 'ios.txt', 'MAC_OS': 'macos.txt'}
LIMIT = 4000  # App Store Connect refuses longer notes

spec = importlib.util.spec_from_file_location('asc', HERE / 'asc-build-states.py')
asc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(asc)


def call(jwt, method, path, body=None):
    req = urllib.request.Request(
        asc.API + path, method=method,
        data=None if body is None else json.dumps(body).encode(),
        headers={'Authorization': 'Bearer ' + jwt, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        sys.exit('%s %s -> %s\n%s' % (method, path, e.code, e.read().decode()[:400]))


def newest_review_detail(jwt, platform):
    vs = call(jwt, 'GET', '/apps/%s/appStoreVersions?filter[platform]=%s&limit=1'
              '&fields[appStoreVersions]=versionString' % (asc.APP_ID, platform))['data']
    if not vs:
        sys.exit('no %s app store version found' % platform)
    rd = call(jwt, 'GET', '/appStoreVersions/%s/appStoreReviewDetail' % vs[0]['id'])['data']
    return vs[0]['attributes']['versionString'], rd['id'], rd['attributes'].get('notes') or ''


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--push', action='store_true', help='write the files to App Store Connect')
    a = ap.parse_args()

    jwt = asc.token(asc.DEFAULT_CRED_DIR)
    failed = False
    for platform, fname in PLATFORMS.items():
        # Normalise to LF on read. This working tree is CRLF on Windows (git's
        # autocrlf), so newline='' handed back 36 carriage returns per file,
        # the comparison below said DIFFERS against a live note that was fine,
        # and --push would have sent the CRs to Apple.
        #
        # The read-back could NOT have caught it: it compares what Apple stored
        # against what was sent, and what was sent was the CRLF text - so it
        # would have printed MATCHES over corrupted notes. The Field session
        # found it by pushing from a normalised copy and noticing the two
        # disagreed. The assert makes the read-back honest again: nothing with
        # a CR in it can be sent, so "matches what was sent" means "is clean".
        want = io.open(NOTES / fname, encoding='utf-8', newline='').read().replace('\r\n', '\n')
        assert '\r' not in want, '%s still carries a carriage return after normalising' % fname
        if len(want) > LIMIT:
            sys.exit('%s is %d chars; App Store Connect allows %d' % (fname, len(want), LIMIT))

        version, rid, live = newest_review_detail(jwt, platform)
        state = 'in sync' if live == want else 'DIFFERS'
        print('%-7s %s  live %d chars, file %d chars  %s' % (platform, version, len(live), len(want), state))

        if a.push and live != want:
            call(jwt, 'PATCH', '/appStoreReviewDetails/' + rid, {'data': {
                'type': 'appStoreReviewDetails', 'id': rid, 'attributes': {'notes': want}}})
            stored = call(jwt, 'GET', '/appStoreReviewDetails/' + rid)['data']['attributes']['notes']
            ok = stored == want
            failed = failed or not ok
            print('        pushed; read back %s' % ('MATCHES' if ok else 'DOES NOT MATCH - check it'))

    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
