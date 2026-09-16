import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/screens/phone/ring_groups_pane.dart';
import 'package:ezbizi_client/screens/phone/settings_tab.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// One list of Phone settings panes, and two doors onto it.
///
/// The settings rail is one door; the drawer the call flow chart opens when
/// somebody clicks a box is the other. Each had its own switch over the same
/// pane ids, which is a duplication that fails silently: add a pane to
/// Settings and the drawer goes on not knowing about it, with nothing red.
///
/// So there is one [phoneSettingsPane] now, and this pins the two halves of
/// its contract that a caller relies on.
void main() {
  late ApiClient api;
  late Session session;

  setUp(() {
    api = ApiClient(
      client: MockClient((_) async => http.Response('{"data": []}', 200)),
    );
    session = Session(api);
  });

  test('a pane id builds the real pane, not a copy of it', () {
    expect(
      phoneSettingsPane('ring-groups', api: api, session: session),
      isA<RingGroupsPane>(),
    );
  });

  /// The drawer's breadcrumb says what the RAIL says, not what a second copy
  /// of the words says.
  ///
  /// `trust` is the case that can tell those apart. The drawer kept its own
  /// map of these labels and that map called this section "Trust"; the rail
  /// has always called it "Trust & caller identity", which is the phrase
  /// somebody scanning the settings list will actually find. Any id whose two
  /// spellings happened to agree would pass against either source.
  test('a label is the rail own word for that section', () {
    expect(phoneSettingsPaneLabel('trust'), 'Trust & caller identity');
    expect(phoneSettingsPaneLabel('ring-groups'), 'Ring groups');
  });

  /// And an id the rail does not list has no name to give.
  ///
  /// Null rather than the id: `phonePaneTrail` stops the breadcrumb on null,
  /// and an internal id is not a place a person can go looking.
  test('an unlisted id has no label', () {
    expect(phoneSettingsPaneLabel('not-a-pane'), isNull);
  });

  /// THE CASE THAT CAN DISTINGUISH.
  ///
  /// `connection` is a real section on the rail, and it is deliberately NOT
  /// buildable here: it reads scopes only the settings tab has loaded. Null is
  /// the answer that makes the drawer show a door to Settings; anything else
  /// would be a pane rendered for an id it cannot serve, which looks like it
  /// worked.
  ///
  /// Asserted beside a nonsense id, because a build that returns null for
  /// everything passes either one alone.
  test(
    'an id it cannot serve is null, and so is one it has never heard of',
    () {
      expect(
        phoneSettingsPane('connection', api: api, session: session),
        isNull,
      );
      expect(
        phoneSettingsPane('not-a-pane', api: api, session: session),
        isNull,
      );
    },
  );
}
