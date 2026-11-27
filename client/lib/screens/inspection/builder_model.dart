/// The Builder's arithmetic: what to call things, and the keys that tie them.
///
/// Kept out of the widgets so it can be read and tested on its own. Everything
/// here works on the definition exactly as the server sends it — maps and lists
/// decoded from JSON, edited in place by the Builder — with one addition, the
/// `_uid` each question carries while it is on screen (see [assignUids]).
library;

import 'package:flutter/material.dart';

import 'inspection_flow.dart';
import 'option.dart';

/// The key a question carries only while the Builder holds it.
///
/// A question's own `key` is not enough to tell two widgets apart: furniture
/// has none, and an author can give two questions the same one. Positions move
/// on every drag. So each question is stamped once on load, and the stamp is
/// taken off again before anything is sent ([withoutUids]).
const String uidKey = '_uid';

int _uidSeq = 0;

/// Stamp every screen and question, follow-ups included, not stamped yet.
void assignUids(Map<String, dynamic> definition) {
  for (final screen in screensOf(definition)) {
    screen[uidKey] ??= 's${++_uidSeq}';
    for (final field in fieldsOf(screen)) {
      _stamp(field);
    }
  }
}

void _stamp(Map<String, dynamic> field) {
  field[uidKey] ??= 'q${++_uidSeq}';
  followUpsOf(field).forEach(_stamp);
}

/// A new stamp, for a question made here.
String freshUid() => 'q${++_uidSeq}';

/// The screens as sent, minus every `_uid` — what the server is given.
List<dynamic> withoutUids(List<dynamic> screens) => [
  for (final screen in screens) _strip(screen),
];

dynamic _strip(dynamic value) {
  if (value is Map) {
    return {
      for (final entry in value.entries)
        if (entry.key != uidKey) '${entry.key}': _strip(entry.value),
    };
  }
  if (value is List) return [for (final item in value) _strip(item)];
  return value;
}

/// The screens of a definition, as the live maps.
List<Map<String, dynamic>> screensOf(Map<String, dynamic>? definition) {
  final raw = definition?['screens'];
  if (raw is! List) return const [];
  return [
    for (final s in raw)
      if (s is Map<String, dynamic>) s,
  ];
}

/// A screen's questions, as the live maps.
List<Map<String, dynamic>> fieldsOf(Map<String, dynamic> screen) {
  final raw = screen['fields'];
  if (raw is! List) return const [];
  return [
    for (final f in raw)
      if (f is Map<String, dynamic>) f,
  ];
}

/// A choice's answers, each turned into its full map in place.
///
/// Options may still be plain strings in a definition stored before options
/// became objects. Anything the Builder edits has to be the object form, so the
/// string is replaced where it stands — the server normalises both shapes to
/// this one anyway.
List<Map<String, dynamic>> optionMaps(Map<String, dynamic> field) {
  final raw = field['options'];
  if (raw is! List) return const [];

  for (var i = 0; i < raw.length; i++) {
    final item = raw[i];
    if (item is Map<String, dynamic>) continue;
    final option = InspectionOption.from(item);
    raw[i] = option == null
        ? <String, dynamic>{
            'value': '',
            'label': '',
            'variant': 'neutral',
            'fields': <dynamic>[],
          }
        : <String, dynamic>{
            'value': option.value,
            'label': option.label,
            'variant': option.variant,
            'fields': <dynamic>[],
          };
  }

  return [for (final o in raw) o as Map<String, dynamic>];
}

/// The questions an answer asks next.
List<Map<String, dynamic>> nestedOf(Map<String, dynamic> option) {
  final raw = option['fields'];
  if (raw is! List) return const [];
  return [
    for (final f in raw)
      if (f is Map<String, dynamic>) f,
  ];
}

/// Every follow-up question a choice holds, across all its answers, without
/// touching the options' shape (reading must not rewrite what it reads).
List<Map<String, dynamic>> followUpsOf(Map<String, dynamic> field) {
  final raw = field['options'];
  if (raw is! List) return const [];
  return [
    for (final option in raw)
      if (option is Map && option['fields'] is List)
        for (final f in option['fields'] as List)
          if (f is Map<String, dynamic>) f,
  ];
}

