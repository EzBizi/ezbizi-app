import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings_screen.dart';
import 'package:ezbizi_client/widgets/sending_server_panel.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The settings screen after a change (plan §6).
///
/// The owner's complaint in their own words: "whenever you change a value, for
/// example i change time format from 12 to 24, the whole page reloads". It did
/// — every write ended in `_load()`, which raised `_loading`, which replaced
/// the screen with a spinner and refetched every setting to learn something the
/// write had already answered.
///
/// So the assertion running through the first group of these is the count of
/// GET /settings. It must stay at one. "No spinner" is the symptom; "no second
/// request" is the cause, and only the second one can be checked without racing
/// an animation.
///
/// The second group is about what a row SAYS. Three things a settings screen
/// can be wrong about, all of which it was:
///
///   - where a value came from, which it stopped saying the moment a row was
///     read-only, on the screen whose whole purpose is showing inheritance;
///   - what a group of settings is called, which it invented by upper-casing
///     the front of the key;
///   - whether changing something will do anything at all.

/// What SettingsController::refusalFor answers for a read-only row.
///
/// Written out rather than referenced, because what is worth catching is this
/// screen drifting away from the 403 it stands in for. The row used to carry
/// the bare permission key and the screen printed "Read-only — needs
/// organizations.edit": a wire name on a customer's screen, naming something
/// that appears nowhere in the product and nobody they could ask (R8).
String _refusedToChange(String label) =>
    'You do not have permission to change the ‘$label’ setting. That '
    'is part of a role rather than of an account, so somebody who can edit '
    'roles can add it to yours.';

