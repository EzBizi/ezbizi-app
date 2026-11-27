import 'package:flutter/material.dart';

import '../../core/theme.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import 'builder_model.dart';
import 'inspection_flow.dart';
import 'option.dart';

/// What every question needs from the Builder around it (UI queue 56).
class BuilderKit {
  const BuilderKit({
    required this.definition,
    required this.types,
    required this.codes,
    required this.bindable,
    required this.narrow,
    required this.onChanged,
  });

  final Map<String, dynamic> definition;
  final List<Map<String, dynamic>> types;
  final List<Map<String, dynamic>> codes;
  final List<Map<String, dynamic>> bindable;

  /// A phone: the editor stacks, and "when" and "report" read as one line each
  /// until they are changed (phone option 4).
  final bool narrow;

  /// Something was edited in place: redraw, and save.
  final VoidCallback onChanged;

  String typeLabel(String key) {
    for (final type in types) {
      if (type['key'] == key) return '${type['label'] ?? key}';
    }
    return switch (key) {
      'divider' => 'A heading between fields',
      'alert' => 'A note to the technician',
      'choice' => 'One of a list',
      'text' => 'Text',
      _ => key,
    };
  }

  /// The types a question may be, in words. Falls back to the question's own
  /// type while the catalogue has not loaded, so the picker is never empty.
  List<SearchOption<String>> typeOptions(
    String current, {
    bool furniture = true,
  }) {
    final keys = [
      for (final type in types)
        if (furniture || !['divider', 'alert'].contains(type['key']))
          '${type['key']}',
    ];
    if (!keys.contains(current)) keys.insert(0, current);
    return [
      for (final key in keys) SearchOption(value: key, label: typeLabel(key)),
    ];
  }

  String? codeName(dynamic id) {
    for (final code in codes) {
      if ('${code['id']}' == '$id') return '${code['code']}';
    }
    return null;
  }

  String? bindName(dynamic key) {
    for (final bind in bindable) {
      if ('${bind['key']}' == '$key') return '${bind['label'] ?? key}';
    }
    return null;
  }
}

/// A question as the list shows it: what it asks, and in words, what it is.
class QuestionCard extends StatelessWidget {
  const QuestionCard({
    super.key,
    required this.field,
    required this.kit,
    required this.words,
    required this.reach,
    required this.index,
    required this.onOpen,
    required this.menu,
  });

  final Map<String, dynamic> field;
  final BuilderKit kit;
  final Vocabulary words;
  final ConditionReach reach;

  /// Its place in the list, for the drag grip.
  final int index;
  final VoidCallback onOpen;
  final Widget menu;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final type = '${field['type'] ?? 'text'}';

    if (type == 'divider') {
      final when = conditionSentence(field['visible_if'], words);
      return InkWell(
        key: ValueKey('question:${field[uidKey]}'),
        onTap: onOpen,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(0, 10, 4, 2),
          child: LayoutBuilder(
            // The words take what they need, up to most of the row, and the
            // rule takes the rest. Two flexible children would split the row
            // in half however short the heading is.
            builder: (context, box) => Row(
              children: [
                _Grip(index: index),
                ConstrainedBox(
                  constraints: BoxConstraints(maxWidth: box.maxWidth * 0.7),
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: labelOf(field).toUpperCase(),
                          style: theme.textTheme.labelMedium?.copyWith(
                            fontWeight: FontWeight.w800,
                            letterSpacing: 0.8,
                          ),
                        ),
                        if (when.isNotEmpty)
                          TextSpan(
                            text: '   only when $when',
                            style: theme.textTheme.bodySmall,
                          ),
                      ],
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                const SizedBox(width: 10),
                const Expanded(child: Divider()),
                menu,
              ],
            ),
          ),
        ),
      );
    }

    final finding = field['print'] == 'root';
    final pills = _pills(context);

    return Card(
      key: ValueKey('question:${field[uidKey]}'),
      margin: EdgeInsets.zero,
      child: InkWell(
        onTap: onOpen,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(0, 6, 4, 6),
          child: Row(
            children: [
              _Grip(index: index),
              TypeBadge(type: type, finding: finding),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      labelOf(field),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    if (pills.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Wrap(spacing: 5, runSpacing: 4, children: pills),
                    ],
                  ],
                ),
              ),
              menu,
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _pills(BuildContext context) {
    final type = '${field['type'] ?? 'text'}';

    if (type == 'alert') {
      final body = '${field['body'] ?? ''}'.trim();
      return [
        StatusPill(kit.typeLabel(type)),
        if (body.isNotEmpty)
          StatusPill(body.length > 48 ? '${body.substring(0, 48)}…' : body),
      ];
    }

    final options = InspectionOption.allOf(field['options']);
    final when = conditionSentence(field['visible_if'], words);
    final code = kit.codeName(field['code_id']);
    final bind = kit.bindName(field['bind']);

    var typeText = kit.typeLabel(type);
    if (type == 'choice') {
      typeText +=
          ' · ${options.length == 1 ? '1 answer' : '${options.length} answers'}';
    }
    if (type == 'photo') {
      final most = (field['max_count'] as num?)?.toInt() ?? 1;
      typeText += most == 1 ? ' · one' : ' · up to $most';
    }

    return [
      StatusPill(typeText),
      if (field['required'] == true)
        const StatusPill('Required', tone: Tone.info),
      if (reach == ConditionReach.never)
        const StatusPill(
          'Never asked: it waits for a later answer',
          tone: Tone.bad,
        )
      else if (when.isNotEmpty)
        StatusPill('Only when $when', tone: Tone.caution),
      if (field['print'] == 'root')
        const StatusPill('Finding', tone: Tone.caution),
      if (field['print'] == 'ignore') const StatusPill('Not on the report'),
      if (code != null) StatusPill('Code $code'),
      for (final option in options)
        if (option.fields.isNotEmpty)
          StatusPill(
            '${option.variant == 'fail' ? 'Fail' : '“${_short(option.label)}”'} '
            'asks ${option.fields.length} more',
          ),
      if (bind != null) StatusPill('Filled from $bind'),
    ];
  }
}

