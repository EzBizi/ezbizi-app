import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';

/// Phone › Settings › Number quality — what a number actually is.
///
/// ── Why anybody needs this ───────────────────────────────────────
///
/// A number in the customer book looks the same whether it rings a mobile, a
/// disconnected landline, or nothing at all. Texting a landline fails silently;
/// so does texting a number that was given up two years ago. The only way to
/// tell is to ask the carrier, and Twilio charges for the answer.
///
/// ── Why the price is on the screen ───────────────────────────────
///
/// Because it is spent by pressing a button here. A screen that hides a
/// per-click charge behind a friendly verb is how somebody runs it five
/// hundred times to see what it does. The cached answer is free and says so;
/// the fresh one costs and says that too.
class NumberQualityPane extends StatefulWidget {
  const NumberQualityPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<NumberQualityPane> createState() => _NumberQualityPaneState();
}

class _NumberQualityPaneState extends State<NumberQualityPane> {
  final _number = TextEditingController();

  bool _looking = false;
  bool _force = false;
  String? _error;
  String? _note;
  Map<String, dynamic>? _result;
  bool _wasCached = false;

  @override
  void dispose() {
    _number.dispose();
    super.dispose();
  }

  bool get _canLookUp => widget.session.me?.can('phone.edit') ?? false;

  Future<void> _lookUp() async {
    final e164 = _number.text.trim();
    // Enter while one is running would pay twice (audit 11-27).
    if (e164.isEmpty || _looking) return;

    setState(() {
      _looking = true;
      _error = null;
      _note = null;
      _result = null;
    });

    final response = await widget.api.get(
      '/phone/lookup?e164=${Uri.encodeQueryComponent(e164)}'
      '${_force ? '&force=1' : ''}',
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _looking = false;
        _error = response.reason;
      });
      return;
    }

    final data = response.body['data'];

    setState(() {
      _looking = false;
      _result = data is Map<String, dynamic> ? data : null;
      _wasCached = response.body['cached'] == true;
      _note = response.body['message'] as String?;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (!_canLookUp) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'Checking a number costs money, so it needs permission to change '
          'phone settings.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('Number quality', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'Check whether a number is real, and whether it is a mobile that can '
          'receive texts. A cached answer is free; a fresh one is billed by '
          'Twilio per check.',
          style: theme.textTheme.bodySmall,
        ),

        const SizedBox(height: 20),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: TextField(
                controller: _number,
                keyboardType: TextInputType.phone,
                onSubmitted: (_) => _lookUp(),
                decoration: const InputDecoration(
                  labelText: 'Phone number',
                  hintText: '+17865550123',
                  border: OutlineInputBorder(),
                ),
              ),
            ),
            const SizedBox(width: 12),
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: FilledButton(
                onPressed: _looking ? null : _lookUp,
                child: Text(_looking ? 'Checking…' : 'Look up'),
              ),
            ),
          ],
        ),

        CheckboxListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: _force,
          onChanged: (on) => setState(() => _force = on ?? false),
          title: const Text('Ask the carrier again'),
          subtitle: Text(
            'Ignores anything checked in the last 30 days and pays for a fresh '
            'answer. Worth it when a number may have been ported.',
            style: theme.textTheme.bodySmall,
          ),
        ),

        if (_error != null) ...[
          const SizedBox(height: 16),
          ErrorNote(_error!),
        ],

        if (_note != null) ...[
          const SizedBox(height: 16),
          Text(_note!, style: theme.textTheme.bodySmall),
        ],

        if (_result != null) ...[
          const SizedBox(height: 20),
          _resultCard(_result!, theme),
        ],

        const SizedBox(height: 32),
        _batchNote(theme),
      ],
    );
  }

  Widget _resultCard(Map<String, dynamic> row, ThemeData theme) {
    final valid = row['valid'] == true;
    final lineType = (row['line_type'] as String?) ?? '';

    // The one that costs somebody a lost message: a landline cannot receive a
    // text, and nothing about the number itself says so.
    final textable = lineType == 'mobile' || lineType.contains('voip');

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  valid ? Icons.check_circle_outline : Icons.error_outline,
                  color: valid ? null : theme.colorScheme.error,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    (row['national_format'] as String?) ??
                        (row['phone_number'] as String? ?? ''),
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                Chip(
                  label: Text(_wasCached ? 'From cache' : 'Fresh'),
                  visualDensity: VisualDensity.compact,
                  materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
              ],
            ),

            const SizedBox(height: 12),

            if (!valid)
              Text(
                'Not a number anybody can dial. Worth correcting wherever it '
                'is stored — calls and texts to it go nowhere.',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.error,
                ),
              )
            else ...[
              _fact('Kind', lineType.isEmpty ? 'Not reported' : lineType, theme),
              _fact('Carrier', (row['carrier_name'] as String?) ?? 'Not reported', theme),
              _fact('Name on file', (row['caller_name'] as String?) ?? 'Not reported', theme),
              _fact('Country', (row['country_code'] as String?) ?? '—', theme),

              const SizedBox(height: 12),
              Text(
                textable
                    ? 'Can receive texts.'
                    : lineType.isEmpty
                    ? 'Twilio did not report a line type, so whether texts '
                          'reach it is unknown.'
                    : 'Cannot receive texts — a message sent here is accepted '
                          'and then goes nowhere.',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: textable ? null : theme.colorScheme.error,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _fact(String label, String value, ThemeData theme) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Row(
      children: [
        SizedBox(
          width: 120,
          child: Text(label, style: theme.textTheme.bodySmall),
        ),
        Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
      ],
    ),
  );

  /// Named rather than offered, because the button would spend real money at
  /// a scale nobody can undo.
  Widget _batchNote(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Checking the whole customer book', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Text(
            'Twilio can also say whether a number has been handed to a new '
            'subscriber since you last verified it — the thing that turns an '
            'old customer record into a stranger’s phone. It is a separate '
            'paid lookup and is not wired up here yet, deliberately: run '
            'across a book of any size it is a bill nobody approved, so it '
            'needs an estimate and a confirmation before it needs a button.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}
