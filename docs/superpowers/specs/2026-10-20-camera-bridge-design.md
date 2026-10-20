# Camera bridge — design

2026-10-20. Monitoring module. Asked for by Jose; decisions below marked
**(Jose)** are his, **(builder's call)** are mine and open to correction.

## Why

A-1 Chimney is putting wired cameras and a recorder (NVR) in its warehouses,
and EzBizi customers will add their own. Today EzBizi can show a camera only
when the camera's own URL is reachable from the internet (the Ring cameras get
there through a hand-built bridge on EzBizi's server). Business camera systems
live on a private warehouse network behind a router. Nothing on that network is
reachable, and opening router ports is a security and support liability.

So EzBizi itself becomes the bridge: the EzBizi Windows app, installed on a PC
at the site that stays on, finds the recorder and cameras on the local network
and relays them to EzBizi. Jose: "we would install ezbizi on either of them"
— the mini PC or an always-on office PC.

## What people do with it (Jose: "all of them")

1. **Watch live** — the camera grid in Monitoring › Camera Monitoring, tap a
   camera for full motion, exactly as the Ring cameras work today.
2. **Look back** — pick a camera and a time, scrub a timeline of what the
   recorder saved, see motion/person/vehicle moments marked on it, download a
   clip.
3. **Get alerted** — a person or vehicle seen by a camera becomes an
   Automations trigger, with a snapshot.

## Goals

- A customer can connect a site's cameras themselves, guided step by step, with
  no router changes and no EzBizi staff involved.
- Live view, playback and alerts all work in the web app and the desktop app.
- Every camera belongs to an organization and to the company or franchise its
  site sits under; it follows the company switcher and §3 reach like the rest
  of Monitoring.
- Costs stay low: video crosses EzBizi's server only while someone watches it.

## Non-goals

- Recording in EzBizi or in the cloud. The site's recorder (or each camera's
  memory card) is the recording. EzBizi plays it back from there. **(builder's
  call: continuous upload would be ~1 TB/week per site.)** Saving a chosen clip
  to EzBizi permanently is a later addition, not v1.
- A bridge on Mac, Linux, phones or the web. Windows only — it is the desktop
  build that runs on an always-on PC.
- PTZ (pan/tilt/zoom) control, two-way audio.
- Brands beyond the launch scope below.

## Anti-goals (failure even if it "works")

- Storing a customer's recorder username/password on EzBizi's server.
- Requiring port forwarding or a public IP at the site.
- Streaming continuously when nobody is watching.
- Any customer able to see another organization's (or another company's, outside
  reach) cameras — including by guessing a URL.
- A setup the installer or the customer's admin cannot finish without calling
  us.

## Launch scope for brands (Jose accepted)

- **Live view:** any camera or recorder that offers RTSP — Amcrest, Dahua,
  Hikvision, Reolink and most business brands — plus ONVIF discovery to find
  them on the network.
- **Playback and alerts:** Amcrest/Dahua recorders first (the system A-1 is
  buying: Amcrest NV2108E-HS NVR with IP5M/IP8M cameras). Other brands added
  as customers need them, behind the same interfaces.

## The pieces

### 1. Sites and the bridge (server)

A **camera site** is one physical place with one bridge: organization, company
or franchise placement, name ("Hialeah warehouse"), the bridge's device
(`monitored_devices` row of the PC running it), and its last check-in.
`monitoring_cameras` gains a nullable `site_id`, the recorder channel (or
camera address) it maps to, and what it supports (live / playback / alerts).
Cameras with no site keep working exactly as today (the Ring cameras, any
public URL).

Placement: today `monitoring_cameras` is organization-only. Cameras inherit
their site's company/franchise, so viewing honours ViewScope and §3 reach like
the device roster does.

Permissions: existing `monitoring.cameras.view` (watch live, look back) and
`monitoring.cameras.manage` (sites, bridge pairing, camera setup). **(builder's
call:** downloading a clip is covered by `.view`; split later if Jose wants
it.)

### 2. The bridge (EzBizi Windows app)

A **"Camera bridge" setting on this PC**. When on, the app:

