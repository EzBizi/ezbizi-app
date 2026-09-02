/*
 * The Windows softphone, as far as JavaScript is concerned.
 *
 * Flutter cannot reach the Twilio Voice SDK on Windows — the SDK is a browser
 * script and there is no Windows build — so the desktop app hosts this page in
 * WebView2 and the two halves talk over postMessage.
 *
 * ── The protocol, deliberately small ────────────────────────────────
 *
 * Dart -> JS, delivered by `postWebMessageAsString(json)`:
 *
 *   {type:'setup',   token}     register with Twilio
 *   {type:'connect', to, callerId}
 *   {type:'hangup'}
 *   {type:'accept'}             answer the ringing call
 *   {type:'decline'}
 *
 * JS -> Dart, each `window.chrome.webview.postMessage(json)`:
 *
 *   {type:'ready',      registered:bool}
 *   {type:'incoming',   from}
 *   {type:'accepted'}   |  {type:'ended'}  |  {type:'error', message}
 *
 * It stays this small on purpose: every message is a thing the Dart side's
 * VoiceDevice interface already has a name for, so neither half has to model
 * the other's state machine. The SDK's own Call object never crosses.
 */
(function () {
  'use strict';

  var statusEl = document.getElementById('status');

  /**
   * Ringtone and DTMF overrides.
   *
   * The SDK's default is a Twilio CDN this page's `media-src 'self'` blocks,
   * so with the defaults the phone registers and routes calls perfectly and
   * rings SILENTLY. The sounds are vendored beside this file instead.
   *
   * Keys are `Device.SoundName` values. An explicit URL bypasses the SDK's own
   * extension negotiation, so .mp3 is right everywhere — WebView2 is Chromium.
   */
  var SOUNDS = {
    incoming: 'sounds/incoming.mp3',
    outgoing: 'sounds/outgoing.mp3',
    disconnect: 'sounds/disconnect.mp3',
    dtmf0: 'sounds/dtmf-0.mp3',
    dtmf1: 'sounds/dtmf-1.mp3',
    dtmf2: 'sounds/dtmf-2.mp3',
    dtmf3: 'sounds/dtmf-3.mp3',
    dtmf4: 'sounds/dtmf-4.mp3',
    dtmf5: 'sounds/dtmf-5.mp3',
    dtmf6: 'sounds/dtmf-6.mp3',
    dtmf7: 'sounds/dtmf-7.mp3',
    dtmf8: 'sounds/dtmf-8.mp3',
    dtmf9: 'sounds/dtmf-9.mp3',
    dtmfh: 'sounds/dtmf-hash.mp3',
    dtmfs: 'sounds/dtmf-star.mp3'
  };

  var device = null;
  var activeCall = null;
  var pendingCall = null;

  function send(payload) {
    try {
      window.chrome.webview.postMessage(JSON.stringify(payload));
    } catch (e) {
      // Nothing to do: the only transport back is the one that just failed.
    }
  }

  function note(text) {
    if (statusEl) statusEl.textContent = text;
  }

  function fail(message) {
    note('error: ' + message);
    send({ type: 'error', message: String(message) });
  }

  /** Both call objects go through here so no listener is registered twice. */
  function watch(call) {
    call.on('accept', function () {
      activeCall = call;
      pendingCall = null;
      note('on a call');
      send({ type: 'accepted' });
    });

    // disconnect, cancel and reject all end up in the same place on the Dart
    // side — there is no call any more — and collapsing them here keeps the
    // protocol from carrying three words for one fact.
    ['disconnect', 'cancel', 'reject'].forEach(function (event) {
      call.on(event, function () {
        if (activeCall === call) activeCall = null;
        if (pendingCall === call) pendingCall = null;
        note('idle');
        send({ type: 'ended' });
      });
    });

    call.on('error', function (error) {
      fail(error && error.message ? error.message : 'call failed');
    });
  }

  function setup(token) {
    try {
      if (device) device.destroy();

      device = new window.Twilio.Device(token, {
        sounds: SOUNDS,

        // Chromium in WebView2 supports Opus; naming it stops the SDK
        // negotiating something worse on a slow line.
        codecPreferences: ['opus', 'pcmu']
      });

      device.on('incoming', function (call) {
        pendingCall = call;
        watch(call);
        note('ringing');
        send({
          type: 'incoming',
          from: (call.parameters && call.parameters.From) || ''
        });
      });

      device.on('error', function (error) {
        fail(error && error.message ? error.message : 'device error');
      });

      device.on('tokenWillExpire', function () {
        // Dart owns the token lifecycle; it refetches and sends setup again.
        send({ type: 'tokenExpiring' });
      });

      device.register().then(
        function () {
          note('registered');
          send({ type: 'ready', registered: true });
        },
        function (error) {
          // Registered false rather than an error: the desk has a state for
          // "cannot take a call" and it is not the same as a failure.
          note('not registered');
          send({
            type: 'ready',
            registered: false,
            message: error && error.message ? error.message : ''
          });
        }
      );
    } catch (e) {
      fail(e && e.message ? e.message : 'could not start the phone');
      send({ type: 'ready', registered: false });
    }
  }

  function connect(to, callerId) {
    if (!device) return fail('the phone is not started');

    var params = { To: to, EzBiziDirection: 'outbound' };
    if (callerId) params.CallerId = callerId;

    device.connect({ params: params }).then(
      function (call) {
        watch(call);
        activeCall = call;
        note('calling');
      },
      function (error) {
        fail(error && error.message ? error.message : 'the call was refused');
      }
    );
  }

  window.addEventListener('message', function (event) {
    var message;
    try {
      message = JSON.parse(event.data);
    } catch (e) {
      return;
    }
    if (!message || !message.type) return;

    switch (message.type) {
      case 'setup':
        return setup(message.token);
      case 'connect':
        return connect(message.to, message.callerId);
      case 'hangup':
        if (activeCall) activeCall.disconnect();
        if (device) device.disconnectAll();
        return;
      case 'accept':
        if (pendingCall) pendingCall.accept();
        return;
      case 'decline':
        if (pendingCall) pendingCall.reject();
        return;
    }
  });

  note('waiting for a token');
  send({ type: 'loaded' });
})();