/// Headings and notes: they ask nothing, take no key and never print.
bool isFurniture(Map<String, dynamic> field) =>
    field['type'] == 'divider' || field['type'] == 'alert';

/// Every key used anywhere in the definition, follow-ups included.
Set<String> keysOf(Map<String, dynamic>? definition) {
  final keys = <String>{};
  void add(Map<String, dynamic> field) {
    final key = '${field['key'] ?? ''}';
    if (key.isNotEmpty) keys.add(key);
    followUpsOf(field).forEach(add);
  }

  for (final screen in screensOf(definition)) {
    fieldsOf(screen).forEach(add);
  }
  return keys;
}

/// A key nobody in this definition holds: `question_<n>`, one past the highest.
///
/// Readable on purpose — it is what a stored answer is filed under — and never
/// derived from the label, because a key renamed after a run has started would
/// orphan every answer already given to it.
String nextKey(
  Map<String, dynamic>? definition, {
  Set<String> also = const {},
}) {
  final taken = {...keysOf(definition), ...also};
  var highest = 0;
  for (final key in taken) {
    final match = RegExp(r'^question_(\d+)$').firstMatch(key);
    if (match != null) {
      final n = int.parse(match.group(1)!);
      if (n > highest) highest = n;
    }
  }
  return 'question_${highest + 1}';
}

/// A copy of a question with fresh keys and stamps, follow-ups included.
Map<String, dynamic> duplicateOf(
  Map<String, dynamic> field,
  Map<String, dynamic>? definition,
) {
  final copy = _strip(field) as Map<String, dynamic>;
  final given = <String>{};

  void rekey(Map<String, dynamic> f) {
    if ('${f['key'] ?? ''}'.isNotEmpty) {
      final key = nextKey(definition, also: given);
      given.add(key);
      f['key'] = key;
    }
    f[uidKey] = freshUid();
    for (final option in optionMaps(f)) {
      nestedOf(option).forEach(rekey);
    }
  }

  rekey(copy);
  final label = '${copy['label'] ?? ''}';
  if (label.isNotEmpty) copy['label'] = '$label (copy)';
  return copy;
}

/// The type's icon, the same one on the card, in the picker and on the phone.
IconData typeIcon(String type) => switch (type) {
  'textarea' => Icons.notes,
  'number' => Icons.pin_outlined,
  'date' => Icons.event_outlined,
  'email' => Icons.alternate_email,
  'phone' => Icons.phone_outlined,
  'choice' => Icons.radio_button_checked,
  'checkbox' => Icons.check_box_outlined,
  'file' => Icons.attach_file,
  'photo' => Icons.photo_camera_outlined,
  'signature' => Icons.draw_outlined,
  'services' => Icons.handyman_outlined,
  'divider' => Icons.title,
  'alert' => Icons.info_outline,
  _ => Icons.short_text,
};

/// One question a condition can be about.
class ConditionTarget {
  const ConditionTarget({
    required this.key,
    required this.label,
    required this.screen,
    required this.options,
  });

  final String key;
  final String label;

  /// The screen it is asked on, to tell two "Notes" apart.
  final String screen;

  /// Its answers when it is a choice, otherwise empty.
  final List<InspectionOption> options;
}

