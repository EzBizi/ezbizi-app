import 'package:flutter/material.dart';

/// A Facebook or Instagram ad form merged into the chat's lead (design 160,
/// C1): its answers in the details column, one line in the thread. Absent
/// fields draw nothing.

List<Map<String, dynamic>> formAnswers(Map<String, dynamic> lead) => [
  for (final a in (lead['form_answers'] as List? ?? const []))
    if (a is Map) Map<String, dynamic>.from(a),
];

bool hasFormAnswers(Map<String, dynamic> lead) => formAnswers(lead).isNotEmpty;

String? _formName(Map<String, dynamic> lead) {
  final name = (lead['form_name'] as String?)?.trim();
  return name == null || name.isEmpty ? null : name;
}

/// "Dana sent the form “Free quote”", or "Dana sent a form" with no name.
String formLineText(Map<String, dynamic> lead) {
  final who = (lead['contact_name'] as String?)?.trim();
  final name = (who == null || who.isEmpty) ? 'The customer' : who;
  final form = _formName(lead);
  return form == null ? '$name sent a form' : '$name sent the form “$form”';
}

/// The marker [withFormLine] puts in the thread's items.
class FormLine {
  const FormLine();
}

/// [items] (day strings and messages, oldest first) with the form's line at
/// form_submitted_at. Unchanged without one.
List<Object> withFormLine(List<Object> items, Map<String, dynamic> lead) {
  final at = DateTime.tryParse('${lead['form_submitted_at'] ?? ''}');
  if (at == null) return items;
  var index = items.length;
  for (var i = 0; i < items.length; i++) {
    final item = items[i];
    if (item is! Map) continue;
    final when = DateTime.tryParse('${item['occurred_at'] ?? ''}');
    if (when != null && when.isAfter(at)) {
      index = i;
      // Keep the day divider above the message with it.
      if (i > 0 && items[i - 1] is String) index = i - 1;
      break;
    }
  }
  return [...items.take(index), const FormLine(), ...items.skip(index)];
}

class FormSystemLine extends StatelessWidget {
  const FormSystemLine({super.key, required this.lead});

  final Map<String, dynamic> lead;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Container(
        key: const ValueKey('form-system-line'),
        margin: const EdgeInsets.symmetric(vertical: 8),
        padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 3),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(999),
        ),
        child: Text(
          formLineText(lead),
          style: theme.textTheme.labelSmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ),
    );
  }
}

/// Whether [answer]'s value is one of [facts]: the same text ignoring case,
/// or for a phone the same last ten digits.
bool repeatsAFact(Map<String, dynamic> answer, List<String> facts) {
  final value = '${answer['value'] ?? ''}'.trim().toLowerCase();
  if (value.isEmpty) return false;
  String digits(String s) {
    final d = s.replaceAll(RegExp(r'\D'), '');
    return d.length > 10 ? d.substring(d.length - 10) : d;
  }

  for (final f in facts) {
    final fact = f.trim().toLowerCase();
    if (fact.isEmpty) continue;
    if (fact == value) return true;
    final dv = digits(value);
    if (dv.length >= 7 && dv == digits(fact)) return true;
  }
  return false;
}

class FormAnswersSection extends StatelessWidget {
  const FormAnswersSection({
    super.key,
    required this.lead,
    this.skipping = const [],
  });

  final Map<String, dynamic> lead;

  /// Facts already on screen (name, phone, email): an answer that only
  /// repeats one is left out (design 161).
  final List<String> skipping;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final form = _formName(lead);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w600,
    );
    return Container(
      key: const ValueKey('form-answers'),
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            form == null ? 'Form answers' : 'Form answers · $form',
            style: theme.textTheme.labelLarge,
          ),
          const SizedBox(height: 6),
          for (final a in formAnswers(lead))
            if (!repeatsAFact(a, skipping))
            Padding(
              padding: const EdgeInsets.only(bottom: 3),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 110,
                    child: Text('${a['label'] ?? ''}', style: muted),
                  ),
                  Expanded(
                    child: SelectableText(
                      '${a['value'] ?? ''}',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
