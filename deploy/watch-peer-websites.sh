#!/bin/bash
#
# App box → websites box. Every five minutes.
#
# `/up` on this box is nginx + PHP + MySQL (DiagnosingHealth select 1).
# This script asks the OTHER box whether it is still answering, and writes
# a verdict the Health screen reads. A missing file is a failure; a timer
# that has stopped looks identical to one that never ran.
#
# This is not backups, mail or TLS. Those stay on Development → Health.

set -uo pipefail

STATUS="${EZBIZI_PEER_STATUS:-/var/www/ezbizi/storage/app/peer-status.json}"
URL="${EZBIZI_PEER_URL:-https://www.ezbizi.com/ezbizi-alive.txt}"
HOST=$(printf '%s' "$URL" | sed -E 's#https?://([^/]+).*#\1#')

umask 077
install -d -m 0700 "$(dirname "$STATUS")"

if curl -fsS --max-time 10 "$URL" >/dev/null; then
  printf '{"ok":true,"at":"%s","host":"%s"}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$HOST" > "$STATUS"
  exit 0
fi

printf '{"ok":false,"at":"%s","host":"%s","problem":"The websites box did not answer."}\n' \
  "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$HOST" > "$STATUS"
exit 1
