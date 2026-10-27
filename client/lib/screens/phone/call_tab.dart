import 'dart:async';
import '../../core/phone_numbers.dart';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/list_table.dart';
import '../../widgets/book_job_panel.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../customers/customer_detail_panel.dart';
import '../scheduling/availability_pane.dart';
import '../../core/phone_call_service.dart';
import '../../core/timestamps.dart';
import 'staff_thread.dart';
import 'call_history_pane.dart';

/// Call desk — layout 1: teammates, pad, person. Below 600px, one pane at a time.
/// Which of the desk's two faces is showing.
///
/// Both are one widget on purpose. They share the softphone, and a Team tab
/// that built its own would either register a second device or tear the first
/// one down every time somebody looked at the roster — and a softphone that
/// deregisters when you glance at your colleagues is a softphone that misses
/// calls.
enum CallView { dial, team }

class CallTab extends StatefulWidget {
  const CallTab({
    super.key,
    required this.api,
    required this.session,
    this.service,
    this.view = CallView.dial,
    this.initialNumber,
    this.onOpenSettings,
  });

  final ApiClient api;
  final Session session;
  /// The desk. Defaults to the app-wide one the shell brought up; a test
  /// passes its own so it does not have to stub three endpoints to get a
  /// phone that answers.
  final PhoneCallService? service;

  final CallView view;

  /// Typed into the pad on open - a lead's Call button (Jose, 2026-09-30).
  final String? initialNumber;

  /// Opens Communication › Settings, for the empty state's button.
  final VoidCallback? onOpenSettings;

  static const double splitAbove = 600;

  @override
  State<CallTab> createState() => _CallTabState();
}

/// Which single pane a narrow screen is showing.
///
/// `teammates` is GONE, not merely unused. The roster became its own sub-tab
/// beside Dial, and the copy of it inside Dial was reachable only by a back
/// arrow on the pad — an arrow that went FORWARD into a list, on a screen
/// whose only way out was to pick somebody, which selected them as well as
/// leaving. Jose reported it as getting stuck there, which is what it was.
///
/// Removed from the enum rather than left unreferenced so that nothing can
/// route to a pane the app no longer offers a way back from.
///
/// `recents` is the root on a phone (UI queue 35, Jose's 4): the calls first,
/// the keypad a button away with ← back to them, the person in place with ←
/// back to wherever it was opened from. No pane is reachable without a way
/// back out of it.
enum _CallPane { recents, pad, person }

class _CallTabState extends State<CallTab> {
  static const _refusal =
      'This line cannot send texts until messaging is approved.';

  late final PhoneCallService _service;

  final _destination = TextEditingController();
  final _body = TextEditingController();

  bool _loading = true;
  String? _error;
  List<_Number> _numbers = const [];
  List<_Teammate> _teammates = const [];
  List<_Message> _messages = const [];

  String? _numberId;
  String? _teammateId;

  /// The colleague whose thread has taken over the Team column, if any.
  _Teammate? _chatWith;

  /// Unread messages waiting, per sender. Counted per person rather than as
  /// one total, because the roster is where you act on it — a single number
  /// says you have messages without saying whose.
  Map<String, int> _unread = const {};
  _CallPane _pane = _CallPane.recents;

  /// Where the person pane's back arrow returns to on a phone.
  _CallPane _personFrom = _CallPane.pad;
  bool _dnd = false;

  /// Who is ringing and whether audio is up are the SERVICE's answers, not
  /// this screen's. They used to be fields here, which is why they were only
  /// true while this screen existed.
  String? get _incomingFrom => _service.incomingFrom;

  bool get _onCall => _service.onCall;

  /// The call just finished, waiting to be said what it was about.
  ///
  /// Held in the desk rather than left to Records, because the person who knows
  /// is the one who has just put the phone down. A tag chosen a day later by
  /// somebody reading a list is a guess, and a report built on guesses is worse
  /// than one built on nothing.
  String? _untaggedCallId;
  List<_Tag> _tags = const [];
  bool _tagging = false;

  /// The company requires a tag before the desk is usable again.
  ///
  /// A company-wide rule on the connection, not a per-line one — see the
  /// migration. Off unless somebody turned it on, so a company with no tags
  /// set up never finds its dial pad locked by a requirement it did not
  /// choose.
  bool _requireTag = false;

  /// A call is finished and unaccounted for, and this company says that stops
  /// the pad. Both halves matter: without the second, one company's rule would
  /// govern everybody's desk.
  bool get _tagBlocking => _requireTag && _untaggedCallId != null;
  String? _tagError;
  String? _sendError;

  @override
  void initState() {
    super.initState();
    _service = widget.service ?? PhoneCallService.current;
    _service.addListener(_onServiceChanged);

    // The pin, from the person rather than from this screen. Read here and not
    // in the field initialiser because the field belongs to a State that is
    // built fresh every time somebody opens Communication — which is exactly
    // the moment the old pin was lost.
    _customerPinned = widget.session.me?.phoneCustomerPanelPinned ?? false;
    _destination.text = widget.initialNumber ?? '';
    // A lead's Call button opens on the number it filled in, not on a list.
    if (widget.initialNumber != null) _pane = _CallPane.pad;

    _load();
  }

  /// The desk changed under us — a call arrived, was answered, or the
  /// registration finally came up. Never disposes the service: it is the
  /// app's, not this screen's, and outliving this screen is the whole point.
  void _onServiceChanged() {
    if (!mounted) return;
    setState(() {});

    // PARKING IS THE ONE THING SOMEBODY ELSE'S SCREEN CHANGES.
    //
    // A colleague parks a caller on another floor and the person who should
    // collect them is looking at this tab. Nothing about that reaches here on
    // its own, so without a refresh the list is only ever right for whoever
    // did the parking — which is the one person who does not need it.
    //
    // Hung on the tokens the desk already bumps rather than a timer of its
    // own: `presenceToken` is moved once a minute by the monitoring beat, and
    // `callsToken` the instant a call ends. So the list is right within a
    // minute always, and immediately at the moment somebody rings off and
    // goes looking for who is holding.
    final token = _service.presenceToken + _service.callsToken;
    if (token == _wasDeskToken) return;
    _wasDeskToken = token;
    unawaited(_loadParked());
    unawaited(_loadRinging());
  }

  int _wasDeskToken = 0;

  @override
  void dispose() {
    _service.removeListener(_onServiceChanged);
    _suggestDebounce?.cancel();
    _destination.dispose();
    _body.dispose();
    super.dispose();
  }

  _Number? get _selected {
    for (final number in _numbers) {
      if (number.id == _numberId) return number;
    }

    // Nothing chosen: the line assigned to this person on Team, before the
    // first on the account.
    //
    // Falling straight to `.first` is what made every outbound call in the
    // business go out under one number — nobody ever changed the picker, so
    // four people ringing four customers all showed the same line and every
    // callback landed on whoever answered it.
    for (final number in _numbers) {
      if (number.assignedToMe) return number;
    }

    return _numbers.isEmpty ? null : _numbers.first;
  }

  /// The number to dial, in E.164, or null when the typed text is not one.
  ///
  /// ── The bug this existed as for weeks ────────────────────────────
  ///
  /// This used to strip the non-digits and put a `+` in front. So "305 724
  /// 2199" — how anybody in the United States writes a phone number — became
  /// `+3057242199`, which is not that number. It is missing the country code,
  /// and +305 is not a country. Every outbound call went to it.
  ///
  /// Nothing rejected it. It is well-formed enough to pass a length check, so
  /// the client sent it, the server dialled it, and the carrier could not
  /// route it — and the failure surfaced as the line's own voicemail, then as
  /// silence, then as "that call could not be connected". Three separate
  /// defects were found and fixed on the way to this one, each of them real,
  /// none of them the reason.
  ///
  /// ── Why a ten-digit number gets +1 and a nine-digit one gets refused ──
  ///
  /// Ten digits is the North American Numbering Plan and is unambiguous.
  /// Anything shorter is not a number we can complete, and guessing a country
  /// code is exactly what produced +3057242199. A refusal is recoverable; a
  /// plausible wrong number is what cost the week.
  String? get _peer {
    final text = _destination.text.trim();
    if (text.isEmpty) return null;

    // Already E.164: the person said which country, so it is not ours to
    // second-guess.
    if (text.startsWith('+')) {
      final digits = text.substring(1).replaceAll(RegExp(r'\D'), '');

      return digits.length >= 8 ? '+$digits' : null;
    }

    final digits = text.replaceAll(RegExp(r'\D'), '');

    // 1-305-724-2199, written out with its country code but no plus.
    if (digits.length == 11 && digits.startsWith('1')) return '+$digits';

    // 305 724 2199 — the ordinary way it is written here.
    if (digits.length == 10) return '+1$digits';

    // Anything else, including a bare 11+ digits that is not NANP, needs the
    // country said out loud. Refusing is the whole point of this function.
    return null;
  }

  bool get _canSend {
    final number = _selected;
    return number != null &&
        number.smsOutboundAllowed &&
        _peer != null &&
        _body.text.trim().isNotEmpty;
  }

