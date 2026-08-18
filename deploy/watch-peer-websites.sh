#!/bin/bash
#
# App box → websites box. Every five minutes.
#
# `/up` on this box is nginx + PHP + MySQL (DiagnosingHealth select 1).
# This script asks the OTHER box whether it is still answering, and writes
# a verdict the Health screen reads. A missing file is a failure; a timer
# that has stopped looks identical to one that never ran.
#
# EZBIZI_PEER_URL must be set. There is no fallback: www.ezbizi.com is this
# box, and a default that named it made the watchdog ask itself whether it
# was alive. Production uses https://panel.triplexhost.net (drop-in + .env).
#
# This is not backups, mail or TLS. Those stay on Development → Health.

set -uo pipefail

STATUS="${EZBIZI_PEER_STATUS:-/var/www/ezbizi/storage/app/peer-status.json}"
URL="${EZBIZI_PEER_URL:-}"
DIR=$(dirname "$STATUS")

umask 077
# Do not chmod an existing parent (storage/app is ubuntu:www-data). Creating
# that directory as 0700 would lock out the backup writer. Only mkdir if missing.
if [ ! -d "$DIR" ]; then
  install -d -m 0700 "$DIR"
fi

if [ -z "$URL" ]; then
  printf '{"ok":false,"at":"%s","host":"","problem":"EZBIZI_PEER_URL is not set. The watchdog has no peer to ask."}\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STATUS"
  exit 1
fi

HOST=$(printf '%s' "$URL" | sed -E 's#https?://([^/]+).*#\1#')

if curl -fsS --max-time 10 "$URL" >/dev/null; then
  printf '{"ok":true,"at":"%s","host":"%s"}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$HOST" > "$STATUS"
  exit 0
fi

printf '{"ok":false,"at":"%s","host":"%s","problem":"The websites box did not answer."}\n' \
  "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$HOST" > "$STATUS"
exit 1
