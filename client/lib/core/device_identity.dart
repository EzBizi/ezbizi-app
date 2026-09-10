import 'dart:math';

import 'package:flutter/foundation.dart';

import 'token_store_io.dart'
    if (dart.library.js_interop) 'token_store_web.dart'
    as store;

/// A stable name for THIS install, so a call can ring it.
///
/// ── Why a device needs a name at all ─────────────────────────────
///
/// A Twilio `<Dial>` makes exactly one call leg per `<Client>` noun, so a Voice
/// identity that names only a person can only ever ring one of that person's
/// devices — whichever Twilio picks. Measured on 2026-09-10 against three real
/// inbound calls: three legs each, one per member of the ring group, never two
/// for the same person. Jose had the desk open on a PC and the app on an
/// iPhone, both registered, and only the iPhone rang.
///
/// The server pairs this with the user id to make one identity per device, and
/// dials all of them. It is also what makes moving a live call to another
/// device possible: you cannot send a call somewhere you cannot name.
///
/// ── Not a secret, but it must not churn ──────────────────────────
///
/// It identifies an install, not a person, and it is never sent anywhere but
/// this app's own server. What matters is that it SURVIVES: a new id on every
/// launch would leave a trail of registrations, and ten of them would fill the
/// dial list with dead endpoints while the caller waits out the timeout.
///
/// So `persist: true` — localStorage in a browser rather than sessionStorage,
/// the keychain on desktop. It outlives the tab on purpose, which is the
/// opposite of the choice made for the session token beside it, and for the
/// opposite reason: the token is a credential and this is a name.
class DeviceIdentity {
  static const _key = 'phone_device_id';

  static String? _cached;

  /// This install's id, made once and kept.
  ///
  /// Falls back to a fresh id whenever storage cannot answer — a private
  /// browsing mode, a locked keyring. That device then registers under a new
  /// name each launch, which is worse than remembering but far better than
  /// having no phone: it still rings, and the old rows expire on their own.
  static String get value {
    final cached = _cached;
    if (cached != null) return cached;

    final stored = store.readItem(_key);
    if (stored != null && stored.isNotEmpty) return _cached = stored;

    final made = _make();
    store.writeItem(_key, made, persist: true);

    return _cached = made;
  }

  /// What to call this device when somebody is choosing where to send a call.
  ///
  /// The platform rather than a model name: "iPhone" and "Windows" are what
  /// somebody looking at a list of their own devices recognises, and no plugin
  /// has to be asked for it.
  static String get label => switch (defaultTargetPlatform) {
    TargetPlatform.iOS => 'iPhone',
    TargetPlatform.android => 'Android',
    TargetPlatform.windows => 'Windows',
    TargetPlatform.macOS => 'Mac',
    TargetPlatform.linux => 'Linux',
    _ => 'Browser',
  };

  /// Hex only: this becomes half of a Twilio client identity, and those are
  /// alphanumeric. `Random.secure()` rather than the default, so two installs
  /// starting in the same second cannot land on the same name.
  static String _make() {
    final random = Random.secure();

    return List.generate(
      16,
      (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'),
    ).join();
  }

  @visibleForTesting
  static void forget() => _cached = null;
}
