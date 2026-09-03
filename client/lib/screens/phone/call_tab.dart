import '../../core/phone_numbers.dart';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import 'voice_device.dart';
import 'staff_thread.dart';
import 'voice_view.dart';
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
    this.device,
    this.view = CallView.dial,
  });

  final ApiClient api;
  final Session session;
  final VoiceDevice? device;
  final CallView view;

  static const double splitAbove = 600;

  @override
  State<CallTab> createState() => _CallTabState();
}

enum _CallPane { teammates, pad, person }

class _CallTabState extends State<CallTab> {
  static const _refusal =
      'This line cannot send texts until messaging is approved.';

  late final VoiceDevice _device;
  late final bool _ownsDevice;

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
  _CallPane _pane = _CallPane.pad;
  bool _dnd = false;
  bool _onCall = false;

  /// The call just finished, waiting to be said what it was about.
  ///
  /// Held in the desk rather than left to Records, because the person who knows
  /// is the one who has just put the phone down. A tag chosen a day later by
  /// somebody reading a list is a guess, and a report built on guesses is worse
  /// than one built on nothing.
  String? _untaggedCallId;
  List<_Tag> _tags = const [];
  bool _tagging = false;
  String? _tagError;
  String? _incomingFrom;
  String? _sendError;

  @override
  void initState() {
    super.initState();
    _ownsDevice = widget.device == null;
    _device = widget.device ?? createVoiceDevice();
    _device.onIncoming = (incoming) {
      if (!mounted) return;
      setState(() => _incomingFrom = incoming.from);
    };
    _load();
  }

