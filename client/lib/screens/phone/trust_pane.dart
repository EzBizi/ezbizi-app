import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import 'number_quality_pane.dart';
import 'phone_refs.dart';

/// Phone › Settings › Trust & caller identity.
///
/// ── The one question this screen answers ─────────────────────────
///
/// "Why do my calls show up as spam." Answering it needs three unlike things —
/// how carriers graded the calls, what is registered with Twilio, and what a
/// person actually saw on a handset — which is why they are drawn as a ladder
/// rather than a dashboard: each rung depends on the one above it.
///
/// ── What Twilio cannot do, said out loud ─────────────────────────
///
/// The Business Profile can only be created in Twilio's own Console. No API
/// makes one. Voice Integrity additionally needs it APPROVED rather than
/// merely created, which is why the two registrations can read differently for
/// one underlying reason rather than two.
///
/// And no API anywhere reports whether a number currently shows as "Scam
/// Likely" on a given carrier. The only way to know is to ring it from a
/// handset on that carrier and write down what appeared — which is what the
/// log at the bottom is, and why it is empty rather than broken.
///
/// Approved design: docs/previews/phone-trust-layouts.html #1 (d66bcef).
class TrustPane extends StatefulWidget {
  const TrustPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TrustPane> createState() => _TrustPaneState();
}

class _TrustPaneState extends State<TrustPane> {
  bool _loading = true;
  bool _saving = false;
  String? _error;

  List<PhoneConnectionRef> _connections = const [];
  PhoneConnectionRef? _scope;

  Map<String, String> _status = const {};
  _Attestation? _attestation;
  List<_Observation> _log = const [];

  int _days = 30;