  Future<void> _load() async {
    setState(() {
      _loading = _numbers.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/phone/numbers');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final numbers = [
      for (final row in (response.data as List? ?? const []))
        if (row is Map<String, dynamic>) _Number.fromJson(row),
    ];

    setState(() {
      _loading = false;
      _numbers = numbers;
      // Your own line first, then the account's first.
      //
      // This used to be `numbers.first.id` outright, which quietly defeated
      // the identical preference in [_selected]: that getter falls back to
      // the assigned line only when nothing is chosen, and this line always
      // chose something the moment the list loaded. So the dropdown opened on
      // whichever number happened to sort first — "General Use" — while the
      // person's own line sat below it marked "yours", and every call went
      // out under the wrong one unless they noticed and switched.
      _numberId ??= (numbers.where((n) => n.assignedToMe).firstOrNull
              ?? numbers.firstOrNull)
          ?.id;
    });

    if (numbers.isEmpty) return;
    await _loadCompanyRules();
    await _loadDesk();
    await _loadParked();
    await _loadRinging();
    await _loadGroups();
  }

  /// The rules this company set for its own phone system.
  ///
  /// Read from the connection the chosen line belongs to, so a person covering
  /// two businesses gets each one's rule while they are on its line — rather
  /// than one company's requirement following them onto the other's calls.
  Future<void> _loadCompanyRules() async {
    final number = _selected;
    if (number == null) return;

    final response = await widget.api.get('/phone/connections');
    if (!mounted || !response.ok) return;

    for (final row in (response.data as List? ?? const [])) {
      if (row is! Map<String, dynamic>) continue;
      if (row['id'] != number.connectionId) continue;

      setState(() => _requireTag = row['require_call_tag'] == true);

      return;
    }
  }

  Future<void> _loadDesk() async {
    final number = _selected;
    if (number == null) return;

    final presence = await widget.api.get('/phone/presence', {
      'connection_id': number.connectionId,
    });
    if (!mounted) return;

    await _loadUnread();

    if (presence.ok) {
      setState(() {
        _teammates = [
          for (final row in (presence.data as List? ?? const []))
            if (row is Map<String, dynamic>) _Teammate.fromJson(row),
        ];
        for (final row in _teammates) {
          if (row.userId == widget.session.me!.id) {
            _dnd = row.state == 'dnd';
          }
        }
      });
    }

    await _loadMessages();
  }

  Future<void> _loadMessages() async {
    final number = _selected;
    final peer = _peer;
    if (number == null || peer == null) {
      if (_messages.isEmpty) return;
      setState(() => _messages = const []);
      return;
    }

    final response = await widget.api.get('/phone/messages', {
      'number_id': number.id,
      'peer': peer,
    });
    if (!mounted) return;

    if (!response.ok) return;

    setState(() {
      _messages = [
        for (final row in (response.data as List? ?? const []))
          if (row is Map<String, dynamic>) _Message.fromJson(row),
      ]..sort((a, b) => a.createdAt.compareTo(b.createdAt));
    });
  }

  /// Who is on hold, for everyone who shares these lines.
  ///
  /// Refetched rather than tracked: parking is the one thing here that another
  /// person's screen changes. A list kept from what THIS desk did would show a
  /// caller a colleague collected twenty minutes ago, and somebody would press
  /// Pick up on an empty queue.
  Future<void> _loadParked() async {
    final response = await widget.api.get('/phone/calls/parked');
    if (!mounted || !response.ok) return;

    setState(() {
      _parked = [
        for (final row in (response.data as List? ?? const []))
          if (row is Map<String, dynamic>) _Parked.fromJson(row),
      ];
    });
  }

  /// What is ringing elsewhere, for everyone who shares these lines.
  Future<void> _loadRinging() async {
    final response = await widget.api.get('/phone/calls/ringing');
    if (!mounted || !response.ok) return;

    setState(() {
      _ringing = [
        for (final row in (response.data as List? ?? const []))
          if (row is Map<String, dynamic>) _Ringing.fromJson(row),
      ];
    });
  }

  /// Take a call that is ringing somebody else.
  Future<void> _takeRinging(_Ringing ringing) async {
    final response = await widget.api.post(
      '/phone/calls/${ringing.id}/pickup',
      const {},
    );
    if (!mounted) return;

    if (!response.ok) {
      setState(() => _bridgeNote = response.reason);
    }

    // Reloaded either way. A refusal is almost always "that call is no longer
    // ringing", which means the list is what is wrong and leaving the row up
    // invites a second press at the same nothing.
    await _loadRinging();
  }

  /// Set this call down where anybody can pick it up.
  Future<void> _park() async {
    final response = await widget.api.post('/phone/calls/park', const {});
    if (!mounted) return;

    if (!response.ok) {
      setState(() => _bridgeNote = response.reason);

      return;
    }

    // The audio has gone from this device — the customer is holding on
    // Twilio's side now — so the desk is off the call even though nobody hung
    // up. Without this the pad keeps offering Hang up for a call that is no
    // longer here.
    _service.hangUp();

    setState(() => _historyToken++);
    await _loadParked();

    final number = _selected;
    if (number == null) return;
    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': _dnd ? 'dnd' : 'registered',
    });
  }

  /// Fetch a parked caller onto this device.
  ///
  /// An ordinary outbound call whose destination happens to be a queue, which
  /// is why there is no pick-up endpoint: the server hands us the destination
  /// and dialling it IS the retrieval.
  Future<void> _pickUp(_Parked parked) async {
    final number = _selected;
    if (number == null) return;

    await _service.connect(to: parked.pickUp, callerId: number.e164);
    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': 'on_call',
    });

    if (!mounted) return;
    await _loadParked();
  }

  /// Teams that can be paged, for every line this person can reach.
  ///
  /// Loaded once with the rest of the desk rather than when the menu opens:
  /// a list fetched on press is a press that does nothing for a moment, and
  /// the thing being pressed is an announcement somebody is waiting to make.
  Future<void> _loadGroups() async {
    final response = await widget.api.get('/phone/ring-groups');
    if (!mounted || !response.ok) return;

    setState(() {
      _groups = [
        for (final row in (response.data as List? ?? const []))
          if (row is Map<String, dynamic>) _Group.fromJson(row),
      ];
    });
  }

  /// Speak to a whole team's handsets at once.
  ///
  /// The server connects the LISTENERS and hands back a destination; dialling
  /// it is what puts this person's microphone into the announcement. Same
  /// shape as picking up a parked call, and for the same reason: the audio has
  /// to come from the desk, and the desk is what owns the microphone.
  Future<void> _page(_Group group) async {
    final number = _selected;
    if (number == null) return;

    final response = await widget.api.post('/phone/page', {
      'ring_group_id': group.id,
    });
    if (!mounted) return;

    if (!response.ok) {
      setState(() => _bridgeNote = response.reason);

      return;
    }

    final data = response.data as Map<String, dynamic>? ?? const {};
    final speakTo = data['speak_to'] as String? ?? '';
    if (speakTo.isEmpty) return;

    await _service.connect(to: speakTo, callerId: number.e164);

    // WHO DID NOT GET IT IS THE HALF WORTH SAYING.
    //
    // A page that reached nine of twelve handsets looks identical to one that
    // reached all twelve, from the speaking end — you talk, and then you stop.
    // Somebody mid-call is skipped deliberately, and being told who lets the
    // person paging go and find them rather than assume the room heard.
    final missed = <String>[
      for (final row in (data['skipped'] as List? ?? const []))
        if (row is Map<String, dynamic> && (row['name'] as String? ?? '') != '')
          row['name'] as String,
    ];

    if (!mounted) return;
    setState(() {
      _bridgeNote = missed.isEmpty
          ? 'Paging ${group.name} — ${data['devices']} handsets.'
          : 'Paging ${group.name} — ${data['devices']} handsets. '
                '${missed.join(', ')} did not get it: on a call, or not '
                'available.';
    });
  }

  /// Teams on the account behind the chosen line.
  ///
  /// A page is a conference on ONE Twilio account, so a group belonging to a
  /// different company is not something this line can reach — offering it
  /// would be a button that fails after the announcement has been started.
  List<_Group> get _pageable {
    final number = _selected;
    if (number == null) return const [];

    return [
      for (final group in _groups)
        if (group.connectionId == number.connectionId) group,
    ];
  }

  /// The desk never registered, so it cannot take a call.
  ///
  /// Read from the service rather than sampled here. It used to be a field set
  /// once when this screen registered the device itself, which is how the
  /// banner came to sit across the top of a call that was connected
  /// underneath it.
  /// Not while it is still coming up — see [PhoneCallService.starting].
  bool get _deskDown => !_service.registered && !_service.starting;

  /// Keep the customer column even with nobody on the line.
  /// Seeded from the person's own preference in [initState], because the
  /// pin has to survive leaving Communication and coming back.
  bool _customerPinned = false;

  /// Bumped when a call ends, so the history beside the pad shows the row it
  /// just gained without anybody reloading the screen.
  int _historyToken = 0;

  /// Calls ringing somewhere else right now, which this person may take.
  ///
  /// Not the same as [_incomingFrom], which is a call ringing THIS device and
  /// already has its own banner with Accept on it. These are the ones ringing
  /// a group you are not in — the whole point of pickup, and the reason the
  /// list is not filtered by who the call is ringing.
  List<_Ringing> _ringing = const [];

  /// Teams this person could page. See [_pageable] for the ones this line can.
  List<_Group> _groups = const [];

  /// Callers somebody has set down, waiting for anybody to pick them up.
  ///
  /// Held on the tab rather than the service because it is not this device's
  /// state: a call parked by a colleague on another floor belongs on this list
  /// too, and the only thing that knows about it is the server.
  List<_Parked> _parked = const [];

  /// A bridged call is being set up, and what came back from asking.
  bool _bridging = false;
  String? _bridgeNote;

  /// The calls the history read, for the person panel's timeline: one
  /// request feeds both (UI queue 35).
  List<CallRow> _calls = const [];

  /// Who the number on the pad is, when the business knows: /phone/caller,
  /// then the customer's record for somebody who may read customers. Asked
  /// once per number.
  String? _lookedUp;
  Map<String, dynamic>? _caller;
  Map<String, dynamic>? _customer;

  /// A name typed into the pad suggests customers and teammates. The one
  /// picked names the Call button; a teammate is rung on their identity.
  List<_Suggestion> _suggestions = const [];
  String? _pickedName;
  bool _pickedTeammate = false;
  Timer? _suggestDebounce;
  int _suggestRequest = 0;

  Future<void> _placeCall({required bool internal}) async {
    // Refused, not queued. The company asked that a call be accounted for
    // before the next one, and a pad that dials anyway makes the requirement
    // decorative — worse than not having it, because the reports it exists to
    // feed then have holes nobody knows about.
    if (_tagBlocking) {
      setState(
        () => _tagError =
            'Say what the last call was about before starting another.',
      );

      return;
    }

    final number = _selected;
    if (number == null) return;

    final to = internal ? _clientTo() : _peer;
    if (to == null || to.isEmpty) return;

    // NO SOFTPHONE ON THIS PLATFORM, so the call is placed a different way.
    //
    // The Twilio Voice SDK is a browser script — there is none for Windows,
    // macOS or Linux, and none inside this app on Android or iOS. Rather than
    // tell four of the five platforms the phone is not for them, EzBizi asks
    // Twilio to ring the person's own handset and bridge them to the customer.
    // No SDK, no microphone, and the customer still sees the company line.
    if (!_service.supported) {
      await _bridgeCall(number, to);

      return;
    }

    await _service.connect(to: to, callerId: number.e164);

    // Refused by the platform before it started — say so where the Call
    // button is, and do NOT tell the server this person is on a call.
    final refused = _service.callRefusal;
    if (refused != null) {
      if (mounted) setState(() => _bridgeNote = refused);

      return;
    }

    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': 'on_call',
    });
  }

  /// Ask Twilio to ring this person, then the customer.
  ///
  /// Deliberately does NOT set `_onCall`: the audio is on their handset, so
  /// EzBizi has no call to hang up and a Hang up button here would be a
  /// control that does nothing.
  Future<void> _bridgeCall(_Number number, String to) async {
    setState(() => _bridging = true);

    final response = await widget.api.post('/phone/calls/bridge', {
      'number_id': number.id,
      'to': to,
    });

    if (!mounted) return;

    setState(() {
      _bridging = false;
      _bridgeNote = response.ok
          ? 'Your phone is ringing. Answer it and EzBizi will connect you to '
                '$to.'
          : response.reason;
    });
  }

  /// Opens on the caller's zip when they are a customer on file.
  Future<void> _openAvailability() async {
    String? zip;
    final peer = _peer;
    if (peer != null && peer.isNotEmpty) {
      final response = await widget.api.get(
        '/phone/caller?e164=${Uri.encodeQueryComponent(peer)}',
      );
      final data = response.ok ? response.data : null;
      if (data is Map) zip = (data['postal_code'] as String?)?.trim();
    }
    if (!mounted) return;

    await showSidePanel<void>(
      context,
      builder: (_) => SidePanel(
        title: 'Availability',
        scrollable: false,
        child: AvailabilityPane(
          api: widget.api,
          session: widget.session,
          visible: true,
          initialZip: (zip ?? '').isEmpty ? null : zip,
        ),
      ),
    );
  }

  String? _clientTo() {
    final id = _teammateId;
    if (id == null) return null;
    return 'client:${id.replaceAll('-', '')}';
  }

  Future<void> _hangUp() async {
    _service.hangUp();

    // The call that just ended is a row the history does not have yet.
    setState(() => _historyToken++);
    final number = _selected;
    if (number == null) return;
    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': _dnd ? 'dnd' : 'registered',
    });

    await _askWhatItWasAbout();
  }

  /// Find the call that just ended and ask what it was about.
  ///
  /// The webhook writes the row, not this screen, so the id is looked up rather
  /// than remembered — the desk never had it. If nothing comes back the desk
  /// stays quiet: an unanswerable question is worse than no question.
  Future<void> _askWhatItWasAbout() async {
    final results = await Future.wait([
      widget.api.get('/phone/tags'),
      widget.api.get('/phone/calls?untagged=1&per_page=1'),
    ]);

    if (!mounted) return;

    if (!results[0].ok || !results[1].ok) return;

    final tags = _Tag.listFrom(
      results[0].body,
    ).where((t) => t.isActive).toList();
    if (tags.isEmpty) return;

    final data = results[1].body['data'];
    if (data is! List || data.isEmpty) return;

    final row = data.first;
    if (row is! Map) return;

    setState(() {
      _tags = tags;
      _untaggedCallId = row['id']?.toString();
      _tagError = null;
    });
  }

  Future<void> _tagCall(String tagId) async {
    final callId = _untaggedCallId;
    if (callId == null) return;

    setState(() {
      _tagging = true;
      _tagError = null;
    });

    final response = await widget.api.patch('/phone/calls/$callId/tag', {
      'tag_id': tagId,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _tagging = false;
        _tagError = response.reason;
      });
      return;
    }

    setState(() {
      _tagging = false;
      _untaggedCallId = null;
      _tags = const [];
    });
  }

  Future<void> _accept() async {
    _service.accept();
    final number = _selected;
    if (number == null) return;
    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': 'on_call',
    });
  }

  void _decline() {
    _service.decline();
  }

  Future<void> _send() async {
    final number = _selected;
    final peer = _peer;
    if (number == null || peer == null || !_canSend) return;

    setState(() => _sendError = null);

    final response = await widget.api.post('/phone/messages', {
      'number_id': number.id,
      'to': peer,
      'body': _body.text.trim(),
    });
    if (!mounted) return;

    if (!response.ok) {
      setState(() => _sendError = response.message);
      return;
    }

    _body.clear();
    await _loadMessages();
  }

  /// A number chosen from the history or a suggestion, shown formatted:
  /// the box read +13057242199 (Jose, 2026-09-30).
  void _pick(String e164, {String? name}) {
    final shown = formatPhoneNumber(e164);
    setState(() {
      _destination.text = shown;
      _destination.selection = TextSelection.collapsed(offset: shown.length);
      _pickedName = name;
      _pickedTeammate = false;
      _suggestions = const [];
    });
    _loadMessages();
    _lookUp();
  }

  void _onTyped(String text) {
    setState(() {
      _pickedName = null;
      _pickedTeammate = false;
    });
    _loadMessages();
    _lookUp();
    _suggest(text);
  }

  /// Names, not numbers: two letters or more ask for customers and match
  /// teammates by name. Digits are a number already and need no help.
  void _suggest(String text) {
    _suggestDebounce?.cancel();
    final term = text.trim();
    if (!RegExp('[A-Za-z]{2}').hasMatch(term)) {
      if (_suggestions.isNotEmpty) setState(() => _suggestions = const []);
      return;
    }

    _suggestDebounce = Timer(const Duration(milliseconds: 300), () async {
      final request = ++_suggestRequest;
      final lower = term.toLowerCase();
      final found = <_Suggestion>[];

      if (widget.session.me?.can('customers.view') ?? false) {
        final response = await widget.api.get('/customers', {
          'search': term,
          'per_page': '5',
        });
        if (!mounted || request != _suggestRequest) return;
        final rows = response.ok ? response.data : null;
        for (final row in rows is List ? rows : const []) {
          final phone = row is Map ? '${row['phone'] ?? ''}' : '';
          if (phone.isEmpty) continue;
          found.add(
            _Suggestion(
              name: '${row['name'] ?? ''}',
              detail: [
                ?(row['number'] as String?),
                formatPhoneNumber(phone),
              ].join(' · '),
              phone: phone,
            ),
          );
        }
      }

      for (final mate in _teammates) {
        if (mate.name.toLowerCase().contains(lower)) {
          found.add(
            _Suggestion(
              name: mate.name,
              detail: 'Teammate',
              teammateId: mate.userId,
            ),
          );
        }
      }

      if (!mounted || request != _suggestRequest) return;
      setState(() => _suggestions = found.take(6).toList());
    });
  }

  void _choose(_Suggestion suggestion) {
    final teammate = suggestion.teammateId;
    if (teammate == null) {
      _pick(suggestion.phone!, name: suggestion.name);
      return;
    }

    setState(() {
      _teammateId = teammate;
      _destination.text = suggestion.name;
      _pickedName = suggestion.name;
      _pickedTeammate = true;
      _suggestions = const [];
    });
  }

  /// Who the number is: the name on file, then the record behind it for
  /// somebody allowed to read customers.
  Future<void> _lookUp() async {
    final peer = _peer;
    if (peer == _lookedUp) return;
    _lookedUp = peer;

    if (peer == null) {
      setState(() {
        _caller = null;
        _customer = null;
      });
      return;
    }

    final response = await widget.api.get('/phone/caller', {'e164': peer});
    if (!mounted || _lookedUp != peer) return;
    final data = response.ok ? response.data : null;
    final caller = data is Map ? data.cast<String, dynamic>() : null;

    Map<String, dynamic>? customer;
    final id = caller?['customer_id'] as String?;
    if (id != null && (widget.session.me?.can('customers.view') ?? false)) {
      final record = await widget.api.get('/customers/$id');
      if (!mounted || _lookedUp != peer) return;
      final row = record.ok ? record.data : null;
      if (row is Map) customer = row.cast<String, dynamic>();
    }

    setState(() {
      _caller = caller;
      _customer = customer;
    });
  }

  void _dial(String glyph) {
    _destination.text = '${_destination.text}$glyph';
    _destination.selection = TextSelection.collapsed(
      offset: _destination.text.length,
    );
    setState(() {
      _pickedName = null;
      _pickedTeammate = false;
    });
    _loadMessages();
    _lookUp();
  }

  Future<void> _loadUnread() async {
    final response = await widget.api.get('/staff-messages/unread');
    if (!mounted || !response.ok) return;

    final rows = response.body['data'];

    setState(() {
      _unread = {
        if (rows is Map)
          for (final entry in rows.entries)
            entry.key.toString(): int.tryParse(entry.value.toString()) ?? 0,
      };
    });
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _numbers.isEmpty
          // An empty list has two different causes and used to give one
          // answer. `/phone/numbers` returns the lines GRANTED to your role,
          // so somebody with no grant saw "Connect Twilio in Settings" — which
          // told them the company had no phone system when it had seven lines,
          // and sent them to a tab they cannot open. Reported 2 Sep by a
          // Dispatcher created ten minutes before his role was granted a line.
          //
          // Whoever can fix it is told what to fix; whoever cannot is told
          // what to ask for.
          ? Center(
              key: const ValueKey('tour-phone-no-line'),
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      (widget.session.me?.can('phone.edit') ?? false)
                          ? 'No phone lines yet. Add one in Settings.'
                          : 'No line has been given to your role yet. An '
                                'administrator can grant one in Communication '
                                'settings.',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    if ((widget.session.me?.can('phone.edit') ?? false) &&
                        widget.onOpenSettings != null) ...[
                      const SizedBox(height: 12),
                      FilledButton(
                        onPressed: widget.onOpenSettings,
                        child: const Text('Open Settings'),
                      ),
                    ],
                  ],
                ),
              ),
            )
          : LayoutBuilder(
              builder: (context, constraints) {
                final wide = constraints.maxWidth >= CallTab.splitAbove;
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (_incomingFrom != null) _banner(),
                    if (_deskDown) _deskDownBanner(),

                    // Only when somebody is actually waiting. A permanent
                    // empty "Parked calls" panel is a box that teaches people
                    // to stop looking at it, and the whole value of this one
                    // is being noticed on the rare day it has somebody in it.
                    if (_parked.isNotEmpty || _ringing.isNotEmpty)
                      _waitingBanner(),
                    Expanded(child: wide ? _wide() : _narrow()),

                    // The zero-sized WebView2 host moved to the shell with
                    // the device it belongs to. Mounting a second one here
                    // would be a second phone on one identity.
                  ],
                );
              },
            ),
    );
  }

  /// Said out loud, because the alternative is a dial pad that looks perfectly
  /// ready and never rings — and a desk that never rings is indistinguishable
  /// from a quiet afternoon until somebody asks why nobody called back.
  Widget _deskDownBanner() {
    final theme = Theme.of(context);

    return Material(
      color: theme.colorScheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        child: Row(
          children: [
            Icon(
              Icons.wifi_off_outlined,
              size: 18,
              color: theme.colorScheme.onErrorContainer,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                // ── ASK "IS THERE A LINE" BEFORE "CAN THIS PLATFORM" ──
                //
                // `supported` is read through the device, and the device does
                // not exist until start() finds a line — so a desk that never
                // started answered false to a question about the PLATFORM.
                // Jose saw the browser sentence in a browser: the shell starts
                // the desk once, at sign-in, and anybody whose view had no
                // phone line then was told for the rest of the session that
                // their browser was not a browser.
                //
                // No line here means nothing registered and nothing to
                // register to, which is a different fact and a fixable one.
                !_service.hasLine
                    ? 'This desk has not picked up a line yet. Press Try '
                          'again; if it keeps happening, sign out and back in.'
                    : _service.supported
                    // Say WHAT WENT WRONG when the platform told us, and only
                    // guess when it did not. The old sentence named the
                    // network every time, so an expired token, a rejected
                    // identity or a missing audio device all read as "your
                    // wifi" — and sent somebody to check a firewall for a
                    // fault that was never there.
                    ? (_service.registrationError != null
                          ? 'This desk could not connect to the phone '
                                'network, so it cannot take or make calls. '
                                '${_service.registrationError}'
                          : 'This desk is not connected to the phone network, '
                                'so it cannot take or make calls. Reload the '
                                'page; if it keeps happening the network is '
                                'blocking the connection.')
                    // A PERMANENT FACT, NOT A FAULT — and no longer a
                    // refusal, which is why the wording changed again.
                    //
                    // The Twilio Voice SDK is a browser script, so this build
                    // cannot carry the audio itself. It CAN still place a
                    // call: Twilio rings this person's own phone and bridges
                    // them. So the honest sentence is about which half works,
                    // not about the app being unusable — the earlier version
                    // told four platforms the phone was not for them, which
                    // stopped being true the moment bridging existed.
                    : 'Calls ring your own phone from here — press Call and '
                          'answer when it rings. Taking calls in the app '
                          'needs a web browser.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onErrorContainer,
                ),
              ),
            ),
            // The way out, where the way out is not a retry.
            //
            // Android will not let the app carry a call until the user has
            // enabled it under Calling accounts, and no amount of pressing
            // Try again reaches that screen. Same rule as the one below,
            // applied to the opposite case: offer the control that CAN
            // succeed, and only where it can.
            if (_service.needsCallingAccount)
              TextButton(
                onPressed: () => _service.openCallingSettings(),
                child: const Text('Open settings'),
              ),

            // No Retry where retrying cannot succeed. A control that can
            // only ever fail is worse than none: it reads as "not yet".
            //
            // With no line, restart() is the one thing that CAN fix it: it
            // re-reads /phone/numbers, which is the call that came back empty.
            if (!_service.hasLine || _service.supported)
              TextButton(
                onPressed: () => _service.restart(widget.api),
                child: const Text('Try again'),
              ),
          ],
        ),
      ),
    );
  }

  Widget _banner() {
    final theme = Theme.of(context);

    return Material(
      color: theme.colorScheme.primaryContainer,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        child: Row(
          children: [
            Expanded(
              child: Text(
                'Incoming from ${formatPhoneNumber(_incomingFrom)}',
                style: theme.textTheme.bodyMedium,
              ),
            ),
            FilledButton(onPressed: _accept, child: const Text('Accept')),
            const SizedBox(width: 8),
            OutlinedButton(onPressed: _decline, child: const Text('Decline')),
          ],
        ),
      ),
    );
  }

  /// The three columns, and the reader can drag the lines between them.
  ///
  /// ── Why the widths are draggable rather than tuned ───────────────
  ///
  /// They were computed from ratios, and the ratio that is right depends on
  /// what somebody is doing: working a list wants the history wide, taking a
  /// call wants the customer wide, and dialling wants neither. Every fixed
  /// split is wrong for two of the three. Dragging costs about thirty lines
  /// and ends the argument.
  ///
  /// The computed ratios stay as the STARTING widths, so a desk that is never
  /// dragged still looks deliberate.
  ///
  /// ── The floors are still here ────────────────────────────────────
  ///
  /// A draggable divider that can be dragged to zero is how a column
  /// disappears and never comes back, because there is nothing left to grab.
  /// Each pane keeps its floor and the drag stops there.
  Widget _wide() {
    // The roster is a whole tab now, not a column. It gets the width.
    if (widget.view == CallView.team) return _teammatesPane();

    return LayoutBuilder(
      builder: (context, constraints) {
        final showCustomer = _customerShowing;

        final available =
            constraints.maxWidth - (showCustomer ? _grip * 2 : _grip);
        if (available <= 0) return const SizedBox.shrink();

        // Starting widths. The history opened at whatever was left over when
        // the customer pane was away, which on a wide window was about three
        // quarters of the screen for a list of phone numbers.
        var history = _historyWidth ?? available * 0.26;
        var customer = showCustomer ? (_customerWidth ?? available * 0.44) : 0.0;

        // The pad is the one with a hard minimum: a list just gets narrower
        // and stays readable, a keypad stops being tappable. So it is paid
        // first and the other two give the width back.
        var pad = available - history - customer;
        if (pad < _padFloor) {
          final short = _padFloor - pad;
          final give = (history - _historyFloor) + (customer - _customerFloor);

          if (give <= 0) {
            // Nothing left to take. Let the pad be squeezed rather than
            // overflow the row, which is a yellow-and-black stripe.
            pad = math.max(0, available - history - customer);
          } else {
            history -= short * ((history - _historyFloor) / give);
            if (showCustomer) customer -= short * ((customer - _customerFloor) / give);
            pad = available - history - customer;
          }
        }

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              key: const Key('phone-call-history'),
              width: history,
              child: _history(onPick: _pick),
            ),

            // Dragging right widens the history and narrows the pad.
            _resizer(
              const Key('phone-split-history'),
              'Drag to resize',
              (dx) => setState(() {
                _historyWidth = (history + dx).clamp(
                  _historyFloor,
                  available - customer - _padFloor,
                );
              }),
            ),

            SizedBox(
              key: const Key('phone-call-pad'),
              width: pad,
              child: _padPane(showPerson: false),
            ),

            if (showCustomer) ...[
              // This one sits on the customer's left edge, so dragging right
              // takes width FROM the customer pane.
              _resizer(
                const Key('phone-split-person'),
                'Drag to resize',
                (dx) => setState(() {
                  _customerWidth = (customer - dx).clamp(
                    _customerFloor,
                    available - history - _padFloor,
                  );
                }),
              ),
              SizedBox(
                key: const Key('phone-call-person'),
                width: customer,
                child: _personPane(showBack: false),
              ),
            ],
          ],
        );
      },
    );
  }

  /// The line between two columns, with enough width either side to catch a
  /// mouse. A 1px target is a divider nobody discovers is draggable.
  Widget _resizer(Key key, String tooltip, void Function(double dx) onDrag) {
    return MouseRegion(
      cursor: SystemMouseCursors.resizeColumn,
      child: GestureDetector(
        key: key,
        behavior: HitTestBehavior.opaque,
        onHorizontalDragUpdate: (details) => onDrag(details.delta.dx),
        child: Tooltip(
          message: tooltip,
          waitDuration: const Duration(seconds: 1),
          child: SizedBox(
            width: _grip,
            child: Center(
              child: Container(width: 1, color: EzBiziTheme.edge(context)),
            ),
          ),
        ),
      ),
    );
  }

  /// Whether the customer column is on screen.
  ///
  /// ── Why it is not simply always there ────────────────────────────
  ///
  /// It is the widest of the three and, with nobody on the line, it holds
  /// "type a number to see who it is" — a column of empty space explaining
  /// that it is empty. The dial pad and the history are what somebody uses
  /// between calls, and they were sharing half a window with it.
  ///
  /// It appears the moment there is a person to show: a call ringing, a call
  /// connected, or a number typed. And it can be PINNED, because somebody
  /// working a list wants the thread to stay put and a panel that comes and
  /// goes under your hands is worse than one that is always there.
  bool get _customerShowing =>
      _customerPinned || _onCall || _incomingFrom != null || _peer != null;

  /// Below these the panes stop giving width back, and a drag stops moving.
  ///
  /// The customer pane needed a constant of its own once it became draggable:
  /// it used to take whatever was left, so it could not be dragged to nothing
  /// because nothing ever dragged it.
  static const double _historyFloor = 200;
  static const double _padFloor = 280;
  static const double _customerFloor = 280;

  /// The grabbable width of a divider. The line drawn in it is still 1px.
  static const double _grip = 9;

  /// Null until the reader drags. Held on the state rather than saved, so it
  /// lasts as long as the app is open and resets on a restart - which is the
  /// lazy half of this and the half to revisit if anybody minds.
  double? _historyWidth;
  double? _customerWidth;

  Widget _history({required void Function(String e164) onPick}) =>
      CallHistoryPane(
        api: widget.api,
        formats: widget.session.me!.display,
        reloadToken: _historyToken,
        onPick: onPick,
        onRows: (rows) => setState(() => _calls = rows),
      );

  Widget _narrow() {
    // The Team tab is the roster at every width; its table stacks its rows
    // below 720 on its own, so a phone needs no second layout of it.
    if (widget.view == CallView.team) return _teammatesPane();

    // A call ringing or connected shows the pad, whatever was open: its
    // controls live there.
    final pane =
        _pane == _CallPane.recents && (_onCall || _incomingFrom != null)
        ? _CallPane.pad
        : _pane;

    return switch (pane) {
      _CallPane.recents => KeyedSubtree(
        key: const Key('phone-call-history'),
        child: Stack(
          children: [
            Positioned.fill(
              child: _history(
                onPick: (e164) {
                  _pick(e164);
                  setState(() {
                    _personFrom = _CallPane.recents;
                    _pane = _CallPane.person;
                  });
                },
              ),
            ),
            Positioned(
              right: 16,
              bottom: 16,
              child: FilledButton.icon(
                key: const Key('phone-open-keypad'),
                onPressed: () => setState(() => _pane = _CallPane.pad),
                icon: const Icon(Icons.dialpad, size: 18),
                label: const Text('Keypad'),
              ),
            ),
          ],
        ),
      ),
      _CallPane.pad => KeyedSubtree(
        key: const Key('phone-call-pad'),
        child: _padPane(
          showPerson: true,
          onBack: () => setState(() => _pane = _CallPane.recents),
        ),
      ),
      _CallPane.person => KeyedSubtree(
        key: const Key('phone-call-person'),
        child: _personPane(showBack: true),
      ),
    };
  }

  /// Which tile is picked on the Team roster: `available`, `on_call`, `dnd`,
  /// `unread`, or null for everyone.
  String? _teamFilter;

  /// The tile a presence state falls under. The same three words the row
  /// prints, so a tile and the pills under it can never disagree.
  static String _stateKey(String state) => switch (state) {
    'on_call' => 'on_call',
    'dnd' => 'dnd',
    _ => 'available',
  };

  Widget _teammatesPane() {
    final theme = Theme.of(context);
    final chat = _chatWith;

    // The column BECOMES the thread rather than the thread opening elsewhere.
    // Somebody messaging a colleague from here is on a call or about to be,
    // so evicting the pad or the customer would take away the thing the
    // message is about.
    if (chat != null) {
      return StaffThread(
        api: widget.api,
        withUserId: chat.userId,
        withName: chat.name,
        onBack: () => setState(() => _chatWith = null),
        onRead: _loadUnread,
      );
    }

    // Tiles over a table (Mia, 2026-10-02, the `team` mockup). Presence comes
    // back whole, never paged, so every count is taken here over the full
    // roster rather than asked of the server.
    int inState(String key) =>
        _teammates.where((row) => _stateKey(row.state) == key).length;
    int unreadFrom(_Teammate row) => _unread[row.userId] ?? 0;

    final waiting = _teammates.fold<int>(0, (sum, row) => sum + unreadFrom(row));
    final shown = [
      for (final row in _teammates)
        if (switch (_teamFilter) {
          null => true,
          'unread' => unreadFrom(row) > 0,
          final key => _stateKey(row.state) == key,
        })
          row,
    ];

    return ListView(
      key: const Key('phone-call-teammates'),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      children: [
        FilterTiles(
          key: const ValueKey('tour-phone-team-tiles'),
          prefix: 'team',
          picked: _teamFilter,
          onPick: (key) => setState(() => _teamFilter = key),
          tiles: [
            FilterTile(
              key: null,
              label: 'Everyone',
              count: _teammates.length,
              note: 'at this desk',
            ),
            FilterTile(
              key: 'available',
              label: 'Available',
              count: inState('available'),
              note: 'can take a call',
              tone: Tone.ok,
            ),
            FilterTile(
              key: 'on_call',
              label: 'On a call',
              count: inState('on_call'),
              note: 'busy now',
            ),
            FilterTile(
              key: 'dnd',
              label: 'Do not disturb',
              count: inState('dnd'),
              note: 'not taking calls',
            ),
            // The sum of messages, not of people: it is the number the old
            // header badge showed, and the one somebody clears by reading.
            FilterTile(
              key: 'unread',
              label: 'Unread',
              count: waiting,
              note: 'staff messages',
              tone: Tone.caution,
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_teammates.isEmpty)
          Padding(
            padding: const EdgeInsets.all(8),
            child: Text(
              'Nobody is at this desk yet.',
              style: theme.textTheme.bodySmall,
            ),
          )
        else if (shown.isEmpty)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Text('Nobody in this state.', textAlign: TextAlign.center),
          )
        else
          ListTable(
            columns: const [
              ListColumn('Person', flex: 4),
              ListColumn('Status', flex: 3),
              ListColumn('Unread', flex: 2),
              ListColumn('', flex: 3, end: true),
            ],
            rowKey: (i) => ValueKey('teammate-${shown[i].userId}'),
            // The row still picks the person, as it did: the pad's internal
            // call goes to whoever was picked last.
            onTap: (i) => setState(() {
              _teammateId = shown[i].userId;
              _pane = _CallPane.pad;
            }),
            rows: [
              for (final row in shown)
                [
                  PersonCell(name: row.name),

                  // RED for Do not disturb, asked for by Jose: the one state
                  // that means "this person will not be rung" must not read
                  // as switched off. Tone.bad follows the theme into dark
                  // mode, as the error-coloured dot it replaces did.
                  StatusPill(
                    _stateLabel(row.state),
                    tone: switch (_stateKey(row.state)) {
                      'dnd' => Tone.bad,
                      'on_call' => Tone.info,
                      _ => Tone.ok,
                    },
                  ),
                  unreadFrom(row) > 0
                      ? StatusPill(
                          '${unreadFrom(row)} new',
                          tone: Tone.caution,
                        )
                      : Text(
                          '—',
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                  Wrap(
                    alignment: WrapAlignment.end,
                    children: [
                      // Messaging is its own control rather than the row's
                      // tap, because the row already means "call this
                      // person" and a colleague who is mid-job wants the
                      // other one.
                      Tooltip(
                        message: 'Message ${row.name}',
                        child: TextButton(
                          key: Key('teammate-message-${row.userId}'),
                          onPressed: () => setState(() => _chatWith = row),
                          child: const Text('Message'),
                        ),
                      ),

                      // Calling a colleague belongs ON the colleague, not on
                      // the dial pad: the old "Internal" button beside Call
                      // did nothing until a row had been picked somewhere
                      // else.
                      Tooltip(
                        message: 'Call ${row.name}',
                        child: TextButton(
                          key: Key('teammate-call-${row.userId}'),
                          onPressed: _onCall
                              ? null
                              : () {
                                  setState(() => _teammateId = row.userId);
                                  _placeCall(internal: true);
                                },
                          child: const Text('Call'),
                        ),
                      ),
                    ],
                  ),
                ],
            ],
          ),
      ],
    );
  }

  /// No `showBack`: there is nothing behind the pad any more.
  Widget _padPane({required bool showPerson, VoidCallback? onBack}) {
    final theme = Theme.of(context);
    final number = _selected;

    // Scrolls only when it has to.
    //
    // The pad is fixed-height content — header, keypad, Call — with the
    // transcript taking whatever is left. On a short viewport the fixed part is
    // taller than the pane, the transcript gets nothing, and the Column
    // overflows: the Call button is drawn past the bottom edge and cannot be
    // reached or tapped. It overflowed by 45px at 835 high, so a laptop with a
    // browser toolbar was already losing it, and a phone loses more.
    //
    // The transcript is given a minimum instead of the remaining space. The
    // usual minHeight + IntrinsicHeight recipe cannot be used here: the keypad
    // is a shrink-wrapped GridView, and a viewport refuses to report intrinsic
    // dimensions — it throws rather than instantiating every child. Trading
    // "transcript stretches to the bottom" for "the Call button is always
    // reachable" is the right way round.
    return SingleChildScrollView(
      child: _padColumn(theme, number, showPerson: showPerson, onBack: onBack),
    );
  }

  Widget _padColumn(
    ThemeData theme,
    _Number? number, {
    required bool showPerson,
    VoidCallback? onBack,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // The state and the line on one strip; the state above the
              // line when the column is too narrow for both (UI queue 35).
              LayoutBuilder(
                key: const ValueKey('tour-phone-calling-as'),
                builder: (context, constraints) {
                  final line = Row(
                    children: [
                      Text(
                        'Calling as',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      const SizedBox(width: 8),
                      // CHOSEN, not merely reported.
                      //
                      // This was a label, so whichever line the pad had
                      // landed on was the only one you could call from — the
                      // number assigned to you on Team, or the first on the
                      // account. A dispatcher covering two businesses could
                      // see which line they were about to appear as and could
                      // not change it, which meant ringing a customer back as
                      // the wrong company.
                      //
                      // One line stays text: a picker offering a single choice
                      // is a control that cannot do anything.
                      Expanded(
                        child: _numbers.length < 2
                            ? Text(
                                number == null
                                    ? ''
                                    : '${number.label} · ${number.e164}',
                                style: theme.textTheme.bodyMedium,
                                overflow: TextOverflow.ellipsis,
                              )
                            : _LinePicker(
                                lines: _numbers,
                                selected: number,
                                enabled: !_onCall,
                                onPicked: (id) =>
                                    setState(() => _numberId = id),
                                onOpened: _load,
                              ),
                      ),
                    ],
                  );

                  return Row(
                    children: [
                      if (onBack != null)
                        IconButton(
                          icon: const Icon(Icons.arrow_back),
                          tooltip: 'Back to recent calls',
                          onPressed: onBack,
                        ),
                      Expanded(
                        child: constraints.maxWidth >= 440
                            ? Row(
                                children: [
                                  _stateChip(),
                                  const SizedBox(width: 12),
                                  Expanded(child: line),
                                ],
                              )
                            : Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  _stateChip(),
                                  const SizedBox(height: 6),
                                  line,
                                ],
                              ),
                      ),
                      if (showPerson)
                        IconButton(
                          icon: const Icon(Icons.person_outline),
                          tooltip: 'To the customer',
                          onPressed: () => setState(() {
                            _personFrom = _CallPane.pad;
                            _pane = _CallPane.person;
                          }),
                        ),
                    ],
                  );
                },
              ),
              // The Do not disturb button moved to the app bar, beside Clock
              // In, so it is reachable from anywhere rather than only from the
              // screen you are trying to stop interrupting you. Two controls
              // for one fact is the drift this module keeps removing, so this
              // one is gone rather than mirrored — the line below still SAYS
              // the state, which is reporting, not a second switch.
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        if (_onCall) _liveCall(theme),
        // A name or a number: letters suggest customers and teammates, ten
        // digits are formatted as they are typed.
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: TextField(
            key: const ValueKey('tour-phone-number'),
            controller: _destination,
            decoration: const InputDecoration(labelText: 'Name or number'),
            inputFormatters: const [PhoneNumberInputFormatter()],
            onChanged: _onTyped,
          ),
        ),
        if (_suggestions.isNotEmpty) _suggestionList(theme),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: _keypad(theme),
        ),
        if (_untaggedCallId != null) _whatWasItAbout(theme),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Wrap(
            alignment: WrapAlignment.center,
            spacing: 8,
            runSpacing: 8,
            children: [
              if (_onCall)
                FilledButton(onPressed: _hangUp, child: const Text('Hang up'))
              else
                FilledButton(
                  onPressed: _destination.text.trim().isEmpty || _bridging
                      ? null
                      : () => _placeCall(internal: _pickedTeammate),
                  child: Text(
                    _bridging
                        ? 'Ringing you…'
                        : _pickedName == null
                        ? 'Call'
                        : 'Call $_pickedName',
                  ),
                ),

              // Hidden rather than disabled when there is no team to page. An
              // account with no ring groups has nobody to announce anything
              // to, and a permanently greyed button teaches people the
              // feature is broken rather than absent.
              if (!_onCall && _pageable.isNotEmpty)
                MenuAnchor(
                  builder: (context, controller, _) => OutlinedButton(
                    onPressed: () => controller.isOpen
                        ? controller.close()
                        : controller.open(),
                    child: const Text('Page'),
                  ),
                  menuChildren: [
                    for (final group in _pageable)
                      MenuItemButton(
                        onPressed: () => _page(group),
                        child: Text(group.name),
                      ),
                  ],
                ),

              // Toula's suggestion (2026-09-10): the caller says a zip, and
              // the dispatcher checks who is free there without leaving the
              // call. Scheduling's own pane, as Leads opens it.
              if (widget.session.me?.can('scheduling.availability.view') ??
                  false)
                OutlinedButton(
                  key: const Key('dial-availability'),
                  onPressed: _openAvailability,
                  child: const Text('Availability'),
                ),
            ],
          ),
        ),
        // What the bridge did, said where the Call button is rather than in a
        // banner: it is the answer to the thing just pressed.
        //
        // Or why the call just placed failed on the device, which arrives
        // after Call has returned — see PhoneCallService._callFailed.
        if ((_bridgeNote ?? _service.callRefusal) != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
            child: Text(
              (_bridgeNote ?? _service.callRefusal)!,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
          ),

        // The live transcript's place, only while there is a call to
        // transcribe: idle, it was a grey block under the keypad with nothing
        // in it (Jose, 2026-09-30).
        if (_onCall)
          ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 140),
            child: Container(
              width: double.infinity,
              color: theme.colorScheme.surfaceContainerHighest.withValues(
                alpha: 0.4,
              ),
              padding: const EdgeInsets.all(14),
              child: Text(
                number != null && number.transcribe
                    ? ''
                    : 'Live transcript is off on this number.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          ),
      ],
    );
  }

  Widget _personPane({required bool showBack}) {
    final theme = Theme.of(context);
    final number = _selected;
    final allowed = number?.smsOutboundAllowed ?? false;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(showBack ? 4 : 14, 8, 14, 8),
          child: Row(
            children: [
              if (showBack)
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: _personFrom == _CallPane.recents
                      ? 'Back to recent calls'
                      : 'Back to the pad',
                  onPressed: () => setState(() => _pane = _personFrom),
                ),
              // Who it is, when anybody knows; the number, formatted, when
              // not. The panel said "Customer" over a raw +13057242199.
              Expanded(
                child: Text(
                  _personTitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleSmall,
                ),
              ),

              // Keep it, or let it come and go with the call.
              //
              // The panel appears on its own when there is somebody to show.
              // Somebody working a list wants it to stay put instead —
              // a column that opens and closes under your hands while you
              // type is worse than one that is simply always there.
              IconButton(
                tooltip: _customerPinned
                    ? 'Pinned — let it hide between calls'
                    : 'Keep this open between calls',
                visualDensity: VisualDensity.compact,
                icon: Icon(
                  _customerPinned
                      ? Icons.push_pin
                      : Icons.push_pin_outlined,
                  size: 18,
                ),
                onPressed: () {
                  final pinned = !_customerPinned;
                  setState(() => _customerPinned = pinned);

                  // Not awaited, and the screen is already right. Session
                  // updates its own copy first and refetches if the write
                  // fails, so a pin that could not be saved comes back as
                  // unpinned rather than as a lie on screen.
                  widget.session.setPreference(
                    'phone.customer_panel_pinned',
                    pinned,
                  );
                },
              ),
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        if (_peer == null)
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: Text(
              'Type a number to see who it is.',
              style: theme.textTheme.titleMedium,
            ),
          )
        else ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
            child: _personCard(theme),
          ),
          if (showBack && !_onCall)
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
              child: FilledButton.icon(
                key: const Key('person-call'),
                onPressed: _bridging ? null : () => _placeCall(internal: false),
                icon: const Icon(Icons.call, size: 18),
                label: const Text('Call'),
              ),
            ),
        ],
        Expanded(
          child: _peer == null ? const SizedBox.shrink() : _timeline(theme),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (!allowed)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(_refusal, style: theme.textTheme.bodySmall),
                ),
              if (_sendError != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                    _sendError!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.error,
                    ),
                  ),
                ),
              TextField(
                controller: _body,
                decoration: const InputDecoration(labelText: 'Message'),
                minLines: 1,
                maxLines: 3,
                onChanged: (_) => setState(() {}),
              ),
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton(
                  onPressed: allowed && _canSend ? _send : null,
                  child: const Text('Send'),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// The desk's state, reported where the line is chosen. Switched from the
  /// top bar; this only says it.
  Widget _stateChip() {
    final (label, tone) = _onCall
        ? ('On a call', Tone.info)
        : _dnd
        ? ('Do not disturb', Tone.bad)
        : ('Ready', Tone.ok);
    return StatusPill(label, tone: tone);
  }

  /// Customers and teammates matching the name typed, under the box.
  Widget _suggestionList(ThemeData theme) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
    child: Material(
      key: const Key('dial-suggestions'),
      elevation: 1,
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          for (final s in _suggestions)
            ListTile(
              dense: true,
              title: Text(s.name),
              subtitle: Text(s.detail),
              trailing: StatusPill(
                s.teammateId == null ? 'Customer' : 'Teammate',
                tone: s.teammateId == null ? Tone.info : Tone.neutral,
              ),
              onTap: () => _choose(s),
            ),
        ],
      ),
    ),
  );

  String get _personTitle {
    final name = (_caller?['name'] as String?)?.trim();
    if (name != null && name.isNotEmpty) return name;
    final peer = _peer;
    return peer == null ? 'Customer' : formatPhoneNumber(peer);
  }

  /// The customer on file for the number: their record's number, address and
  /// jobs, and the way to it. A number nobody is on file for says so.
  Widget _personCard(ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final id = _caller?['customer_id'] as String?;
    final name = (_caller?['name'] as String?)?.trim();
    final customer = _customer;
    final address = (customer?['address'] as Map?)?.cast<String, dynamic>();
    final where = [
      for (final part in [
        address?['line1'],
        address?['city'],
        address?['state'],
      ])
        if ('${part ?? ''}'.trim().isNotEmpty) '$part',
    ].join(', ');
    final jobs = (customer?['jobs_count'] as num?)?.toInt();

    return Container(
      key: const Key('person-card'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            name == null || name.isEmpty ? 'Not a customer on file' : name,
            style: theme.textTheme.titleMedium,
          ),
          Text(
            [
              formatPhoneNumber(_peer),
              ?(customer?['number'] as String?),
            ].join(' · '),
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          if (where.isNotEmpty)
            Text(
              where,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          if (jobs != null)
            Text(
              jobs == 0 ? 'No jobs yet' : '$jobs ${jobs == 1 ? 'job' : 'jobs'}',
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          if (id != null && (widget.session.me?.can('customers.view') ?? false))
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                key: const Key('person-open-customer'),
                style: TextButton.styleFrom(padding: EdgeInsets.zero),
                onPressed: () => showSidePanel<void>(
                  context,
                  builder: (_) => CustomerDetailPanel(
                    api: widget.api,
                    session: widget.session,
                    id: id,
                  ),
                ),
                child: const Text('Open customer'),
              ),
            ),
          if (widget.session.me?.can('jobs.create') ?? false)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                key: const Key('person-book-job'),
                style: TextButton.styleFrom(padding: EdgeInsets.zero),
                onPressed: () => showBookJobPanel(
                  context,
                  api: widget.api,
                  session: widget.session,
                  prefill: BookPrefill(customerId: id, phone: _peer ?? ''),
                ),
                icon: const Icon(Icons.event_available_outlined, size: 18),
                label: const Text('Book a job'),
              ),
            ),
        ],
      ),
    );
  }

  /// Calls and texts with this number in one column, oldest first, under
  /// their days: the calls from the history beside the pad, the texts from
  /// the line's thread.
  Widget _timeline(ThemeData theme) {
    final peer = _peer;
    final entries = <(DateTime, Widget)>[
      for (final call in _calls)
        if (call.peer == peer && call.at != null)
          (call.at!, _callEvent(theme, call)),
      for (final message in _messages)
        if (DateTime.tryParse(message.createdAt) case final at?)
          (at.toLocal(), _bubble(theme, message)),
    ]..sort((a, b) => a.$1.compareTo(b.$1));

    if (entries.isEmpty) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(14, 4, 14, 8),
        child: Text(
          'No calls or messages with this number yet.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      );
    }

    final children = <Widget>[];
    String? day;
    for (final (at, entry) in entries) {
      final heading = _dayOf(at);
      if (heading != day) {
        day = heading;
        children.add(_dayDivider(theme, heading));
      }
      children.add(entry);
    }

    return ListView(
      key: const Key('person-timeline'),
      padding: const EdgeInsets.fromLTRB(14, 0, 14, 8),
      children: children,
    );
  }

  static String _dayOf(DateTime at) {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final ago = today.difference(DateTime(at.year, at.month, at.day)).inDays;
    if (ago == 0) return 'Today';
    if (ago == 1) return 'Yesterday';
    return '${at.month}/${at.day}/${at.year}';
  }

  Widget _dayDivider(ThemeData theme, String text) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: Row(
      children: [
        const Expanded(child: Divider()),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8),
          child: Text(
            text,
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        const Expanded(child: Divider()),
      ],
    ),
  );

  /// One call in the timeline: which way, what happened, and who took it.
  Widget _callEvent(ThemeData theme, CallRow call) {
    final colours = ToneColours.of(
      context,
      call.missed ? Tone.bad : Tone.neutral,
    );
    final at = call.at!;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Row(
        children: [
          Icon(
            call.voicemail
                ? Icons.voicemail_outlined
                : call.inbound
                ? Icons.call_received
                : Icons.call_made,
            size: 16,
            color: colours.ink,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              [
                call.inbound ? 'Incoming call' : 'You rang',
                if (call.subtitle != 'Incoming' && call.subtitle != 'Outgoing')
                  call.subtitle.toLowerCase(),
                ?call.duration,
                if (call.answeredByName != null)
                  'answered by ${call.answeredByName}',
                widget.session.me!.display.formatTime(at.hour, at.minute),
              ].join(' · '),
              style: theme.textTheme.bodySmall?.copyWith(color: colours.ink),
            ),
          ),
        ],
      ),
    );
  }

  // ── WHICH WAY DID THIS ONE GO ───────────────
  //
  // Every message was the same box in a column, separated from the one above
  // it only by a tint at half opacity. Jose put it plainly: "I can't see who
  // the message was sent to." Nothing here said. The panel names the other
  // end at the top, so the missing fact was never WHO the conversation is
  // with - it was which of the two of you said each line, and when.
  //
  // Said three ways because one is not enough in a column this narrow: the
  // side it sits on, the name above it, and a full-strength colour instead of
  // a wash. It is the same bubble Records draws for the same rows, which is
  // the point - two screens showing one conversation should not disagree
  // about what it looks like.
  Widget _bubble(ThemeData theme, _Message message) => Align(
    alignment: message.outbound ? Alignment.centerRight : Alignment.centerLeft,
    child: Container(
      constraints: const BoxConstraints(maxWidth: 320),
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: message.outbound
            ? theme.colorScheme.primaryContainer
            : theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            message.outbound ? 'You' : formatPhoneNumber(_peer),
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 2),
          Text(message.body, style: theme.textTheme.bodyMedium),
          const SizedBox(height: 4),
          Text(
            formatStamp(
              message.createdAt,
              '',
              formats: widget.session.me!.display,
            ),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    ),
  );

  String _stateLabel(String state) => switch (state) {
    'on_call' => 'On a call',
    'dnd' => 'Do not disturb',
    _ => 'Available',
  };
}

