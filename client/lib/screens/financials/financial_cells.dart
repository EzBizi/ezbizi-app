import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// Shared vocabulary for Financials: field types, how a cell reads, choice
/// colours and the formula language.

const fieldTypeLabels = <String, String>{
  'text': 'Single line text',
  'long_text': 'Long text',
  'number': 'Number',
  'currency': 'Currency',
  'percent': 'Percent',
  'date': 'Date',
  'checkbox': 'Checkbox',
  'single_select': 'Single select',
  'multi_select': 'Multiple select',
  'email': 'Email',
  'phone': 'Phone number',
  'url': 'URL',
  'rating': 'Rating',
  'formula': 'Formula',
};

const fieldTypeIcons = <String, IconData>{
  'text': Icons.short_text,
  'long_text': Icons.notes,
  'number': Icons.tag,
  'currency': Icons.attach_money,
  'percent': Icons.percent,
  'date': Icons.event_outlined,
  'checkbox': Icons.check_box_outlined,
  'single_select': Icons.arrow_drop_down_circle_outlined,
  'multi_select': Icons.list_alt,
  'email': Icons.alternate_email,
  'phone': Icons.phone_outlined,
  'url': Icons.link,
  'rating': Icons.star_border,
  'formula': Icons.functions,
};

bool isNumeric(String type) =>
    const {'number', 'currency', 'percent', 'rating'}.contains(type);

List<String> choicesOf(Map field) => [
  for (final c in (field['options']?['choices'] as List? ?? const []))
    c is Map ? '${c['name']}' : '$c',
];

Color choiceColour(Map field, String choice) {
  final i = choicesOf(field).indexOf(choice);
  const palette = EzBiziTheme.avatarPalette;
  return palette[(i < 0 ? choice.hashCode : i) % palette.length];
}

/// The value a cell holds, computing formulas from the rest of the record.
dynamic cellValue(Map field, Map values, List<Map> fields) {
  if (field['type'] == 'formula') {
    return evaluateFormula(
      '${field['options']?['formula'] ?? ''}',
      values,
      fields,
    );
  }
  return values[field['id']];
}

String formatCell(Map field, dynamic v) {
  if (v == null || v == '') return '';
  final options = field['options'] as Map? ?? const {};
  switch (field['type']) {
    case 'currency':
      // Text in a money column (an Airtable "#ERROR!") shows as itself.
      final n = v is num ? v : num.tryParse('$v');
      if (n == null) return '$v';
      final p = (options['precision'] as int?) ?? 2;
      final s = n.abs().toStringAsFixed(p);
      final parts = s.split('.');
      final whole = parts[0].replaceAllMapped(
        RegExp(r'\B(?=(\d{3})+(?!\d))'),
        (_) => ',',
      );
      return '${n < 0 ? '-' : ''}${options['symbol'] ?? r'$'}$whole${parts.length > 1 ? '.${parts[1]}' : ''}';
    case 'percent':
      return '$v%';
    case 'checkbox':
      return v == true ? '✓' : '';
    case 'multi_select':
      return (v as List).join(', ');
    case 'rating':
      final stars = v is num ? v : num.tryParse('$v');
      return stars == null ? '$v' : '★' * stars.toInt();
    case 'formula':
      if (v is double) {
        return v == v.roundToDouble()
            ? v.toInt().toString()
            : v.toStringAsFixed(2);
      }
      return '$v';
    default:
      return '$v';
  }
}

/// Airtable-style formulas: `{Field}` references, numbers, "strings",
/// + - * / for arithmetic and & to join text. An error reads as `#ERROR`.
// ponytail: arithmetic and joins only; add functions (IF, SUM, …) when asked.
dynamic evaluateFormula(String source, Map values, List<Map> fields) {
  try {
    final p = _Formula(source, values, {
      for (final f in fields) '${f['name']}'.toLowerCase(): f,
    }, fields);
    final v = p.join();
    if (p.at < p.src.length) return '#ERROR';
    return v;
  } catch (_) {
    return '#ERROR';
  }
}