void main() {
  // UI queue 110: the business address saves itself once typing stops, like
  // every row here, rather than behind a Save button of its own.
  testWidgets('the business address saves as you type, after a pause', (
    tester,
  ) async {
    final patches = <Map>[];
    tester.view.physicalSize = const Size(1280, 3000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await _pumpSettings(tester, (request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': ['organizations.edit'],
            'scope': {
              'type': 'organization',
              'id': 'org-1',
              'label': 'Everdoors',
              'path': ['Everdoors'],
            },
          },
        });
      }
      if (request.method == 'PATCH' && path.endsWith('/organization')) {
        patches.add(jsonDecode(request.body) as Map);
        return _json({'data': <String, dynamic>{}});
      }
      return _json({
        'data': [
          _row(
            'general.timezone',
            label: 'Time zone',
            type: 'string',
            value: 'America/New_York',
            source: _source('organization', 'org-1', 'Everdoors', false),
          ),
        ],
      });
    });

    expect(find.text('Save address'), findsNothing);
    expect(patches, isEmpty, reason: 'loading the address is not a change');

    await tester.enterText(
      find.widgetWithText(TextField, 'City'),
      'Springfield',
    );
    await tester.pump(const Duration(milliseconds: 500));
    expect(patches, isEmpty, reason: 'still typing');

    await tester.pump(const Duration(milliseconds: 900));
    await tester.pumpAndSettle();
    expect(patches, hasLength(1));
    expect(patches.single['city'], 'Springfield');
  });

  // UI queue 104, design 7: a place setting is found by address, not typed
  // as coordinates.
  testWidgets('a location setting is set by finding an address', (tester) async {
    Object? written;
    await _pumpSettings(tester, (request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/geocode')) {
        return _json({
          'data': {
            'address': '14 Elm St, Riverton',
            'zip': '06001',
            'exact': true,
            'lat': 41.0534,
            'lng': -73.5387,
          },
        });
      }
      if (path.endsWith('/field/map/tiles')) return _json({'message': 'No.'}, 403);
      final row = _row(
        'time_clock.geofence_center',
        label: 'Clock-in zone centre',
        type: 'location',
        value: written ?? '',
        source: _source('organization', 'org-1', 'Everdoors', false),
      );
      if (request.method == 'GET') return _json({'data': [row]});
      written = (jsonDecode(request.body) as Map)['value'];
      return _json({'data': {...row, 'value': written}});
    });

    await tester.tap(find.byKey(const ValueKey('location-setting')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('location-address')),
      '14 Elm St, Riverton',
    );
    await tester.tap(find.byKey(const ValueKey('location-find')));
    await tester.pumpAndSettle();

    expect(find.text('14 Elm St, Riverton'), findsWidgets);
    expect(find.byKey(const ValueKey('location-map')), findsNothing);
    expect(find.text('See it on Google Maps'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('location-save')));
    await tester.pumpAndSettle();
    expect(written, '41.053400, -73.538700');
  });

  testWidgets('changing a setting moves its row and nothing else', (
    tester,
  ) async {
    _wide(tester);

    var reads = 0;

    // Held open so the screen can be inspected WHILE the write is in flight —
    // that is the moment the spinner used to appear.
    final write = Completer<http.Response>();

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.method == 'GET') {
        reads++;
        return _json({
          'data': [
            _row(
              'time_format',
              label: 'Time format',
              type: 'enum',
              value: '12h',
              options: const ['12h', '24h'],
              source: _source('organization', 'org-1', 'Everdoors', true),
            ),
          ],
        });
      }

      return write.future;
    });

    expect(reads, 1);
    expect(find.text('Inherited from Everdoors'), findsOneWidget);

    await tester.tap(find.byType(SearchPickerField<Object>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('24h').last);
    await tester.pump();

    // Mid-flight. The old screen is still the honest one: the server has not
    // answered, so nothing is known to have changed yet — and there is nothing
    // to hide behind a spinner.
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.text('Inherited from Everdoors'), findsOneWidget);

    write.complete(
      _json({
        'data': _row(
          'time_format',
          label: 'Time format',
          type: 'enum',
          value: '24h',
          options: const ['12h', '24h'],
          source: _source('franchise', 'fr-1', 'Connecticut', false),
        ),
      }),
    );
    await tester.pumpAndSettle();

    expect(
      reads,
      1,
      reason:
          'The write already said what the setting now is. Asking again is the '
          'round trip that blanked the screen.',
    );
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.text('Saved'), findsOneWidget);

    // The row picked up BOTH halves of the answer — the value and where it now
    // comes from. Source is the half the client could not have worked out.
    expect(
      tester
          .widget<SearchPickerField<Object>>(
            find.byType(SearchPickerField<Object>),
          )
          .value,
      '24h',
    );
    expect(find.text('Set here'), findsOneWidget);
    expect(find.text('Inherited from Everdoors'), findsNothing);
  });

  testWidgets('an enum offers the words, not the key', (tester) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          _row(
            'locale',
            label: 'Language',
            type: 'enum',
            value: 'en',
            options: const [
              {'value': 'en', 'label': 'English'},
              {'value': 'es', 'label': 'Spanish'},
            ],
            source: null,
          ),
        ],
      });
    });

    expect(find.text('English'), findsWidgets);
    expect(find.text('en'), findsNothing);
    expect(find.byType(SearchPickerField<Object>), findsOneWidget);
    expect(find.byType(DropdownButton<String>), findsNothing);
  });

  // UI audit 2026-10-08: AI Assistance's welcome message read "Choose an
  // optio" in the box a number gets.
  testWidgets('a sentence gets the width of one, and reads from the left', (
    tester,
  ) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          _row(
            'ai_assistance.welcome',
            label: 'Welcome message',
            type: 'string',
            value: 'Choose an option below.',
            source: null,
          ),
          _row(
            'jobs.tax_rate',
            label: 'Tax rate',
            type: 'decimal',
            value: 8.5,
            source: null,
          ),
        ],
      });
    });

    final sentence = find.byWidgetPredicate(
      (w) => w is TextField && w.controller?.text == 'Choose an option below.',
    );
    final number = find.byWidgetPredicate(
      (w) => w is TextField && w.controller?.text == '8.5',
    );
    expect(tester.getSize(sentence).width, 440);
    expect(tester.widget<TextField>(sentence).textAlign, TextAlign.start);
    expect(tester.getSize(number).width, 150);
    expect(tester.widget<TextField>(number).textAlign, TextAlign.right);
  });

  testWidgets('on a phone a row reads across, with its control underneath', (
    tester,
  ) async {
    // The Android emulator's width. Here the picker and the reset slot used to
    // take the row and leave the label about one letter wide, so every word on
    // the screen ran downwards a character at a time.
    tester.view.physicalSize = const Size(411, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          _row(
            'locale',
            label: 'Language',
            type: 'enum',
            value: 'en',
            options: const [
              {'value': 'en', 'label': 'English'},
            ],
            source: null,
          ),
        ],
      });
    });

    final label = find.text('Language');
    expect(tester.getSize(label).height, lessThan(30));
    expect(
      tester.getTopLeft(find.byType(SearchPickerField<Object>)).dy,
      greaterThan(tester.getBottomLeft(label).dy),
    );
  });

  /// Inside Work Hours › Settings: that tab's rows, that tab's count, and none
  /// of the organization's own cards (UI review, 2026-10-08).
  for (final onlyGroup in [null, 'work_hours']) {
    testWidgets('a module tab counts and draws only its own group '
        '(onlyGroup: $onlyGroup)', (tester) async {
      _wide(tester);

      await _pumpSettings(tester, (request) async {
        if (request.url.path.endsWith('/me')) return _json({'data': _me});

        return _json({
          'data': [
            _row(
              'locale',
              label: 'Language',
              type: 'string',
              value: 'en',
              source: null,
            ),
            _row(
              'timezone',
              label: 'Timezone',
              type: 'string',
              value: 'UTC',
              source: null,
            ),
            _row(
              'time_clock.enabled',
              label: 'Clock in',
              type: 'bool',
              value: false,
              source: null,
              group: 'work_hours',
              groupLabel: 'Work Hours',
            ),
          ],
        });
      }, onlyGroup: onlyGroup);

      final field = tester.widget<TextField>(find.byType(TextField).first);

      if (onlyGroup == null) {
        // The control: the whole page counts everything and draws the card.
        expect(field.decoration?.hintText, 'Search 3 settings');
        expect(find.byType(SendingServerPanel), findsOneWidget);
      } else {
        expect(field.decoration?.hintText, 'Search 1 settings');
        expect(find.byType(SendingServerPanel), findsNothing);
      }
    });
  }

  testWidgets('a code-to-place map keeps a migrated name and adds a line', (
    tester,
  ) async {
    _wide(tester);
    Object? sent;

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});
      if (request.method == 'PATCH') {
        sent = (jsonDecode(request.body) as Map)['value'];
        return _json({'data': null}, 422);
      }

      return _json({
        'data': [
          _row(
            'leads.line_markets',
            label: 'Line markets',
            type: 'franchise_map',
            // A name from before the type: not an id, and must survive.
            value: const {'NJ': 'New Jersey'},
            options: const [
              {'value': 'fr-ct', 'label': 'Connecticut'},
            ],
            source: null,
          ),
        ],
      });
    });

    expect(find.text('1 set'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('franchise-map-button')));
    await tester.pumpAndSettle();
    expect(find.text('New Jersey — not a place'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('map-add')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('map-code:1')), 'CT');
    await tester.tap(find.byKey(const ValueKey('map-place:1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Connecticut').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(sent, {'NJ': 'New Jersey', 'CT': 'fr-ct'});
  });

  testWidgets('bounds are said under the row, and roles tick like a subset', (
    tester,
  ) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          {
            ..._row(
              'field.estimate_mph',
              label: 'Speed',
              type: 'int',
              value: 35,
              source: null,
            ),
            'min': 5,
          },
          _row(
            'field.map_roles',
            label: 'Roles on the map',
            type: 'roles',
            value: const <String>[],
            options: const [
              {'value': 'r-1', 'label': 'Technician'},
            ],
            source: null,
          ),
        ],
      });
    });

    expect(find.text('At least 5.'), findsOneWidget);
    expect(find.text('None chosen'), findsOneWidget);
  });

  testWidgets('a company setting is a picker of company names', (tester) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          _row(
            'leads.calls_company',
            label: 'Company that takes calls',
            type: 'company',
            value: 'co-1',
            options: const [
              {'value': 'co-1', 'label': 'A-1 Chimney'},
              {'value': 'co-2', 'label': 'Everdoors Garage'},
            ],
            source: null,
          ),
        ],
      });
    });

    expect(find.byType(SearchPickerField<Object>), findsOneWidget);
    expect(find.text('A-1 Chimney'), findsWidgets);
    expect(find.text('co-1'), findsNothing);
  });

  testWidgets('a subset is ticked from the words, and saved as the values', (
    tester,
  ) async {
    _wide(tester);

    Object? sent;

    Map<String, dynamic> row(List<String> value) => _row(
      'jobs.earned_statuses',
      label: 'Statuses that mean the money is earned',
      type: 'subset',
      value: value,
      options: const [
        {'value': 'done', 'label': 'Done and paid'},
        {'value': 'cancelled', 'label': 'Cancelled'},
        {'value': 'quoted', 'label': 'Quoted'},
      ],
      source: null,
    );

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.method == 'PATCH') {
        sent = (jsonDecode(request.body) as Map)['value'];
        return _json({'data': row(List<String>.from(sent as List))});
      }

      // `gone` was a status once. It is not offered, so it is not counted.
      return _json({
        'data': [
          row(['cancelled', 'gone']),
        ],
      });
    });

    expect(find.text('1 of 3 chosen'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('subset-button')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Done and paid'));
    await tester.pump();
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    // The list's order, the stored values, and no `gone`.
    expect(sent, ['done', 'cancelled']);
    expect(find.text('2 of 3 chosen'), findsOneWidget);
  });

  testWidgets(
    'a refused change leaves the control on the value the server has',
    (tester) async {
      _wide(tester);

      var reads = 0;

      await _pumpSettings(tester, (request) async {
        if (request.url.path.endsWith('/me')) return _json({'data': _me});

        if (request.method == 'GET') {
          reads++;
          return _json({
            'data': [
              _row(
                'notifications.email_enabled',
                label: 'Send email notifications',
                type: 'bool',
                value: false,
                source: null,
              ),
            ],
          });
        }

        return _json({
          'message': 'That franchise is outside your assignments.',
        }, 403);
      });

      await tester.tap(find.byType(Switch));
      await tester.pumpAndSettle();

      // A switch left sitting in the position the server refused reads as saved,
      // which is worse than one that never moved.
      expect(tester.widget<Switch>(find.byType(Switch)).value, isFalse);
      expect(
        find.text('That franchise is outside your assignments.'),
        findsOneWidget,
      );
      expect(
        reads,
        1,
        reason: 'A refusal changes nothing, so there is nothing to refetch.',
      );
    },
  );

  /// The same promise, for the one control that could not keep it.
  ///
  /// A switch and a dropdown are drawn from the row, so a refused write leaves
  /// them where they were for free. A text field holds its own copy of what was
  /// typed, and nothing put it back — so the rejected number sat in the box
  /// looking exactly like a saved one. `_update`'s comment said "nothing on
  /// screen moves"; for this control it did, and stayed moved.
  testWidgets('a refused number goes back to the value the server still has', (
    tester,
  ) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.method == 'GET') {
        return _json({
          'data': [
            _row(
              'money.tax_rate',
              label: 'Default tax rate (%)',
              type: 'decimal',
              value: 6.625,
              source: null,
            ),
          ],
        });
      }

      return _json({'message': _refusedToChange('Default tax rate (%)')}, 403);
    });

    await tester.enterText(_valueField, '99');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(find.text(_refusedToChange('Default tax rate (%)')), findsOneWidget);
    expect(
      tester.widget<TextField>(_valueField).controller?.text,
      '6.625',
      reason:
          'The server still holds 6.625. A box showing 99 after a refusal is a '
          'value that looks saved and is not.',
    );
  });

  testWidgets('resetting a setting splices in the row it now inherits', (
    tester,
  ) async {
    _wide(tester);

    var reads = 0;

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.method == 'GET') {
        reads++;
        return _json({
          'data': [
            _row(
              'money.tax_rate',
              label: 'Default tax rate (%)',
              type: 'decimal',
              value: 6.625,
              source: _source('franchise', 'fr-1', 'Connecticut', false),
            ),
          ],
        });
      }

      return _json({
        'data': _row(
          'money.tax_rate',
          label: 'Default tax rate (%)',
          type: 'decimal',
          value: 8.5,
          source: _source('company', 'co-1', 'Northeast', true),
        ),
      });
    });

    expect(find.text('Set here'), findsOneWidget);

    await tester.tap(find.byIcon(Icons.settings_backup_restore));
    await tester.pumpAndSettle();

    expect(reads, 1);

    // Which ancestor caught it is the server's answer, not a guess from the
    // scope on screen — dropping a franchise override can land on the company,
    // the organization, or nothing at all.
    expect(find.text('Inherited from Northeast'), findsOneWidget);
    expect(find.text('Now inherited from Northeast'), findsOneWidget);

    expect(tester.widget<TextField>(_valueField).controller?.text, '8.5');
  });

  testWidgets('a write answering with an unexpected body falls back to a reload', (
    tester,
  ) async {
    _wide(tester);

    var reads = 0;

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.method == 'GET') {
        reads++;
        return _json({
          'data': [
            _row(
              'notifications.email_enabled',
              label: 'Send email notifications',
              type: 'bool',
              // The second read is the truth the fallback goes and gets.
              value: reads > 1,
              source: null,
            ),
          ],
        });
      }

      // A row for a different setting — the shape is plausible, the content is
      // not about the thing that was written.
      return _json({
        'data': {'key': 'money.tax_rate', 'value': true},
      });
    });

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    expect(
      reads,
      2,
      reason:
          'Nothing spliceable came back, so the only honest option is to go and '
          'ask — a stale control that looks saved is the failure worth a spinner.',
    );
    expect(tester.widget<Switch>(find.byType(Switch)).value, isTrue);
  });

  // ── what the row says ──────────────────────────────────────────────

  /// Headings are the server's words, not the front of the key.
  ///
  /// They used to be `key.split('.').first.toUpperCase()`, so the numbering
  /// settings sat under "NUMBERING" and everything without a dot under
  /// "GENERAL". Nobody wrote those; they fell out of identifiers. A Dart map of
  /// prefix to English would have been the same bug one layer along — the fact
  /// that `numbering.*` means "Document numbering" is not recoverable from the
  /// string, so it travels with the row.
  testWidgets('group headings come from the server', (tester) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          _row(
            'numbering.pad_to_digits',
            label: 'Pad numbers to',
            type: 'int',
            value: 5,
            source: null,
            group: 'numbering',
            groupLabel: 'Document numbering',
          ),
          _row(
            'security.password_min_length',
            label: 'Minimum password length',
            type: 'int',
            value: 12,
            source: null,
            group: 'security',
            groupLabel: 'Security',
          ),
        ],
      });
    });

    expect(find.text('Document numbering'), findsOneWidget);
    expect(find.text('Security'), findsOneWidget);
    expect(find.text('NUMBERING'), findsNothing);
    expect(find.text('SECURITY'), findsNothing);
  });

  /// A row with no group is not given an invented one.
  testWidgets('a row the server did not group gets no made-up heading', (
    tester,
  ) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          _row(
            'ops.default_job_duration_minutes',
            label: 'Default job duration',
            type: 'int',
            value: 60,
            source: null,
            group: null,
            groupLabel: null,
          ),
        ],
      });
    });

    expect(find.text('Default job duration'), findsOneWidget);
    expect(find.text('OPS'), findsNothing);
    expect(find.text('Ops'), findsNothing);
  });

  /// The distinction the screen has to draw, in one list.
  ///
  /// Two rows nobody can act on today, for entirely different reasons, and they
  /// must not look the same. One is read-only because of who you are; its
  /// control is dead and the row carries the server's own refusal. The other is
  /// live — the
  /// value saves, audits and inherits exactly as any other — and does nothing,
  /// because no code reads it yet. Greying the second would say "not for you",
  /// which is a different lie from the one being fixed.
  testWidgets(
    'a setting that is not in effect is marked, and does not read as disabled',
    (tester) async {
      _wide(tester);

      await _pumpSettings(tester, (request) async {
        if (request.url.path.endsWith('/me')) return _json({'data': _me});

        return _json({
          'data': [
            _row(
              'money.tax_inclusive_pricing',
              label: 'Prices include tax',
              type: 'bool',
              value: false,
              source: null,
              group: 'money',
              groupLabel: 'Money and tax',
              notYetInEffect: 'Takes effect when invoicing ships.',
            ),
            _row(
              'notifications.email_enabled',
              label: 'Send email notifications',
              type: 'bool',
              value: true,
              source: null,
              group: 'money',
              groupLabel: 'Money and tax',
              mayEdit: false,
            ),
          ],
        });
      });

      // Said out loud, in the server's own words about what it is waiting for.
      expect(
        find.text('Not in effect yet — Takes effect when invoicing ships.'),
        findsOneWidget,
      );

      final switches = find.byType(Switch);

      expect(
        tester.widget<Switch>(switches.at(0)).onChanged,
        isNotNull,
        reason:
            'Pending is not read-only. The value is still worth setting up in '
            'advance, and locking the control would trade one lie for another.',
      );

      // The row beside it, which IS read-only, still looks read-only.
      expect(tester.widget<Switch>(switches.at(1)).onChanged, isNull);
      expect(
        find.text(_refusedToChange('Send email notifications')),
        findsOneWidget,
      );
    },
  );

  /// Provenance survives everything else the row has to say.
  ///
  /// It used to be one chain of ternaries printing EITHER where the value came
  /// from OR why you could not change it, so a read-only row went silent about
  /// its inheritance — on the screen that exists to show inheritance.
  testWidgets('a read-only row still says where its value came from', (
    tester,
  ) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          _row(
            'money.tax_rate',
            label: 'Default tax rate (%)',
            type: 'decimal',
            value: 8.5,
            source: _source('company', 'co-1', 'Northeast', true),
            mayEdit: false,
          ),
        ],
      });
    });

    expect(find.text('Inherited from Northeast'), findsOneWidget);

    // R8: what is missing and who can hand it over, and not one word of the
    // wire. A dotted key on a customer's screen is the defect this replaced.
    expect(find.text(_refusedToChange('Default tax rate (%)')), findsOneWidget);
    expect(find.textContaining('organizations.edit'), findsNothing);
  });

  /// A setting this scope cannot hold names the levels that can — from
  /// `allowed_scopes`, in the tenant's word for a franchise.
  ///
  /// The row used to print the fixed words "Set at organization level only",
  /// which is true of every restricted setting today and false the first time
  /// one is company-and-franchise. It also lost to the "EzBizi default" branch
  /// whenever nothing was set anywhere, so a franchise looking at a security
  /// policy was told the platform default and nothing about why the control was
  /// dead.
  testWidgets('a setting this scope cannot hold says which levels can', (
    tester,
  ) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          _row(
            'security.password_min_length',
            label: 'Minimum password length',
            type: 'int',
            value: 12,
            source: null,
            editableHere: false,
            mayEdit: false,
            allowedScopes: const ['organization', 'company'],
          ),
        ],
      });
    });

    expect(
      find.text('EzBizi default'),
      findsOneWidget,
      reason: 'Still true, and still the answer to a different question.',
    );
    expect(
      find.text('Only the organization or a company can set this.'),
      findsOneWidget,
    );
  });

  /// Thirty rows is a list people scroll past. Search is how they stop.
  testWidgets('search narrows the list, and the counts say what is set here', (
    tester,
  ) async {
    _wide(tester);

    await _pumpSettings(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      return _json({
        'data': [
          _row(
            'security.password_min_length',
            label: 'Minimum password length',
            type: 'int',
            value: 16,
            source: _source('franchise', 'fr-1', 'Connecticut', false),
            group: 'security',
            groupLabel: 'Security',
          ),
          _row(
            'money.tax_rate',
            label: 'Default tax rate (%)',
            type: 'decimal',
            value: 8.5,
            source: _source('company', 'co-1', 'Northeast', true),
            group: 'money',
            groupLabel: 'Money and tax',
          ),
        ],
      });
    });

    // One of the two is this scope's own doing. That count is the summary the
    // screen owes somebody arriving at a franchise they did not configure.
    expect(find.text('Set here · 1'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, 'tax');
    await tester.pumpAndSettle();

    expect(find.text('Default tax rate (%)'), findsOneWidget);
    expect(find.text('Minimum password length'), findsNothing);
    expect(find.text('Security'), findsNothing);

    // Nothing matching is a state with a way out of it.
    await tester.enterText(find.byType(TextField).first, 'zzz');
    await tester.pumpAndSettle();

    expect(find.text('Nothing matches “zzz”.'), findsOneWidget);

    await tester.tap(find.text('Show all settings'));
    await tester.pumpAndSettle();

    expect(find.text('Minimum password length'), findsOneWidget);
    expect(find.text('Default tax rate (%)'), findsOneWidget);
  });

  // ── the organization's own data (§9) ───────────────────────────

  /// The two obligations that had nowhere else to go.
  ///
  /// "Import & export" was a tab of its own carrying three things: exporting
  /// and importing customers, the same for jobs, and these two — everything
  /// this organization holds, and the record of what it holds about people.
  /// The first two moved into the Customers and Jobs tabs when the tab was
  /// removed. These are not about a resource, so they had no tab to move into,
  /// and dropping them would have deleted a §9 answer from the product.
  ///
  /// Which is why this group exists rather than a note in a commit message: the
  /// failure mode of removing a screen is that the parts nobody was looking at
  /// go with it, silently, and the people who need these two are the ones who
  /// ask once a year.
  group('the organization’s data section', () {
    testWidgets('offers both §9 answers at organization scope', (tester) async {
      _wide(tester);

      await _pumpSettings(tester, _oneSetting, exports: _exports());

      expect(find.text('This organization’s data'), findsOneWidget);
      expect(find.text('Export everything'), findsWidgets);
      expect(find.text('Open the record'), findsOneWidget);

      // Live, not decoration.
      expect(_enabled(tester, 'Export everything'), isTrue);
      expect(_enabled(tester, 'Open the record'), isTrue);
    });

    /// R8: a control somebody may not use stays on screen and says why, in the
    /// server's words. The alternative — omitting it — leaves an administrator
    /// unable to tell "we do not offer this" from "my role is missing a grant",
    /// and only one of those has a fix.
    testWidgets('shows the server’s sentence when it is refused', (
      tester,
    ) async {
      _wide(tester);

      const refusal =
          'You do not have permission to export everything this organization '
          'holds. That is part of a role rather than of an account, so somebody '
          'who can edit roles can add it to yours.';

      await _pumpSettings(
        tester,
        _oneSetting,
        exports: _exports(
          exportRefusal: refusal,
          inventoryRefusal: 'You do not have permission to read the record.',
        ),
      );

      expect(find.text('This organization’s data'), findsOneWidget);
      expect(_enabled(tester, 'Export everything'), isFalse);
      expect(_enabled(tester, 'Open the record'), isFalse);

      // The sentence itself is on the control, reachable by hover, long press
      // and a screen reader alike — see Refused.
      final tooltips = tester
          .widgetList<Tooltip>(find.byType(Tooltip))
          .map((t) => t.message)
          .toList();

      expect(tooltips, contains(refusal));
    });

    /// Both answers cover the whole business. There is no franchise-sized
    /// version of "export everything this organization holds", and offering one
    /// under a franchise's heading would claim there is.
    testWidgets('is absent at franchise scope', (tester) async {
      _wide(tester);

      await _pumpSettings(tester, _oneSettingAtFranchise, exports: _exports());

      expect(find.text('Minimum password length'), findsOneWidget);
      expect(find.text('This organization’s data'), findsNothing);
    });

    /// Somebody typing "export" into the settings search is looking for exactly
    /// this, and a section that vanished the moment they searched would be
    /// findable only by scrolling past everything else.
    testWidgets('survives a search for its own words', (tester) async {
      _wide(tester);

      await _pumpSettings(tester, _oneSetting, exports: _exports());

      await tester.enterText(find.byType(TextField).first, 'export');
      await tester.pumpAndSettle();

      expect(find.text('This organization’s data'), findsOneWidget);
      // And it is not claiming to be a setting: the settings themselves are
      // filtered out by the same query.
      expect(find.text('Minimum password length'), findsNothing);
    });
  });

  // ── the address this business sends its own mail from (§6) ──────

  /// The card belongs to this screen, so this is about it BEING here and
  /// obeying the screen's rules. What it says about inheritance, verification
  /// and refusals is test/sending_identity_test.dart.
  // Jose, 2026-10-22: "the work hours setting could be separated in their
  // respective categories". A row's `section` is a sub-heading in its group.
  group('sections', () {
    Future<void> pumpRows(
      WidgetTester tester,
      List<Map<String, dynamic>> rows,
    ) {
      tester.view.physicalSize = const Size(1280, 3000);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      return _pumpSettings(tester, (request) async {
        if (request.url.path.endsWith('/me')) return _json({'data': _me});
        return _json({'data': rows});
      }, onlyGroup: 'work_hours');
    }

    Map<String, dynamic> wh(String key, String label, String? section) => _row(
      key,
      label: label,
      type: 'bool',
      value: false,
      source: _source('organization', 'org-1', 'Everdoors', false),
      group: 'work_hours',
      groupLabel: 'Work Hours',
      section: section,
    );

    final rows = [
      wh('time_clock.enabled', 'Clock in', 'Clock in and lock screen'),
      wh(
        'time_clock.approval_enabled',
        'Daily approval of hours',
        'Daily approval',
      ),
      wh('time_clock.max_hours', 'Longest shift', 'Clock in and lock screen'),
      wh('pto.enabled', 'Paid days', 'Paid time off'),
      wh('time_clock.odd', 'Unsorted one', null),
    ];

    double top(WidgetTester tester, String text) =>
        tester.getTopLeft(find.text(text).first).dy;

    testWidgets(
      'headings render in first-seen order, ungrouped last as Other',
      (tester) async {
        await pumpRows(tester, rows);

        final order = [
          'Clock in and lock screen',
          'Clock in',
          'Longest shift',
          'Daily approval',
          'Daily approval of hours',
          'Paid time off',
          'Paid days',
          'Other',
          'Unsorted one',
        ];
        for (final text in order) {
          expect(find.text(text), findsOneWidget, reason: text);
        }
        for (var i = 1; i < order.length; i++) {
          expect(
            top(tester, order[i - 1]) < top(tester, order[i]),
            isTrue,
            reason: '${order[i - 1]} above ${order[i]}',
          );
        }
      },
    );

    testWidgets('search hides sections with no match', (tester) async {
      await pumpRows(tester, rows);

      await tester.enterText(find.byType(TextField).first, 'approval');
      await tester.pumpAndSettle();

      expect(find.text('Daily approval'), findsOneWidget);
      expect(find.text('Daily approval of hours'), findsOneWidget);
      expect(find.text('Clock in and lock screen'), findsNothing);
      expect(find.text('Paid time off'), findsNothing);
      expect(find.text('Other'), findsNothing);
      expect(find.text('Unsorted one'), findsNothing);
    });

    testWidgets('rows with no section draw with no sub-heading', (
      tester,
    ) async {
      await pumpRows(tester, [
        wh('time_clock.enabled', 'Clock in', null),
        wh('pto.enabled', 'Paid days', null),
      ]);

      expect(find.text('Clock in'), findsOneWidget);
      expect(find.text('Paid days'), findsOneWidget);
      expect(find.text('Other'), findsNothing);
      expect(find.byType(Card), findsOneWidget);
    });
  });
}