/// Every question with a key, where it is asked, and its answers.
///
/// Follow-ups count as asked on their parent's screen. A key that sits on two
/// screens is left out: the server refuses a condition naming it, because which
/// of the two answers it means cannot be told.
List<ConditionTarget> targetsOf(Map<String, dynamic>? definition, {int? upTo}) {
  final seen = <String, Set<int>>{};
  final found = <String, ConditionTarget>{};
  final screens = screensOf(definition);

  // Every screen is counted, so a key repeated further on is still caught;
  // only the screens up to [upTo] are offered.
  for (var i = 0; i < screens.length; i++) {
    final name = '${screens[i]['name'] ?? ''}'.trim();
    final offered = upTo == null || i <= upTo;
    void add(Map<String, dynamic> field) {
      final key = '${field['key'] ?? ''}';
      if (key.isEmpty || isFurniture(field)) return;
      (seen[key] ??= {}).add(i);
      if (offered) {
        found.putIfAbsent(
          key,
          () => ConditionTarget(
            key: key,
            label: labelOf(field),
            screen: name.isEmpty ? 'Screen ${i + 1}' : name,
            options: field['type'] == 'choice'
                ? InspectionOption.allOf(field['options'])
                : const [],
          ),
        );
      }
      followUpsOf(field).forEach(add);
    }

    fieldsOf(screens[i]).forEach(add);
  }

  return [
    for (final entry in found.entries)
      if ((seen[entry.key]?.length ?? 0) == 1) entry.value,
  ];
}

/// A question's words, or what to call it when it has none yet.
String labelOf(Map<String, dynamic> field) {
  if (field['type'] == 'alert') {
    final title = '${field['title'] ?? ''}'.trim();
    if (title.isNotEmpty) return title;
  }
  final label = '${field['label'] ?? ''}'.trim();
  if (label.isNotEmpty) return label;
  return switch ('${field['type'] ?? ''}') {
    'divider' => 'Untitled heading',
    'alert' => 'A note to the technician',
    _ => 'Untitled question',
  };
}

/// The words of every question with a key, and of every answer they offer.
class Vocabulary {
  Vocabulary(Map<String, dynamic>? definition) {
    void add(Map<String, dynamic> field) {
      final key = '${field['key'] ?? ''}';
      if (key.isNotEmpty) {
        questions.putIfAbsent(key, () => labelOf(field));
        if (field['type'] == 'choice') {
          answers.putIfAbsent(
            key,
            () => {
              for (final o in InspectionOption.allOf(field['options']))
                o.value: o.label,
            },
          );
        }
      }
      followUpsOf(field).forEach(add);
    }

    for (final screen in screensOf(definition)) {
      fieldsOf(screen).forEach(add);
    }
  }

  final Map<String, String> questions = {};
  final Map<String, Map<String, String>> answers = {};

  String answer(String key, String value) => answers[key]?[value] ?? value;
}

/// A condition as a sentence: "Level is Level 2", "System type is Masonry or
/// Built-in". Empty for no condition.
String conditionSentence(dynamic condition, Vocabulary words) {
  if (condition is! Map || condition.isEmpty) return '';

  final op = '${condition['op'] ?? ''}';
  if (op == 'and' || op == 'or') {
    final children = condition['conditions'] is List
        ? condition['conditions'] as List
        : const [];
    final parts = [
      for (final child in children)
        if (conditionSentence(child, words).isNotEmpty)
          conditionSentence(child, words),
    ];
    return parts.join(op == 'and' ? ' and ' : ' or ');
  }

  final key = '${condition['field'] ?? ''}';
  if (key.isEmpty) return '';

  final name = words.questions[key] ?? key;
  final values = op == 'in' || op == 'not_in'
      ? [
          for (final v
              in (condition['values'] is List
                  ? condition['values'] as List
                  : const []))
            words.answer(key, '$v'),
        ]
      : [words.answer(key, '${condition['value'] ?? ''}')];
  final spoken = values.where((v) => v.isNotEmpty).join(' or ');
  if (spoken.isEmpty) {
    return op == 'ne' || op == 'not_in' ? '$name is answered' : name;
  }

  return op == 'ne' || op == 'not_in'
      ? '$name is not $spoken'
      : '$name is $spoken';
}

/// Where each key is first asked, for [conditionReach].
Map<String, int> askedOn(Map<String, dynamic>? definition) {
  final asked = <String, int>{};
  final screens = screensOf(definition);
  for (var i = 0; i < screens.length; i++) {
    void add(Map<String, dynamic> field) {
      final key = '${field['key'] ?? ''}';
      if (key.isNotEmpty) asked.putIfAbsent(key, () => i);
      followUpsOf(field).forEach(add);
    }

    fieldsOf(screens[i]).forEach(add);
  }
  return asked;
}