  /// The inline observation row, open or not. No modals.
  bool _logging = false;
  final _carrier = TextEditingController();
  final _observed = TextEditingController();
  String? _observedNumber;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _carrier.dispose();
    _observed.dispose();
    super.dispose();
  }

  bool get _canEdit => widget.session.me?.can('phone.edit') ?? false;

  Future<void> _load() async {
    setState(() => _error = null);

    final results = await Future.wait([
      widget.api.get('/phone/connections'),
      widget.api.get('/phone/trust/attestation?days=$_days'),
      widget.api.get('/phone/trust/observations'),
      widget.api.get('/phone/numbers'),
    ]);

    if (!mounted) return;

    final connections = PhoneConnectionRef.listFrom(results[0].body);

    setState(() {
      _loading = false;
      _connections = connections;
      _scope ??= connections.isEmpty ? null : connections.first;
      _attestation = results[1].ok ? _Attestation.from(results[1].body) : null;
      _log = results[2].ok ? _Observation.listFrom(results[2].body) : const [];
      _observedNumber ??= _firstNumber(results[3]);
      _error = results[0].ok ? null : results[0].reason;
    });

    await _loadStatus();
  }

  String? _firstNumber(ApiResponse response) {
    if (!response.ok) return null;
    final rows = response.body['data'];
    if (rows is! List || rows.isEmpty) return null;

    return (rows.first as Map<String, dynamic>)['e164'] as String?;
  }

  Future<void> _loadStatus() async {
    final scope = _scope;

    if (scope == null) {
      setState(() => _status = const {});
      return;
    }

    final response = await widget.api.get('/phone/connections/${scope.id}/trust');

    if (!mounted) return;

    setState(() {
      _status = response.ok
          ? {
              for (final entry in (response.body['data'] as Map? ?? const {}).entries)
                entry.key.toString(): entry.value?.toString() ?? 'unknown',
            }
          : const {};
    });
  }

  Future<void> _reloadAttestation() async {
    final response = await widget.api.get('/phone/trust/attestation?days=$_days');
    if (!mounted) return;

    setState(() {
      _attestation = response.ok ? _Attestation.from(response.body) : null;
    });
  }

  Future<void> _logObservation() async {
    final scope = _scope;
    if (scope == null) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api.post('/phone/trust/observations', {
      'connection_id': scope.id,
      'e164': _observedNumber ?? '',
      'carrier': _carrier.text.trim(),
      'observed': _observed.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.reason;
      });
      return;
    }

    _carrier.clear();
    _observed.clear();
    setState(() {
      _saving = false;
      _logging = false;
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
        Text('Trust & caller identity', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'Whether your outbound calls arrive looking trustworthy — and what '
          'is standing in the way.',
          style: theme.textTheme.bodySmall,
        ),

        if (_error != null) ...[
          const SizedBox(height: 16),
          ErrorNote(_error!),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(onPressed: _load, child: const Text('Try again')),
          ),
        ],

        if (_connections.isEmpty) ...[
          const SizedBox(height: 20),
          Text(
            'No phone account is connected yet, so there is nothing registered '
            'and nothing to grade.',
            style: theme.textTheme.bodySmall,
          ),
        ] else ...[
          const SizedBox(height: 20),
          _profileStep(theme),

          const SizedBox(height: 12),
          _cnamCard(theme),

          const SizedBox(height: 12),
          _voiceIntegrityCard(theme),

          const Divider(height: 40),
          _attestationPanel(theme),

          const Divider(height: 40),
          _observationLog(theme),
        ],

        const Divider(height: 40),
        Text('Check one number', style: theme.textTheme.labelLarge),
        const SizedBox(height: 2),
        Text(
          'Whether a number is real, and whether a text can reach it.',
          style: theme.textTheme.bodySmall,
        ),

        // Absorbed rather than reimplemented: the lookup tool answers the same
        // "is something wrong with my number" question, and two sections for
        // one question is what the rail change removed.
        SizedBox(
          height: 520,
          child: NumberQualityPane(api: widget.api, session: widget.session),
        ),
      ],
    );
  }

  /// Step zero. Both registrations below depend on it, and it is the one thing
  /// on this screen that cannot be done from EzBizi at all.
  Widget _profileStep(ThemeData theme) {
    final state = _status['customer_profile'] ?? 'unknown';
    final approved = state == 'approved' || state == 'twilio-approved';

    return _card(
      theme,
      title: 'Twilio Business Profile',
      state: approved ? 'Approved' : _label(state),
      good: approved,
      body: approved
          ? 'Twilio has your business on record. Both registrations below '
                'depend on this.'
          : 'Twilio has no API that creates a Business Profile — it only '
                'exists once somebody fills it in on Twilio’s own site, and '
                'EzBizi reads it afterwards. A Twilio limitation, not a '
                'missing feature here.',
    );
  }

  Widget _cnamCard(ThemeData theme) {
    final state = _status['cnam'] ?? 'unknown';
    final approved = state == 'approved' || state == 'twilio-approved';

    return _card(
      theme,
      title: 'CNAM — the name on the handset',
      state: _label(state),
      good: approved,
      body: approved
          ? 'Customers see your business name written above the number when '
                'you ring them.'
          : 'Until this is approved, customers see the bare number.',
    );
  }

  Widget _voiceIntegrityCard(ThemeData theme) {
    final state = _status['voice_integrity'] ?? 'unknown';
    final approved = state == 'approved' || state == 'twilio-approved';
    final profileApproved = (_status['customer_profile'] ?? '') == 'approved'
        || (_status['customer_profile'] ?? '') == 'twilio-approved';

    return _card(
      theme,
      title: 'Voice Integrity — spam labelling',
      state: _label(state),
      good: approved,
      body: 'Asks carriers to stop labelling this number as spam. This is the '
          'one that answers “Scam Likely”.',
      note: profileApproved
          ? null
          : 'Waiting on the Business Profile above: Voice Integrity starts '
                'once that profile is approved.',
    );
  }

  /// ── Why the hatched half is never an accusation ────────────────
  ///
  /// "Not captured" is a call from before EzBizi started recording grades. It
  /// is not a bad grade, and colouring it like one would tell somebody every
  /// call they ever made was untrusted — on the screen they opened because
  /// they already suspected it.
  Widget _attestationPanel(ThemeData theme) {
    final a = _attestation;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text('Attestation', style: theme.textTheme.labelLarge),
            ),
            for (final days in [7, 30, 90])
              Padding(
                padding: const EdgeInsets.only(left: 6),
                child: ChoiceChip(
                  label: Text('${days}d'),
                  selected: _days == days,
                  onSelected: (_) {
                    setState(() => _days = days);
                    _reloadAttestation();
                  },
                ),
              ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'How carriers graded your outbound calls. An A means the carrier '
          'vouched for the number; B, C or unsigned went out less trusted.',
          style: theme.textTheme.bodySmall,
        ),

        const SizedBox(height: 12),

        if (a == null || !a.capturing)
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Nothing graded yet — this is not an error.',
                  style: theme.textTheme.bodyMedium,
                ),
                const SizedBox(height: 4),
                Text(
                  'EzBizi only began recording call grades recently. This fills '
                  'forward from that day and cannot be backfilled, because the '
                  'grade exists only at the moment a call is placed.'
                  '${a != null && a.uncaptured > 0 ? ' ${a.uncaptured} call${a.uncaptured == 1 ? '' : 's'} in this window came before that.' : ''}',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          )
        else ...[
          Text(
            '${a.degradedRate?.toStringAsFixed(1) ?? '—'}% degraded',
            style: theme.textTheme.headlineSmall?.copyWith(
              color: (a.degradedRate ?? 0) > 0 ? theme.colorScheme.error : null,
            ),
          ),
          const SizedBox(height: 8),
          _legend(theme, 'A — full attestation', a.full),
          _legend(theme, 'B, C or unsigned — degraded', a.degraded),
          _legend(theme, 'Not captured', a.uncaptured),
          const SizedBox(height: 8),
          Text(
            '${a.total} call${a.total == 1 ? '' : 's'} in this window, '
            '${a.graded} of them graded. The rate is measured against those '
            '${a.graded} — a call we never recorded a grade for is not a bad '
            'grade.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ],
    );
  }

  Widget _legend(ThemeData theme, String label, int count) => Padding(
    padding: const EdgeInsets.only(bottom: 2),
    child: Row(
      children: [
        Expanded(child: Text(label, style: theme.textTheme.bodySmall)),
        Text('$count', style: theme.textTheme.bodySmall),
      ],
    ),
  );

  Widget _observationLog(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                'What people have observed',
                style: theme.textTheme.labelLarge,
              ),
            ),
            if (_canEdit && !_logging)
              TextButton.icon(
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Record what you saw'),
                onPressed: () => setState(() => _logging = true),
              ),
          ],
        ),
        const SizedBox(height: 2),
        Text(
          'No Twilio API reports whether a number currently shows as “Scam '
          'Likely” on a given carrier — not one. The only way to know is to '
          'ring this number from a handset on that carrier and write down what '
          'the screen said. It is empty because nobody has looked, not because '
          'a sync failed.',
          style: theme.textTheme.bodySmall,
        ),

        if (_logging) ...[
          const SizedBox(height: 12),
          _observationForm(theme),
        ],

        const SizedBox(height: 12),

        if (_log.isEmpty)
          Text('No entries logged yet.', style: theme.textTheme.bodySmall)
        else
          for (final row in _log)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 96,
                    child: Text(row.carrier, style: theme.textTheme.bodySmall),
                  ),
                  Expanded(
                    child: Text(row.observed, style: theme.textTheme.bodyMedium),
                  ),
                  Text(
                    row.by.isEmpty ? '' : row.by,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
      ],
    );
  }

  Widget _observationForm(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              SizedBox(
                width: 180,
                child: TextField(
                  controller: _carrier,
                  decoration: const InputDecoration(
                    labelText: 'Carrier',
                    hintText: 'Verizon',
                    border: OutlineInputBorder(),
                    isDense: true,
                  ),
                ),
              ),
              SizedBox(
                width: 280,
                child: TextField(
                  controller: _observed,
                  decoration: const InputDecoration(
                    labelText: 'What the handset showed',
                    hintText: 'Scam Likely',
                    border: OutlineInputBorder(),
                    isDense: true,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              FilledButton(
                onPressed: _saving ? null : _logObservation,
                child: Text(_saving ? 'Saving…' : 'Record'),
              ),
              const SizedBox(width: 12),
              TextButton(
                onPressed: () => setState(() => _logging = false),
                child: const Text('Cancel'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _card(
    ThemeData theme, {
    required String title,
    required String state,
    required bool good,
    required String body,
    String? note,
  }) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(title, style: theme.textTheme.titleSmall),
                ),
                Chip(
                  label: Text(state),
                  visualDensity: VisualDensity.compact,
                  materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(body, style: theme.textTheme.bodySmall),

            // Kept from the screen this replaced, because it is the truest
            // sentence on it: a carrier review is somebody else's queue, and
            // a customer who thinks EzBizi is sitting on it will ring EzBizi.
            if (state == 'Pending review') ...[
              const SizedBox(height: 6),
              Text(
                'The carriers decide when this clears and nobody at EzBizi can '
                'hurry it.',
                style: theme.textTheme.bodySmall,
              ),
            ],

            if (note != null) ...[
              const SizedBox(height: 8),
              // A wait on somebody else is not a fault: amber, never the
              // error red (UI pass 2026-11-17).
              Container(
                key: const ValueKey('trust-card-note'),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: ToneColours.of(context, Tone.caution).fill,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  note,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: ToneColours.of(context, Tone.caution).ink,
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  String _label(String raw) => switch (raw) {
    'approved' || 'twilio-approved' => 'Approved',
    'pending' || 'in-review' => 'Pending review',
    'rejected' || 'twilio-rejected' => 'Rejected',
    'unknown' || '' => 'Not started',
    _ => raw,
  };
}

class _Attestation {
  const _Attestation({
    required this.total,
    required this.graded,
    required this.uncaptured,
    required this.full,
    required this.degraded,
    required this.degradedRate,
    required this.capturing,
  });

  final int total;
  final int graded;
  final int uncaptured;
  final int full;
  final int degraded;

  /// Null when nothing is graded — "0% degraded" out of no measurements reads
  /// as a clean bill of health, which is the opposite of the truth.
  final double? degradedRate;
  final bool capturing;

  static _Attestation? from(Map<String, dynamic> body) {
    final d = body['data'];
    if (d is! Map) return null;

    int n(String key) => (d[key] as num?)?.toInt() ?? 0;

    return _Attestation(
      total: n('total'),
      graded: n('graded'),
      uncaptured: n('uncaptured'),
      full: n('full'),
      degraded: n('degraded'),
      degradedRate: (d['degraded_rate'] as num?)?.toDouble(),
      capturing: d['capturing'] == true,
    );
  }
}

class _Observation {
  const _Observation(this.carrier, this.observed, this.by);

  final String carrier;
  final String observed;
  final String by;

  static List<_Observation> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Observation(
          (row['carrier'] ?? '').toString(),
          (row['observed'] ?? '').toString(),
          (row['observed_by'] ?? '').toString(),
        ),
    ];
  }
}