String _short(String text) =>
    text.length > 22 ? '${text.substring(0, 22)}…' : text;

/// The grip a question is dragged by.
class _Grip extends StatelessWidget {
  const _Grip({required this.index});

  final int index;

  @override
  Widget build(BuildContext context) {
    return ReorderableDragStartListener(
      index: index,
      child: MouseRegion(
        cursor: SystemMouseCursors.grab,
        child: SizedBox(
          width: 32,
          height: 40,
          child: Icon(
            Icons.drag_indicator,
            size: 18,
            color: Theme.of(context).colorScheme.onSurfaceVariant,
          ),
        ),
      ),
    );
  }
}

/// The small square that says what kind of question it is. A finding is
/// drawn in caution colours: it is what the report is for.
class TypeBadge extends StatelessWidget {
  const TypeBadge({super.key, required this.type, this.finding = false});

  final String type;
  final bool finding;

  @override
  Widget build(BuildContext context) {
    final colours = ToneColours.of(
      context,
      finding ? Tone.caution : Tone.neutral,
    );
    return Container(
      width: 28,
      height: 28,
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Icon(
        finding ? Icons.flag_outlined : typeIcon(type),
        size: 16,
        color: colours.ink,
      ),
    );
  }
}

/// A text box that writes to the definition as it is typed.
///
/// Its controller is its own and outlives rebuilds; a new question is a new
/// widget (keyed by its stamp), so nothing has to be copied in by hand the way
/// the old Builder did on every selection.
class BoundText extends StatefulWidget {
  const BoundText({
    super.key,
    required this.initial,
    required this.onChanged,
    this.label,
    this.hint,
    this.helper,
    this.minLines,
    this.maxLines = 1,
    this.autofocus = false,
    this.style,
  });

  final String initial;
  final ValueChanged<String> onChanged;
  final String? label;
  final String? hint;
  final String? helper;
  final int? minLines;
  final int maxLines;
  final bool autofocus;
  final TextStyle? style;

  @override
  State<BoundText> createState() => _BoundTextState();
}

class _BoundTextState extends State<BoundText> {
  late final _controller = TextEditingController(text: widget.initial);

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: _controller,
      autofocus: widget.autofocus,
      minLines: widget.minLines,
      maxLines: widget.maxLines,
      style: widget.style,
      decoration: InputDecoration(
        isDense: true,
        labelText: widget.label,
        hintText: widget.hint,
        helperText: widget.helper,
        helperMaxLines: 2,
        alignLabelWithHint: widget.maxLines > 1,
      ),
      onChanged: widget.onChanged,
    );
  }
}

/// One question opened where it stands: what it asks, its answers and what
/// each asks next, when it is asked, and how it prints (desktop option 1).
class QuestionEditor extends StatefulWidget {
  const QuestionEditor({
    super.key,
    required this.field,
    required this.kit,
    required this.words,
    required this.targets,
    required this.reach,
    required this.onClose,
    required this.onDuplicate,
    required this.onMove,
    required this.onDelete,
    this.dependents = const [],
    this.autofocus = false,
  });

  final Map<String, dynamic> field;
  final BuilderKit kit;
  final Vocabulary words;

  /// The questions this one may wait for: its own screen and the ones before.
  final List<ConditionTarget> targets;
  final ConditionReach reach;
  final VoidCallback onClose;
  final VoidCallback onDuplicate;

  /// Null when there is no other screen to move it to.
  final VoidCallback? onMove;
  final VoidCallback onDelete;

