import 'package:flutter/material.dart';

import '../core/api_client.dart';

/// Who was @mentioned in a note box, by the name inserted for them.
///
/// [ids] keeps only people whose "@Name" is still in the text, so deleting a
/// mention un-mentions them.
class MentionController {
  final Map<String, String> _picked = {};

  void add(String name, String id) => _picked[name] = id;

  List<String> ids(String text) => [
    for (final e in _picked.entries)
      if (text.contains('@${e.key}')) e.value,
  ];

  void clear() => _picked.clear();
}

/// @mentions in a note box (monday port, Jose 2026-11-09).
///
/// Wraps [child], a TextField on [controller]. Typing "@" and a few letters
/// lists, under the box, the people who can see the record ([type] and [id],
/// asked of `GET /mentionable` once); a tap inserts "@Name " and records them
/// in [mentions]. Inline, not a popup: the list is part of the column.
class MentionField extends StatefulWidget {
  const MentionField({
    super.key,
    required this.api,
    required this.type,
    required this.id,
    required this.controller,
    required this.mentions,
    required this.child,
  });

  final ApiClient api;
  final String type;
  final String id;
  final TextEditingController controller;
  final MentionController mentions;
  final Widget child;

  @override
  State<MentionField> createState() => _MentionFieldState();
}

class _MentionFieldState extends State<MentionField> {
  List<({String id, String name})>? _people;
  String? _query;

  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_changed);
  }

  @override
  void dispose() {
    widget.controller.removeListener(_changed);
    super.dispose();
  }

  /// The word being typed after an "@", or null when the caret is not in one.
  String? _typing() {
    final value = widget.controller.value;
    final caret = value.selection.baseOffset;
    if (caret < 0 || caret > value.text.length) return null;
    final before = value.text.substring(0, caret);
    final at = before.lastIndexOf('@');
    if (at < 0) return null;
    if (at > 0 && !RegExp(r'\s').hasMatch(before[at - 1])) return null;
    final word = before.substring(at + 1);
    if (word.contains('\n') || word.length > 30) return null;
    return word;
  }

  Future<void> _changed() async {
    final q = _typing();
    if (q != _query) setState(() => _query = q);
    if (q != null && _people == null) {
      _people = const [];
      final r = await widget.api.get(
        '/mentionable?type=${widget.type}&id=${widget.id}',
      );
      if (!mounted) return;
      setState(
        () => _people = r.ok && r.data is List
            ? [
                for (final p in (r.data as List).whereType<Map>())
                  (id: '${p['id']}', name: '${p['name']}'),
              ]
            : const [],
      );
    }
  }

  void _pick(({String id, String name}) p) {
    final value = widget.controller.value;
    final caret = value.selection.baseOffset;
    final at = value.text.substring(0, caret).lastIndexOf('@');
    final text =
        '${value.text.substring(0, at)}@${p.name} ${value.text.substring(caret)}';
    widget.mentions.add(p.name, p.id);
    widget.controller.value = TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: at + p.name.length + 2),
    );
  }

  @override
  Widget build(BuildContext context) {
    final q = _query?.toLowerCase();
    final matches = q == null
        ? const <({String id, String name})>[]
        : (_people ?? const <({String id, String name})>[])
              .where((p) => p.name.toLowerCase().contains(q))
              .take(6)
              .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        widget.child,
        if (matches.isNotEmpty)
          Card(
            key: const Key('mention-list'),
            margin: const EdgeInsets.only(top: 4),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                for (final p in matches)
                  ListTile(
                    key: ValueKey('mention-${p.id}'),
                    dense: true,
                    leading: const Icon(Icons.alternate_email, size: 18),
                    title: Text(p.name),
                    onTap: () => _pick(p),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

/// [icon] with the count of notes this person has not read yet.
Widget unreadNotesBadge(Widget icon, Object? count, {Key? key}) {
  final n = count is num ? count.toInt() : 0;
  return Badge(key: key, isLabelVisible: n > 0, label: Text('$n'), child: icon);
}
