# Monitoring — review 2026-11-02 (measured on prod)

## 1. Working
- Presence: 17 Windows PCs online (seen < 10 min); 66 devices seen in 24 h; no 4xx/5xx on any `/api/v1/monitoring/*` route today (nginx).
- Heartbeats: 0 refused since 0.0.0504 (`monitoring.heartbeat.refused` = 0); throttle buckets per route (dcce43d08) hold.
- Screenshots: 1957 captured in 24 h, last 14:50 UTC — the startup shutdown-flag fix (af4127e6f) reached the PCs.
- PC Specs / hardware inventory: 19 of 23 recent Windows PCs report `hardware`; the 4 without are on older builds.
- Files: browse + download round trip live; downloads > 5 MB in 4 MB pieces (6af3a7455).
- People chart: removed accounts hidden, online first (6af3a7455).
- Cameras: camera-relay, camera-mjpeg, ring-snapshots, wyze-bridge services all active; 9 cameras, 1 site.
- Failed jobs touching Monitoring, 7 days: 0.

## 2. Broken
- **Lighting: 8 PCs "engine not responding".** `rgb_error` on all 8 = `TimeoutException after 0:00:05`. OpenRGB on ASUS boards answers only after SMBus detection, slower than our 5 s reply timeout. Fix: 30 s timeout — this review's SHA (client; needs the next desktop build).
- **The box's test gate writes into prod go2rtc.** 98 `cam-*` streams in the live go2rtc belong to no camera and carry the test relay password. Cause: the gate runs phpunit on the box (`/opt/ezbizi-test`) and unfaked camera tests PUT to `CAMERA_BOX_GO2RTC`, default `http://127.0.0.1:1984` = prod go2rtc. Fix: phpunit.xml points it at port 9 (refuses) — this review's SHA. The 98 junk streams are in memory only (not in go2rtc.yaml); they go at the next `camera-mjpeg` restart. Harmless meanwhile (go2rtc pulls only on view).
- **`shutting_down` capture reason, 447 lines today.** Older desktop builds still running (pre-af4127e6f); clears as they update. Beats are accepted (2c158808f), so no presence impact.
- **1 PC `brand_app_running: ASUS Armoury Crate`** — Armoury Crate holds the RGB devices; not a bug, the PC owner must quit it for EzBizi lighting.

## 3. Cohesiveness (UI-UX owns)
- Files rail platform chips are a Wrap of ChoiceChips; Lighting uses filter chips with counts ("All · 63") — two chip styles in one module.
- Download tray shows a bare "42%" text; the rest of the app uses progress bars.
- Device rows say "Desktop app" as the PC name everywhere when the agent has no `computer_name` yet — reads as a placeholder.

## 4. Suggestions (ranked)
1. Merge duplicate device rows per person+machine (Natalie Escobar ×2, Tiara ×3): one person, one PC row — cleaner roster, correct online counts.
2. Prune devices unseen > 30 days (261 rows, 66 active) — faster roster, less noise.
3. Show the agent's app version on each device row — "update or restart" questions answered at a glance (today it is not recorded anywhere).
4. Collapse the agent's 6 polls (input, files, live, lighting, heartbeat, screenshots) into one long-poll — ~4× fewer requests per PC, no throttle tuning.
5. Lighting: offer "quit Armoury Crate" guidance in the status chip.

## 5. Needs Jose
- Restart the camera viewer service now to drop the 98 junk streams (≈5 s blip for anyone watching a camera), or let it happen at the next deploy?
- Should duplicate device rows be merged automatically (suggestion 1)?