  /// Who waits for this question's answer. Deleting it would leave them
  /// waiting for a question that no longer exists, which the server refuses.
  final List<String> dependents;
  final bool autofocus;

  @override
  State<QuestionEditor> createState() => _QuestionEditorState();
}

class _QuestionEditorState extends State<QuestionEditor> {
  /// The answers whose follow-ups are open for editing, by position.
  final Set<int> _openFollowUps = {};

  /// On a phone, the parts that read as one line until changed.
  bool _whenOpen = false;
  bool _reportOpen = false;
  bool _asking = false;

  Map<String, dynamic> get _field => widget.field;
  String get _type => '${_field['type'] ?? 'text'}';

  void _changed() {
    setState(() {});
    widget.kit.onChanged();
  }

  void _setType(String type) {
    _field['type'] = type;
    if (type == 'choice' && InspectionOption.allOf(_field['options']).isEmpty) {
      // A list with nothing in it cannot be saved, so it starts with one.
      _field['options'] = <dynamic>[
        <String, dynamic>{
          'value': 'Option 1',
          'label': 'Option 1',
          'variant': 'neutral',
          'fields': <dynamic>[],
        },
      ];
    }
    if (type == 'alert' &&
        '${_field['title'] ?? ''}'.trim().isEmpty &&
        '${_field['body'] ?? ''}'.trim().isEmpty) {
      // A note has to say something before the server will keep it.
      _field['title'] = '${_field['label'] ?? ''}'.trim().isEmpty
          ? 'Note'
          : _field['label'];
    }
    _changed();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final narrow = widget.kit.narrow;
    final furniture = isFurniture(_field);

    final header = <Widget>[
      if (_type == 'alert') ...[
        BoundText(
          key: const Key('question-title'),
          initial: '${_field['title'] ?? ''}',
          label: 'Heading',
          autofocus: widget.autofocus,
          onChanged: (text) {
            _field['title'] = text;
            widget.kit.onChanged();
          },
        ),
        const SizedBox(height: 8),
        BoundText(
          key: const Key('question-body'),
          initial: '${_field['body'] ?? ''}',
          label: 'What it says',
          minLines: 2,
          maxLines: 5,
          onChanged: (text) {
            _field['body'] = text;
            widget.kit.onChanged();
          },
        ),
      ] else
        BoundText(
          key: const Key('question-label'),
          initial: '${_field['label'] ?? ''}',
          label: _type == 'divider' ? 'Heading' : 'Question',
          autofocus: widget.autofocus,
          style: theme.textTheme.bodyLarge?.copyWith(
            fontWeight: FontWeight.w700,
          ),
          onChanged: (text) {
            _field['label'] = text;
            widget.kit.onChanged();
          },
        ),
    ];

    final typePicker = SearchPickerField<String>(
      key: const Key('question-type'),
      value: _type,
      label: 'Kind of answer',
      isDense: true,
      options: widget.kit.typeOptions(_type),
      onChanged: (value) {
        if (value != null && value != _type) _setType(value);
      },
    );

    final required = furniture
        ? null
        : Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Switch(
                key: const Key('question-required'),
                value: _field['required'] == true,
                onChanged: (on) {
                  _field['required'] = on;
                  _changed();
                },
              ),
              const SizedBox(width: 4),
              const Text('Required'),
            ],
          );

    return Container(
      key: ValueKey('editor:${_field[uidKey]}'),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        border: Border.all(color: theme.colorScheme.primary, width: 2),
      ),
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (narrow) ...[
            ...header,
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(child: typePicker),
                if (required != null) ...[const SizedBox(width: 8), required],
              ],
            ),
          ] else
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: TypeBadge(
                    type: _type,
                    finding: _field['print'] == 'root',
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(child: Column(children: header)),
                const SizedBox(width: 10),
                SizedBox(width: 210, child: typePicker),
                if (required != null) ...[const SizedBox(width: 8), required],
              ],
            ),
          if (_type == 'choice')
            _section(context, 'Answers', _answers(context)),
          if (_type == 'photo') ...[
            const SizedBox(height: 10),
            Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                width: 220,
                child: SearchPickerField<int>(
                  key: const Key('question-photos'),
                  value: (_field['max_count'] as num?)?.toInt() ?? 1,
                  label: 'How many photos',
                  isDense: true,
                  options: [
                    for (final n in [1, 2, 3, 4, 6, 8, 12])
                      SearchOption(
                        value: n,
                        label: n == 1 ? 'One' : 'Up to $n',
                      ),
                  ],
                  onChanged: (value) {
                    _field['max_count'] = value ?? 1;
                    _changed();
                  },
                ),
              ),
            ),
          ],
          _section(context, 'When to ask it', _when(context)),
          if (!furniture) _section(context, 'On the report', _report(context)),
          if (!furniture && widget.kit.bindable.isNotEmpty)
            _section(context, 'Filled in for the technician', [
              SizedBox(
                width: narrow ? double.infinity : 320,
                child: SearchPickerField<String>(
                  key: const Key('question-bind'),
                  value: _field['bind'] is String
                      ? _field['bind'] as String
                      : '',
                  label: 'Fill it in from',
                  isDense: true,
                  options: [
                    const SearchOption(
                      value: '',
                      label: 'Nothing: the technician types it',
                    ),
                    for (final bind in widget.kit.bindable)
                      SearchOption(
                        value: '${bind['key']}',
                        label: '${bind['label'] ?? bind['key']}',
                      ),
                  ],
                  onChanged: (value) {
                    if (value == null || value.isEmpty) {
                      _field.remove('bind');
                    } else {
                      _field['bind'] = value;
                    }
                    _changed();
                  },
                ),
              ),
            ]),
          const Divider(height: 20),
          _footer(context),
        ],
      ),
    );
  }

  Widget _section(BuildContext context, String title, List<Widget> children) {
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title.toUpperCase(),
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
              fontWeight: FontWeight.w800,
              letterSpacing: 0.6,
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 6),
          ...children,
        ],
      ),
    );
  }

  // ── Answers ─────────────────────────────────────────────────────────

  List<Widget> _answers(BuildContext context) {
    final options = optionMaps(_field);
    final key = '${_field['key'] ?? ''}';
    // Answers are stamped like questions, so a row keeps its own text box when
    // the one above it is removed.
    for (final option in options) {
      option[uidKey] ??= freshUid();
    }

    return [
      for (var i = 0; i < options.length; i++) ...[
        _AnswerRow(
          key: ValueKey('answer:${options[i][uidKey]}'),
          option: options[i],
          canRemove: options.length > 1,
          onText: (text) {
            final option = options[i];
            final plain =
                '${option['value'] ?? ''}' == '${option['label'] ?? ''}';
            final was = '${option['value'] ?? ''}';
            option['label'] = text;
            if (plain) {
              // Plain words are their own value, so the value follows the
              // words, and so does every condition waiting for them — unless
              // another answer still holds the old value: mid-typing "Ye" on
              // the way to "Yes" moved the real "Yes"'s conditions (audit
              // 11-27).
              option['value'] = text;
              final shared = options.any(
                (o) => !identical(o, option) && '${o['value'] ?? ''}' == was,
              );
              if (!shared) renameAnswer(widget.kit.definition, key, was, text);
            }
            widget.kit.onChanged();
          },
          onVariant: (variant) {
            options[i]['variant'] = variant;
            _changed();
          },
          onAsk: () {
            if (options[i]['fields'] is! List) {
              options[i]['fields'] = <dynamic>[];
            }
            final nested = options[i]['fields'] as List;
            nested.add(<String, dynamic>{
              uidKey: freshUid(),
              'key': nextKey(widget.kit.definition),
              'label': '',
              'type': 'text',
            });
            _openFollowUps.add(i);
            _changed();
          },
          onRemove: () {
            final value = '${options[i]['value'] ?? ''}';
            final shared = options.any(
              (o) =>
                  !identical(o, options[i]) && '${o['value'] ?? ''}' == value,
            );
            final waiting = shared
                ? const <String>[]
                : waitingForAnswer(widget.kit.definition, key, value);
            if (waiting.isNotEmpty) {
              ScaffoldMessenger.maybeOf(context)?.showSnackBar(
                SnackBar(
                  content: Text(
                    '${waiting.join(', ')} waits for this answer. '
                    'Change that condition first.',
                  ),
                ),
              );
              return;
            }
            (_field['options'] as List).removeAt(i);
            _openFollowUps.clear();
            _changed();
          },
        ),
        if (nestedOf(options[i]).isNotEmpty)
          _FollowUps(
            key: ValueKey('followups:${options[i][uidKey]}'),
            option: options[i],
            kit: widget.kit,
            open: _openFollowUps.contains(i),
            onToggle: () => setState(() {
              _openFollowUps.contains(i)
                  ? _openFollowUps.remove(i)
                  : _openFollowUps.add(i);
            }),
            onChanged: _changed,
          ),
      ],
      Wrap(
        spacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          TextButton.icon(
            key: const Key('answer-add'),
            onPressed: () {
              (_field['options'] as List).add(<String, dynamic>{
                'value': '',
                'label': '',
                'variant': 'neutral',
                'fields': <dynamic>[],
              });
              _changed();
            },
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Add an answer'),
          ),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Switch(
                key: const Key('answer-multiple'),
                value: _field['multiple'] == true,
                onChanged: (on) {
                  _field['multiple'] = on;
                  _changed();
                },
              ),
              const SizedBox(width: 4),
              const Text('More than one can be picked'),
            ],
          ),
        ],
      ),
    ];
  }

  // ── When ────────────────────────────────────────────────────────────

  List<Widget> _when(BuildContext context) {
    final sentence = conditionSentence(_field['visible_if'], widget.words);
    final warning = widget.reach == ConditionReach.never
        ? 'Never true here: it waits for a question asked on a later screen.'
        : null;

    if (widget.kit.narrow && !_whenOpen) {
      return [
        _Summary(
          text: sentence.isEmpty ? 'Always asked' : 'Only when $sentence',
          warning: warning,
          onChange: () => setState(() => _whenOpen = true),
        ),
      ];
    }

    return [
      ConditionSentence(
        condition: _field['visible_if'],
        targets: [
          for (final target in widget.targets)
            if (target.key != '${_field['key'] ?? ''}') target,
        ],
        words: widget.words,
        always: 'Always asked',
        add: 'Only ask it when…',
        onChanged: (value) {
          if (value == null) {
            _field.remove('visible_if');
          } else {
            _field['visible_if'] = value;
          }
          _changed();
        },
      ),
      if (warning != null) ...[
        const SizedBox(height: 4),
        Text(
          warning,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
            color: ToneColours.of(context, Tone.bad).ink,
          ),
        ),
      ],
    ];
  }

  // ── Report ──────────────────────────────────────────────────────────

  static const _prints = [
    (
      'root',
      'A finding',
      'Its own heading on the report, with the photo, the code and the work under it.',
    ),
    (
      'text',
      'A plain line',
      'The question and its answer, as a line on the report.',
    ),
    ('ignore', 'Leave it off', 'Asked to steer the form, never printed.'),
  ];

  List<Widget> _report(BuildContext context) {
    final print = '${_field['print'] ?? 'text'}';
    final chosen = _prints.firstWhere(
      (p) => p.$1 == print,
      orElse: () => _prints[1],
    );
    final code = widget.kit.codeName(_field['code_id']);

    if (widget.kit.narrow && !_reportOpen) {
      return [
        _Summary(
          text: [chosen.$2, ?code].join(' · '),
          onChange: () => setState(() => _reportOpen = true),
        ),
      ];
    }

    return [
      Wrap(
        key: Key('field-print-${_field[uidKey]}'),
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final option in _prints)
            ChoiceChip(
              key: ValueKey('print-${option.$1}'),
              label: Text(option.$2),
              selected: print == option.$1,
              onSelected: (_) {
                _field['print'] = option.$1;
                _changed();
              },
            ),
        ],
      ),
      const SizedBox(height: 6),
      Text(chosen.$3, style: Theme.of(context).textTheme.bodySmall),
      if (widget.kit.codes.isNotEmpty &&
          (print == 'root' || _field['code_id'] != null)) ...[
        const SizedBox(height: 10),
        SizedBox(
          width: widget.kit.narrow ? double.infinity : 360,
          child: SearchPickerField<String>(
            key: const Key('field-code'),
            value: code == null ? '' : '${_field['code_id']}',
            label: 'Building code',
            isDense: true,
            helperText:
                'Printed beside the finding. Its wording is copied in when you publish.',
            helperMaxLines: 2,
            options: [
              const SearchOption(value: '', label: 'No code'),
              for (final c in widget.kit.codes)
                SearchOption(
                  value: '${c['id']}',
                  label: '${c['code']}',
                  detail: '${c['title'] ?? ''}',
                ),
            ],
            onChanged: (value) {
              _field['code_id'] = (value ?? '').isEmpty ? null : value;
              _changed();
            },
          ),
        ),
      ],
    ];
  }

  // ── Footer ──────────────────────────────────────────────────────────

  Widget _footer(BuildContext context) {
    final bad = ToneColours.of(context, Tone.bad);

    if (_asking) {
      final blocked = widget.dependents.isNotEmpty;
      return Container(
        key: const Key('question-delete-ask'),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: bad.fill,
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        ),
        child: Wrap(
          spacing: 8,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              blocked
                  ? '${widget.dependents.join(', ')} '
                        '${widget.dependents.length == 1 ? 'waits' : 'wait'} for this '
                        'answer. Change ${widget.dependents.length == 1 ? 'that' : 'those'} first.'
                  : 'Delete this question?',
              style: TextStyle(color: bad.ink),
            ),
            if (!blocked)
              FilledButton(
                key: const Key('question-delete-yes'),
                style: FilledButton.styleFrom(
                  backgroundColor: Theme.of(context).colorScheme.error,
                  foregroundColor: Theme.of(context).colorScheme.onError,
                ),
                onPressed: widget.onDelete,
                child: const Text('Delete'),
              ),
            TextButton(
              onPressed: () => setState(() => _asking = false),
              child: Text(blocked ? 'OK' : 'Keep'),
            ),
          ],
        ),
      );
    }

    return Wrap(
      spacing: 4,
      runSpacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        TextButton(
          key: const Key('question-duplicate'),
          onPressed: widget.onDuplicate,
          child: const Text('Duplicate'),
        ),
        if (widget.onMove != null)
          TextButton(
            key: const Key('question-move'),
            onPressed: widget.onMove,
            child: const Text('Move to another screen'),
          ),
        TextButton(
          key: const Key('question-delete'),
          style: TextButton.styleFrom(foregroundColor: bad.ink),
          onPressed: () => setState(() => _asking = true),
          child: const Text('Delete'),
        ),
        TextButton(
          key: const Key('question-done'),
          onPressed: widget.onClose,
          child: const Text('Done'),
        ),
      ],
    );
  }
}