  @override
  void dispose() {
    _device.onIncoming = null;
    if (_ownsDevice) _device.dispose();
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
    await _loadDesk();
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
    await _register();
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

  Future<void> _register() async {
    final number = _selected;
    if (number == null) return;

    // Phase one FIRST, then a frame, then register.
    //
    // The Windows device's microphone prompt is delivered to its mounted
    // widget; unmounted, `getUserMedia` goes unanswered and Twilio's register()
    // rejects. [VoiceDevice.platformView] is null until prepare() has built the
    // controller, so the widget cannot exist before this call — and awaiting
    // one frame after it is what actually puts it in the tree. Without the
    // frame this is the same bug with an extra method: prepared, still not
    // mounted, still refused.
    //
    // A no-op on every other platform, so there is one path here rather than a
    // Windows branch.
    await _device.prepare();
    if (!mounted) return;
    setState(() {});
    await WidgetsBinding.instance.endOfFrame;
    if (!mounted) return;

    final token = await widget.api.post('/phone/tokens', {
      'connection_id': number.connectionId,
    });
    if (token.ok) {
      final value = token.body['token'] as String? ?? '';
      if (value.isNotEmpty) await _device.setup(value);
    }

    // Say so when the desk did not actually come up. setup() returns on a
    // refused token and on signalling that never connected as well as on
    // success, so "it returned" is not "it is ready" — and a dial pad that
    // looks ready but can never ring is the failure this whole path had.
    if (mounted) setState(() => _deskDown = !_device.registered);

    // And keep reading it. Registration finishes after setup() returns on
    // Windows, so the line above is a sample taken while the answer is still
    // false — which is how the desk came to say "not connected to the phone
    // network" across the top of a call that was connected underneath it.
    _device.onRegistrationChanged = () {
      if (mounted) setState(() => _deskDown = !_device.registered);
    };

    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': _dnd ? 'dnd' : 'registered',
    });
  }

  /// The Voice device never registered, so this desk cannot take a call.
  bool _deskDown = false;

  /// Keep the customer column even with nobody on the line.
  bool _customerPinned = false;

  /// Bumped when a call ends, so the history beside the pad shows the row it
  /// just gained without anybody reloading the screen.
  int _historyToken = 0;

  /// A bridged call is being set up, and what came back from asking.
  bool _bridging = false;
  String? _bridgeNote;

  Future<void> _placeCall({required bool internal}) async {
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
    if (!_device.supported) {
      await _bridgeCall(number, to);

      return;
    }

    await _device.connect(to: to, callerId: number.e164);
    setState(() => _onCall = true);
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

  String? _clientTo() {
    final id = _teammateId;
    if (id == null) return null;
    return 'client:${id.replaceAll('-', '')}';
  }

  Future<void> _hangUp() async {
    _device.hangUp();

    // The call that just ended is a row the history does not have yet.
    setState(() {
      _onCall = false;
      _historyToken++;
    });
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
    _device.acceptIncoming();
    setState(() {
      _incomingFrom = null;
      _onCall = true;
    });
    final number = _selected;
    if (number == null) return;
    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': 'on_call',
    });
  }

  void _decline() {
    _device.declineIncoming();
    setState(() => _incomingFrom = null);
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

  void _dial(String glyph) {
    _destination.text = '${_destination.text}$glyph';
    _destination.selection = TextSelection.collapsed(
      offset: _destination.text.length,
    );
    setState(() {});
    _loadMessages();
  }

  Widget _badge(ThemeData theme, int count) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
      decoration: BoxDecoration(
        color: theme.colorScheme.primary,
        borderRadius: BorderRadius.circular(9),
      ),
      child: Text(
        '$count',
        style: theme.textTheme.labelSmall?.copyWith(
          color: theme.colorScheme.onPrimary,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
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
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  (widget.session.me?.can('phone.edit') ?? false)
                      ? 'No phone lines yet. Add one in Settings.'
                      : 'No line has been given to your role yet. An '
                            'administrator can grant one in Communication '
                            'settings.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleMedium,
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
                    Expanded(child: wide ? _wide() : _narrow()),

                    // Zero-sized, and load-bearing. WebView2 delivers the
                    // microphone prompt to this widget's own delegate, so
                    // a device whose view never enters the tree registers
                    // and then cannot hear anybody — a phone that looks
                    // perfect and is deaf. Empty on every other platform.
                    voiceDeviceView(_device.platformView),
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
                _device.supported
                    // Say WHAT WENT WRONG when the platform told us, and only
                    // guess when it did not. The old sentence named the
                    // network every time, so an expired token, a rejected
                    // identity or a missing audio device all read as "your
                    // wifi" — and sent somebody to check a firewall for a
                    // fault that was never there.
                    ? (_device.registrationError != null
                          ? 'This desk could not connect to the phone '
                                'network, so it cannot take or make calls. '
                                '${_device.registrationError}'
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
            // No Retry where retrying cannot succeed. A control that can
            // only ever fail is worse than none: it reads as "not yet".
            if (_device.supported)
              TextButton(onPressed: _register, child: const Text('Try again')),
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
              child: CallHistoryPane(
                api: widget.api,
                reloadToken: _historyToken,
                onPick: (e164) => setState(() {
                  _destination.text = e164;
                  _destination.selection = TextSelection.collapsed(
                    offset: e164.length,
                  );
                }),
              ),
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
              child: _padPane(showBack: false, showPerson: false),
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

  Widget _narrow() {
    return switch (_pane) {
      _CallPane.teammates => KeyedSubtree(
        key: const Key('phone-call-teammates'),
        child: _teammatesPane(),
      ),
      _CallPane.pad => KeyedSubtree(
        key: const Key('phone-call-pad'),
        child: _padPane(showBack: true, showPerson: true),
      ),
      _CallPane.person => KeyedSubtree(
        key: const Key('phone-call-person'),
        child: _personPane(showBack: true),
      ),
    };
  }

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

    final waiting = _unread.values.fold<int>(0, (sum, n) => sum + n);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
          child: Row(
            children: [
              Expanded(child: Text('Team', style: theme.textTheme.titleSmall)),
              if (waiting > 0) _badge(theme, waiting),
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(8, 8, 8, 16),
            children: [
              for (final row in _teammates)
                Material(
                  color: row.userId == _teammateId
                      ? theme.colorScheme.primaryContainer
                      : theme.colorScheme.surface,
                  borderRadius: BorderRadius.circular(8),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(8),
                    onTap: () => setState(() {
                      _teammateId = row.userId;
                      _pane = _CallPane.pad;
                    }),
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(minHeight: 44),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 8,
                        ),
                        child: Row(
                          children: [
                            Icon(
                              Icons.circle,
                              size: 8,
                              color: row.state == 'dnd'
                                  ? theme.colorScheme.onSurfaceVariant
                                  : theme.colorScheme.primary,
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    row.name,
                                    style: theme.textTheme.bodyMedium,
                                  ),
                                  Text(
                                    _stateLabel(row.state),
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      color: theme.colorScheme.onSurfaceVariant,
                                    ),
                                  ),
                                ],
                              ),
                            ),

                            // Messaging is its own control rather than the
                            // row's tap, because the row already means "call
                            // this person" and a colleague who is mid-job
                            // wants the other one.
                            if ((_unread[row.userId] ?? 0) > 0)
                              _badge(theme, _unread[row.userId]!),
                            IconButton(
                              tooltip: 'Message ${row.name}',
                              visualDensity: VisualDensity.compact,
                              icon: const Icon(
                                Icons.chat_bubble_outline,
                                size: 18,
                              ),
                              onPressed: () => setState(() => _chatWith = row),
                            ),

                            // Calling a colleague belongs ON the colleague,
                            // not on the dial pad.
                            //
                            // It used to be an "Internal" button beside Call,
                            // disabled until a row had been selected — so the
                            // control for reaching a PERSON sat next to a
                            // keypad for reaching a NUMBER, and did nothing
                            // until you had already picked the person
                            // somewhere else. Two steps, and the first was
                            // invisible: nothing on the pad said what
                            // "Internal" would dial, or why it was grey.
                            IconButton(
                              tooltip: 'Call ${row.name}',
                              visualDensity: VisualDensity.compact,
                              icon: const Icon(Icons.phone_outlined, size: 18),
                              onPressed: _onCall
                                  ? null
                                  : () {
                                      setState(() => _teammateId = row.userId);
                                      _placeCall(internal: true);
                                    },
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              if (_teammates.isEmpty)
                Padding(
                  padding: const EdgeInsets.all(8),
                  child: Text(
                    'Nobody is at this desk yet.',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _padPane({required bool showBack, required bool showPerson}) {
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
      child: _padColumn(
        theme,
        number,
        showBack: showBack,
        showPerson: showPerson,
      ),
    );
  }

  Widget _padColumn(
    ThemeData theme,
    _Number? number, {
    required bool showBack,
    required bool showPerson,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(showBack ? 4 : 12, 8, 8, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  if (showBack)
                    IconButton(
                      icon: const Icon(Icons.arrow_back),
                      tooltip: 'Back to the team',
                      onPressed: () =>
                          setState(() => _pane = _CallPane.teammates),
                    ),
                  Text(
                    'Calling as',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(width: 8),
                  // CHOSEN, not merely reported.
                  //
                  // This was a label, so whichever line the pad had landed on
                  // was the only one you could call from — the number assigned
                  // to you on Team, or the first on the account. A dispatcher
                  // covering two businesses could see which line they were
                  // about to appear as and could not change it, which meant
                  // ringing a customer back as the wrong company.
                  //
                  // One line stays text: a picker offering a single choice is
                  // a control that cannot do anything.
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
                          ),
                  ),
                  if (showPerson)
                    IconButton(
                      icon: const Icon(Icons.person_outline),
                      tooltip: 'To the customer',
                      onPressed: () => setState(() => _pane = _CallPane.person),
                    ),
                ],
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
        if (_onCall)
          _liveCall(theme)
        else
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Text(
              _dnd ? 'Do not disturb' : 'Ready',
              textAlign: TextAlign.center,
              style: theme.textTheme.titleSmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: TextField(
            controller: _destination,
            decoration: const InputDecoration(labelText: 'Number'),
            keyboardType: TextInputType.phone,
            onChanged: (_) {
              setState(() {});
              _loadMessages();
            },
          ),
        ),
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
                      : () => _placeCall(internal: false),
                  child: Text(_bridging ? 'Ringing you…' : 'Call'),
                ),
            ],
          ),
        ),
        // What the bridge did, said where the Call button is rather than in a
        // banner: it is the answer to the thing just pressed.
        if (_bridgeNote != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
            child: Text(
              _bridgeNote!,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
          ),

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
                  tooltip: 'Back to the pad',
                  onPressed: () => setState(() => _pane = _CallPane.pad),
                ),
              Expanded(
                child: Text('Customer', style: theme.textTheme.titleSmall),
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
                onPressed: () =>
                    setState(() => _customerPinned = !_customerPinned),
              ),
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
          child: Text(
            _peer ?? 'Type a number to see who it is.',
            style: theme.textTheme.titleMedium,
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 0, 14, 8),
          child: Text('Messages', style: theme.textTheme.titleSmall),
        ),
        Expanded(
          child: _peer == null
              ? const SizedBox.shrink()
              : ListView(
                  padding: const EdgeInsets.fromLTRB(14, 0, 14, 8),
                  children: [
                    for (final message in _messages)
                      Container(
                        margin: const EdgeInsets.only(bottom: 8),
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: message.outbound
                              ? theme.colorScheme.primaryContainer.withValues(
                                  alpha: 0.5,
                                )
                              : theme.colorScheme.surfaceContainerHighest
                                    .withValues(alpha: 0.5),
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: EzBiziTheme.edge(context)),
                        ),
                        child: Text(
                          message.body,
                          style: theme.textTheme.bodyMedium,
                        ),
                      ),
                    if (_messages.isEmpty)
                      Text(
                        'No messages with this number yet.',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                  ],
                ),
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
  });

  final List<_Number> lines;
  final _Number? selected;
  final bool enabled;
  final void Function(String id) onPicked;

  /// What a line reads as, and what typing searches over.
  ///
  /// The label AND the number, because people know a line by either — "the
  /// Miami one" and "the one ending 0188" are the same request.
  static String describe(_Number line) => line.assignedToMe
      ? '${line.label} · ${formatPhoneNumber(line.e164)} — yours'
      : '${line.label} · ${formatPhoneNumber(line.e164)}';

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

        return lines.where(
          (line) => describe(line).toLowerCase().contains(query),
        );
      },
      onSelected: (line) => onPicked(line.id),
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
        onTap: () => controller.selection = TextSelection(
          baseOffset: 0,
          extentOffset: controller.text.length,
        ),
      ),
    );
  }
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
  });

  final String id;
  final String connectionId;
  final String e164;
  final String label;
  final bool transcribe;
  final bool smsOutboundAllowed;

  /// Whether this is the line the person reading calls out from, set on Team.
  final bool assignedToMe;

  factory _Number.fromJson(Map<String, dynamic> json) => _Number(
    id: json['id'] as String? ?? '',
    connectionId: json['connection_id'] as String? ?? '',
    e164: json['e164'] as String? ?? '',
    label: json['label'] as String? ?? json['e164'] as String? ?? '',
    transcribe: json['transcribe'] == true,
    smsOutboundAllowed: json['sms_outbound_allowed'] == true,
    assignedToMe: json['assigned_to_me'] == true,
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
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  peer.isEmpty ? 'On a call' : peer,
                  style: theme.textTheme.titleSmall,
                  overflow: TextOverflow.ellipsis,
                ),
                Text(
                  'Connected',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          FilledButton(onPressed: _hangUp, child: const Text('Hang up')),
        ],
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
        width: 184, // 3 x 56 + 2 x 8
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (var row = 0; row < 4; row++)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
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
      width: 56,
      height: 46,
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
            'Pick one so it can be counted later.',
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
