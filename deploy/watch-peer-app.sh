#!/bin/bash
#
# Websites box → app box. Every five minutes.
#
# Curls https://app.ezbizi.com/up — readiness: nginx, PHP-FPM and MySQL.
# Not backups, mail or TLS.
#
# Writes a file the next backup pull can see. If this box can send mail on 587,
# also try that; a missed ping is still the record when mail cannot send.

set -uo pipefail

STATUS="${EZBIZI_PEER_STATUS:-/home/ubuntu/ezbizi-peer-status.json}"
URL="${EZBIZI_PEER_APP_URL:-https://app.ezbizi.com/up}"

umask 077

if curl -fsS --max-time 10 "$URL" >/dev/null; then
  printf '{"ok":true,"at":"%s","host":"app.ezbizi.com"}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STATUS"
  exit 0
fi

printf '{"ok":false,"at":"%s","host":"app.ezbizi.com","problem":"The app box did not answer /up."}\n' \
  "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STATUS"
exit 1
