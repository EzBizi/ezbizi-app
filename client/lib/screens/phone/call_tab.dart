import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import 'voice_device.dart';
import 'staff_thread.dart';

/// Call desk — layout 1: teammates, pad, person. Below 600px, one pane at a time.
class CallTab extends StatefulWidget {
  const CallTab({
    super.key,
    required this.api,
    required this.session,
    this.device,
  });

  final ApiClient api;
  final Session session;
  final VoiceDevice? device;

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
    return _numbers.isEmpty ? null : _numbers.first;
  }

  String? get _peer {
    final text = _destination.text.trim();
    if (text.isEmpty) return null;
    if (text.startsWith('+')) {
      final digits = text.substring(1).replaceAll(RegExp(r'\D'), '');
      return digits.length >= 7 ? '+$digits' : null;
    }
    final digits = text.replaceAll(RegExp(r'\D'), '');
    return digits.length >= 8 ? '+$digits' : null;
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
      _numberId ??= numbers.isEmpty ? null : numbers.first.id;
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

    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': _dnd ? 'dnd' : 'registered',
    });
  }

  /// The Voice device never registered, so this desk cannot take a call.
  bool _deskDown = false;

  Future<void> _toggleDnd() async {
    final number = _selected;
    if (number == null) return;

    final next = !_dnd;
    setState(() => _dnd = next);

    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': next ? 'dnd' : 'registered',
    });
  }

  Future<void> _placeCall({required bool internal}) async {
    final number = _selected;
    if (number == null) return;

    final to = internal ? _clientTo() : _peer;
    if (to == null || to.isEmpty) return;

    await _device.connect(to: to, callerId: number.e164);
    setState(() => _onCall = true);
    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': 'on_call',
    });
  }

  String? _clientTo() {
    final id = _teammateId;
    if (id == null) return null;
    return 'client:${id.replaceAll('-', '')}';
  }

  Future<void> _hangUp() async {
    _device.hangUp();
    setState(() => _onCall = false);
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

    final tags = _Tag.listFrom(results[0].body).where((t) => t.isActive).toList();
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

    final response = await widget.api.patch(
      '/phone/calls/$callId/tag',
      {'tag_id': tagId},
    );

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
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  'Connect Twilio in Settings',
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
                'This desk is not connected to the phone network, so it '
                'cannot take or make calls. Reload the page; if it keeps '
                'happening the network is blocking the connection.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onErrorContainer,
                ),
              ),
            ),
            TextButton(
              onPressed: _register,
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
                'Incoming from $_incomingFrom',
                style: theme.textTheme.bodyMedium,
              ),
            ),
            FilledButton(
              onPressed: _accept,
              child: const Text('Accept'),
            ),
            const SizedBox(width: 8),
            OutlinedButton(
              onPressed: _decline,
              child: const Text('Decline'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _wide() {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(
          key: const Key('phone-call-teammates'),
          width: 220,
          child: _teammatesPane(),
        ),
        VerticalDivider(
          width: 1,
          thickness: 1,
          color: EzBiziTheme.edge(context),
        ),
        Expanded(
          child: KeyedSubtree(
            key: const Key('phone-call-pad'),
            child: _padPane(showBack: false, showPerson: false),
          ),
        ),
        VerticalDivider(
          width: 1,
          thickness: 1,
          color: EzBiziTheme.edge(context),
        ),
        SizedBox(
          key: const Key('phone-call-person'),
          width: 280,
          child: _personPane(showBack: false),
        ),
      ],
    );
  }

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
                                  Text(row.name, style: theme.textTheme.bodyMedium),
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
                              onPressed: () =>
                                  setState(() => _chatWith = row),
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
      child: _padColumn(theme, number, showBack: showBack, showPerson: showPerson),
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
                  Expanded(
                    child: Text(
                      number == null ? '' : '${number.label} · ${number.e164}',
                      style: theme.textTheme.bodyMedium,
                      overflow: TextOverflow.ellipsis,
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
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton(
                  onPressed: _toggleDnd,
                  child: Text(_dnd ? 'Available' : 'Do not disturb'),
                ),
              ),
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
                  onPressed: _destination.text.trim().isEmpty
                      ? null
                      : () => _placeCall(internal: false),
                  child: const Text('Call'),
                ),
              OutlinedButton(
                onPressed: _teammateId == null
                    ? null
                    : () => _placeCall(internal: true),
                child: const Text('Internal'),
              ),
            ],
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

class _Number {
  const _Number({
    required this.id,
    required this.connectionId,
    required this.e164,
    required this.label,
    required this.transcribe,
    required this.smsOutboundAllowed,
  });

  final String id;
  final String connectionId;
  final String e164;
  final String label;
  final bool transcribe;
  final bool smsOutboundAllowed;

  factory _Number.fromJson(Map<String, dynamic> json) => _Number(
    id: json['id'] as String? ?? '',
    connectionId: json['connection_id'] as String? ?? '',
    e164: json['e164'] as String? ?? '',
    label: json['label'] as String? ?? json['e164'] as String? ?? '',
    transcribe: json['transcribe'] == true,
    smsOutboundAllowed: json['sms_outbound_allowed'] == true,
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
      ('1', ''), ('2', 'ABC'), ('3', 'DEF'),
      ('4', 'GHI'), ('5', 'JKL'), ('6', 'MNO'),
      ('7', 'PQRS'), ('8', 'TUV'), ('9', 'WXYZ'),
      ('*', ''), ('0', '+'), ('#', ''),
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
