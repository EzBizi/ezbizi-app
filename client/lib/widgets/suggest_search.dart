import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// One row under a [SuggestSearchField].
class SuggestItem {
  const SuggestItem({
    required this.title,
    this.icon,
    this.subtitle,
    this.kind,
    this.key,
  });

  final String title;
  final IconData? icon;
  final String? subtitle;

  /// A short tag drawn before the title ("Client", "Job").
  final String? kind;
  final Key? key;
}

/// A search box that suggests as you type, the Scheduling › Availability one.
///
/// Inspection › New Inspection reuses it (Jose, 2026-10-26: "sort of like the
/// scheduling one where you have the search bar in the middle, and it autofills
/// / suggests as you type"). The caller owns the query and the suggestions;
/// this owns the box, the list, the debounce and the keys: up and down move
/// the highlight, Enter picks it, Esc closes the list.
class SuggestSearchField extends StatefulWidget {
  const SuggestSearchField({
    super.key,
    required this.controller,
    required this.suggestions,
    required this.onChanged,
    required this.onPick,
    this.onSubmitted,
    this.onDismiss,
    this.fieldKey,
    this.hintText,
    this.prefixIcon,
    this.trailing,
    this.debounce = Duration.zero,
    this.enterPicksFirst = false,
    this.autofocus = false,
  });

  final TextEditingController controller;
  final List<SuggestItem> suggestions;
  final ValueChanged<String> onChanged;
  final ValueChanged<int> onPick;

  /// Enter with nothing highlighted (and [enterPicksFirst] off, or no rows).
  final ValueChanged<String>? onSubmitted;

  /// Esc: the caller clears [suggestions].
  final VoidCallback? onDismiss;
  final Key? fieldKey;
  final String? hintText;
  final Widget? prefixIcon;

  /// Drawn beside the box, e.g. Scheduling's Check button.
  final Widget? trailing;
  final Duration debounce;

  /// Enter picks the first row when none is highlighted.
  final bool enterPicksFirst;
  final bool autofocus;

  @override
  State<SuggestSearchField> createState() => _SuggestSearchFieldState();
}

class _SuggestSearchFieldState extends State<SuggestSearchField> {
  late final FocusNode _focus = FocusNode(onKeyEvent: _onKey);
  Timer? _timer;
  int _highlight = -1;

  @override
  void didUpdateWidget(SuggestSearchField old) {
    super.didUpdateWidget(old);
    if (_highlight >= widget.suggestions.length) _highlight = -1;
    if (!identical(old.suggestions, widget.suggestions)) _highlight = -1;
  }

  @override
  void dispose() {
    _timer?.cancel();
    _focus.dispose();
    super.dispose();
  }

  KeyEventResult _onKey(FocusNode node, KeyEvent event) {
    if (event is KeyUpEvent) return KeyEventResult.ignored;
    final rows = widget.suggestions.length;
    final key = event.logicalKey;

    if (key == LogicalKeyboardKey.arrowDown && rows > 0) {
      setState(() => _highlight = (_highlight + 1) % rows);
      return KeyEventResult.handled;
    }
    if (key == LogicalKeyboardKey.arrowUp && rows > 0) {
      setState(() => _highlight = _highlight <= 0 ? rows - 1 : _highlight - 1);
      return KeyEventResult.handled;
    }
    if (key == LogicalKeyboardKey.escape && rows > 0) {
      setState(() => _highlight = -1);
      widget.onDismiss?.call();
      return KeyEventResult.handled;
    }
    return KeyEventResult.ignored;
  }

  void _changed(String value) {
    _timer?.cancel();
    if (widget.debounce == Duration.zero) {
      widget.onChanged(value);
      return;
    }
    _timer = Timer(widget.debounce, () {
      if (mounted) widget.onChanged(value);
    });
  }

  void _submitted(String value) {
    _timer?.cancel();
    final rows = widget.suggestions.length;
    final pick = _highlight >= 0
        ? _highlight
        : (widget.enterPicksFirst && rows > 0 ? 0 : -1);
    if (pick >= 0 && pick < rows) {
      widget.onPick(pick);
      return;
    }
    widget.onSubmitted?.call(value);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final field = TextField(
      key: widget.fieldKey,
      controller: widget.controller,
      focusNode: _focus,
      autofocus: widget.autofocus,
      textInputAction: TextInputAction.search,
      decoration: InputDecoration(
        hintText: widget.hintText,
        prefixIcon: widget.prefixIcon,
        border: const OutlineInputBorder(),
      ),
      onChanged: _changed,
      onSubmitted: _submitted,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (widget.trailing == null)
          field
        else
          Row(
            children: [
              Expanded(child: field),
              const SizedBox(width: 8),
              widget.trailing!,
            ],
          ),
        if (widget.suggestions.isNotEmpty)
          Card(
            margin: const EdgeInsets.only(top: 4),
            clipBehavior: Clip.antiAlias,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                for (var i = 0; i < widget.suggestions.length; i++)
                  _row(theme, i, widget.suggestions[i]),
              ],
            ),
          ),
      ],
    );
  }

  Widget _row(ThemeData theme, int i, SuggestItem s) {
    final kind = s.kind;

    return ListTile(
      key: s.key,
      dense: true,
      selected: i == _highlight,
      selectedTileColor: theme.colorScheme.primaryContainer.withValues(
        alpha: 0.5,
      ),
      leading: s.icon == null ? null : Icon(s.icon, size: 18),
      title: Row(
        children: [
          if (kind != null) ...[
            Text(
              kind,
              style: theme.textTheme.labelSmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(width: 8),
          ],
          Expanded(
            child: Text(s.title, maxLines: 1, overflow: TextOverflow.ellipsis),
          ),
        ],
      ),
      subtitle: s.subtitle == null
          ? null
          : Text(s.subtitle!, maxLines: 1, overflow: TextOverflow.ellipsis),
      onTap: () => widget.onPick(i),
    );
  }
}
