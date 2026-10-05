import 'package:flutter/material.dart';

import 'sidebar_order.dart';

/// The label a dashboard row is drawn with.
///
/// ── Why it is not just `row['title']` ────────────────────────────
///
/// Some widgets count over a rolling window and some do not, and one widget can
/// do both at once. HR's starters-and-leavers reports joining over the last N
/// days and "no longer active" as a running total, because `users.is_active` is
/// a boolean with no timestamp beside it — nothing records the DAY somebody was
/// switched off, so there is no honest answer to "who left this month".
///
/// So the SERVER says which rows the window covers (`period_applies_to`) and
/// how long it is (`days`), and this composes the label from that. A client
/// that decided for itself which row was the dated one would keep working until
/// a third row was added and then quietly mislabel it.
///
/// ── One function, because there are two surfaces ─────────────────
///
/// The HR tab draws a fixed set of these panels; the main dashboard draws
/// whatever somebody composed. The window was applied on the first and dropped
/// on the second, so the same figure read "Joined in the last 30 days" on one
/// screen and a bare "Joined" — an all-time count, to anybody reading it — on
/// the other. Two homes for one fact is the trap; this is the one home.
String dashboardRowLabel(Map<String, dynamic> data, Map<String, dynamic> row) {
  // `status` for the widgets whose rows are named after a job status, `title`
  // for the rest. Both are the row's NAME; neither carries a period.
  final title = (row['status'] ?? row['title'] ?? '').toString();

  final dated = data['period_applies_to'];
  final days = data['days'];

  // A widget that says nothing about a period gets its title back unchanged,
  // which is every widget that existed before this was written.
  if (dated is! List || days is! int || !dated.contains(title)) {
    return title;
  }

  // `days` is a number the server chose; nothing in the payload promises it is
  // more than one, and a one-day window is already a period a widget offers
  // (automations.health). "in the last 1 days" is the tell of a sentence glued
  // together without anybody reading it back.
  return days == 1
      ? '$title in the last day'
      : '$title in the last $days days';
}

/// The glyph a panel is headed with.
///
/// Glance keys are module keys (`hr`, `jobs`). An unrecognised key still falls
/// back to its module prefix (`hr.something_new` gets HR's) so a leftover
/// widget key on the HR tab is never a bare header, and a module this list
/// has not heard of still gets a neutral icon rather than none.
///
/// Not a preference and not an organization setting: it is how a thing is drawn,
/// the same for everybody, in the app's own visual language.
IconData dashboardPanelIcon(String key) {
  const byModule = <String, IconData>{
    'hr': Icons.badge_outlined,
  // The same mark as its sidebar entry (module_screens.dart), so the rail and
  // the dashboard name Work Hours alike (2026-09-24).
  'work_hours': Icons.schedule_outlined,
  // Same mark as its sidebar entry (module_screens.dart).
  'ai_assistance': Icons.smart_toy_outlined,
    'customers': Icons.people_outline,
    'jobs': Icons.construction_outlined,
    'scheduling': Icons.calendar_month_outlined,
    // Same mark as its sidebar entry (module_screens.dart).
    'field': Icons.near_me_outlined,
    'inspection': Icons.fact_check_outlined,
    'marketing': Icons.campaign_outlined,
    'customer_experience': Icons.sentiment_satisfied_outlined,
    'newsletter': Icons.newspaper_outlined,
    'web_management': Icons.language_outlined,
    'phone': Icons.phone_outlined,
    'fleet_management': Icons.local_shipping_outlined,
    'inventory': Icons.inventory_2_outlined,
    'services': Icons.handyman_outlined,
    'email': Icons.inbox_outlined,
    'automations': Icons.bolt_outlined,
    // A rising line, not a coin and not the clipboard Reports uses. The panel
    // is about what the advertising DID over a range - spend beside clicks and
    // conversions - and it must not be mistaken for its parent tab at a glance,
    // which is what reusing assessment_outlined would do.
    // Matches module_screens.dart, so the sidebar and the dashboard agree.
    'ads_performance': Icons.show_chart_outlined,
    // A ledger rather than a chart or a currency symbol: the tab holds a
    // business's own books read out of its accounting sheet. Matches
    // module_screens.dart, so the sidebar and the dashboard agree.
    'profit_loss': Icons.account_balance_outlined,
    'reports': Icons.assessment_outlined,
    'reviews': Icons.reviews_outlined,
    'leads': Icons.person_search_outlined,
    'business_profile': Icons.storefront_outlined,
    // New Google reviews raised to Automations. Matches module_screens.dart,
    // so the sidebar and the dashboard agree.
    'a1leads_gbp': Icons.business_outlined,
    // Training is a guide somebody reads and a test they sit, so the icon is
    // the learning, not the paperwork — a certificate or a checklist would
    // read as HR, whose panel sits beside this one and counts a different
    // thing entirely.
    'training': Icons.school_outlined,
    // Monitoring watches DEVICES — the subject is `MonitoredDevice` — so this is
    // a machine, not a heartbeat or a chart.
    'monitoring': Icons.desktop_windows_outlined,
  };

  return byModule[key] ??
      byModule[key.split('.').first] ??
      Icons.insights_outlined;
}