/// One line saying what is set, and Change: the phone's way of keeping an
/// open question short (phone option 4).
class _Summary extends StatelessWidget {
  const _Summary({required this.text, required this.onChange, this.warning});

  final String text;
  final String? warning;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            warning ?? text,
            style: TextStyle(
              color: warning == null
                  ? null
                  : ToneColours.of(context, Tone.bad).ink,
            ),
          ),
        ),
        TextButton(onPressed: onChange, child: const Text('Change')),
      ],
    );
  }
}

/// The colour an answer is drawn in, by its variant.
Color variantColour(BuildContext context, String variant) => switch (variant) {
  'pass' => ToneColours.of(context, Tone.ok).ink,
  'fail' => ToneColours.of(context, Tone.bad).ink,
  'not_applicable' => ToneColours.of(context, Tone.neutral).ink,
  _ => Theme.of(context).colorScheme.outline,
};

const _variants = [
  ('pass', 'Pass · green'),
  ('fail', 'Fail · red'),
  ('not_applicable', 'Not applicable · grey'),
  ('neutral', 'No colour'),
];

class _AnswerRow extends StatelessWidget {
  const _AnswerRow({
    super.key,
    required this.option,
    required this.canRemove,
    required this.onText,
    required this.onVariant,
    required this.onAsk,
    required this.onRemove,
  });