/// Is the button carrying this label pressable?
bool _enabled(WidgetTester tester, String label) =>
    tester
        .widget<ButtonStyleButton>(
          find.ancestor(
            of: find.text(label),
            matching: find.byType(OutlinedButton),
          ),
        )
        .onPressed !=
    null;

/// A server with one setting on it, at organization scope.
Future<http.Response> _oneSetting(http.Request request) async {
  if (request.url.path.endsWith('/me')) {
    return _json({
      'data': {
        ..._me,
        'scope': {
          'type': 'organization',
          'id': 'org-1',
          'label': 'Everdoors',
          'path': ['Everdoors'],
        },
      },
    });
  }

  return _json({
    'data': [
      _row(
        'security.password_min_length',
        label: 'Minimum password length',
        type: 'integer',
        value: 12,
        source: _source('organization', 'org-1', 'Everdoors', false),
        group: 'security',
        groupLabel: 'Security',
      ),
    ],
  });
}

/// The same server, from a franchise.
Future<http.Response> _oneSettingAtFranchise(http.Request request) async {
  if (request.url.path.endsWith('/me')) return _json({'data': _me});

  return _json({
    'data': [
      _row(
        'security.password_min_length',
        label: 'Minimum password length',
        type: 'integer',
        value: 12,
        source: _source('organization', 'org-1', 'Everdoors', true),
        group: 'security',
        groupLabel: 'Security',
      ),
    ],
  });
}