/// Which line you are calling from, chosen from a searchable list.
///
/// ── Why a search box and not a plain dropdown ────────────────────
///
/// A dropdown is fine for three lines and useless for thirty. An organization
/// with a line per company, per branch and per campaign gives a dispatcher a
/// list they have to read top to bottom every time — and the one they want is
/// identifiable by a name they already know, which is exactly what typing is
/// for.
///
/// It is an Autocomplete rather than a dialog because the house rule is no
/// modals: the list drops under the field, and nothing takes the screen away
/// from a desk that may be mid-call.
class _LinePicker extends StatelessWidget {
  const _LinePicker({
    required this.lines,
    required this.selected,
    required this.enabled,
    required this.onPicked,
    required this.onOpened,
  });

  final List<_Number> lines;
  final _Number? selected;
  final bool enabled;
  final void Function(String id) onPicked;

  /// Refetch the lines, because "on a call" is only true for a moment.
  ///
  /// The list is loaded once when the screen opens. Without this the busy
  /// marks are as old as the tab, so a line freed ten minutes ago still reads
  /// as taken and a line taken since reads as free — and the second of those
  /// is the one that puts two calls on one number.
  final VoidCallback onOpened;

  /// What a line reads as, and what typing searches over.
  ///
  /// The label AND the number, because people know a line by either — "the
  /// Miami one" and "the one ending 0188" are the same request.
  static String describe(_Number line) => line.assignedToMe
      ? '${line.label} · ${formatPhoneNumber(line.e164)} — yours'
      : '${line.label} · ${formatPhoneNumber(line.e164)}';