class _Formula {
  _Formula(this.src, this.values, this.byName, this.fields);

  final String src;
  final Map values;
  final Map<String, Map> byName;
  final List<Map> fields;
  int at = 0;

  void _space() {
    while (at < src.length && src[at] == ' ') {
      at++;
    }
  }

  bool _eat(String c) {
    _space();
    if (at < src.length && src[at] == c) {
      at++;
      return true;
    }
    return false;
  }

  dynamic join() {
    var v = sum();
    while (_eat('&')) {
      v = '${_text(v)}${_text(sum())}';
    }
    return v;
  }

  dynamic sum() {
    var v = product();
    while (true) {
      if (_eat('+')) {
        v = _num(v) + _num(product());
      } else if (_eat('-')) {
        v = _num(v) - _num(product());
      } else {
        return v;
      }
    }
  }

  dynamic product() {
    var v = unary();
    while (true) {
      if (_eat('*')) {
        v = _num(v) * _num(unary());
      } else if (_eat('/')) {
        final d = _num(unary());
        if (d == 0) throw StateError('divide by zero');
        v = _num(v) / d;
      } else {
        return v;
      }
    }
  }

  dynamic unary() => _eat('-') ? -_num(unary()) : atom();

  dynamic atom() {
    _space();
    if (_eat('(')) {
      final v = join();
      if (!_eat(')')) throw StateError(')');
      return v;
    }
    if (_eat('{')) {
      final end = src.indexOf('}', at);
      if (end < 0) throw StateError('}');
      final name = src.substring(at, end).trim().toLowerCase();
      at = end + 1;
      final field = byName[name] ?? (throw StateError('no field $name'));
      if (field['type'] == 'formula') throw StateError('nested formula');
      return values[field['id']];
    }
    if (_eat('"')) {
      final end = src.indexOf('"', at);
      if (end < 0) throw StateError('"');
      final s = src.substring(at, end);
      at = end + 1;
      return s;
    }
    final m = RegExp(r'\d+(\.\d+)?').matchAsPrefix(src, at);
    if (m == null) throw StateError('unexpected');
    at = m.end;
    return double.parse(m.group(0)!);
  }

  double _num(dynamic v) =>
      v == null ? 0 : (v is num ? v.toDouble() : double.parse('$v'));

  String _text(dynamic v) => v == null
      ? ''
      : (v is double && v == v.roundToDouble() ? '${v.toInt()}' : '$v');
}

/// One cell's look in a grid, card or kanban.
class CellView extends StatelessWidget {
  const CellView({super.key, required this.field, required this.value});

  final Map field;
  final dynamic value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (value == null || value == '') return const SizedBox.shrink();
    switch (field['type']) {
      case 'checkbox':
        return Icon(
          value == true ? Icons.check_box : Icons.check_box_outline_blank,
          size: 18,
          color: theme.colorScheme.primary,
        );
      case 'single_select':
        return ChoicePill(field: field, choice: '$value');
      case 'multi_select':
        return Wrap(
          spacing: 4,
          runSpacing: 2,
          children: [
            for (final c in value as List)
              ChoicePill(field: field, choice: '$c'),
          ],
        );
      default:
        return Text(
          formatCell(field, value),
          overflow: TextOverflow.ellipsis,
          textAlign: isNumeric('${field['type']}') || field['type'] == 'formula'
              ? TextAlign.right
              : TextAlign.start,
        );
    }
  }
}

class ChoicePill extends StatelessWidget {
  const ChoicePill({super.key, required this.field, required this.choice});

  final Map field;
  final String choice;

  @override
  Widget build(BuildContext context) {
    final colour = choiceColour(field, choice);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: colour.withValues(alpha: 0.18),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        choice,
        style: Theme.of(context).textTheme.labelMedium,
        overflow: TextOverflow.ellipsis,
      ),
    );
  }
}
