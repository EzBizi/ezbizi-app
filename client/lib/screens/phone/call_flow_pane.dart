import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';

/// Phone › Settings › Call flow — what actually happens when somebody rings.
///
/// ── Why this screen exists ───────────────────────────────────────
///
/// Everything built on the other screens is inert until a line points at it.
/// A ring group with nobody in it, an IVR menu never selected, opening hours
/// on no schedule — each looks finished on its own screen and changes nothing
/// about the call. Nowhere said so.
///
/// Five production lines had no ring group from the day they were imported.
/// Every caller heard voicemail for months. The Numbers screen did disclose
/// it, in grey, as the fourth line of a row somebody opened to do something
/// else. Disclosure is not the same as being told.
///
/// So this reads top to bottom in the order the call is actually decided, and
/// a step that stops the call reads as a fault rather than as a caption.
///
/// ── Why the steps come from the server ───────────────────────────
///
/// The trace is TwimlRenderer::describe(), which branches on the same private
/// predicates the renderer's inbound() does. Judging it again in Dart would be
/// a second rule that could drift — which is the precise mechanism that let
/// those five lines look healthy while every call went to voicemail.
class CallFlowPane extends StatefulWidget {
  const CallFlowPane({super.key, required this.api, this.onOpen});

  final ApiClient api;

  /// Take the reader to the settings section that changes a step.
  ///
  /// The TAB decides how — it owns the section list and the selection, and a
  /// pane that reached into that would be the second thing choosing which
  /// section is open. Null where nothing can navigate, and the rows are then
  /// plain text rather than buttons that do nothing.
  final void Function(String pane)? onOpen;

  @override
  State<CallFlowPane> createState() => _CallFlowPaneState();
}

class _CallFlowPaneState extends State<CallFlowPane> {
  bool _loading = true;
  String? _error;
  List<_Line> _lines = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _lines.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/phone/call-flow');

    if (!mounted) return;

    setState(() {
      _loading = false;
      _lines = response.ok ? _Line.listFrom(response.body) : const [];
      _error = response.ok ? null : response.reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ErrorNote(_error!),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    final broken = _lines.where((l) => !l.reaches).length;

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('Call flow', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'What actually happens when somebody rings this business. Everything '
          'set up on the other screens does nothing until a line points at it.',
          style: theme.textTheme.bodySmall,
        ),

        if (_lines.isEmpty) ...[
          const SizedBox(height: 20),
          Text(
            'No lines yet. Import or buy one on the Numbers screen and its '
            'call flow appears here.',
            style: theme.textTheme.bodySmall,
          ),
        ],

        if (broken > 0) ...[
          const SizedBox(height: 16),
          _alarm(theme, broken),
        ],

        const SizedBox(height: 8),
        for (final line in _lines) _lineCard(line, theme),
      ],
    );
  }

  /// The count first, because one silent line among five is easy to miss when
  /// each is drawn identically.
  Widget _alarm(ThemeData theme, int broken) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.phone_missed_outlined,
            color: theme.colorScheme.onErrorContainer,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              broken == 1
                  ? 'One line reaches nobody. Every call to it goes straight '
                        'to voicemail.'
                  : '$broken lines reach nobody. Every call to them goes '
                        'straight to voicemail.',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onErrorContainer,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _lineCard(_Line line, ThemeData theme) {
    return Card(
      key: ValueKey('flow:${line.id}'),
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(line.heading, style: theme.textTheme.titleSmall),
                ),
                if (line.transcribe)
                  const _Flag(icon: Icons.subject, label: 'Transcribed'),
                if (line.record)
                  const _Flag(icon: Icons.fiber_manual_record, label: 'Recorded'),
              ],
            ),
            const SizedBox(height: 12),
            for (final step in line.steps) _stepRow(step, theme),
          ],
        ),
      ),
    );
  }

  /// A step, and a way to the screen that changes it.
  ///
  /// Jose, 2026-09-23: *"i'd like to be able to click the 4 items they each
  /// have, and that should direct me to the screen where that can be
  /// changed."*
  ///
  /// It takes you to the SECTION, not to the record inside it. Landing on Ring
  /// groups with the right team already open would be better, and it is a
  /// different job: none of the five panes accepts "open this one" yet, and
  /// adding it to all of them to save one click is the kind of plumbing that
  /// earns its place on the second request rather than the first.
  ///
  /// A step with nowhere to go stays a plain row rather than a button that
  /// does nothing, which is the failure this whole screen is about.
  Widget _stepRow(_Step step, ThemeData theme) {
    final row = _stepBody(step, theme);
    final pane = step.editPane;

    if (pane == null || widget.onOpen == null) return row;

    return InkWell(
      key: Key('call-flow-step:${step.step}'),
      onTap: () => widget.onOpen!(pane),
      borderRadius: BorderRadius.circular(6),
      child: row,
    );
  }

  Widget _stepBody(_Step step, ThemeData theme) {
    final bad = !step.usable;
    final colour = bad ? theme.colorScheme.error : null;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            bad ? Icons.warning_amber_rounded : _icons[step.step] ?? Icons.circle_outlined,
            size: 18,
            color: colour,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  step.title,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: colour,
                    fontWeight: bad ? FontWeight.w600 : FontWeight.w500,
                  ),
                ),
                Text(
                  step.detail,
                  style: theme.textTheme.bodySmall?.copyWith(color: colour),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  static const _icons = <String, IconData>{
    'arrival': Icons.call_received,
    'hours': Icons.schedule_outlined,
    'menu': Icons.dialpad_outlined,
    'answer': Icons.groups_outlined,
  };
}