  /// Why a line cannot be picked, or null when it can.
  static String? busyReason(_Number line) {
    if (!line.onCall) return null;

    return line.onCallWith == null
        ? 'on a call'
        : 'on a call with ${line.onCallWith}';
  }

  @override
  Widget build(BuildContext context) {
    return Autocomplete<_Number>(
      initialValue: TextEditingValue(
        text: selected == null ? '' : describe(selected!),
      ),
      displayStringForOption: describe,
      optionsBuilder: (value) {
        final query = value.text.trim().toLowerCase();

        // An empty box offers everything rather than nothing: opening the list
        // to see what there is has to work without knowing a name first.
        if (query.isEmpty) return lines;

        // AND SO DOES AN UNTOUCHED ONE.
        //
        // The field holds the selected line's own description, so tapping to
        // open the list searched for that exact string and matched exactly one
        // thing — itself. A business with thirty numbers looked like a
        // business with one, and the picker looked like a label that had been
        // styled as a dropdown by mistake. Jose reported it as "I should be
        // able to choose from among all the numbers", which is what it looks
        // like from outside.
        //
        // Compared against the SELECTION rather than against every line's
        // description: typing another line's name in full is a real search and
        // must still narrow to it.
        if (selected != null && query == describe(selected!).toLowerCase()) {
          return lines;
        }

        return lines.where(
          (line) => describe(line).toLowerCase().contains(query),
        );
      },
      // A busy line is shown and refused, not hidden. Hiding it would answer
      // "why can I not see Ana's number" with silence; this answers it with
      // the reason, and stops answering the moment the call ends.
      onSelected: (line) {
        if (busyReason(line) != null) return;
        onPicked(line.id);
      },

      optionsViewBuilder: (context, onSelected, options) {
        final theme = Theme.of(context);
        final rows = options.toList();

        return Align(
          alignment: Alignment.topLeft,
          child: Material(
            elevation: 4,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxHeight: 320, maxWidth: 520),
              child: ListView.builder(
                shrinkWrap: true,
                padding: EdgeInsets.zero,
                itemCount: rows.length,
                itemBuilder: (context, i) {
                  final line = rows[i];
                  final busy = busyReason(line);

                  return ListTile(
                    key: ValueKey('calling-as-${line.id}'),
                    dense: true,
                    enabled: busy == null,
                    title: Text(describe(line)),
                    subtitle: busy == null ? null : Text(busy),
                    trailing: busy == null
                        ? null
                        : Icon(
                            Icons.phone_in_talk_outlined,
                            size: 18,
                            color: theme.colorScheme.error,
                          ),
                    onTap: busy == null ? () => onSelected(line) : null,
                  );
                },
              ),
            ),
          ),
        );
      },
      fieldViewBuilder: (context, controller, focus, onSubmit) => TextField(
        controller: controller,
        focusNode: focus,
        enabled: enabled,
        style: Theme.of(context).textTheme.bodyMedium,
        decoration: const InputDecoration(
          isDense: true,
          border: InputBorder.none,
          suffixIcon: Icon(Icons.arrow_drop_down),
        ),

        // Selecting the text on focus so the first keystroke searches rather
        // than appending to the line's own name, which never matches anything.
        onTap: () {
          controller.selection = TextSelection(
            baseOffset: 0,
            extentOffset: controller.text.length,
          );
          onOpened();
        },
      ),
    );
  }
}