  final Map<String, dynamic> option;
  final bool canRemove;
  final ValueChanged<String> onText;
  final ValueChanged<String> onVariant;
  final VoidCallback onAsk;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final variant = '${option['variant'] ?? 'neutral'}';
    final label = '${option['label'] ?? ''}';

    return Row(
      children: [
        PopupMenuButton<String>(
          tooltip: 'Colour of “$label”',
          initialValue: variant,
          onSelected: onVariant,
          itemBuilder: (context) => [
            for (final v in _variants)
              PopupMenuItem(
                value: v.$1,
                child: Row(
                  children: [
                    _Dot(
                      colour: variantColour(context, v.$1),
                      hollow: v.$1 == 'neutral',
                    ),
                    const SizedBox(width: 10),
                    Text(v.$2),
                  ],
                ),
              ),
          ],
          child: SizedBox(
            width: 32,
            height: 40,
            child: Center(
              child: _Dot(
                colour: variantColour(context, variant),
                hollow: variant == 'neutral',
              ),
            ),
          ),
        ),
        Expanded(
          child: BoundText(
            initial: label,
            hint: 'An answer',
            onChanged: (text) => onText(text.trim()),
          ),
        ),
        IconButton(
          tooltip: 'Ask something after “$label”',
          onPressed: onAsk,
          icon: const Icon(Icons.subdirectory_arrow_right, size: 20),
        ),
        if (canRemove)
          IconButton(
            tooltip: 'Remove “$label”',
            onPressed: onRemove,
            icon: const Icon(Icons.close, size: 18),
          ),
      ],
    );
  }
}