class _Flag extends StatelessWidget {
  const _Flag({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(left: 8),
      child: Chip(
        avatar: Icon(icon, size: 13),
        label: Text(label),
        visualDensity: VisualDensity.compact,
        materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
      ),
    );
  }
}

class _Step {
  const _Step({
    required this.step,
    required this.title,
    required this.detail,
    required this.usable,
    this.editPane,
  });

  final String step;
  final String title;
  final String detail;
  final bool usable;

  /// Which settings section changes this step, from the server.
  ///
  /// The server's, not derived here. `TwimlRenderer::describe()` stamps every
  /// step with the same `{pane, id}` the call flow CHART puts on its nodes —
  /// they are two drawings of one call flow, so a step that sent somebody
  /// somewhere the chart does not would mean one of them is wrong. Working it
  /// out on this screen would be a third opinion.
  final String? editPane;
}

class _Line {
  const _Line({
    required this.id,
    required this.e164,
    required this.label,
    required this.record,
    required this.transcribe,
    required this.steps,
  });

  final String id;
  final String e164;
  final String label;
  final bool record;
  final bool transcribe;
  final List<_Step> steps;

  String get heading => label.trim().isEmpty || label == e164
      ? formatPhoneNumber(e164)
      : '$label · ${formatPhoneNumber(e164)}';

  /// Whether a call to this line reaches a person at all — the step the whole
  /// screen exists to make impossible to miss.
  bool get reaches =>
      steps.every((s) => s.step != 'answer' || s.usable) &&
      steps.every((s) => s.step != 'arrival' || s.usable);

  static List<_Line> listFrom(Map<String, dynamic> body) {
    final rows = (body['data'] as List?) ?? const [];

    return [
      for (final row in rows.cast<Map<String, dynamic>>())
        _Line(
          id: row['id'].toString(),
          e164: (row['e164'] ?? '') as String,
          label: (row['label'] ?? '') as String? ?? '',
          record: row['record'] == true,
          transcribe: row['transcribe'] == true,
          steps: [
            for (final step
                in ((row['steps'] as List?) ?? const [])
                    .cast<Map<String, dynamic>>())
              _Step(
                step: (step['step'] ?? '') as String,
                title: (step['title'] ?? '') as String,
                detail: (step['detail'] ?? '') as String,
                usable: step['usable'] == true,
                editPane:
                    ((step['edit'] as Map<String, dynamic>?) ?? const {})['pane']
                        as String?,
              ),
          ],
        ),
    ];
  }
}
