/// The body of a submitted inspection, drawn the way its PDF prints it
/// (design 203, Jose 2026-11-23: "app one should be more similar, using
/// groups, colors").
///
/// The mirror of `App\Support\Inspection\ReportDocument`:
/// - only what this inspection asked (the same conditions the run used)
/// - a field marked `print: ignore` is never shown
/// - a `print: root` field is a FINDING card: variant bar, PASS/FAIL/N/A badge,
///   the chosen words, the code citation, and what the option revealed
/// - dividers are subheads and alerts are notes
/// - a result line on top and the summary table last
///
/// Kept side by side with the PHP so the screen and the PDF never disagree.
library;

import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/status_pill.dart';
import 'condition.dart';
import 'option.dart';

class InspectionReportBody extends StatelessWidget {
  const InspectionReportBody({
    super.key,
    required this.screens,
    required this.answers,
    required this.findings,
    required this.photos,
    required this.formats,
  });

  final List<Map<String, dynamic>> screens;
  final Map<String, dynamic> answers;

  /// `findings` from the report payload: field key → {label, variant}.
  final Map<String, dynamic> findings;

  /// Picture bytes by attachment id; a missing one draws a placeholder.
  final Map<String, Uint8List> photos;
  final DisplayFormats formats;

  /// The screens and fields this inspection showed (ReportDocument::asked).
  static List<Map<String, dynamic>> asked(
    List<Map<String, dynamic>> screens,
    Map<String, dynamic> answers,
  ) => [
    for (final s in screens)
      if (conditionPasses(s['visible_if'], answers))
        {
          ...s,
          'fields': [
            for (final f in reportItems(s['fields']))
              if (f is Map && conditionPasses(f['visible_if'], answers))
                Map<String, dynamic>.from(f),
          ],
        },
  ];

  static String printAs(Map<String, dynamic> field) {
    final p = '${field['print'] ?? 'text'}';
    return const ['root', 'text', 'ignore'].contains(p) ? p : 'text';
  }

  /// The chosen option's words and its variant, as the server's
  /// ReportDocument::findings worked them out (the PDF's own code, so the
  /// screen and the PDF cannot disagree). Null when unanswered.
  ({String label, String variant})? chosen(Map<String, dynamic> field) {
    final f = findings['${field['key'] ?? ''}'];
    if (f is! Map) return null;
    return (
      label: '${f['label'] ?? ''}',
      variant: '${f['variant'] ?? 'neutral'}',
    );
  }

  static Tone _tone(String variant) => switch (variant) {
    'pass' => Tone.ok,
    'fail' => Tone.bad,
    _ => Tone.neutral,
  };

  static const _badge = {
    'pass': 'PASS',
    'fail': 'FAIL',
    'not_applicable': 'N/A',
  };