class _Ringing {
  const _Ringing({
    required this.id,
    required this.fromE164,
    required this.toE164,
  });

  final String id;
  final String fromE164;

  /// WHICH LINE it is ringing, which is how somebody decides whether to take
  /// it. "+1 305… is ringing" says a stranger is calling somebody; "+1 305… is
  /// ringing Sales" says whether it is any of your business.
  final String toE164;

  factory _Ringing.fromJson(Map<String, dynamic> json) => _Ringing(
    id: json['id'] as String? ?? '',
    fromE164: json['from_e164'] as String? ?? '',
    toE164: json['to_e164'] as String? ?? '',
  );
}

class _Parked {
  const _Parked({
    required this.id,
    required this.fromE164,
    required this.parkedByName,
    required this.pickUp,
  });

  final String id;
  final String fromE164;
  final String? parkedByName;

  /// What the desk dials to fetch them. Sent by the server rather than built
  /// here, so the queue naming stays one fact in one place.
  final String pickUp;

  factory _Parked.fromJson(Map<String, dynamic> json) => _Parked(
    id: json['id'] as String? ?? '',
    fromE164: json['from_e164'] as String? ?? '',
    parkedByName: json['parked_by_name'] as String?,
    pickUp: json['pick_up'] as String? ?? '',
  );
}

