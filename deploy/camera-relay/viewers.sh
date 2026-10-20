#!/bin/sh
# Tells Laravel how many viewers a relay path has (MediaMTX runOnRead/runOnUnread).
# A script rather than inline YAML: the curl headers' "Host: ..." made the
# YAML value a mapping and MediaMTX refused to start.
exec curl -s -X POST -H "Host: app.ezbizi.com" -H "X-Relay-Secret: $CAMERA_RELAY_SECRET" \
  -H "Content-Type: application/json" -d "{\"path\":\"$MTX_PATH\",\"viewers\":$1}" \
  --resolve app.ezbizi.com:443:127.0.0.1 https://app.ezbizi.com/api/v1/camera-relay/viewers
