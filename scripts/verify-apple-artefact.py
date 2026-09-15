#!/usr/bin/env python3
"""What an iOS .ipa says about itself, versus what its filename claims.

    python scripts/verify-apple-artefact.py Distribution/Apple/EzBizi-0.0.0195-110c6917.ipa
    python scripts/verify-apple-artefact.py Distribution/Apple/*.ipa

.ipa ONLY, despite the name. A macOS .pkg is a xar archive, not a zip, and
expanding one needs pkgutil, which exists on the relay and not on this box. Run
it on a .pkg and it refuses by name rather than erroring obscurely - "File is
not a zip file" reads like a corrupt artefact when it only means wrong format.
The equivalent macOS check is on the relay:

    pkgutil --expand-full EzBizi-<ver>-<sha>-macos.pkg exp
    security cms -D -i exp/*.pkg/Payload/EzBizi.app/Contents/embedded.provisionprofile

-- Why this is not just reading the filename --------------------

The filename is a label release.sh wrote. The version in Info.plist and the
commit in the Dart snapshot are what the BINARY carries, and the snapshot one
is what the running app prints in its footer. Those are different claims and
only the last two are about the artefact. If they ever disagree, a user reads a
commit the binary was not built from and nothing else notices - which is the
same shape as an altool upload that exits 0 having delivered nothing.

Measured on 2026-09-11 across builds 188-195: zero disagreements. That is the
point of having it - the next disagreement is the interesting one.

-- What it cannot tell you --------------------------------------

Whether the build in App Store Connect is THIS file. The portal knows versions
and build numbers, never commits; the only link is the byte count and delivery
time in altool's own output. Ask the deploy desk for those. A green run here
plus "build 196 exists" is still two facts with a gap between them.

-- The distinction that makes it worth running ------------------

"SHA absent" and "could not look for the SHA" are different answers and a naive
scan reports them identically. If App.framework/App moves or is renamed, a
scan-and-report-missing script says MISMATCH about a perfectly good artefact and
somebody re-cuts a release over it. So a missing snapshot is a hard ERROR here,
never a mismatch: the instrument failing is not evidence about the subject.
"""

import plistlib
import sys
import zipfile
from pathlib import Path

SNAPSHOT = 'Payload/{app}/Frameworks/App.framework/App'


def check(path):
    """-> (ok, [lines]). ok is False for a real disagreement OR an unreadable one."""
    out = []
    name = Path(path).name

    # Refuse by NAME, before trying to open it. zipfile on a .pkg says "File is
    # not a zip file", which reads as a damaged artefact and sends somebody
    # looking at the build; the truth is only that this script reads one format.
    if not name.endswith('.ipa'):
        return False, ['%s: ERROR this reads .ipa only. A macOS .pkg is a xar '
                       'archive and needs pkgutil on the relay - see the module '
                       'docstring for the equivalent check.' % name]

    stem = name[:-4]

    # EzBizi-0.0.0195-110c6917.ipa -> ('0.0.0195', '110c6917')
    parts = stem.split('-')
    if len(parts) < 3:
        return False, ['%s: ERROR cannot parse a version and sha out of the name' % name]
    want_ver, want_sha = parts[1], parts[-1]

    try:
        z = zipfile.ZipFile(path)
        apps = sorted({n.split('/')[1] for n in z.namelist()
                       if n.startswith('Payload/') and n.count('/') >= 2})
        if not apps:
            return False, ['%s: ERROR no Payload/*.app inside' % name]
        app = apps[0]
        info = plistlib.loads(z.read('Payload/%s/Info.plist' % app))
    except Exception as e:                      # noqa: BLE001 - any failure here is "cannot look"
        return False, ['%s: ERROR could not read the archive (%s)' % (name, e)]

    got_ver = info.get('CFBundleShortVersionString')
    got_build = info.get('CFBundleVersion')

    try:
        snap = z.read(SNAPSHOT.format(app=app))
    except KeyError:
        # NOT a mismatch. The place the commit lives has moved, so this script
        # can no longer answer the question it exists to answer. Saying
        # "MISMATCH" here would send somebody re-cutting a release over a
        # relocated file.
        return False, ['%s: ERROR %s is not in the archive - the snapshot has moved '
                       'and this check can no longer see the commit. Fix the path '
                       'before trusting a result either way.'
                       % (name, SNAPSHOT.format(app=app))]

    sha_in_snapshot = want_sha.encode() in snap

    bad = []
    if got_ver != want_ver:
        bad.append('version: filename says %s, Info.plist says %s' % (want_ver, got_ver))
    # 0.0.0195 -> 195. Same derivation build-app.sh uses.
    expect_build = want_ver.replace('.', '').lstrip('0')
    if str(got_build) != expect_build:
        bad.append('build: expected %s from the version, Info.plist says %s'
                   % (expect_build, got_build))
    if not sha_in_snapshot:
        bad.append('commit: %s is NOT in the snapshot, so the app would report a '
                   'different commit than its filename claims' % want_sha)

    if bad:
        out.append('%s: MISMATCH' % name)
        out += ['    ' + b for b in bad]
        return False, out

    out.append('%s  v%s build %s  commit %s in snapshot  OK'
               % (name, got_ver, got_build, want_sha))
    return True, out


def main(argv):
    if not argv:
        print(__doc__.strip().split('\n\n')[1])
        return 2
    ok = True
    for p in argv:
        good, lines = check(p)
        ok = ok and good
        for line in lines:
            print(line)
    if not ok:
        print('\nAt least one artefact disagrees with its name, or could not be read.')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
