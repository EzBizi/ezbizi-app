#!/usr/bin/env bash
#
# What Google says we spent on the Ads API in the last 24 hours.
#
# READ THE FIGURE, DO NOT MODEL IT. Arithmetic from the tiering rules said
# 16,704/day on 2026-09-10 while Google's counter said 12,361: runs that
# overrun their five-minute slot are skipped and some accounts fail before
# making a call, and only the counter knows that.
#
# And note WHAT the ceiling is. Measured 2026-09-11, the quota ROLLS rather
# than resetting at an hour: refusals began when no calendar day could have
# held 15,000, and the same clock hour carried 530 successes and 576 refusals.
# A fixed daily reset cannot produce that. So being over the line is partial
# service rather than a blackout, and a 4xx count beside a 2xx count is the
# shape to look for - not "did anything fail".
#
# Nothing in EzBizi logs a SUCCESSFUL googleads call, which is why this script
# exists rather than a grep of laravel.log. The log can only ever show you the
# refusals, and refusals alone cannot tell a spent quota from a quiet day.
#
# Needs an interactive `gcloud auth login` first: the token refresh cannot
# prompt from an agent session and fails with "Reauthentication failed".
set -euo pipefail

PROJECT="${1:-ezbizi-506117}"
HOURS="${2:-24}"

TOKEN="$(gcloud auth print-access-token)"
END="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
START="$(date -u -d "${HOURS} hours ago" +%Y-%m-%dT%H:%M:%SZ)"

echo "Google Ads API request_count, ${START} -> ${END}"

curl -s -G "https://monitoring.googleapis.com/v3/projects/${PROJECT}/timeSeries" \
  -H "Authorization: Bearer ${TOKEN}" \
  --data-urlencode 'filter=metric.type="serviceruntime.googleapis.com/api/request_count" AND resource.label."service"="googleads.googleapis.com"' \
  --data-urlencode "interval.startTime=${START}" \
  --data-urlencode "interval.endTime=${END}" \
  --data-urlencode 'aggregation.alignmentPeriod=3600s' \
  --data-urlencode 'aggregation.perSeriesAligner=ALIGN_SUM' \
| python -c '
import json, sys
from collections import defaultdict

d = json.load(sys.stdin)

if "error" in d:
    print("Google refused the monitoring read:", d["error"].get("message"))
    raise SystemExit(1)

per, tot = defaultdict(lambda: defaultdict(int)), defaultdict(int)

for s in d.get("timeSeries", []):
    labels = s["metric"]["labels"]
    code = labels.get("response_code_class") or labels.get("response_code", "?")
    for p in s["points"]:
        v = int(p["value"].get("int64Value", 0))
        per[p["interval"]["endTime"][:13]][code] += v
        tot[code] += v

if not tot:
    print("No series returned. Either nothing was sent, or the metric/service "
          "label is wrong - check the API is enabled and named as you expect.")
    raise SystemExit(0)

print()
for code in sorted(tot):
    print(f"  {code:>4}  {tot[code]:>7,}")
print(f"  {'ALL':>4}  {sum(tot.values()):>7,}   against a 15,000/day Basic Access ceiling")
print()
print("  per hour (UTC), newest last:")
for t in sorted(per)[-12:]:
    row = "  ".join(f"{c}:{n}" for c, n in sorted(per[t].items()))
    print(f"    {t}  {row}")
'
