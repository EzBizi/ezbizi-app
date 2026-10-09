import 'package:flutter/foundation.dart';
import 'api_client.dart';

/// Points every [ApiClient] in the process at [gate], which then holds the
/// server's word that this app is too old to talk to it, or null.
///
/// ── Why this is not a field on Session ───────────────────────────
///
/// Being out of date has nothing to do with being signed in. It happens to
/// somebody halfway through a job just as readily as to somebody looking at the
/// sign-in form, and it happens on the control plane as well as in the customer
/// app — two faces, two [ApiClient]s, two sessions, one fact about the binary
/// they are all running inside. Hanging it off [Session] would leave the sign-in
/// screen unable to explain itself and the staff face unable to see it at all.
///
/// So it sits beside them and listens to every client in the process, however
/// many there turn out to be. See main.dart, where it is installed once and then
/// consulted before anything else is drawn.
///
/// ── Why the app stops rather than carries on ─────────────────────
///
/// The alternative is letting each screen report its own 426 in its own words,
/// which is exactly the shape of the bug that produced
/// [ApiClient.onUnauthorized]: the shell stays up, the sidebar still looks live,
/// every tab fails differently, and nothing anywhere says the one true thing.
/// A server that has refused one request for this reason will refuse the next
/// one for the same reason, so there is nothing left to do in the app and every
/// reason to say so once, plainly, in a whole screen.
///
/// ── Why the first refusal wins ───────────────────────────────────
///
/// A screen has several requests in flight, and once the server refuses this
/// build every one of them comes back 426 within milliseconds. `??=` keeps the
/// first, so the app rebuilds once and the sentence does not change under
/// somebody who has started reading it.
///
/// Registered once, on [ApiClient] itself, not per client: a per-client
/// registration silently missed the clients the Development section and staff
/// enrolment build for themselves. Setting [gate] back to null is the way out;
/// main.dart's `onTryAgain` says why there is one.
void installVersionGate(ValueNotifier<UpgradeRequired?> gate) =>
    ApiClient.onUpgradeRequiredAnywhere = (notice) => gate.value ??= notice;
