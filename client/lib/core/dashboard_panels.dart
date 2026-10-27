import 'package:flutter/material.dart';

import 'sidebar_order.dart';

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
  'guidelines': Icons.menu_book_outlined,
    'customers': Icons.people_outline,
    'jobs': Icons.construction_outlined,
    // Same mark as its sidebar entry (module_screens.dart).
    'project_management': Icons.timeline_outlined,
    'project_management_2': Icons.view_timeline_outlined,
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
    'financials': Icons.table_chart_outlined,
    'tasks': Icons.view_kanban_outlined,
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
