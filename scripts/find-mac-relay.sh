#!/usr/bin/env bash
#
# Make the `mac` ssh alias point at the Mac build relay, wherever it is today.
#
# WHY THIS EXISTS. release.sh cannot build anything Apple-shaped without the
# relay, and when it cannot reach it, it prints "Is the Mac mini awake?". On
# 2026-09-09 that sentence was repeated as a diagnosis four times while the Mac
# sat awake beside Jose: the alias resolved `Joses-Mac-mini.local`, and mDNS
# does not resolve reliably from the Windows box. The error text named a cause;
# it was not evidence of one.
#
# The address is not fixable from here — the relay is on Wi-Fi with a DHCP
# lease from the router, a reservation needs the router's admin password, and a
# static address needs sudo on the Mac. So instead of fixing the address, this
# makes the address not matter: the relay is identified by its SSH HOST KEY,
# which does not change when its lease does.
#
# Run it before a cut. It is a no-op when the alias already works.
#
#   bash scripts/find-mac-relay.sh          # fix if needed, quiet when fine
#   bash scripts/find-mac-relay.sh --check  # report only, change nothing
#
set -uo pipefail

# The relay's own ed25519 host key, read off the machine on 2026-09-09.
# If the Mac is ever rebuilt this must be re-pinned, and the script will
# refuse to touch anything until it is — better to stop than to point the
# alias at whatever else answers on port 22.
FINGERPRINT='SHA256:8WTUsaCHWUUMOmyCSMVtBI475UMGVhEjHYVpwfqJ52k'
SSH_CONFIG="$HOME/.ssh/config"
CHECK_ONLY=0
[ "${1:-}" = "--check" ] && CHECK_ONLY=1

reachable() {
  ssh -o BatchMode=yes -o ConnectTimeout=5 mac true >/dev/null 2>&1
}

if reachable; then
  echo "✓ mac relay reachable at $(ssh -o BatchMode=yes mac 'ipconfig getifaddr en1 2>/dev/null || ipconfig getifaddr en0' 2>/dev/null)"
  exit 0
fi

echo "→ 'ssh mac' does not answer — looking for the relay by its host key"
[ "$CHECK_ONLY" -eq 1 ] || cp -f "$SSH_CONFIG" "$SSH_CONFIG.bak-find-mac" 2>/dev/null

# Candidates: whatever this machine has spoken to lately, then the rest of the
# /24 it sits on. The ARP table is first because it is instant and usually
# right; the sweep is the fallback after a long idle.
mapfile -t candidates < <(
  arp -a 2>/dev/null | grep -i dynamic | tr -s ' ' | cut -d' ' -f2 |
    grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'
)
self=$(ipconfig 2>/dev/null | grep -a 'IPv4' | tr -s ' ' | cut -d' ' -f15 |
  grep -E '^10\.|^192\.168\.' | head -1)
if [ -n "$self" ]; then
  prefix="${self%.*}"
  for n in $(seq 1 254); do candidates+=("$prefix.$n"); done
fi

seen=""
for ip in "${candidates[@]}"; do
  case " $seen " in *" $ip "*) continue ;; esac
  seen="$seen $ip"
  timeout 2 bash -c "echo > /dev/tcp/$ip/22" 2>/dev/null || continue
  got=$(ssh-keyscan -t ed25519 -T 3 "$ip" 2>/dev/null | ssh-keygen -lf - 2>/dev/null | awk '{print $2}')
  [ "$got" = "$FINGERPRINT" ] || continue

  echo "  found the relay at $ip (host key matches)"
  if [ "$CHECK_ONLY" -eq 1 ]; then
    echo "  --check: leaving $SSH_CONFIG alone"
    exit 0
  fi

  python - "$SSH_CONFIG" "$ip" <<'PY'
import re, sys
path, ip = sys.argv[1], sys.argv[2]
lines = open(path, encoding='utf-8', errors='replace').read().splitlines()
out, in_mac, changed = [], False, False
for ln in lines:
    if re.match(r'^\s*Host\s+', ln, re.I):
        in_mac = bool(re.match(r'^\s*Host\s+mac\s*$', ln, re.I))
    if in_mac and re.match(r'^\s*HostName\s+', ln, re.I):
        out.append('    HostName ' + ip)
        changed = True
        continue
    out.append(ln)
open(path, 'w', encoding='utf-8', newline='\n').write('\n'.join(out) + '\n')
print('  ' + ('rewrote' if changed else 'NO HostName line found in Host mac —') + ' HostName -> ' + ip)
PY

  if reachable; then
    echo "✓ mac relay reachable again at $ip"
    exit 0
  fi
  echo "✗ rewrote the alias but 'ssh mac' still refuses — key or user problem, not the address"
  exit 1
done

echo "✗ no host on this network presents the relay's key."
echo "  The Mac is off, on another network, or its host key changed."
echo "  Nothing Apple-shaped can be built until it answers; the rest of a cut is unaffected."
exit 1
