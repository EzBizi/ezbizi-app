import 'package:flutter/material.dart';

import '../../core/theme.dart';
import '../../widgets/dashboard_card.dart';
import 'builder_model.dart';
import 'builder_question.dart';
import 'condition.dart';
import 'option.dart';

/// The phone beside the Builder: the chosen screen as a technician meets it.
///
/// Live (UI queue 56, option 1): an answer tapped here reveals what it asks
/// next and brings in the questions that wait for it, by the same rules the
/// run uses ([conditionPasses], [InspectionOption.revealedBy]). The answers
/// are samples held by the Builder and saved nowhere — never a real client.
class LivePhone extends StatelessWidget {
  const LivePhone({
    super.key,
    required this.definition,
    required this.screenIndex,
    required this.answers,
    required this.onAnswer,
    this.highlight,
  });

  final Map<String, dynamic>? definition;
  final int screenIndex;

  /// Sample answers, by question key.
  final Map<String, dynamic> answers;
  final void Function(String key, dynamic value) onAnswer;

  /// The stamp of the question open in the Builder, outlined here too.
  final String? highlight;

  /// What a field shows before anybody answers it. A bound field shows a
  /// SAMPLE of what it will be filled with, never a real client or job.
  static String placeholderFor(Map<String, dynamic> field) {
    final bind = field['bind'];
    if (bind == 'customer.name') return 'Sample client';
    if (bind == 'job.number') return 'J-0000';
    if (bind == 'job.scheduled_for') return '9:00 AM';
    return switch ('${field['type'] ?? ''}') {
      'textarea' => 'Types a few lines',
      'number' => 'A number',
      'date' => 'Picks a date',
      'email' => 'name@example.com',
      'phone' => '(555) 010-0000',
      'photo' =>
        (field['max_count'] as num?)?.toInt() == 1 || field['max_count'] == null
            ? 'Adds a photo'
            : 'Adds up to ${field['max_count']} photos',
      'signature' => 'Signs here',
      'services' => 'Picks the recommended work',
      'file' => 'Attaches a file',
      _ => 'Sample answer',
    };
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final current = definition;
    final screens = screensOf(current);

    if (current == null || screens.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(DashboardCard.padding),
        child: DashboardNote(
          'The phone shows a screen with sample answers, not a real client.',
        ),
      );
    }

    final index = screenIndex.clamp(0, screens.length - 1);
    final screen = screens[index];
    final words = Vocabulary(current);
    final shownWhen = conditionPasses(screen['visible_if'], answers);
    final name = '${screen['name'] ?? ''}'.trim();

    return LayoutBuilder(
      builder: (context, box) {
        final width = box.maxWidth.clamp(0.0, 300.0);

        return Center(
          child: Container(
            key: const Key('live-phone'),
            width: width,
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerLowest,
              border: Border.all(color: theme.colorScheme.onSurface, width: 7),
              borderRadius: BorderRadius.circular(30),
            ),
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                Container(
                  padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
                  color: theme.colorScheme.surface,
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          name.isEmpty ? 'Screen ${index + 1}' : name,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                      Text(
                        '${index + 1} of ${screens.length}',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                const Divider(height: 1),
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.all(12),
                    children: [
                      if (!shownWhen)
                        _Banner(
                          'Technicians see this screen only when '
                          '${conditionSentence(screen['visible_if'], words)}.',
                        ),
                      for (final field in fieldsOf(screen))
                        if (conditionPasses(field['visible_if'], answers)) ...[
                          _control(context, field),
                          for (final nested in InspectionOption.revealedBy(
                            field,
                            answers,
                          ))
                            if (conditionPasses(nested['visible_if'], answers))
                              Padding(
                                padding: const EdgeInsets.only(left: 14),
                                child: _control(context, nested),
                              ),
                        ],
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _control(BuildContext context, Map<String, dynamic> field) {
    final theme = Theme.of(context);
    final type = '${field['type'] ?? 'text'}';
    final key = '${field['key'] ?? ''}';
    final label = '${labelOf(field)}${field['required'] == true ? ' *' : ''}';
    final lit = highlight != null && field[uidKey] == highlight;

    Widget body;
    switch (type) {
      case 'divider':
        return Padding(
          padding: const EdgeInsets.only(top: 6, bottom: 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                labelOf(field).toUpperCase(),
                style: theme.textTheme.labelSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  letterSpacing: 0.6,
                ),
              ),
              const Divider(height: 8),
            ],
          ),
        );

      case 'alert':
        body = Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: ToneColours.of(context, Tone.info).fill,
            borderRadius: BorderRadius.circular(8),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if ('${field['title'] ?? ''}'.trim().isNotEmpty)
                Text(
                  '${field['title']}',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    color: ToneColours.of(context, Tone.info).ink,
                  ),
                ),
              if ('${field['body'] ?? ''}'.trim().isNotEmpty)
                Text(
                  '${field['body']}',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: ToneColours.of(context, Tone.info).ink,
                  ),
                ),
            ],
          ),
        );

      case 'choice':
        final multiple = field['multiple'] == true;
        final answer = answers[key];
        final chosen = answer is List
            ? answer.map((v) => '$v').toList()
            : [if (answer != null) '$answer'];
        body = Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: theme.textTheme.labelMedium),
            const SizedBox(height: 6),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final option in InspectionOption.allOf(field['options']))
                  ChoiceChip(
                    key: ValueKey('sample-$key-${option.value}'),
                    label: Text(option.label, overflow: TextOverflow.ellipsis),
                    selected: chosen.contains(option.value),
                    selectedColor: option.variant == 'pass'
                        ? ToneColours.of(context, Tone.ok).fill
                        : option.variant == 'fail'
                        ? ToneColours.of(context, Tone.bad).fill
                        : null,
                    side: BorderSide(
                      color: variantColour(context, option.variant),
                    ),
                    visualDensity: VisualDensity.compact,
                    onSelected: (on) {
                      if (multiple) {
                        onAnswer(key, [
                          for (final v in chosen)
                            if (v != option.value) v,
                          if (on) option.value,
                        ]);
                      } else {
                        onAnswer(key, on ? option.value : null);
                      }
                    },
                  ),
              ],
            ),
          ],
        );

      case 'checkbox':
        body = Row(
          children: [
            Checkbox(
              value: answers[key] == true,
              onChanged: (on) => onAnswer(key, on == true),
            ),
            Expanded(child: Text(label)),
          ],
        );

      default:
        body = Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: theme.textTheme.labelMedium),
            const SizedBox(height: 4),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
              decoration: BoxDecoration(
                border: Border.all(color: theme.colorScheme.outlineVariant),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                placeholderFor(field),
                key: Key('sample-$key'),
                style: theme.textTheme.bodySmall,
              ),
            ),
          ],
        );
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: lit ? const EdgeInsets.all(6) : EdgeInsets.zero,
      decoration: lit
          ? BoxDecoration(
              border: Border.all(color: theme.colorScheme.primary, width: 2),
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            )
          : null,
      child: body,
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final colours = ToneColours.of(context, Tone.caution);
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(text, style: TextStyle(color: colours.ink, fontSize: 12)),
    );
  }
}
