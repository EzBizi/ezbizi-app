#!/usr/bin/env python3
"""What App Store Connect says about our uploaded builds, without a browser.

    python scripts/asc-build-states.py
    python scripts/asc-build-states.py --limit 20

── Why this exists ──────────────────────────────────────────────

"Delivered" and "accepted" are different facts. altool exits 0 on a rejected
upload, so a green release proves a delivery and never a verdict. The verdict
lives in App Store Connect, and reading it had one path: a person signs in to
the portal and looks.

That path failed five times in ten days. The ASC web session expires within
hours, signing in means typing Jose's password, and nobody should be doing that
on his behalf - so four builds (0.0.0228 to 0.0.0231) sat overnight with no
verdict, which is exactly how a rejection costs a day of review clock.

The API key has none of those properties: it is a credential we already hold
for uploading, it does not expire, and it needs no browser and no password.

── The signature trap, which is why the self-check is here ──────

APNs and App Store Connect both want ES256, and `cryptography` signs ES256 into
DER - a 70-72 byte structure whose length varies run to run. JOSE wants the raw
pair, R and S each padded to exactly 32 bytes, so 64 total. A DER signature
verifies perfectly against the same key locally and is rejected by Apple, and
the rejection reads as an authentication problem, which sends you to look at the
key - the one thing that was never wrong.

_es256() converts, and asserts 64. That assertion is the only one that
distinguishes the two forms, which is what makes it worth having rather than
decorative. The Notifications module hit the same trap on the APNs sender the
same week; it is a property of the algorithm, not of either service.

── Credentials ──────────────────────────────────────────────────

Read from H:/Development/Credentials/Apple Signing/ - asc-key.env for the ids
and AuthKey_<id>.p8 for the key. Not in the repository and never to be. Override
with ASC_CRED_DIR if they live elsewhere.

NOT the APNs key. That folder holds two .p8 files eight characters apart and
they authenticate to different services; see its README.
"""

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, utils

APP_ID = '6805979795'
AUDIENCE = 'appstoreconnect-v1'
API = 'https://api.appstoreconnect.apple.com/v1'

DEFAULT_CRED_DIR = Path('H:/Development/Credentials/Apple Signing')


def _b64(raw: bytes) -> str:
    import base64
    return base64.urlsafe_b64encode(raw).rstrip(b'=').decode()


def _es256(signing_input: bytes, key) -> bytes:
    """DER -> raw R||S, 64 bytes. See the module docstring."""
    der = key.sign(signing_input, ec.ECDSA(hashes.SHA256()))
    r, s = utils.decode_dss_signature(der)
    raw = r.to_bytes(32, 'big') + s.to_bytes(32, 'big')
    # The assertion that can tell the two forms apart. A DER signature reaching
    # Apple comes back as an auth failure, which accuses the key.
    assert len(raw) == 64, 'expected a 64-byte JOSE signature, got %d' % len(raw)
    return raw


def token(cred_dir: Path) -> str:
    env = {}
    env_file = cred_dir / 'asc-key.env'
    if not env_file.exists():
        sys.exit('missing %s - copy it from the relay at ~/ezbizi-signing/asc-key.env'
                 % env_file)
    for line in env_file.read_text(encoding='utf-8').splitlines():
        line = line.strip()
        if '=' in line and not line.startswith('#'):
            k, v = line.split('=', 1)
            env[k.strip()] = v.strip().strip('"').strip("'")

    key_id, issuer = env.get('ASC_KEY_ID'), env.get('ASC_ISSUER_ID')
    if not key_id or not issuer:
        sys.exit('asc-key.env must set ASC_KEY_ID and ASC_ISSUER_ID')

    p8 = cred_dir / ('AuthKey_%s.p8' % key_id)
    if not p8.exists():
        sys.exit('missing %s' % p8)
    key = serialization.load_pem_private_key(p8.read_bytes(), password=None)

    now = int(time.time())
    header = {'alg': 'ES256', 'kid': key_id, 'typ': 'JWT'}
    # 20 minutes is Apple's ceiling; anything longer is rejected outright.
    claims = {'iss': issuer, 'iat': now, 'exp': now + 15 * 60, 'aud': AUDIENCE}
    signing_input = (_b64(json.dumps(header).encode()) + '.'
                     + _b64(json.dumps(claims).encode())).encode()
    return signing_input.decode() + '.' + _b64(_es256(signing_input, key))


def builds(jwt: str, limit: int):
    url = ('%s/builds?filter[app]=%s&limit=%d&sort=-uploadedDate'
           '&fields[builds]=version,processingState,uploadedDate,expired'
           % (API, APP_ID, limit))
    req = urllib.request.Request(url, headers={'Authorization': 'Bearer ' + jwt})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8', 'replace')[:400]
        sys.exit('App Store Connect returned %s\n%s' % (e.code, body))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--limit', type=int, default=12)
    ap.add_argument('--cred-dir', default=os.environ.get('ASC_CRED_DIR'))
    a = ap.parse_args()

    cred_dir = Path(a.cred_dir) if a.cred_dir else DEFAULT_CRED_DIR
    data = builds(token(cred_dir), a.limit)

    rows = data.get('data', [])
    if not rows:
        print('No builds returned. That is an answer about the QUERY as much as '
              'about the app - check the app id before concluding anything.')
        return 1

    print('%-10s %-12s %-22s %s' % ('VERSION', 'STATE', 'UPLOADED', 'EXPIRED'))
    bad = []
    for b in rows:
        at = b.get('attributes', {})
        state = at.get('processingState', '?')
        print('%-10s %-12s %-22s %s' % (
            at.get('version', '?'), state,
            (at.get('uploadedDate') or '')[:19], at.get('expired')))
        if state not in ('VALID', 'PROCESSING'):
            bad.append((at.get('version'), state))

    if bad:
        print('\nNOT VALID: ' + ', '.join('%s=%s' % x for x in bad))
        return 1
    print('\nEvery build listed is VALID or still PROCESSING.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