/// A team, for the one thing this screen does with one: page it.
class _Group {
  const _Group({
    required this.id,
    required this.connectionId,
    required this.name,
  });

  final String id;
  final String connectionId;
  final String name;

  factory _Group.fromJson(Map<String, dynamic> json) => _Group(
    id: json['id'] as String? ?? '',
    connectionId: json['connection_id'] as String? ?? '',
    name: json['name'] as String? ?? '',
  );
}

class _Number {
  const _Number({
    required this.id,
    required this.connectionId,
    required this.e164,
    required this.label,
    required this.transcribe,
    required this.smsOutboundAllowed,
    required this.assignedToMe,
    required this.onCall,
    this.onCallWith,
  });

  final String id;
  final String connectionId;
  final String e164;
  final String label;
  final bool transcribe;
  final bool smsOutboundAllowed;

  /// Whether this is the line the person reading calls out from, set on Team.
  final bool assignedToMe;

  /// Somebody is on this line right now.
  ///
  /// Offering it anyway would put a second call on a number a colleague is
  /// mid-conversation on, and send the callback to them.
  final bool onCall;

  /// Who is on it, when the call has a person on it. Null for an inbound call
  /// still ringing a group, which belongs to nobody yet.
  final String? onCallWith;

  factory _Number.fromJson(Map<String, dynamic> json) => _Number(
    id: json['id'] as String? ?? '',
    connectionId: json['connection_id'] as String? ?? '',
    e164: json['e164'] as String? ?? '',
    label: json['label'] as String? ?? json['e164'] as String? ?? '',
    transcribe: json['transcribe'] == true,
    smsOutboundAllowed: json['sms_outbound_allowed'] == true,
    assignedToMe: json['assigned_to_me'] == true,
    onCall: json['on_call'] == true,
    onCallWith: json['on_call_with'] as String?,
  );
}

