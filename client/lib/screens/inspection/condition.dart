/// Whether a field or a screen is asked at all.
///
/// The mirror of `App\Support\Inspection\Condition` on the server, and it has
/// to stay one: the server decides whether a hidden field blocks Submit and
/// whether its answer is frozen into the report, so a client that disagreed
/// would show a question the report then throws away, or hide one the server
/// still demands.
///
/// That risk is why the language is deliberately tiny — `eq`, `ne`, `in`,
/// `not_in`, and `and`/`or` over nested conditions. Two implementations of four
/// comparisons can be kept identical by reading them side by side. Two
/// implementations of an expression language cannot.
library;

/// Is this field or screen shown, given what has been answered so far?
///
/// [condition] is a `visible_if` map, or null for "always".
bool conditionPasses(dynamic condition, Map<String, dynamic> answers) {
  if (condition is! Map || condition.isEmpty) return true;

  final op = '${condition['op'] ?? ''}';

  if (op == 'and' || op == 'or') {
    final children = condition['conditions'];
    final list = children is List ? children : const [];

    // An empty `and` is true and an empty `or` is false — what those words mean
    // over an empty set. A half-built condition should not decide the form.
    if (op == 'and') {
      return list.every((child) => conditionPasses(child, answers));
    }
    return list.any((child) => conditionPasses(child, answers));
  }

  final field = '${condition['field'] ?? ''}';

  // A condition naming nothing cannot be evaluated, and hiding the field would
  // silently remove a question. Show it.
  if (field.isEmpty) return true;

  final wanted = <String>[
    if (op == 'in' || op == 'not_in')
      for (final v in (condition['values'] is List ? condition['values'] as List : const []))
        _scalar(v)
    else
      _scalar(condition['value']),
  ];

  final matched = _matches(answers[field], wanted);

  switch (op) {
    case 'eq':
    case 'in':
      return matched;
    case 'ne':
    case 'not_in':
      return !matched;
    default:
      // An operator this build does not know shows the field rather than hiding
      // it. A newer server's condition should leave the question answerable,
      // not make it vanish from a form somebody is filling in.
      return true;
  }
}

/// Does the answer match any of the wanted values?
///
/// A photo, signature or multi-choice answer is a LIST, and comparing a list to
/// a string is never equal — so without this every condition on one would be
/// silently false forever.
bool _matches(dynamic answer, List<String> wanted) {
  if (answer == null) return false;

  final held = answer is List
      ? answer.map(_scalar).toList()
      : <String>[_scalar(answer)];

  if (held.isEmpty) return false;
  if (held.length == 1 && held.first.isEmpty) return false;

  return held.any(wanted.contains);
}

/// Compared as strings, because `2` from a form and `"2"` from JSON are one
/// answer. Matches the server's `Condition::scalar`.
String _scalar(dynamic value) {
  if (value == null) return '';
  if (value is bool) return value ? 'true' : 'false';
  return '$value';
}
