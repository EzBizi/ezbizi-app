import 'package:flutter/material.dart';

import '../../widgets/tag.dart';

/// A customer's tags (Jose 2026-11-03: newsletters and automations "by tag").
List<String> customerTags(Map<String, dynamic>? row) => [
  for (final t in (row?['tags'] as List? ?? const [])) '$t',
];

/// The tags on a list row: two, then "+N" (UI queue 174); one when the cell
/// is narrow, where two Flexible pills were squeezed to empty grey ovals.
class CustomerTagChips extends StatelessWidget {
  const CustomerTagChips(this.tags, {super.key});

  final List<String> tags;

  @override
  Widget build(BuildContext context) {
    if (tags.isEmpty) return const SizedBox.shrink();
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    return LayoutBuilder(
      builder: (context, box) {
        final shown = box.maxWidth >= 180 ? 2 : 1;
        return Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final t in tags.take(shown)) Flexible(child: Tag(t)),
            if (tags.length > shown)
              Padding(
                padding: const EdgeInsets.only(left: 6),
                child: Text(
                  '+${tags.length - shown}',
                  style: TextStyle(fontSize: 11, color: muted),
                ),
              ),
          ],
        );
      },
    );
  }
}

/// Chips with × under the customer's name and an inline "+ Tag" field that
/// suggests tags already in use. Each change is sent at once with the whole
/// list, which is what the server takes; no Save (UI queue 174, option 1).
class CustomerTagsEditor extends StatefulWidget {
  const CustomerTagsEditor({
    super.key,
    required this.tags,
    required this.suggestions,
    required this.enabled,
    required this.onChanged,
  });

  final List<String> tags;
  final Iterable<String> suggestions;
  final bool enabled;

  /// Sends the whole new list; resolves false when the server refused it.
  final Future<bool> Function(List<String> tags) onChanged;

  static const maxTags = 20;
  static const maxLength = 40;

  @override
  State<CustomerTagsEditor> createState() => _CustomerTagsEditorState();
}

class _CustomerTagsEditorState extends State<CustomerTagsEditor> {
  bool _adding = false;
  bool _busy = false;

  bool _has(String tag) =>
      widget.tags.any((t) => t.toLowerCase() == tag.toLowerCase());

  Future<void> _send(List<String> next) async {
    setState(() => _busy = true);
    await widget.onChanged(next);
    if (mounted) setState(() => _busy = false);
  }

  void _add(String raw) {
    final tag = raw.trim();
    setState(() => _adding = false);
    if (tag.isEmpty || _has(tag)) return;
    _send([...widget.tags, tag]);
  }

  @override
  Widget build(BuildContext context) {
    final on = widget.enabled && !_busy;
    final full = widget.tags.length >= CustomerTagsEditor.maxTags;

    return Wrap(
      spacing: 6,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        for (final t in widget.tags)
          InputChip(
            key: ValueKey('customer-tag-$t'),
            label: Text(t),
            visualDensity: VisualDensity.compact,
            onDeleted: on
                ? () => _send([
                    for (final x in widget.tags)
                      if (x != t) x,
                  ])
                : null,
          ),
        if (_adding)
          SizedBox(
            width: 220,
            child: Autocomplete<String>(
              optionsBuilder: (value) {
                final q = value.text.trim().toLowerCase();
                return widget.suggestions
                    .where((s) => !_has(s) && s.toLowerCase().contains(q))
                    .take(8);
              },
              onSelected: _add,
              fieldViewBuilder: (context, controller, focus, submit) =>
                  TextField(
                    key: const ValueKey('customer-tag-input'),
                    controller: controller,
                    focusNode: focus,
                    autofocus: true,
                    maxLength: CustomerTagsEditor.maxLength,
                    decoration: const InputDecoration(
                      isDense: true,
                      hintText: 'Tag',
                      counterText: '',
                    ),
                    onSubmitted: _add,
                    onTapOutside: (_) => setState(() => _adding = false),
                  ),
            ),
          )
        else if (!full)
          ActionChip(
            key: const ValueKey('customer-tag-add'),
            avatar: const Icon(Icons.add, size: 16),
            label: const Text('Tag'),
            visualDensity: VisualDensity.compact,
            onPressed: on ? () => setState(() => _adding = true) : null,
          ),
      ],
    );
  }
}