// ── harness ────────────────────────────────────────────────────────

void _wide(WidgetTester tester) {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

/// The text field holding a setting's VALUE, as opposed to the search box in
/// the toolbar. Only the value fields live inside the cards.
final Finder _valueField = find.descendant(
  of: find.byType(Card),
  matching: find.byType(TextField),
);

/// Put the settings screen on screen with a canned server behind it.
///
/// The session is adopted through /me exactly as the real app does, because the
/// screen reads its scope off `session.me` and a hand-built one would let the
/// test disagree with the client's own parsing.
///
/// `/exports` is answered HERE rather than being passed down to [handler].
///
/// The screen asks it once — to decide whether this person may take the two
/// whole-organization §9 answers at the bottom of the page — and every test
/// above counts `GET /settings` to prove a write does not refetch the page. A
/// second endpoint landing in the same counter would make those numbers about
/// something else. [exports] overrides it for the tests that are about that
/// section.
///
/// `/mail/sending-identity` was answered here too until 2026-09-14, when Jose
/// had the sending-identity card removed from this screen (b32fc797, "I HAVE
/// ANSWERED 4 TIMES ALREADY ... GET RID OF IT"). Its four tests went with it
/// rather than being left green: "is absent when the server does not answer
/// for it" would have passed forever against a screen that can no longer draw
/// the card at all, which is a test asserting a retracted claim in its own
/// name.
Future<void> _pumpSettings(
  WidgetTester tester,
  MockClientHandler handler, {
  Map<String, dynamic>? exports,
  String? onlyGroup,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/exports')) {
        return _json(exports ?? _exports());
      }

      /*
       * Answered here, like /exports, so these tests stay about SETTINGS.
       *
       * The mail-server card was added to this screen on 2026-09-15 and fetches
       * its own answer on build. Without this, its GET falls through to each
       * test's handler — and four of them count reads, so `expect(reads, 1)`
       * saw 2 and failed on a request that has nothing to do with the row under
       * test.
       *
       * `in_effect` is the field the card requires before it will draw
       * anything; this shape makes it render its empty state, which is what
       * every one of these tests wants it to be doing while they look at
       * something else.
       */
      if (request.url.path.endsWith('/mail/sending-server')) {
        return _json({
          'data': {
            'connected_here': null,
            'in_effect': {
              'from_address': 'noreply@ezbizi.test',
              'from_name': 'EzBizi',
              'transport': 'platform',
              'transport_label': 'EzBizi',
              'source_name': null,
              'inherited': false,
            },
            'may_edit': false,
            'may_edit_reason': null,
            'ports': [587, 465],
          },
        });
      }

      return handler(request);
    }),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(
    session.signedIn,
    isTrue,
    reason: 'The /me stub did not produce a session.',
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SettingsScreen(api: api, session: session, onlyGroup: onlyGroup),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// `GET /exports`, in the shape DataController::catalogue answers.
///
/// The `organization` block carries one nullable sentence per §9 answer rather
/// than a flag beside a reason — null means the control works, a string is what
/// the screen shows instead.
Map<String, dynamic> _exports({
  String? exportRefusal,
  String? inventoryRefusal,
}) => {
  'resources': <dynamic>[],
  'organization': {
    'export_refusal': exportRefusal,
    'inventory_refusal': inventoryRefusal,
  },
};

/// One row in the shape SettingsController builds for every endpoint.
Map<String, dynamic> _row(
  String key, {
  required String label,
  required String type,
  required dynamic value,
  required Map<String, dynamic>? source,
  List<dynamic>? options,
  String? group = 'general',
  String? groupLabel = 'General',
  bool editableHere = true,
  bool mayEdit = true,

  /// Why not, in the server's words. The row used to carry the bare permission
  /// key instead and the screen printed it; see the read-only tests.
  String? mayEditReason,
  List<String> allowedScopes = const ['organization', 'company', 'franchise'],
  bool isSensitive = false,

  /// Null means the setting does something. A sentence says what has to ship
  /// before it does — one field, so the flag and the reason cannot disagree.
  String? notYetInEffect,
  String? section,
}) => {
  'key': key,
  'label': label,
  'type': type,
  'group': group,
  'group_label': groupLabel,
  'section': section,
  'value': value,
  'is_sensitive': isSensitive,
  'options': options,
  'help': null,
  'not_yet_in_effect': notYetInEffect,
  'editable_here': editableHere,
  'may_edit': mayEdit,
  'may_edit_reason': mayEdit
      ? null
      : (mayEditReason ?? _refusedToChange(label)),
  'allowed_scopes': allowedScopes,
  'source': source,
};

Map<String, dynamic> _source(
  String type,
  String id,
  String name,
  bool inherited,
) => {'type': type, 'id': id, 'name': name, 'inherited': inherited};

/// Signed in, looking at a franchise — the scope where inheritance is visible
/// and therefore where a wrong `source` would show.
const Map<String, dynamic> _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'franchise',
    'id': 'fr-1',
    'label': 'Connecticut',
    'path': ['Everdoors', 'Connecticut'],
  },
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
