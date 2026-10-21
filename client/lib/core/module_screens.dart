import 'package:flutter/material.dart';

import '../screens/financials_screen.dart';
import '../screens/ai_assistance_screen.dart';
import '../screens/guidelines_screen.dart';
import '../screens/ads_performance_screen.dart';
import '../screens/automations_screen.dart';
import '../screens/customers/memberships_screen.dart';
import '../screens/dashboard_screen.dart';
import '../screens/email/email_screen.dart';
import '../screens/hr_screen.dart';
import '../screens/inspection_screen.dart';
import '../screens/field_screen.dart';
import '../screens/fleet_screen.dart';
import '../screens/inventory_screen.dart';
import '../screens/jobs_screen.dart';
import '../screens/business_profile_screen.dart';
import '../screens/leads_screen.dart';
import '../screens/monitoring/monitoring_screen.dart';
import '../screens/reports_screen.dart';
import '../screens/reviews_screen.dart';
import '../screens/sales_screen.dart';
import '../screens/customer_experience_screen.dart';
import '../screens/marketing_screen.dart';
import '../screens/profit_loss_screen.dart';
import '../screens/project_management_screen.dart';
import '../screens/phone_screen.dart';
import '../screens/scheduling_screen.dart';
import '../screens/services_screen.dart';
import '../screens/task_board_screen.dart';
import '../screens/training_screen.dart';
import '../screens/web_screen.dart';
import '../screens/work_hours_screen.dart';
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
    (api, session) => CustomersWithMemberships(api: api, session: session),
  ),
  'jobs': ModuleScreen(
    Icons.construction_outlined,
    (api, session) => JobsScreen(api: api, session: session),
  ),
  // Its own module since 2026-10-21 (Jose: "its own tab, not a sub tab of
  // Jobs"), with the timeline mark it had as a Jobs tab.
  'project_management': ModuleScreen(
    Icons.timeline_outlined,
    (api, session) => ProjectManagementScreen(api: api, session: session),
  ),
  'scheduling': ModuleScreen(
    Icons.calendar_month_outlined,
    (api, session) => SchedulingScreen(api: api, session: session),
  ),
  // The glyph matches the 'reports' row in dashboard_panels.dart deliberately:
  // the rail and the dashboard naming the same module with the same mark is
  // the point of the two maps agreeing.
  'reports': ModuleScreen(
    Icons.assessment_outlined,
    (api, session) => ReportsScreen(api: api, session: session),
  ),
  'inspection': ModuleScreen(
    Icons.fact_check_outlined,
    (api, session) => InspectionScreen(api: api, session: session),
  ),
  'hr': ModuleScreen(
    Icons.badge_outlined,
    (api, session) => HrScreen(api: api, session: session),
  ),
  'field': ModuleScreen(
    Icons.near_me_outlined,
    (api, session) => FieldScreen(api: api, session: session),
  ),
  'work_hours': ModuleScreen(
    Icons.schedule_outlined,
    (api, session) => WorkHoursScreen(api: api, session: session),
  ),
  'ai_assistance': ModuleScreen(
    Icons.smart_toy_outlined,
    (api, session) => AiAssistanceScreen(api: api, session: session),
  ),
  'guidelines': ModuleScreen(
    Icons.menu_book_outlined,
    (api, session) => GuidelinesScreen(api: api, session: session),
  ),
  // Declared and not built, and it has an entry here for exactly that reason.
  // The sidebar draws the intersection of what the server declares with what
  // this bundle can render, so a module with no entry is a tab that silently
  // does not appear — which for a placeholder would be indistinguishable from
  // having lost the permission. The screen behind it says what Newsletter will
  // be and what it is waiting for; see marketing_screen.dart.
  // Sales pipeline: quoted jobs awaiting approval (2026-10-14).
  'sales': ModuleScreen(
    Icons.trending_up,
    (api, session) => SalesScreen(api: api, session: session),
  ),
  // Reviews and Business Profile, moved out of Marketing 2026-09-25.
  'customer_experience': ModuleScreen(
    Icons.sentiment_satisfied_outlined,
    (api, session) => CustomerExperienceScreen(api: api, session: session),
  ),
  'marketing': ModuleScreen(
    Icons.campaign_outlined,
    (api, session) => MarketingScreen(api: api, session: session),
  ),
  'web_management': ModuleScreen(
    Icons.language_outlined,
    (api, session) => WebScreen(api: api, session: session),
  ),
  'phone': ModuleScreen(
    Icons.phone_outlined,
    (api, session) => PhoneScreen(api: api, session: session),
  ),
  'fleet_management': ModuleScreen(
    Icons.local_shipping_outlined,
    (api, session) => FleetScreen(api: api, session: session),
  ),
  'inventory': ModuleScreen(
    Icons.inventory_2_outlined,
    (api, session) => InventoryScreen(api: api, session: session),
  ),
  'services': ModuleScreen(
    Icons.handyman_outlined,
    (api, session) => ServicesScreen(api: api, session: session),
  ),
  'financials': ModuleScreen(
    Icons.table_chart_outlined,
    (api, session) => FinancialsScreen(api: api, session: session),
  ),
  'tasks': ModuleScreen(
    Icons.view_kanban_outlined,
    (api, session) => TaskBoardScreen(api: api, session: session),
  ),
  'monitoring': ModuleScreen(
    Icons.desktop_windows_outlined,
    (api, session) => MonitoringScreen(api: api, session: session),
  ),
  // Matches dashboard_panels.dart's own icon for this module (task 12).
  'training': ModuleScreen(
    Icons.school_outlined,
    (api, session) => TrainingScreen(api: api, session: session),
  ),
  // ── Where a module is drawn is not decided here ────────────────
  //
  // An entry in this map answers "what do I render for this key", and that
  // question is asked whether the answer is drawn in the sidebar or in another
  // module's tab strip. What decides which is `parent` on the server's own
  // declaration, read straight off /me — see `_hasDestination` in main.dart for
  // the rail's half and MarketingScreen for the strip's.
  //
  // That claim was tested on 2026-09-03 and held: Leads moved OUT of Marketing
  // to a row of its own and Web Management moved IN, and this file did not
  // change a line for it. The entries below are grouped by nothing but the
  // order they were written in, and deliberately so — a comment here listing
  // who is nested where is a second opinion that goes stale the next time the
  // owner rearranges the sidebar.
  //
  // A call coming in, not a person or a chart. A lead is somebody who rang or
  // messaged the business about work; `person_add` would say "a new contact"
  // and `trending_up` would say "a number went up", and neither is what a
  // branch manager is opening this tab to deal with.
  // A shop front, not a map pin. `place`/`location_on` says "somewhere on a
  // map", which is what a pin on a route is; this module is about the listing
  // a business publishes about itself, and a storefront is the thing being
  // published.
  'business_profile': ModuleScreen(
    Icons.storefront_outlined,
    (api, session) => BusinessProfileScreen(api: api, session: session),
  ),
  // A chart line, not a coin or a wallet. The tab is about what the advertising
  // DID over a range - spend beside clicks, conversions and impressions - and a
  // currency symbol would say "billing", which is Google's screen, not this one.
  'ads_performance': ModuleScreen(
    Icons.show_chart_outlined,
    (api, session) => AdsPerformanceScreen(api: api, session: session),
  ),
  // A ledger, not a currency symbol or a chart. What the tab holds is a
  // business's own books read out of its accounting sheet - $ would say
  // "billing" and a rising line would say "a number went up", and the week
  // this exists to surface is one where a number did not add up.
  'profit_loss': ModuleScreen(
    Icons.account_balance_outlined,
    (api, session) => ProfitLossScreen(api: api, session: session),
  ),
  'leads': ModuleScreen(
    Icons.call_received_outlined,
    (api, session) => LeadsScreen(api: api, session: session),
  ),
  // A star, not a speech bubble. What the tab is about is the public verdict
  // on this business; the replying is what you do about it, not what it is.
  'reviews': ModuleScreen(
    Icons.star_outline,
    (api, session) => ReviewsScreen(api: api, session: session),
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