class _Dot extends StatelessWidget {
  const _Dot({required this.colour, this.hollow = false});

  final Color colour;
  final bool hollow;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 12,
      height: 12,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: hollow ? null : colour,
        border: hollow ? Border.all(color: colour, width: 2) : null,
      ),
    );
  }
}

/// What an answer asks next: one line until opened, then each follow-up as a
/// short row (OptionSet: one level, never deeper).
class _FollowUps extends StatelessWidget {
  const _FollowUps({
    super.key,
    required this.option,
    required this.kit,
    required this.open,
    required this.onToggle,
    required this.onChanged,
  });

  final Map<String, dynamic> option;
  final BuilderKit kit;
  final bool open;
  final VoidCallback onToggle;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final nested = nestedOf(option);
    final edge = '${option['variant']}' == 'fail'
        ? ToneColours.of(context, Tone.bad).fill
        : theme.colorScheme.outlineVariant;

    final summary = nested
        .map((f) {
          final extras = [
            if (f['required'] == true) 'required',
            if (f['type'] == 'photo' &&
                ((f['max_count'] as num?)?.toInt() ?? 1) > 1)
              'up to ${f['max_count']}',
          ];
          return extras.isEmpty
              ? labelOf(f)
              : '${labelOf(f)} (${extras.join(', ')})';
        })
        .join(' · ');

