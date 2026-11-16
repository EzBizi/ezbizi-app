import 'package:ezbizi_client/widgets/module_page_header.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Desktop review 2026-11-13, D3: a screen's primary action is drawn in the
/// module header, on the right, wherever the screen wrote it.
void main() {
  testWidgets('inside the shell the action moves to the header', (
    tester,
  ) async {
    final toolbar = ValueNotifier<Widget?>(null);
    final action = ValueNotifier<Widget?>(null);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              ListenableBuilder(
                listenable: action,
                builder: (context, _) => ModulePageHeader(
                  key: const Key('header'),
                  title: 'Jobs',
                  actions: [?action.value],
                ),
              ),
              Expanded(
                child: ModuleToolbarScope(
                  slot: toolbar,
                  action: action,
                  child: const Align(
                    key: Key('body'),
                    child: ModuleAction(child: Text('Add job')),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    await tester.pump();
    expect(
      find.descendant(
        of: find.byKey(const Key('header')),
        matching: find.text('Add job'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('body')),
        matching: find.text('Add job'),
      ),
      findsNothing,
    );
  });

  testWidgets('outside the shell it draws where it is', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(home: ModuleAction(child: Text('Add job'))),
    );
    expect(find.text('Add job'), findsOneWidget);
  });

  testWidgets('only the tab in front fills the header (offstage tabs)', (
    tester,
  ) async {
    final toolbar = ValueNotifier<Widget?>(null);
    final action = ValueNotifier<Widget?>(null);
    var front = 0;
    late StateSetter set;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              ListenableBuilder(
                listenable: action,
                builder: (context, _) => ModulePageHeader(
                  key: const Key('header'),
                  title: 'Inventory',
                  actions: [?action.value],
                ),
              ),
              Expanded(
                child: ModuleToolbarScope(
                  slot: toolbar,
                  action: action,
                  child: StatefulBuilder(
                    builder: (context, setState) {
                      set = setState;
                      return Stack(
                        children: [
                          for (final (i, label) in const [
                            (0, 'Add item'),
                            (1, 'Add location'),
                          ])
                            Offstage(
                              offstage: i != front,
                              child: ModuleActionGate(
                                active: i == front,
                                child: ModuleAction(child: Text(label)),
                              ),
                            ),
                        ],
                      );
                    },
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    await tester.pump();
    Finder inHeader(String text) => find.descendant(
      of: find.byKey(const Key('header')),
      matching: find.text(text),
    );
    expect(inHeader('Add item'), findsOneWidget);
    expect(inHeader('Add location'), findsNothing);

    set(() => front = 1);
    await tester.pump();
    await tester.pump();
    expect(inHeader('Add location'), findsOneWidget);
    expect(inHeader('Add item'), findsNothing);
  });
}
