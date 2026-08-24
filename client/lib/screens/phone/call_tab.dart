import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import 'voice_device.dart';

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
  _CallPane _pane = _CallPane.pad;
  bool _dnd = false;
  bool _onCall = false;
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

    await widget.api.put('/phone/presence', {
      'connection_id': number.connectionId,
      'state': _dnd ? 'dnd' : 'registered',
    });
  }

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
                    Expanded(child: wide ? _wide() : _narrow()),
                  ],
                );
              },
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

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
          child: Text('Team', style: theme.textTheme.titleSmall),
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
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: Text(
            _onCall
                ? 'On a call'
                : _dnd
                ? 'Do not disturb'
                : 'Ready',
            textAlign: TextAlign.center,
            style: theme.textTheme.titleSmall?.copyWith(
              color: theme.colorScheme.primary,
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
          padding: const EdgeInsets.fromLTRB(24, 12, 24, 0),
          child: GridView.count(
            crossAxisCount: 3,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 8,
            crossAxisSpacing: 8,
            childAspectRatio: 1.6,
            children: [
              for (final glyph in const [
                '1',
                '2',
                '3',
                '4',
                '5',
                '6',
                '7',
                '8',
                '9',
                '*',
                '0',
                '#',
              ])
                OutlinedButton(
                  onPressed: () => _dial(glyph),
                  child: Text(glyph, style: theme.textTheme.titleLarge),
                ),
            ],
          ),
        ),
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
        Expanded(
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