    return Container(
      margin: const EdgeInsets.only(left: 16, bottom: 6),
      padding: const EdgeInsets.only(left: 12),
      decoration: BoxDecoration(
        border: Border(left: BorderSide(color: edge, width: 3)),
      ),
      child: open
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Then it asks', style: theme.textTheme.labelMedium),
                for (var i = 0; i < nested.length; i++)
                  _FollowUpRow(
                    key: ValueKey('followup:${nested[i][uidKey]}'),
                    field: nested[i],
                    kit: kit,
                    onChanged: onChanged,
                    onRemove: () {
                      (option['fields'] as List).remove(nested[i]);
                      onChanged();
                    },
                  ),
                TextButton(onPressed: onToggle, child: const Text('Done')),
              ],
            )
          : InkWell(
              onTap: onToggle,
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Text.rich(
                  TextSpan(
                    children: [
                      const TextSpan(text: 'Then it asks: '),
                      TextSpan(
                        text: summary,
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                      TextSpan(
                        text: '  Change',
                        style: TextStyle(
                          color: theme.colorScheme.primary,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  style: theme.textTheme.bodySmall,
                ),
              ),
            ),
    );
  }
}

class _FollowUpRow extends StatelessWidget {
  const _FollowUpRow({
    super.key,
    required this.field,
    required this.kit,
    required this.onChanged,
    required this.onRemove,
  });

  final Map<String, dynamic> field;
  final BuilderKit kit;
  final VoidCallback onChanged;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final type = '${field['type'] ?? 'text'}';

    final picker = SearchPickerField<String>(
      value: type,
      label: 'Kind of answer',
      isDense: true,
      options: kit.typeOptions(type, furniture: false),
      onChanged: (value) {
        if (value == null) return;
        field['type'] = value;
        if (value == 'choice' &&
            InspectionOption.allOf(field['options']).isEmpty) {
          field['options'] = <dynamic>['Yes', 'No'];
        }
        onChanged();
      },
    );

    final requiredSwitch = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Switch(
          value: field['required'] == true,
          onChanged: (on) {
            field['required'] = on;
            onChanged();
          },
        ),
        const Text('Required'),
      ],
    );

    final label = BoundText(
      initial: '${field['label'] ?? ''}',
      hint: 'What to ask',
      onChanged: (text) {
        field['label'] = text;
        onChanged();
      },
    );

    final remove = IconButton(
      tooltip: 'Remove “${labelOf(field)}”',
      onPressed: onRemove,
      icon: const Icon(Icons.close, size: 18),
    );

    if (kit.narrow) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Column(
          children: [
            Row(
              children: [
                Expanded(child: label),
                remove,
              ],
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(child: picker),
                requiredSwitch,
              ],
            ),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Icon(typeIcon(type), size: 18),
          const SizedBox(width: 8),
          Expanded(child: label),
          const SizedBox(width: 8),
          SizedBox(width: 190, child: picker),
          if (type == 'photo') ...[
            const SizedBox(width: 8),
            SizedBox(
              width: 110,
              child: SearchPickerField<int>(
                value: (field['max_count'] as num?)?.toInt() ?? 1,
                label: 'Photos',
                isDense: true,
                options: [
                  for (final n in [1, 2, 3, 4, 6, 8, 12])
                    SearchOption(value: n, label: n == 1 ? 'One' : 'Up to $n'),
                ],
                onChanged: (value) {
                  field['max_count'] = value ?? 1;
                  onChanged();
                },
              ),
            ),
          ],
          const SizedBox(width: 4),
          requiredSwitch,
          remove,
        ],
      ),
    );
  }
}

/// A `visible_if` as a sentence: "Only when [question] [is] [answer]".
///
/// ── One clause, deliberately ─────────────────────────────────────
///
/// The engine takes nested and/or trees. This edits ONE comparison, because
/// that is what A1's inspection uses for all but a handful of its questions,
/// and a tree editor is a screen nobody can use on a phone. A nested condition
/// is said in words and left alone — losing somebody's and/or because the
/// editor could not draw it would be the worst outcome.
class ConditionSentence extends StatelessWidget {
  const ConditionSentence({
    super.key,
    required this.condition,
    required this.targets,
    required this.words,
    required this.always,
    required this.add,
    required this.onChanged,
  });

  final dynamic condition;
  final List<ConditionTarget> targets;
  final Vocabulary words;

  /// What is said with no condition: "Always asked".
  final String always;

  /// The button that starts one: "Only ask it when…".
  final String add;
  final ValueChanged<Map<String, dynamic>?> onChanged;

