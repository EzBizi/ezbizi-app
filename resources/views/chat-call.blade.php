<!doctype html>
{{--
  Chat's call page: one Twilio Video room, voice or video, two people or a
  group. The app shows it in a web view (Windows WebView2, webview_flutter on
  Android, iOS and macOS) or an iframe (web).

  It is served from the app's own https host because getUserMedia needs a
  secure context, which a file:// or asset page is not on Android.

  Nothing secret reaches the server: the token arrives in the URL FRAGMENT,
  which browsers never send. The page draws its own controls and tells the app
  only that it ended, through whichever bridge the host has.
--}}
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>EzBizi call</title>
<style>
  :root { color-scheme: dark; --bg:#0b141a; --tile:#1f2c34; --ink:#e9edef; --dim:#8696a0; --red:#ea4335; --accent:#00aeef; }
  * { box-sizing: border-box; }
  html, body { margin:0; height:100%; background:var(--bg); color:var(--ink); font:15px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; overflow:hidden; }
  #status { position:absolute; top:16px; left:0; right:0; text-align:center; z-index:2; }
  #status .who { font-size:22px; font-weight:600; }
  #status .what { color:var(--dim); margin-top:4px; }
  #grid { position:absolute; inset:0 0 96px 0; display:grid; gap:6px; padding:6px; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); grid-auto-rows:1fr; }
  .tile { position:relative; background:var(--tile); border-radius:12px; overflow:hidden; display:flex; align-items:center; justify-content:center; min-height:120px; }
  .tile video { width:100%; height:100%; object-fit:cover; }
  .tile .name { position:absolute; left:10px; bottom:8px; background:rgba(0,0,0,.45); padding:2px 8px; border-radius:6px; font-size:13px; }
  .tile .initials { width:96px; height:96px; border-radius:50%; background:#2a3942; display:flex; align-items:center; justify-content:center; font-size:34px; font-weight:600; }
  .tile.speaking { outline:3px solid var(--accent); }
  #self { position:absolute; right:12px; bottom:108px; width:160px; height:110px; border-radius:10px; overflow:hidden; background:var(--tile); z-index:3; display:none; }
  #self video { width:100%; height:100%; object-fit:cover; transform:scaleX(-1); }
  #bar { position:absolute; left:0; right:0; bottom:0; height:96px; display:flex; gap:18px; align-items:center; justify-content:center; padding-bottom:env(safe-area-inset-bottom); }
  #bar button { width:56px; height:56px; border-radius:50%; border:0; background:#2a3942; color:var(--ink); cursor:pointer; display:flex; align-items:center; justify-content:center; }
  #bar button.off { background:var(--ink); color:#111; }
  #bar button#hang { background:var(--red); color:#fff; width:64px; }
  #bar svg { width:26px; height:26px; fill:currentColor; }
  #timer { position:absolute; left:16px; bottom:36px; color:var(--dim); font-variant-numeric:tabular-nums; }
</style>
</head>
<body>
<div id="status"><div class="who" id="who"></div><div class="what" id="what">Connecting…</div></div>
<div id="grid"></div>
<div id="self"></div>
<div id="timer"></div>
<div id="bar">
  <button id="mic" title="Mute" aria-label="Mute"><svg viewBox="0 0 24 24"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2z"/></svg></button>
  <button id="cam" title="Camera" aria-label="Camera"><svg viewBox="0 0 24 24"><path d="M17 10.5V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3.5l4 4v-11l-4 4z"/></svg></button>
  <button id="flip" title="Switch camera" aria-label="Switch camera" style="display:none"><svg viewBox="0 0 24 24"><path d="M20 5h-3.17L15 3H9L7.17 5H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm-8 13a5 5 0 0 1-4.9-4H5l3-3 3 3H9.1a3 3 0 0 0 5.66 1.4l1.46 1.46A5 5 0 0 1 12 18zm4.9-6L14 9h1.9a3 3 0 0 0-5.66-1.4L8.78 6.14A5 5 0 0 1 16.9 9H19l-2.1 3z"/></svg></button>
  <button id="hang" title="End call" aria-label="End call"><svg viewBox="0 0 24 24"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85a1 1 0 0 1-1.41-.02L.29 13.08a1 1 0 0 1 0-1.41C3.34 8.78 7.46 7 12 7s8.66 1.78 11.71 4.67a1 1 0 0 1 0 1.41l-2.48 2.48a1 1 0 0 1-1.41.02 11.2 11.2 0 0 0-2.67-1.85.99.99 0 0 1-.56-.9v-3.1A15.3 15.3 0 0 0 12 9z"/></svg></button>
</div>
<script src="https://sdk.twilio.com/js/video/releases/2.31.0/twilio-video.min.js"
        integrity="sha384-cuBBc3m3D1epAZVrw3OOlg144AtQq0JSW3Ovf8D9W+SGVpxRSCSg/UCWQCyb1Dx9"
        crossorigin="anonymous"></script>
<script>
(function () {
  var q = new URLSearchParams(location.hash.slice(1));
  // Out of the address bar and history at once: the token is a credential.
  history.replaceState(null, '', location.pathname);
  var token = q.get('token'), roomName = q.get('room');
  var video = q.get('video') === '1', group = q.get('group') === '1';
  var ringSeconds = parseInt(q.get('ring') || '45', 10);
  var names = {}; try { names = JSON.parse(q.get('names') || '{}'); } catch (e) {}
  var title = q.get('title') || '';
  var $ = function (id) { return document.getElementById(id); };
  var room = null, local = [], started = null, ended = false, everyone = false;

  $('who').textContent = title;
  if (!video) $('cam').style.display = 'none';

  /** Tell the app, through whichever bridge hosts this page. */
  function post(msg) {
    var s = JSON.stringify(msg);
    try { if (window.chrome && chrome.webview) chrome.webview.postMessage(s); } catch (e) {}
    try { if (window.EzBiziCall) EzBiziCall.postMessage(s); } catch (e) {}
    try { if (window.parent !== window) window.parent.postMessage({ezbiziCall: msg}, '*'); } catch (e) {}
  }
  function what(text) { $('what').textContent = text; }
  function initials(id) {
    var n = (names[id] || '?').trim().split(/\s+/);
    return ((n[0] || '?')[0] + (n.length > 1 ? n[n.length - 1][0] : '')).toUpperCase();
  }
  function tileFor(p) {
    var t = document.getElementById('t-' + p.sid);
    if (t) return t;
    t = document.createElement('div');
    t.className = 'tile'; t.id = 't-' + p.sid;
    t.innerHTML = '<div class="initials"></div><div class="name"></div>';
    t.querySelector('.initials').textContent = initials(p.identity);
    t.querySelector('.name').textContent = names[p.identity] || 'Colleague';
    $('grid').appendChild(t);
    return t;
  }
  function attach(track, p) {
    var t = tileFor(p);
    var el = track.attach();
    if (track.kind === 'video') { var i = t.querySelector('.initials'); if (i) i.style.display = 'none'; t.insertBefore(el, t.firstChild); }
    else { el.style.display = 'none'; t.appendChild(el); }
  }
  function detach(track, p) {
    track.detach().forEach(function (el) { el.remove(); });
    var t = document.getElementById('t-' + p.sid);
    if (t && track.kind === 'video') { var i = t.querySelector('.initials'); if (i) i.style.display = ''; }
  }
  function participant(p) {
    tileFor(p);
    p.tracks.forEach(function (pub) { if (pub.track) attach(pub.track, p); });
    p.on('trackSubscribed', function (tr) { attach(tr, p); });
    p.on('trackUnsubscribed', function (tr) { detach(tr, p); });
    if (!started) { started = Date.now(); everyone = true; tick(); }
    update();
  }
  function update() {
    var n = room ? room.participants.size : 0;
    $('status').style.display = n > 0 ? 'none' : '';
    if (n === 0 && started) what(group ? 'Everyone else left' : 'Call ended');
  }
  function tick() {
    if (ended || !started) return;
    var s = Math.floor((Date.now() - started) / 1000);
    $('timer').textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    setTimeout(tick, 1000);
  }
  function hangUp(reason) {
    if (ended) return; ended = true;
    var alone = !room || room.participants.size === 0;
    local.forEach(function (t) { try { t.stop(); } catch (e) {} });
    if (room) room.disconnect();
    post({event: 'ended', alone: alone, answered: everyone, reason: reason || 'hangup'});
    what('Call ended');
  }

  $('hang').onclick = function () { hangUp('hangup'); };
  $('mic').onclick = function () {
    var a = local.filter(function (t) { return t.kind === 'audio'; })[0]; if (!a) return;
    a.isEnabled ? a.disable() : a.enable(); this.classList.toggle('off', !a.isEnabled);
  };
  $('cam').onclick = function () {
    var v = local.filter(function (t) { return t.kind === 'video'; })[0]; if (!v) return;
    v.isEnabled ? v.disable() : v.enable(); this.classList.toggle('off', !v.isEnabled);
    $('self').style.display = v.isEnabled ? 'block' : 'none';
  };
  var facing = 'user';
  $('flip').onclick = function () {
    var v = local.filter(function (t) { return t.kind === 'video'; })[0]; if (!v) return;
    facing = facing === 'user' ? 'environment' : 'user';
    v.restart({facingMode: facing});
  };
  window.addEventListener('message', function (e) {
    if (e.data && e.data.ezbiziCallCommand === 'hangup') hangUp('app');
  });
  window.ezbiziHangUp = function () { hangUp('app'); };

  if (!window.Twilio || !Twilio.Video) { what('The call could not load. Check your connection.'); post({event: 'failed', reason: 'sdk'}); return; }
  if (!token || !roomName) { what('This call link is incomplete.'); post({event: 'failed', reason: 'token'}); return; }
  what(group ? 'Calling the group…' : 'Calling…');

  // A missing camera (or microphone) is not a failed call: drop to what the
  // device has, down to listening only, and say so. A refusal still stops.
  function tracksWith(audio, cam) {
    if (!audio && !cam) return Promise.resolve([]);
    return Twilio.Video.createLocalTracks({audio: audio, video: cam ? {width: 640, facingMode: 'user'} : false})
      .catch(function (err) {
        if (!err || err.name !== 'NotFoundError') throw err;
        if (cam) { $('cam').style.display = 'none'; return tracksWith(audio, false); }
        what('No microphone found: you can listen only.');
        return tracksWith(false, false);
      });
  }

  tracksWith(true, video)
    .then(function (tracks) {
      local = tracks;
      tracks.forEach(function (t) {
        if (t.kind === 'video') { $('self').appendChild(t.attach()); $('self').style.display = 'block'; }
      });
      if (video && /Android|iPhone|iPad/.test(navigator.userAgent)) $('flip').style.display = '';
      return Twilio.Video.connect(token, {name: roomName, tracks: tracks, dominantSpeaker: group});
    })
    .then(function (r) {
      room = r;
      post({event: 'connected'});
      room.participants.forEach(participant);
      room.on('participantConnected', participant);
      room.on('participantDisconnected', function (p) {
        var t = document.getElementById('t-' + p.sid); if (t) t.remove();
        update();
        // Two people: the other one leaving ends it, as a phone call.
        if (!group && room.participants.size === 0) hangUp('other-left');
      });
      room.on('dominantSpeakerChanged', function (p) {
        document.querySelectorAll('.tile.speaking').forEach(function (t) { t.classList.remove('speaking'); });
        if (p) { var t = document.getElementById('t-' + p.sid); if (t) t.classList.add('speaking'); }
      });
      room.on('disconnected', function () { if (!ended) hangUp('disconnected'); });
      // Nobody answered: stop ringing, as WhatsApp does.
      setTimeout(function () { if (!everyone && !ended) { what('No answer'); hangUp('no-answer'); } }, ringSeconds * 1000);
    })
    .catch(function (err) {
      var denied = err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError');
      what(denied ? 'EzBizi needs the microphone' + (video ? ' and camera' : '') + ' for calls.' : 'The call could not connect.');
      post({event: 'failed', reason: denied ? 'permission' : 'connect'});
    });
})();
</script>
</body>
</html>