class _Teammate {
  const _Teammate({
    required this.userId,
    required this.name,
    required this.state,
  });

  final String userId;
  final String name;
  final String state;

  factory _Teammate.fromJson(Map<String, dynamic> json) => _Teammate(
    userId: json['user_id'] as String? ?? '',
    name: json['name'] as String? ?? '',
    state: json['state'] as String? ?? '',
  );
}

/// A name typed into the pad, matched: a customer is rung on their number, a
/// teammate on their identity.
class _Suggestion {
  const _Suggestion({
    required this.name,
    required this.detail,
    this.phone,
    this.teammateId,
  });

  final String name;
  final String detail;
  final String? phone;
  final String? teammateId;
}

class _Message {
  const _Message({
    required this.id,
    required this.body,
    required this.outbound,
    required this.createdAt,
  });

  final String id;
  final String body;
  final bool outbound;
  final String createdAt;

  factory _Message.fromJson(Map<String, dynamic> json) => _Message(
    id: json['id'] as String? ?? '',
    body: json['body'] as String? ?? '',
    outbound: json['direction'] == 'outbound',
    createdAt: json['created_at'] as String? ?? '',
  );
}

extension _LiveCall on _CallTabState {
  /// What is happening right now, where "Ready" used to sit.
  ///
  /// The desk said "On a call" in small type in the middle of the column and
  /// nothing else — no who, and the way to end it was a button below the
  /// keypad. During a call this is the only thing on screen anybody is looking
  /// at, so it takes the space and carries Hang up with it.
  Widget _liveCall(ThemeData theme) {
    final peer = _peer ?? _destination.text.trim();

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 8, 16, 0),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.primary.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(10),
      ),
      // WRAP, NOT ROW, and the second button is why.
      //
      // With one button an Expanded label and a Row fitted anywhere. Adding
      // Park overflowed the middle column by 41px — the label is already at
      // its minimum, so the buttons are what has to give, and a Row cannot
      // give. Nothing warns: it renders, and the yellow stripes only appear in
      // a test or in front of somebody with a narrow window.
      //
      // Same shape the on-call bar already uses for the same reason. On a
      // wide desk it is one line; on a narrow one the buttons drop below the
      // name rather than off the edge of it.
      child: Wrap(
        crossAxisAlignment: WrapCrossAlignment.center,
        alignment: WrapAlignment.spaceBetween,
        spacing: 8,
        runSpacing: 8,
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                peer.isEmpty ? 'On a call' : peer,
                style: theme.textTheme.titleSmall,
              ),
              Text(
                'Connected',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
          Wrap(
            spacing: 8,
            children: [
              // Beside Hang up because they are the two ways a call ends for
              // this person, and the wrong one ends it for the CUSTOMER too.
              // Outlined rather than filled: hanging up is the ordinary
              // action and park is the deliberate one.
              OutlinedButton(onPressed: _park, child: const Text('Park')),
              FilledButton(onPressed: _hangUp, child: const Text('Hang up')),
            ],
          ),
        ],
      ),
    );
  }

  /// Calls this person could take: ringing elsewhere, or set down waiting.
  ///
  /// ── Why one strip and not two ────────────────────────────────────
  ///
  /// They are different facts — one is urgent and one is patient — but they
  /// answer the same question, which is "is there a caller here that nobody
  /// is dealing with". Two strips would stack, and the second would be the
  /// one nobody reads.
  ///
  /// Ringing first, because it is the one with a clock on it: a parked caller
  /// waits as long as they are willing to, and a ringing one goes to voicemail
  /// in seconds.
  ///
  /// Named rather than counted throughout. "2 calls waiting" is a fact
  /// somebody then has to open something to act on; the number they are
  /// holding for is what decides whether this one is theirs.
  Widget _waitingBanner() {
    final theme = Theme.of(context);

    // Refused while this desk is already on a call — either kind would place a
    // second one, and the first is a customer mid-sentence.
    final busy = _service.onCall;

    Widget row(IconData icon, String text, VoidCallback take) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Icon(icon, size: 18, color: theme.colorScheme.onTertiaryContainer),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onTertiaryContainer,
              ),
            ),
          ),
          TextButton(
            onPressed: busy ? null : take,
            child: const Text('Pick up'),
          ),
        ],
      ),
    );

    return Material(
      color: theme.colorScheme.tertiaryContainer,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final ringing in _ringing)
              row(
                Icons.ring_volume_outlined,
                '${formatPhoneNumber(ringing.fromE164)} is ringing '
                    '${formatPhoneNumber(ringing.toE164)}',
                () => _takeRinging(ringing),
              ),
            for (final parked in _parked)
              row(
                Icons.pause_circle_outline,
                parked.parkedByName == null
                    ? '${formatPhoneNumber(parked.fromE164)} is waiting'
                    : '${formatPhoneNumber(parked.fromE164)} is waiting '
                          '— parked by ${parked.parkedByName}',
                () => _pickUp(parked),
              ),
          ],
        ),
      ),
    );
  }
}