- pairs to a site (a one-time code shown in Monitoring › Settings, typed into
  the bridge — the same shape as other device pairings);
- discovers recorders/cameras on the LAN (ONVIF WS-Discovery, plus "add by
  address" for anything discovery misses);
- keeps the recorder's credentials **only on this PC**, in the Windows
  credential store;
- checks in every minute (that check-in is what "bridge online" means);
- polls EzBizi for work, the same poll-channel pattern as remote file access
  and remote control: "someone wants camera X live", "someone wants camera X
  from 14:02 to 14:20", "list what was recorded on camera X on this day";
- relays video **outbound** to EzBizi's server only for the cameras currently
  wanted, and stops a few seconds after the last viewer leaves (the same
  on-demand rule as the Ring bridge's `?live=1`).

**Engine (builder's call):** ship go2rtc (MIT, single ~15 MB exe, no install)
inside the Windows build and run it as a child process the app supervises. It
reads RTSP from the recorder and publishes to EzBizi's relay without
re-encoding, which keeps CPU near zero on a $240 mini PC. ffmpeg is the
fallback if go2rtc cannot do something. The app restarts the engine if it
dies, and runs at Windows sign-in with the PC set to sign in automatically
(the setup guide covers this) so a power cut recovers by itself.

**Must not freeze the app** (the 10-06 lesson): no blocking work on the UI
isolate or the platform thread; the engine is a separate process and all LAN
I/O is async.

### 3. The relay (EzBizi server)

A go2rtc (or MediaMTX) instance on the app box receives the bridge's outbound
stream and serves it to viewers:

- **MJPEG** for the camera tiles and the existing tap-to-live panel, so the
  current viewers (web `<img>`, native `camera_view_stub.dart`) work unchanged;
- **HLS or WebRTC** for smoother full-motion and playback where the platform
  supports it **(builder's call on which; MJPEG is the floor)**.

**Viewer auth:** every stream URL is a short-lived signed URL minted by Laravel
for the signed-in viewer after the permission and reach check, verified by
nginx `auth_request` (or signature check) before the relay answers. Replaces
the Ring bridge's secret-path model for anything multi-tenant. No permanent
camera URL ever reaches a client.

### 4. Look back (playback)

- Timeline: the bridge asks the recorder for recorded segments and events for
  a camera and day (Dahua/Amcrest `mediaFileFind`), answers through the poll
  channel; the app draws a day timeline with motion/person/vehicle marks.
- Play: picking a time asks the bridge to relay the recorder's playback stream
  for that window (Dahua/Amcrest RTSP playback with start/end time) through the
  same relay and signed URL as live.
- Download clip: the bridge pulls the window from the recorder and uploads it
  as a file the viewer downloads; capped (builder's call: 15 minutes / ~500 MB
  per clip), audited like a file download.

### 5. Alerts (Automations)

- The bridge subscribes to the recorder's event stream (Dahua/Amcrest
  `eventManager` attach: human, vehicle, tripwire/intrusion, plus motion) and
  posts each event with a snapshot.
- New Automations trigger **"Camera detected…"**, with fields: site, camera,
  kind (person / vehicle / motion / line crossed), time. Conditions on any of
  those — so "after hours", "only dock cameras", "vehicles only" are ordinary
  conditions **(Jose: "i want all options … set them up on the monitoring
  settings - automations")**. Actions are whatever Automations already does
  (notify, text, email…), with the snapshot attached.
- Events are kept short (builder's call: 30 days) with their snapshots;
  retention stated in the model like every other table.
- Debounce at the bridge so one person walking past is one event, not forty.

### 6. Bridge offline

- New Automations trigger **"Camera bridge went offline / came back"** (missed
  check-ins for 5 minutes, builder's call).
- **Default (Jose): notify the Administrator role.** Implemented as a default
  automation each organization gets when its first site is created, editable
  and removable like any other rule — not hard-coded.

### 7. Screens (all through UI/UX as galleries, per the standing flow)

- **Monitoring › Settings › Cameras:** sites (online/offline, last seen, which
  PC), cameras per site (name, on/off, what it supports), "Add a site", and the
  setup wizard.
- **Setup wizard (Jose: "a visual guide as well for how to connect"):** a
  picture per step, each step green/red so the admin sees where it stalled:
  1) install EzBizi on the PC that stays on, 2) turn on Camera bridge and enter
  the pairing code, 3) the bridge finds the recorder — enter its password,
  4) pick and name cameras, 5) done — live view appears, "send a test alert".
- **Wiring guide:** an illustrated printable sheet for the installer (camera →
  cable → recorder → router; monitor, battery backup, PC; cable length under
  328 ft; checking each camera's light), downloadable from the same settings
  page.
- **Camera Monitoring:** the existing grid, plus a **Recordings** view per
  camera (day timeline, marks, play, download).
- **Automations:** the two new triggers appear in the existing builder.

## Delivery order (builder's call)

1. Sites + pairing + the bridge relaying **live** view via the signed relay
   (A-1's warehouses can be watched as soon as the hardware is in).
2. Setup wizard + wiring guide.
3. Recordings (timeline, play, download).
4. Detection alerts trigger.
5. Bridge-offline trigger + the default Administrator rule.

Each ships on its own, gated, through the deploy desk.

## Decisions settled at review (all builder's calls)

1. **Bridge identity and the outbound connection.** Pairing gives the bridge a
   **site key** (stored in the Windows credential store, revocable from
   Settings, rotatable). The bridge never uses a person's EzBizi login. To
   publish a camera, the bridge asks Laravel (with its site key) for a
   **short-lived publish token for that one camera**, then pushes outbound over
   TLS (RTSP-over-TLS or WebSocket to the relay, port 443 through nginx — no new
   inbound ports at the site, none beyond 443 on the box). The relay accepts a
   publish only with a valid token whose camera belongs to that site; a token
   for one tenant's camera can never publish or read another's.
2. **Starting live fast.** The bridge holds a **long-poll** to EzBizi (request
   open up to ~25 s, answered the instant a viewer wants a camera), not the
   file-access interval poll. Target: first frame within a few seconds of the
   tap.
3. **Pairing and who runs it.** Pairing links the bridge PC's existing
   `monitored_devices` row to a site and issues the site key. **No EzBizi user
   needs to be signed in** for the bridge to run — it starts with Windows and
   works on the site key alone. (Windows itself still has to sign in after a
   power cut; the guide covers auto sign-in.)
4. **Viewers and stopping.** The relay knows its live viewers and tells Laravel
   when a camera's count reaches zero; Laravel tells the bridge (on the
   long-poll) to stop after a short grace. Signed viewer URLs last long enough
   to start a stream (minutes); an open stream is not cut when its token
   expires — only a new connection needs a fresh token.
5. **Clips and snapshots.** A dedicated upload endpoint, not the poll channel.
   Snapshots stored with the event (30-day retention); clips stored for
   24 hours for download and then deleted, on the same storage the app already
   uses for uploaded files.
6. **Org-level sites.** A site with no company or franchise is visible the same
   way an org-level monitored device is today (org-level people). Moving a site
   moves its cameras; past events keep the placement they were recorded under.
7. **Reinstalling the bridge PC** means re-entering the recorder password.
   Accepted; the wizard says so at step 3.
8. **Wiring guide artwork** is produced by UI/UX with the screens. Step 2 of
   delivery waits for it.

## Left to the builder

The go2rtc-vs-MediaMTX choice on the server; HLS vs WebRTC above the MJPEG
floor; exact table shapes; the pairing-code mechanics (reuse an existing
pairing pattern if one exists); debounce windows; how the Windows build ships
and updates the engine binary.

## Known risks

- **Unofficial auto sign-in:** a bridge PC that needs a person to sign in after
  a power cut is down until someone does; the guide must cover Windows auto
  sign-in, and Settings must show "offline" loudly.
- **Upload bandwidth at the site** limits how many cameras can be watched at
  once from outside; the relay should prefer the recorder's lower-resolution
  sub-stream for tiles and the main stream only for the tapped camera.
- **Recorder firmware differences** within Amcrest/Dahua — verify on the actual
  NV2108E-HS before building playback against documentation alone.