/// The main dashboard's sections: [keys] cut where this person's sidebar is.
///
/// Jose picked "sections like the menu" (UI queue 50, 2026-10-05), and the
/// menu's groups are the person's own dividers in `navigation.order` — they
/// have no names, so neither do the sections; a rule separates them, as in the
/// sidebar. Sections run in the sidebar's order and boxes keep the
/// dashboard's own order inside each. A box whose module the saved order does
/// not name goes where the sidebar puts such a module: the last group, or a
/// group of its own after a divider the order ends on. Nobody who never added
/// a divider has more than one section.
List<List<String>> dashboardSections(List<String> keys, List<String> order) {
  final groupOf = <String, int>{};
  var group = 0;
  var opened = false;

  for (final entry in order) {
    if (entry == sidebarDivider) {
      // A rule needs something above it, and two touching rules are one.
      if (opened) {
        group++;
        opened = false;
      }
      continue;
    }
    groupOf.putIfAbsent(entry, () => group);
    opened = true;
  }

  final sections = <int, List<String>>{};
  for (final key in keys) {
    (sections[groupOf[key] ?? group] ??= []).add(key);
  }

  return [
    for (final at in sections.keys.toList()..sort()) sections[at]!,
  ];
}

/// Where a user's panel order lives inside their preferences.
///
/// The main dashboard does not use this: its order IS the order of the list it
/// already stores under `dashboard.widgets`, because on that screen choosing a
/// panel and placing it are the same act. This is for the dashboards whose
/// panels are fixed — Human Resources today — where there is an order to
/// remember and no list to remember it in.
///
/// One key holding a map of surfaces, rather than a key per surface, so a third
/// dashboard adds an entry rather than a preference.
const String dashboardOrderPreference = 'dashboard.order';

/// The order this user last put a surface's panels in.
///
/// Read defensively on purpose. This is JSON out of a preferences blob that was
/// written by an older version of this app, so nothing about its shape is
/// promised — and a dashboard that threw because somebody's stored order was a
/// string would be a screen nobody could open and nobody could fix.
List<String> savedPanelOrder(Map<String, dynamic> preferences, String surface) {
  final all = preferences[dashboardOrderPreference];

  if (all is! Map) return const [];

  final order = all[surface];

  if (order is! List) return const [];

  return [
    for (final key in order)
      if (key is String) key,
  ];
}

/// [available] arranged the way [saved] asks for.
///
/// ── What a saved order has to survive ────────────────────────────
///
/// It outlives the thing it names. A role loses the Human Resources tab, a
/// module is switched off platform-wide, or a widget is renamed, and the order
/// still names it. Meanwhile the app grows panels the saved order has never
/// heard of.
///
/// So: a key naming a panel that is not here is ignored, a panel the order does
/// not name is APPENDED rather than dropped, and neither is an error. Dropping
/// the unnamed ones is the tempting mistake — it makes the saved order the whole
/// truth, and then every new panel is invisible to everybody who has ever
/// rearranged their dashboard.
///
/// A key repeated in the stored order is honoured once, so a bad write cannot
/// draw one panel twice.
List<String> panelsInSavedOrder(List<String> available, List<String> saved) {
  final remaining = [...available];
  final ordered = <String>[];

  for (final key in saved) {
    if (remaining.remove(key)) ordered.add(key);
  }

  return [...ordered, ...remaining];
}