extension _Keypad on _CallTabState {
  /// A keypad sized like a keypad.
  ///
  /// This was `GridView.count(crossAxisCount: 3)`, which STRETCHES to whatever
  /// width it is given — in a wide middle column that made each key roughly
  /// 400px across, and `childAspectRatio: 1.6` made it 1.6 times wider than
  /// tall, so twelve stadium-shaped slabs filled the entire desk. The dial pad
  /// is the least-used control on the screen and it was the loudest.
  ///
  /// Fixed 56px columns, centred, so the pad is the same size on a monitor and
  /// on a phone. 46px tall with 8px gaps clears the 44px touch target.
  Widget _keypad(ThemeData theme) {
    const glyphs = <(String, String)>[
      ('1', ''),
      ('2', 'ABC'),
      ('3', 'DEF'),
      ('4', 'GHI'),
      ('5', 'JKL'),
      ('6', 'MNO'),
      ('7', 'PQRS'),
      ('8', 'TUV'),
      ('9', 'WXYZ'),
      ('*', ''),
      ('0', '+'),
      ('#', ''),
    ];

    return Center(
      child: SizedBox(
        width: 240, // 3 x 72 + 2 x 12
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (var row = 0; row < 4; row++)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    for (var col = 0; col < 3; col++)
                      _key(theme, glyphs[row * 3 + col]),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _key(ThemeData theme, (String, String) glyph) {
    return SizedBox(
      width: 72,
      height: 52,
      child: OutlinedButton(
        key: ValueKey('key:${glyph.$1}'),
        style: OutlinedButton.styleFrom(padding: EdgeInsets.zero),
        onPressed: () => _dial(glyph.$1),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(glyph.$1, style: theme.textTheme.titleMedium),

            // The letters are what makes a keypad readable as one, and they
            // are how somebody dials a number they were told as a word.
            if (glyph.$2.isNotEmpty)
              Text(
                glyph.$2,
                style: theme.textTheme.labelSmall?.copyWith(
                  fontSize: 8,
                  letterSpacing: 1,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

extension _CallTagging on _CallTabState {
  /// An in-page banner, not a pop-up. §6 has no modals, and a modal here would
  /// also block the next incoming call behind a question about the last one.
  ///
  /// It has no dismiss. That is the requirement — whoever answered says what it
  /// was about — but the desk stays usable behind it: another call can be taken
  /// and this stays until it is answered.
  Widget _whatWasItAbout(ThemeData theme) {
    return Container(
      key: const ValueKey('tag-the-call'),
      width: double.infinity,
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('What was that call about?', style: theme.textTheme.titleSmall),
          const SizedBox(height: 4),
          Text(
            _requireTag
                ? 'Required before the next call, so it can be counted later.'
                : 'Pick one so it can be counted later.',
            style: theme.textTheme.bodySmall,
          ),
          if (_tagError != null) ...[
            const SizedBox(height: 8),
            Text(_tagError!, style: theme.textTheme.bodySmall),
          ],
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final tag in _tags)
                OutlinedButton(
                  key: ValueKey('tag-choice:${tag.id}'),
                  onPressed: _tagging ? null : () => _tagCall(tag.id),
                  child: Text(tag.name),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

@immutable
class _Tag {
  const _Tag({required this.id, required this.name, required this.isActive});

  final String id;
  final String name;
  final bool isActive;

  static List<_Tag> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => _Tag(
            id: r['id'].toString(),
            name: r['name']?.toString() ?? '',
            isActive: r['is_active'] != false,
          ),
        )
        .toList(growable: false);
  }
}