  bool get _nested {
    final op = condition is Map ? '${(condition as Map)['op'] ?? ''}' : '';
    return op == 'and' || op == 'or';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_nested) {
      return Wrap(
        spacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text('Only when ${conditionSentence(condition, words)}.'),
          Text(
            'A combined condition: it still works, and cannot be changed here yet.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      );
    }

    final map = condition is Map
        ? Map<String, dynamic>.from(condition as Map)
        : null;

    if (map == null || map.isEmpty) {
      if (targets.isEmpty) {
        return Text(
          '$always. Add another question first.',
          style: theme.textTheme.bodyMedium,
        );
      }
      return Wrap(
        spacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(always),
          TextButton(
            key: const Key('cond-start'),
            onPressed: () {
              final first = targets.first;
              onChanged({
                'field': first.key,
                'op': 'eq',
                'value': first.options.isEmpty ? '' : first.options.first.value,
              });
            },
            child: Text(add),
          ),
        ],
      );
    }

    final key = '${map['field'] ?? ''}';
    final op = '${map['op'] ?? 'eq'}';
    final many = op == 'in' || op == 'not_in';
    final target = targets.where((t) => t.key == key).firstOrNull;
    final options = target?.options ?? const <InspectionOption>[];
    final values = many
        ? [for (final v in (map['values'] as List? ?? const [])) '$v']
        : ['${map['value'] ?? ''}'];

    Map<String, dynamic> build(String field, String op, List<String> values) =>
        op == 'in' || op == 'not_in'
        ? {'field': field, 'op': op, 'values': values}
        : {
            'field': field,
            'op': op,
            'value': values.isEmpty ? '' : values.first,
          };

    final question = SizedBox(
      width: 220,
      child: SearchPickerField<String>(
        key: const Key('cond-field'),
        value: key,
        label: 'Question',
        isDense: true,
        options: [
          for (final t in targets)
            SearchOption(value: t.key, label: t.label, detail: t.screen),
          if (target == null)
            SearchOption(
              value: key,
              label: '${words.questions[key] ?? key} (not before this)',
            ),
        ],
        onChanged: (chosen) {
          if (chosen == null || chosen == key) return;
          final next = targets.where((t) => t.key == chosen).firstOrNull;
          onChanged(
            build(
              chosen,
              many ? op : 'eq',
              next == null || next.options.isEmpty
                  ? []
                  : [next.options.first.value],
            ),
          );
        },
      ),
    );

    final verb = SizedBox(
      width: 140,
      child: SearchPickerField<String>(
        key: const Key('cond-op'),
        value: op,
        label: 'Is',
        isDense: true,
        options: const [
          SearchOption(value: 'eq', label: 'is'),
          SearchOption(value: 'ne', label: 'is not'),
          SearchOption(value: 'in', label: 'is one of'),
          SearchOption(value: 'not_in', label: 'is none of'),
        ],
        onChanged: (chosen) {
          if (chosen == null || chosen == op) return;
          onChanged(
            build(key, chosen, values.where((v) => v.isNotEmpty).toList()),
          );
        },
      ),
    );

    final Widget answer;
    if (options.isNotEmpty && !many) {
      answer = SizedBox(
        width: 240,
        child: SearchPickerField<String>(
          key: const Key('cond-value'),
          value: values.first,
          label: 'Answer',
          isDense: true,
          options: [
            for (final o in options)
              SearchOption(value: o.value, label: o.label),
            if (!options.any((o) => o.value == values.first))
              SearchOption(
                value: values.first,
                label: '${values.first} (no longer offered)',
              ),
          ],
          onChanged: (chosen) {
            if (chosen != null) onChanged(build(key, op, [chosen]));
          },
        ),
      );
    } else if (options.isNotEmpty) {
      answer = Wrap(
        key: const Key('cond-values'),
        spacing: 6,
        runSpacing: 6,
        children: [
          for (final o in options)
            FilterChip(
              label: Text(o.label),
              selected: values.contains(o.value),
              onSelected: (on) {
                final next = [
                  for (final v in values)
                    if (v.isNotEmpty && v != o.value) v,
                  if (on) o.value,
                ];
                onChanged(build(key, op, next));
              },
            ),
        ],
      );
    } else {
      answer = SizedBox(
        width: 240,
        child: BoundText(
          key: ValueKey('cond-value-text:$key:$op'),
          initial: values.where((v) => v.isNotEmpty).join(', '),
          label: 'Answer',
          helper: many ? 'Separate several answers with commas.' : null,
          onChanged: (typed) => onChanged(
            build(
              key,
              op,
              many
                  ? [
                      for (final part in typed.split(','))
                        if (part.trim().isNotEmpty) part.trim(),
                    ]
                  : [typed.trim()],
            ),
          ),
        ),
      );
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        const Text('Only when'),
        question,
        verb,
        answer,
        IconButton(
          key: const Key('cond-clear'),
          tooltip: always,
          onPressed: () => onChanged(null),
          icon: const Icon(Icons.close, size: 18),
        ),
      ],
    );
  }
}
