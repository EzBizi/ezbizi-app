import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/address_fields.dart';
import '../../widgets/list_table.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import 'phone_refs.dart';

/// Phone › Settings › Porting — numbers moving in from another carrier.
///
/// ── Why this screen matters more than its size suggests ──────────
///
/// Moving a business's main number is the most anxious thing this module does.
/// It takes days, it is driven by a carrier nobody here can telephone, and
/// until now EzBizi could START one and then show nothing: the row existed,
/// the webhook updated its status for weeks, and no screen read it.
///
/// ── What is honestly automated, and what is not ──────────────────
///
/// Submission and status are. Everything else is a carrier process this screen
/// can only report on — it does not control the losing carrier, cannot hurry
/// them, and has no API for port-OUT at all. Saying so is the point: a screen
/// that implies EzBizi is in charge of a port makes every delay look like our
/// fault, and makes somebody wait here instead of ringing their old provider.
class PortingPane extends StatefulWidget {
  const PortingPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<PortingPane> createState() => _PortingPaneState();
}

class _PortingPaneState extends State<PortingPane> {
  bool _loading = true;
  bool _starting = false;
  bool _open = false;
  String? _error;

  List<_Port> _ports = const [];
  List<PhoneConnectionRef> _connections = const [];
  String? _connectionId;

  /// The tile picked (tiles over a table, Mia 2026-10-02): `progress`,
  /// `action` or `done`; null is every port. Counted here - `/phone/ports`
  /// answers with every port in one list, not a page of them.
  String? _tile;

  /// What is typed in the search box. /phone/ports returns every port, so
  /// the filter runs here.
  String _query = '';

  final _numbers = TextEditingController();

  // THE LOSING CARRIER'S ACCOUNT. Jose, 2026-10-05: port in "showing pin
  // codes and verification as well". Twilio sends the letter of
  // authorization from these to the person named, who signs it.
  String _customerType = 'Business';
  final _customerName = TextEditingController();
  final _accountNumber = TextEditingController();
  final _accountPhone = TextEditingController();
  final _pin = TextEditingController();
  final _representative = TextEditingController();
  final _representativeEmail = TextEditingController();
  final _line1 = TextEditingController();
  final _line2 = TextEditingController();
  final _city = TextEditingController();
  final _state = TextEditingController();
  final _postal = TextEditingController();

  List<TextEditingController> get _carrierFields => [
    _customerName,
    _accountNumber,
    _accountPhone,
    _pin,
    _representative,
    _representativeEmail,
    _line1,
    _line2,
    _city,
    _state,
    _postal,
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _numbers.dispose();
    for (final c in _carrierFields) {
      c.dispose();
    }
    super.dispose();
  }

  bool get _canEdit => widget.session.me?.can('phone.edit') ?? false;

  Future<void> _load() async {
    setState(() {
      _loading = _ports.isEmpty && _connections.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/ports'),
      widget.api.get('/phone/connections'),
    ]);

    if (!mounted) return;

    setState(() {
      _loading = false;
      _ports = results[0].ok ? _Port.listFrom(results[0].body) : const [];
      _connections = results[1].ok ? PhoneConnectionRef.listFrom(results[1].body) : const [];
      _error = results[0].ok ? null : results[0].reason;
      _connectionId ??= _connections.isEmpty ? null : _connections.first.id;
    });
  }

  Future<void> _start() async {
    final connectionId = _connectionId;
    if (connectionId == null) return;

    // One per line, so pasting a list from a carrier's letter works without
    // anybody reformatting it.
    final numbers = _numbers.text
        .split(RegExp(r'[\n,;]'))
        .map((n) => n.trim())
        .where((n) => n.isNotEmpty)
        .toList();

    if (numbers.isEmpty) {
      setState(() => _error = 'Give at least one number to port in.');
      return;
    }

    String text(TextEditingController c) => c.text.trim();
    if ([
      _customerName,
      _representative,
      _representativeEmail,
      _line1,
      _city,
      _state,
      _postal,
    ].any((c) => text(c).isEmpty)) {
      setState(
        () => _error =
            'Fill in the account name, who signs, their email and the '
            'service address as the current carrier has them.',
      );
      return;
    }

    setState(() {
      _starting = true;
      _error = null;
    });

    final response = await widget.api.post('/phone/ports', {
      'connection_id': connectionId,
      'e164s': numbers,
      'losing_carrier': {
        'customer_type': _customerType,
        'customer_name': text(_customerName),
        if (text(_accountNumber).isNotEmpty)
          'account_number': text(_accountNumber),
        if (text(_accountPhone).isNotEmpty)
          'account_telephone_number': text(_accountPhone),
        'authorized_representative': text(_representative),
        'authorized_representative_email': text(_representativeEmail),
        'address': {
          'street': text(_line1),
          if (text(_line2).isNotEmpty) 'street_2': text(_line2),
          'city': text(_city),
          'state': text(_state),
          'zip': text(_postal),
          'country': 'US',
        },
      },
      if (text(_pin).isNotEmpty) 'pin': text(_pin),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _starting = false;
        _error = response.reason;
      });
      return;
    }

