import 'package:flutter/material.dart';

import '../screens/automations_screen.dart';
import '../screens/customers_screen.dart';
import '../screens/dashboard_screen.dart';
import '../screens/data_screen.dart';
import '../screens/jobs_screen.dart';
import 'api_client.dart';
import 'session.dart';

/// Which widget draws a module, and the glyph beside its name.
///
/// ── Why this is not the hardcoded list it replaces ───────────────
///
/// The sidebar used to hold five destinations compiled into Dart: their
/// existence, their order, their names and the fact that everybody saw all five.
/// All of that now comes from `/me`'s `modules` block. What is left here is the
/// part that genuinely cannot travel over the wire — a Dart class and an
/// `IconData` are code, not data, and a server cannot send either.
///
/// So this map answers exactly one question: "the server says this person can
/// reach `customers`; what do I render?" It does not decide WHETHER to render
/// it, what to call it, or where it sits in the list. Grep it for a permission
/// key or a label and there is nothing to find, which is the test worth applying
/// to any future entry.
///
/// ── The two degradations, both deliberate ────────────────────────
///
/// A module the server declares and this bundle has no entry for is not drawn.
/// That is a newer server than the client — the module exists, the person may
/// reach it, and this build has no screen to open. A tab leading nowhere would
/// be worse than its absence, and both halves ship in one deploy so the window
/// is a redeploy wide.
///
/// An entry here whose module the server never declares is likewise not drawn,
/// for the same reason in the other direction: the client has no label for it
/// and no idea whether the platform has switched it off. Silence is not
/// permission.
final Map<String, ModuleScreen> moduleScreens = {
  'dashboard': ModuleScreen(
    Icons.dashboard_outlined,
    (api, session) => DashboardScreen(api: api, session: session),
  ),
  'customers': ModuleScreen(
    Icons.people_outline,
    (api, session) => CustomersScreen(api: api, session: session),
  ),
  'jobs': ModuleScreen(
    Icons.construction_outlined,
    (api, session) => JobsScreen(api: api, session: session),
  ),
  'automations': ModuleScreen(
    Icons.bolt_outlined,
    (api, session) => AutomationsScreen(api: api, session: session),
  ),
  'data': ModuleScreen(
    Icons.import_export_outlined,
    (api, session) => DataScreen(api: api, session: session),
  ),
};

/// How to draw one module: its glyph, and the screen behind it.
@immutable
class ModuleScreen {
  const ModuleScreen(this.icon, this.build);

  final IconData icon;

  /// Built on demand rather than stored, because a screen holds a State that
  /// fetches in `initState` — one instance kept in a map would be one screen
  /// shared by every scope the switcher ever points at.
  final Widget Function(ApiClient api, Session session) build;
}
