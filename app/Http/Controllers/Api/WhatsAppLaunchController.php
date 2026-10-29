<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\MetaApp;
use App\Support\Social\WhatsAppOAuth;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Opens Embedded Signup through Meta's JavaScript SDK (FB.login).
 *
 * A plain dialog/oauth link ignores `extras`, so Meta showed the ordinary
 * "Add your WhatsApp phone number" list and never offered "Connect your
 * existing WhatsApp Business app" (A-1, 2026-10-29). FB.login carries the
 * coexistence featureType. The code it returns goes to the usual callback
 * with the sign-in's state, so nothing after this changes.
 *
 * Public, like the callback: the state is the sign-in, and it is only echoed.
 */
final class WhatsAppLaunchController
{
    public function __invoke(Request $request): Response
    {
        $state = (string) $request->query('state', '');
        abort_unless(preg_match('/^[A-Za-z0-9_\-]{8,200}$/', $state) === 1, 404);

        $json = fn ($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $callback = $json(WhatsAppOAuth::CALLBACK_PATH.'?state='.rawurlencode($state));
        $appId = $json(MetaApp::id());
        $version = $json(FacebookOAuth::GRAPH_VERSION);
        $options = $json([
            'config_id' => (string) config('services.meta.whatsapp_signup_config'),
            'response_type' => 'code',
            'override_default_response_type' => true,
            'extras' => WhatsAppOAuth::EXTRAS,
        ]);

        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connect WhatsApp</title>
<style>body{font-family:system-ui,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:90vh;gap:16px;text-align:center;padding:16px}
button{background:#1877f2;color:#fff;border:0;border-radius:6px;padding:14px 24px;font-size:16px;cursor:pointer}</style></head>
<body><h2>Connect your WhatsApp</h2>
<p>To keep using the WhatsApp Business app on your number, choose <b>Connect your existing WhatsApp Business app</b> in the next window.</p>
<button id="go" disabled>Continue with Facebook</button><p id="msg"></p>
<script>
window.fbAsyncInit = function () {
  FB.init({appId: {$appId}, autoLogAppEvents: true, xfbml: false, version: {$version}});
  var go = document.getElementById('go');
  go.disabled = false;
  go.onclick = function () {
    FB.login(function (r) {
      if (r.authResponse && r.authResponse.code) {
        location.href = {$callback} + '&code=' + encodeURIComponent(r.authResponse.code);
      } else {
        document.getElementById('msg').textContent = 'The window was closed before finishing. Press the button to try again.';
      }
    }, {$options});
  };
};
</script>
<script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
</body></html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