    _numbers.clear();
    for (final c in _carrierFields) {
      c.clear();
    }
    setState(() {
      _starting = false;
      _open = false;
    });

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            Expanded(
              child: Text('Porting', style: theme.textTheme.titleMedium),
            ),
            if (_ports.isNotEmpty) ...[
              Flexible(
                child: Align(
                  alignment: Alignment.centerRight,
                  child: _searchBox(
                    key: const ValueKey('comm-porting-search'),
                    hint: 'Search number, status or signer',
                    onChanged: (value) => setState(() => _query = value),
                  ),
                ),
              ),
              const SizedBox(width: 12),
            ],
            if (_canEdit && _connections.isNotEmpty && !_open)
              OutlinedButton.icon(
                icon: const Icon(Icons.call_received, size: 18),
                label: const Text('New port-in'),
                onPressed: () => setState(() => _open = true),
              ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'Move a number here from another carrier. Submitting and tracking '
          'are automated; the rest is the carriers’ own process, and this '
          'screen reports it rather than driving it.',
          style: theme.textTheme.bodySmall,
        ),

        if (_error != null) ...[
          const SizedBox(height: 16),
          ErrorNote(_error!),

          // When the load is what failed there are no ports and no accounts,
          // so nothing else on this pane is pressable.
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(onPressed: _load, child: const Text('Try again')),
          ),
        ],

        if (_open) ...[const SizedBox(height: 16), _form(theme)],

        const SizedBox(height: 24),

        if (_connections.isEmpty)
          Text(
            'No phone account is connected yet, so there is nowhere to port a '
            'number to.',
            style: theme.textTheme.bodySmall,
          )
        else if (_ports.isEmpty)
          Text(
            'No ports yet. Numbers you already own elsewhere can be moved here '
            'without changing them.',
            style: theme.textTheme.bodySmall,
          )
        else ...[
          _tiles(),
          const SizedBox(height: 12),
          _table(theme),
        ],

        const SizedBox(height: 24),
        _portOutNote(theme),
      ],
    );
  }

  Widget _form(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('New port-in', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Text(
            'The losing carrier decides when this completes. Do not cancel '
            'service with them until it says completed — a disconnected number '
            'cannot be ported, and getting it back is a new number.',
            style: theme.textTheme.bodySmall,
          ),

          if (_connections.length > 1) ...[
            const SizedBox(height: 16),
            SearchPickerField<String>(
              value: _connectionId,
              label: 'Port into',
              border: OutlineInputBorder(),
              options: [
                for (final c in _connections)
                  SearchOption(value: c.id, label: c.label),
              ],
              onChanged: (id) => setState(() => _connectionId = id),
            ),
          ],

          const SizedBox(height: 16),
          TextField(
            controller: _numbers,
            maxLines: 4,
            decoration: const InputDecoration(
              labelText: 'Numbers',
              hintText: '+17865550123\n+17865550124',
              helperText:
                  'One per line. They must all be on the same losing '
                  'account, which is the carriers’ rule, not ours.',
              helperMaxLines: 3,
              border: OutlineInputBorder(),
            ),
          ),

          const SizedBox(height: 20),
          Text(
            'The current carrier’s account',
            style: Theme.of(context).textTheme.titleSmall,
          ),
          const SizedBox(height: 4),
          Text(
            'Exactly as it appears on their bill. Twilio emails the letter of '
            'authorization to the person below, and nothing moves until they '
            'sign it.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            key: const ValueKey('port-customer-type'),
            value: _customerType,
            label: 'Account type',
            border: const OutlineInputBorder(),
            options: const [
              SearchOption(value: 'Business', label: 'Business'),
              SearchOption(value: 'Individual', label: 'Individual'),
            ],
            onChanged: (v) => setState(() => _customerType = v ?? 'Business'),
          ),
          for (final (key, controller, label, helper) in [
            ('port-customer-name', _customerName, 'Name on the account', null),
            (
              'port-account-number',
              _accountNumber,
              'Account number',
              'Required for mobile numbers.',
            ),
            ('port-account-phone', _accountPhone, 'Account phone number', null),
            (
              'port-pin',
              _pin,
              'Port PIN',
              'Required for US mobile numbers. Ask the current carrier for it.',
            ),
            (
              'port-representative',
              _representative,
              'Who signs',
              'The person the carrier has as authorized on the account.',
            ),
            (
              'port-representative-email',
              _representativeEmail,
              'Their email',
              'The letter of authorization is sent here.',
            ),
          ]) ...[
            const SizedBox(height: 12),
            TextField(
              key: ValueKey(key),
              controller: controller,
              decoration: InputDecoration(
                labelText: label,
                helperText: helper,
                border: const OutlineInputBorder(),
              ),
            ),
          ],
          const SizedBox(height: 12),
          AddressFields(
            api: widget.api,
            line1: _line1,
            line2: _line2,
            city: _city,
            state: _state,
            postal: _postal,
            dense: false,
          ),

          const SizedBox(height: 16),
          Row(
            children: [
              FilledButton(
                onPressed: _starting ? null : _start,
                child: Text(_starting ? 'Submitting…' : 'Submit port-in'),
              ),
              const SizedBox(width: 12),
              TextButton(
                onPressed: _starting
                    ? null
                    : () => setState(() {
                        _open = false;
                        _error = null;
                      }),
                child: const Text('Cancel'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _tiles() {
    int count(String key) => _ports.where((p) => p.stage == key).length;

    return FilterTiles(
      prefix: 'ports',
      picked: _tile,
      onPick: (key) => setState(() => _tile = key),
      tiles: [
        FilterTile(
          key: null,
          label: 'Ports',
          count: _ports.length,
          note: 'all',
        ),
        FilterTile(
          key: 'progress',
          label: 'In progress',
          count: count('progress'),
          note: 'with the carrier',
          tone: Tone.info,
        ),
        FilterTile(
          key: 'action',
          label: 'Action needed',
          count: count('action'),
          note: 'something to sign or fix',
          tone: Tone.bad,
        ),
        FilterTile(
          key: 'done',
          label: 'Completed',
          count: count('done'),
          note: 'moved over',
          tone: Tone.ok,
        ),
      ],
    );
  }

  Widget _table(ThemeData theme) {
    final shown = [
      for (final port in _ports)
        if ((_tile == null || port.stage == _tile) &&
            _searchHit(_query, [
              ...port.numbers,
              port.statusLabel,
              port.said,
              port.account,
              port.signer,
            ]))
          port,
    ];
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;

    if (shown.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          _query.trim().isNotEmpty
              ? 'Nothing matches "${_query.trim()}".'
              : 'No ports like that.',
          textAlign: TextAlign.center,
        ),
      );
    }

    return ListTable(
      columns: const [
        ListColumn('Numbers', flex: 4),
        ListColumn('Status', flex: 2),
        ListColumn('What the carrier said', flex: 5),
        ListColumn('Started', flex: 2, end: true),
      ],
      rowKey: (i) => ValueKey('port:${shown[i].id}'),
      rows: [
        for (final port in shown)
          [
            Text(
              port.numbers.join(', '),
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
            StatusPill(port.statusLabel, tone: port.tone),
            Text(port.said, style: small),
            Text(formatListStamp(port.createdAt, '—', formats: formats)),
          ],
      ],
    );
  }

  /// Said plainly rather than offered as a button that would do nothing.
  Widget _portOutNote(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Moving a number away', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Text(
            'Port-out is started by the carrier you are moving TO, not here — '
            'there is no API for it, so EzBizi cannot show its progress and '
            'will not pretend to. Give your new provider the number and the '
            'account details from Connection, and keep the line here until '
            'they confirm, because releasing it first loses the number.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

class _Port {
  const _Port({
    required this.id,
    required this.numbers,
    required this.status,
    required this.reason,
    required this.settled,
    required this.createdAt,
    this.pin,
    this.account,
    this.signer,
  });

  final String id;
  final List<String> numbers;
  final String status;
  final String reason;
  final bool settled;
  final String? createdAt;

  /// From what we gave the carrier, when the port was started here.
  final String? pin;
  final String? account;
  final String? signer;

  /// Which tile it counts under. `waiting_for_signature` is action needed,
  /// not progress: the carrier has emailed a letter of authorization and
  /// nothing moves until somebody here signs it. The vocabulary is
  /// HttpTwilioGateway::normalizePortStatus's; `settled` is the server's.
  /// A port that ended without completing (canceled, failed, expired)
  /// counts under none but Ports.
  String? get stage {
    if (status == 'completed') return 'done';
    if (status == 'action_required' || status == 'waiting_for_signature') {
      return 'action';
    }
    if (!settled) return 'progress';
    return null;
  }

  /// What the carrier said, then what we gave them: the PIN and account,
  /// and who was sent the letter while it waits for a signature.
  String get said {
    final parts = [
      if (reason.isNotEmpty) reason,
      if (status == 'waiting_for_signature' && signer != null)
        'Letter of authorization sent to $signer',
      if (pin != null) 'PIN $pin',
      if (account != null) 'Account $account',
    ];

    return parts.isEmpty ? '—' : parts.join(' · ');
  }

  String get statusLabel => switch (status) {
    'in_review' => 'In review',
    'waiting_for_signature' => 'Waiting for signature',
    'in_progress' => 'In progress',
    'action_required' => 'Action needed',
    'completed' => 'Completed',
    'canceling' => 'Canceling',
    'canceled' => 'Canceled',
    'failed' => 'Failed',
    'expired' => 'Expired',
    _ => status.replaceAll('_', ' '),
  };

  Tone get tone => switch (status) {
    'completed' => Tone.ok,
    'action_required' || 'failed' => Tone.bad,
    'waiting_for_signature' => Tone.caution,
    _ when !settled => Tone.info,
    _ => Tone.neutral,
  };

  static List<_Port> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Port(
          id: row['id'].toString(),
          numbers: [
            for (final n in (row['e164s'] as List? ?? const [])) n.toString(),
          ],
          status: (row['status'] ?? 'unknown').toString(),
          reason: (row['status_reason'] ?? '').toString(),
          settled: row['settled'] == true,
          createdAt: row['created_at'] as String?,
          pin: (row['carrier'] as Map?)?['pin'] as String?,
          account:
              ((row['carrier'] as Map?)?['losing_carrier']
                      as Map?)?['account_number']
                  as String?,
          signer:
              ((row['carrier'] as Map?)?['losing_carrier']
                      as Map?)?['authorized_representative_email']
                  as String?,
        ),
    ];
  }
}

/// The list's search box, sized to sit in the pane's header beside its
/// buttons: 40 high, the height of the controls around it.
Widget _searchBox({
  required Key key,
  required String hint,
  required ValueChanged<String> onChanged,
}) => SizedBox(
  width: 260,
  child: TextField(
    key: key,
    onChanged: onChanged,
    textAlignVertical: TextAlignVertical.center,
    decoration: InputDecoration(
      isDense: true,
      hintText: hint,
      prefixIcon: const Icon(Icons.search, size: 18),
      prefixIconConstraints: const BoxConstraints(minWidth: 36),
      border: const OutlineInputBorder(),
    ),
  ),
);


/// Whether any of [fields] holds [query], ignoring case. A query of digits
/// and phone punctuation also matches a number however it is stored, so
/// "(203) 555" finds +12035550199.
bool _searchHit(String query, Iterable<String?> fields) {
  final q = query.trim().toLowerCase();
  if (q.isEmpty) return true;
  final digits = q.replaceAll(RegExp(r'\D'), '');
  final phoneish = digits.length >= 3 && RegExp(r'^[\d\s()+.-]+$').hasMatch(q);
  for (final field in fields) {
    if (field == null || field.isEmpty) continue;
    final value = field.toLowerCase();
    if (value.contains(q)) return true;
    if (phoneish && value.replaceAll(RegExp(r'\D'), '').contains(digits)) {
      return true;
    }
  }
  return false;
}