  @override
  Widget build(BuildContext context) {
    final shown = asked(screens, answers);
    final roots = [
      for (final s in shown)
        for (final f in s['fields'] as List)
          if (printAs(f) == 'root') f as Map<String, dynamic>,
    ];
    final count = {'pass': 0, 'fail': 0, 'not_applicable': 0};
    for (final f in roots) {
      final v = chosen(f)?.variant;
      if (count.containsKey(v)) count[v!] = count[v]! + 1;
    }

    final graded = roots.isNotEmpty && findings.isNotEmpty;
    final sections = [
      for (final s in shown)
        if (_section(context, s) case final Widget w) w,
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // No `findings` in the payload (a server before 0624): no verdict,
        // rather than a "Pass · 0 of N" nobody worked out.
        if (graded) _result(context, roots.length, count),
        if (sections.isEmpty)
          Text('This inspection had no questions.', style: _muted(context)),
        ...sections,
        if (graded) _summary(context, roots),
      ],
    );
  }

  TextStyle? _muted(BuildContext context) => Theme.of(context)
      .textTheme
      .bodySmall
      ?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant);

  Widget _result(BuildContext context, int total, Map<String, int> count) {
    final failed = count['fail']!;
    final tone = ToneColours.of(context, failed > 0 ? Tone.bad : Tone.ok);
    return Container(
      key: const ValueKey('inspection-report-result'),
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: tone.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Wrap(
        spacing: 12,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                failed > 0 ? Icons.cancel_outlined : Icons.check_circle_outline,
                size: 20,
                color: tone.ink,
              ),
              const SizedBox(width: 8),
              Flexible(
                child: Text(
                  'Inspection result: ${failed > 0 ? 'Fail' : 'Pass'} · '
                  '$failed of $total item${total == 1 ? '' : 's'} '
                  'did not meet standards',
                  style: TextStyle(
                    color: tone.ink,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
          Wrap(
            spacing: 6,
            children: [
              for (final v in const ['pass', 'fail', 'not_applicable'])
                StatusPill(
                  '${_badge[v]} ${count[v]}',
                  tone: _tone(v),
                  solid: true,
                  dense: true,
                ),
            ],
          ),
        ],
      ),
    );
  }

  /// One screen as a card; null when nothing on it prints.
  Widget? _section(BuildContext context, Map<String, dynamic> screen) {
    final theme = Theme.of(context);
    final body = <Widget>[];
    final plain = <Map<String, dynamic>>[];
    var fails = 0;

    void flush() {
      if (plain.isEmpty) return;
      body.add(_grid(context, List.of(plain)));
      plain.clear();
    }

    for (final field
        in (screen['fields'] as List).cast<Map<String, dynamic>>()) {
      final type = '${field['type'] ?? ''}';
      if (type == 'divider') {
        flush();
        final label = '${field['label'] ?? ''}'.trim();
        body.add(
          label.isEmpty
              ? const Divider(height: 16)
              : Padding(
                  padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
                  child: Text(label, style: theme.textTheme.titleSmall),
                ),
        );
        continue;
      }
      if (type == 'alert') {
        flush();
        final note = _quote(context, field['title'], field['body']);
        if (note != null) {
          body.add(Padding(padding: const EdgeInsets.all(12), child: note));
        }
        continue;
      }
      final print = printAs(field);
      if (print == 'ignore') continue;
      if (print == 'root') {
        flush();
        if (chosen(field)?.variant == 'fail') fails++;
        body.add(_finding(context, field));
        continue;
      }
      plain.add(field);
    }
    flush();

    final name = '${screen['name'] ?? screen['title'] ?? ''}'.trim();
    if (body.isEmpty && name.isEmpty) return null;

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (name.isNotEmpty)
            Container(
              color: theme.colorScheme.surfaceContainerHighest,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      name.toUpperCase(),
                      style: theme.textTheme.labelMedium?.copyWith(
                        letterSpacing: .6,
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                  if (fails > 0)
                    StatusPill(
                      '$fails FAIL',
                      tone: Tone.bad,
                      solid: true,
                      dense: true,
                    ),
                ],
              ),
            ),
          ...body,
          const SizedBox(height: 4),
        ],
      ),
    );
  }

  /// Plain answers side by side: 3 columns wide, 2 on a phone. A picture,
  /// signature, long text or work list takes the whole row.
  Widget _grid(BuildContext context, List<Map<String, dynamic>> fields) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
      child: LayoutBuilder(
        builder: (context, box) {
          final cols = box.maxWidth >= 600 ? 3 : (box.maxWidth >= 300 ? 2 : 1);
          const gap = 16.0;
          final cell = (box.maxWidth - gap * (cols - 1)) / cols;
          return Wrap(
            spacing: gap,
            runSpacing: 10,
            children: [
              for (final f in fields)
                SizedBox(
                  width:
                      const [
                        'photo',
                        'signature',
                        'textarea',
                        'services',
                      ].contains(f['type'])
                      ? box.maxWidth
                      : cell,
                  child: _labelled(context, f),
                ),
            ],
          );
        },
      ),
    );
  }

  Widget _labelled(BuildContext context, Map<String, dynamic> field) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text('${field['label'] ?? field['key']}', style: _muted(context)),
      const SizedBox(height: 2),
      _answer(context, field, answers['${field['key']}']),
    ],
  );

  Widget _finding(BuildContext context, Map<String, dynamic> field) {
    final theme = Theme.of(context);
    final pick = chosen(field);
    final variant = pick?.variant ?? 'neutral';
    final tone = ToneColours.of(context, _tone(variant));
    final bar = variant == 'neutral'
        ? theme.colorScheme.outlineVariant
        : tone.ink;
    final revealed = [
      for (final n in InspectionOption.revealedBy(field, answers))
        if (printAs(n) != 'ignore') n,
    ];
    final cite = _citation(context, field);

    return Container(
      key: ValueKey('inspection-finding-${field['key']}'),
      margin: const EdgeInsets.fromLTRB(12, 10, 12, 0),
      padding: const EdgeInsets.fromLTRB(12, 8, 10, 10),
      decoration: BoxDecoration(
        color: variant == 'fail' ? tone.fill : null,
        border: Border(left: BorderSide(color: bar, width: 4)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  '${field['label'] ?? field['key']}',
                  style: theme.textTheme.titleSmall,
                ),
              ),
              if (_badge[variant] case final String word)
                StatusPill(
                  word,
                  tone: _tone(variant),
                  solid: true,
                  dense: true,
                ),
            ],
          ),
          if (pick != null && pick.label.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(pick.label),
            )
          else if (pick == null)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: _answer(context, field, answers['${field['key']}']),
            ),
          ?cite,
          for (final n in revealed)
            Padding(
              padding: const EdgeInsets.only(left: 8, top: 8),
              child: _labelled(context, n),
            ),
        ],
      ),
    );
  }

  /// The code a finding is measured against, as frozen into the version.
  Widget? _citation(BuildContext context, Map<String, dynamic> field) {
    final code = field['code'];
    if (code is! Map) return null;
    final quote = _quote(
      context,
      code['title'] ?? code['code'],
      code['body'],
      key: ValueKey('inspection-citation-${field['key']}'),
    );
    return quote == null
        ? null
        : Padding(
            padding: const EdgeInsets.only(left: 8, top: 6),
            child: quote,
          );
  }

  /// A bold title and its words in a quiet block with an edge on the left:
  /// a citation, or a note the form showed the technician.
  Widget? _quote(
    BuildContext context,
    dynamic title,
    dynamic body, {
    Key? key,
  }) {
    final t = '${title ?? ''}'.trim();
    final b = '${body ?? ''}'.trim();
    if (t.isEmpty && b.isEmpty) return null;
    final theme = Theme.of(context);
    return Container(
      key: key,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        border: Border(
          left: BorderSide(color: theme.colorScheme.outlineVariant, width: 2),
        ),
      ),
      child: Text.rich(
        TextSpan(
          children: [
            if (t.isNotEmpty)
              TextSpan(
                text: t,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            if (b.isNotEmpty) TextSpan(text: t.isEmpty ? b : '  $b'),
          ],
        ),
        style: theme.textTheme.bodySmall,
      ),
    );
  }

  /// Every finding with its badge and words — the PDF's last page.
  Widget _summary(BuildContext context, List<Map<String, dynamic>> findings) {
    final theme = Theme.of(context);
    return Container(
      key: const ValueKey('inspection-report-summary'),
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(10),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            color: theme.colorScheme.surfaceContainerHighest,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            child: Text(
              'INSPECTION SUMMARY',
              style: theme.textTheme.labelMedium?.copyWith(
                letterSpacing: .6,
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          for (final f in findings)
            if (chosen(f) case final pick)
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 7,
                ),
                decoration: BoxDecoration(
                  border: Border(
                    top: BorderSide(color: EzBiziTheme.edge(context)),
                  ),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(flex: 3, child: Text('${f['label'] ?? f['key']}')),
                    SizedBox(
                      width: 64,
                      child: Align(
                        alignment: Alignment.centerLeft,
                        child: _badge[pick?.variant] == null
                            ? const SizedBox.shrink()
                            : StatusPill(
                                _badge[pick!.variant]!,
                                tone: _tone(pick.variant),
                                solid: true,
                                dense: true,
                              ),
                      ),
                    ),
                    Expanded(
                      flex: 3,
                      child: Text(
                        pick?.label ?? 'Not answered',
                        style: _muted(context),
                      ),
                    ),
                  ],
                ),
              ),
        ],
      ),
    );
  }

  /// One answer, shown the way its type was answered (ReportDocument::answer).
  Widget _answer(
    BuildContext context,
    Map<String, dynamic> field,
    dynamic value,
  ) {
    final type = '${field['type'] ?? 'text'}';
    final none = TextStyle(
      fontStyle: FontStyle.italic,
      color: Theme.of(context).colorScheme.onSurfaceVariant,
    );

    if (type == 'photo' || type == 'signature') {
      return _photoRow(context, value, signature: type == 'signature');
    }
    if (type == 'services') return _work(context, value);
    // A tick box says Yes or No, never "1" or a blank.
    if (type == 'checkbox') return Text(value == true ? 'Yes' : 'No');

    var text = '';
    if (type == 'choice' && value != null) {
      final words = {
        for (final o in InspectionOption.allOf(field['options']))
          o.value: o.label,
      };
      text = [
        for (final v in (value is List ? value : [value])) words['$v'] ?? '$v',
      ].join(', ');
    } else if (value is List) {
      text = value.join(', ');
    } else {
      text = value == null ? '' : '$value';
    }

    // "Nothing to report" and "nobody looked" are different findings.
    if (text.trim().isEmpty) return Text('Not answered', style: none);
    if (type == 'date') return Text(formatStamp(text, text, formats: formats));
    return Text(text);
  }

  /// The work recommended, priced from the snapshot in the answer.
  Widget _work(BuildContext context, dynamic value) {
    final picks = [
      for (final p in reportItems(value))
        if (p is Map) p,
    ];
    if (picks.isEmpty) {
      return Text(
        'No work recommended',
        style: TextStyle(
          fontStyle: FontStyle.italic,
          color: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
      );
    }
    var total = 0;
    final rows = <Widget>[];
    for (final p in picks) {
      final qty = (p['qty'] as num?)?.toInt() ?? 1;
      final amount = ((p['unit_price_minor'] as num?)?.toInt() ?? 0) * qty;
      total += amount;
      rows.add(_workRow('${p['name'] ?? ''}', '$qty', _amount(amount)));
    }
    return Column(
      children: [...rows, _workRow('Total', '', _amount(total), bold: true)],
    );
  }

  Widget _workRow(String name, String qty, String amount, {bool bold = false}) {
    final style = bold ? const TextStyle(fontWeight: FontWeight.w700) : null;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(child: Text(name, style: style)),
          SizedBox(width: 40, child: Text(qty, textAlign: TextAlign.right)),
          SizedBox(
            width: 96,
            child: Text(amount, textAlign: TextAlign.right, style: style),
          ),
        ],
      ),
    );
  }

  /// Minor units as 1,250.00, no symbol — as the PDF prints it.
  static String _amount(int minor) {
    final whole = (minor.abs() ~/ 100).toString().replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+$)'),
      (_) => ',',
    );
    final cents = (minor.abs() % 100).toString().padLeft(2, '0');
    return '${minor < 0 ? '-' : ''}$whole.$cents';
  }

  /// The photographs on one answer, read-only: a submitted report is frozen.
  Widget _photoRow(
    BuildContext context,
    dynamic value, {
    bool signature = false,
  }) {
    final ids = [for (final id in reportItems(value)) '$id'];
    if (ids.isEmpty) {
      return Text(
        signature ? 'Not signed' : 'No photograph',
        style: TextStyle(
          fontStyle: FontStyle.italic,
          color: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
      );
    }
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final id in ids)
          SizedBox(
            width: signature ? 220 : 96,
            height: signature ? 90 : 96,
            child: ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: photos[id] == null
                  ? ColoredBox(
                      color: Theme.of(
                        context,
                      ).colorScheme.surfaceContainerHighest,
                      child: const Center(
                        child: SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      ),
                    )
                  // contain for a signature: never crop somebody's name.
                  : Image.memory(
                      photos[id]!,
                      fit: signature ? BoxFit.contain : BoxFit.cover,
                    ),
            ),
          ),
      ],
    );
  }
}

/// A column read as a list, whatever shape it came in. A report imported from
/// A1 keeps A1's shape: a photo answer can be one string, a keyed object or a
/// list (phone audit 2026-11-04, Section 3 #1).
List<dynamic> reportItems(dynamic value) => switch (value) {
  final List list => list,
  final Map map => map.values.toList(),
  final String text when text.trim().isNotEmpty => [text],
  _ => const [],
};
