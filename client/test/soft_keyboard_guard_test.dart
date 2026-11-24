import 'dart:io';

import 'package:ezbizi_client/core/soft_keyboard.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-11-24: "Whenever opening any dropdown with search bar, do not
/// open keyboard by default". On a phone the keyboard covered half of every
/// picker the moment it opened. See lib/core/soft_keyboard.dart.
void main() {
  tearDown(() => hasSoftKeyboard = _platformDefault);

  Future<bool> searchFocusedOnOpen(WidgetTester tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => showSearchPicker<int>(
                context,
                title: 'Pick',
                options: [
                  for (var i = 0; i < 12; i++)
                    SearchOption(value: i, label: 'Option $i'),
                ],
              ),
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    final search = find.descendant(
      of: find.byType(TextField),
      matching: find.byType(EditableText),
    );
    expect(search, findsOneWidget);
    return tester.widget<EditableText>(search).focusNode.hasFocus;
  }

  testWidgets('a picker opened on a phone does not raise the keyboard', (
    tester,
  ) async {
    hasSoftKeyboard = () => true;
    expect(await searchFocusedOnOpen(tester), isFalse);
  });

  // The control: same picker, desktop. If this goes false too, the test above
  // proves nothing.
  testWidgets('a picker opened on a desktop still focuses its search', (
    tester,
  ) async {
    hasSoftKeyboard = () => false;
    expect(await searchFocusedOnOpen(tester), isTrue);
  });

  // FoldWhileTyping: summaries and floating buttons step aside while a phone
  // types. A Scaffold body sees no inset, so the test puts it in one.
  for (final (width, folds) in [(390.0, true), (1200.0, false)]) {
    testWidgets('FoldWhileTyping at $width ${folds ? 'folds' : 'stays'}', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                FoldWhileTyping(child: Text('Billed')),
                TextField(),
              ],
            ),
          ),
        ),
      );
      expect(find.text('Billed'), findsOneWidget);

      tester.view.viewInsets = const FakeViewPadding(bottom: 300);
      await tester.pumpAndSettle();
      expect(find.text('Billed'), folds ? findsNothing : findsOneWidget);

      tester.view.resetViewInsets();
      await tester.pumpAndSettle();
      expect(find.text('Billed'), findsOneWidget);
    });
  }

  /// A literal `autofocus: true` raises the keyboard on a phone. Each one left
  /// is a box whose only purpose is typing, opened by asking to type, or a
  /// Focus that is not a text box at all. Anything new uses `autofocusOnOpen`,
  /// or is added here with its reason.
  test('every literal autofocus: true is one that may raise the keyboard', () {
    const allowed = <String, int>{
      // Codes, passwords, typed confirmations: typing is the whole step.
      'screens/development_screen.dart': 1,
      'screens/email/mailbox_panels.dart': 1,
      'screens/invitation_screen.dart': 1,
      'screens/jobs/job_detail_panel.dart': 1,
      'screens/login_screen.dart': 2,
      'screens/monitoring/files_tab.dart': 1,
      'screens/reset_password_screen.dart': 1,
      'screens/staff/staff_accounts_screen.dart': 1,
      'screens/staff/staff_login_screen.dart': 1,
      'screens/staff/staff_shell.dart': 1,
      'screens/task_board_sensitive.dart': 1,
      'widgets/disconnect_panel.dart': 1,
      'widgets/two_factor_card.dart': 2,
      // A rename, an inline add or a one-box prompt, after tapping it.
      'screens/ai_assistance/whatsapp_groups_tab.dart': 1,
      'screens/customers/customer_tags.dart': 1,
      'screens/customers/memberships_members.dart': 1,
      'screens/financials_screen.dart': 1,
      'screens/hr/invitations_tab.dart': 1,
      'screens/hr/onboarding_form.dart': 1,
      'screens/marketing/media_library_panel.dart': 1,
      'screens/marketing/newsletter_editor.dart': 1,
      'screens/monitoring/camera_sites_settings.dart': 1,
      'screens/monitoring/ownership_tab.dart': 1,
      'screens/organization_screen.dart': 1,
      'screens/phone/call_flow_pane.dart': 1,
      'screens/roles_screen.dart': 1,
      'screens/settings/integration_provider_screen.dart': 1,
      'screens/task_board_columns.dart': 1,
      'screens/task_board_screen.dart': 8, // + the update reply box
      'screens/task_board_sidebar.dart': 1,
      'screens/web/widgets_tab.dart': 1,
      // A reply, note or reason, after tapping Reply / Note / Deny.
      'screens/email/reply_panel.dart': 1,
      'screens/hr/pto_tab.dart': 1,
      'screens/hr/work_hours_tab.dart': 1,
      'screens/marketing/comments_inbox_tab.dart': 1,
      'screens/marketing/instagram_comments_panel.dart': 1,
      'screens/suggestions_screen.dart': 2,
      'widgets/lead_thread.dart': 1,
      // Focus(autofocus: true) for keyboard shortcuts: no text box.
      'screens/email/gmail_inbox.dart': 1,
      'screens/monitoring/desktop_monitoring_tab.dart': 1,
      'widgets/date_range_picker.dart': 1,
      'widgets/panel_field.dart': 1,
      'widgets/suggestion_files.dart': 1,
    };

    final found = <String, int>{};
    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart')) continue;
      final n = f
          .readAsLinesSync()
          .where((l) => !l.trimLeft().startsWith('//'))
          .where((l) => l.contains('autofocus: true'))
          .length;
      if (n > 0) {
        found[f.path.replaceAll('\\', '/').substring('lib/'.length)] = n;
      }
    }

    final over = {
      for (final e in found.entries)
        if (e.value > (allowed[e.key] ?? 0)) e.key: e.value,
    };
    expect(
      over,
      isEmpty,
      reason:
          'New autofocus: true raises the phone keyboard when this opens. Use '
          'autofocusOnOpen (lib/core/soft_keyboard.dart) for a search bar or '
          'a form panel; allow it here only if typing is the whole point.',
    );
  });
}

final _platformDefault = hasSoftKeyboard;