/// Whether a QUESTION's condition on screen [screen] can ever be true.
///
/// A question reads answers from its own screen as they are given — the run
/// re-checks on every answer — so its own screen counts as asked, which a
/// screen's own condition cannot do.
ConditionReach questionReach(
  dynamic condition,
  Map<String, int> asked,
  int screen,
) => conditionReach(condition, asked, screen + 1);

/// The places whose conditions name [key]: question labels and screen names.
List<String> dependentsOf(Map<String, dynamic>? definition, String key) {
  if (key.isEmpty) return const [];

  bool names(dynamic condition) {
    if (condition is! Map) return false;
    final op = '${condition['op'] ?? ''}';
    if (op == 'and' || op == 'or') {
      final children = condition['conditions'];
      return children is List && children.any(names);
    }
    return '${condition['field'] ?? ''}' == key;
  }

  final out = <String>[];
  final screens = screensOf(definition);
  for (var i = 0; i < screens.length; i++) {
    if (names(screens[i]['visible_if'])) {
      out.add('the screen “${screens[i]['name'] ?? 'Screen ${i + 1}'}”');
    }
    void check(Map<String, dynamic> field) {
      if (names(field['visible_if'])) out.add('“${labelOf(field)}”');
      followUpsOf(field).forEach(check);
    }

    fieldsOf(screens[i]).forEach(check);
  }
  return out;
}

/// Rewrite every condition that names [key] answered [from] to say [to].
///
/// An answer the Builder writes as plain words keeps its value equal to its
/// words, so renaming the words renames the value — and every condition that
/// was waiting for the old words has to follow, or it silently never matches.
/// What waits for answer [value] of question [key]: removing that answer
/// would leave them never shown (audit 11-27).
List<String> waitingForAnswer(
  Map<String, dynamic>? definition,
  String key,
  String value,
) {
  bool names(dynamic condition) {
    if (condition is! Map) return false;
    final op = '${condition['op'] ?? ''}';
    if (op == 'and' || op == 'or') {
      final children = condition['conditions'];
      return children is List && children.any(names);
    }
    if ('${condition['field'] ?? ''}' != key) return false;
    final values = condition['values'];
    return '${condition['value'] ?? ''}' == value ||
        (values is List && values.any((v) => '$v' == value));
  }

  final out = <String>[];
  final screens = screensOf(definition);
  for (var i = 0; i < screens.length; i++) {
    if (names(screens[i]['visible_if'])) {
      out.add('the screen “${screens[i]['name'] ?? 'Screen ${i + 1}'}”');
    }
    void check(Map<String, dynamic> field) {
      if (names(field['visible_if'])) out.add('“${labelOf(field)}”');
      followUpsOf(field).forEach(check);
    }

    fieldsOf(screens[i]).forEach(check);
  }
  return out;
}

void renameAnswer(
  Map<String, dynamic>? definition,
  String key,
  String from,
  String to,
) {
  void fix(dynamic condition) {
    if (condition is! Map) return;
    final op = '${condition['op'] ?? ''}';
    if (op == 'and' || op == 'or') {
      final children = condition['conditions'];
      if (children is List) children.forEach(fix);
      return;
    }
    if ('${condition['field'] ?? ''}' != key) return;
    if (op == 'in' || op == 'not_in') {
      final values = condition['values'];
      if (values is List) {
        for (var i = 0; i < values.length; i++) {
          if ('${values[i]}' == from) values[i] = to;
        }
      }
    } else if ('${condition['value'] ?? ''}' == from) {
      condition['value'] = to;
    }
  }

  for (final screen in screensOf(definition)) {
    fix(screen['visible_if']);
    void walk(Map<String, dynamic> field) {
      fix(field['visible_if']);
      followUpsOf(field).forEach(walk);
    }

    fieldsOf(screen).forEach(walk);
  }
}

/// How many questions a screen asks, headings and notes not counted.
int questionCount(Map<String, dynamic> screen) =>
    fieldsOf(screen).where((f) => !isFurniture(f)).length;
