import 'package:flutter/material.dart';

import '../screens/automations_screen.dart';
import '../screens/customers_screen.dart';
import '../screens/dashboard_screen.dart';
import '../screens/email/email_screen.dart';
import '../screens/hr_screen.dart';
import '../screens/inspection_screen.dart';
import '../screens/fleet_screen.dart';
import '../screens/jobs_screen.dart';
import '../screens/marketing_screen.dart';
import '../widgets/coming_soon.dart';
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
  'inspection': ModuleScreen(
    Icons.fact_check_outlined,
    (api, session) => InspectionScreen(api: api, session: session),
  ),
  'hr': ModuleScreen(
    Icons.badge_outlined,
    (api, session) => HrScreen(api: api, session: session),
  ),
  // Declared and not built, and it has an entry here for exactly that reason.
  // The sidebar draws the intersection of what the server declares with what
  // this bundle can render, so a module with no entry is a tab that silently
  // does not appear — which for a placeholder would be indistinguishable from
  // having lost the permission. The screen behind it says what Newsletter will
  // be and what it is waiting for; see marketing_screen.dart.
  'marketing': ModuleScreen(
    Icons.campaign_outlined,
    (api, session) => MarketingScreen(api: api, session: session),
  ),
  'web_management': ModuleScreen(
    Icons.language_outlined,
    (_, _) => const ComingSoonScreen(
      title: 'Web Management',
      icon: Icons.language_outlined,
      what:
          'Web Management is where a company will edit and publish its public '
          'website from EzBizi — the same place it already runs the rest of '
          'the business. The editor is not here yet.',
    ),
  ),
  'phone': ModuleScreen(
    Icons.phone_outlined,
    (_, _) => const ComingSoonScreen(
      title: 'Phone',
      icon: Icons.phone_outlined,
      what:
          'Phone is where the team will place and receive calls, and send '
          'texts, with customers without leaving EzBizi. Calling is not '
          'here yet.',
    ),
  ),
  'fleet_management': ModuleScreen(
    Icons.local_shipping_outlined,
    (api, session) => FleetScreen(api: api, session: session),
  ),
  'automations': ModuleScreen(
    Icons.bolt_outlined,
    (api, session) => AutomationsScreen(api: api, session: session),
  ),
  // An inbox rather than an envelope. `mail_outline` is already the glyph on
  // Human Resources' Invitations tab, and this module is not "mail" in general
  // — it is the shared addresses an organization lets a role read, which is
  // what the tray says and the envelope does not.
  'email': ModuleScreen(
    Icons.inbox_outlined,
    (api, session) => EmailScreen(api: api, session: session),
  ),
  // There is no `data` entry, and its absence is the decision rather than an
  // omission. "Import & export" was a destination of its own listing every
  // resource; the owner's call was that transfer belongs in the tab that owns
  // the records, so exporting customers is in Customers and exporting jobs is
  // in Jobs (see the DataTransfer widget). The two §9 answers that are about
  // the whole organization rather than one resource — export everything, and
  // the record of what is held about people — are in Settings, under the
  // organization's own data. Those two did not come off that tab: it never
  // listed them, and they had no screen anywhere until Settings got one.
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
