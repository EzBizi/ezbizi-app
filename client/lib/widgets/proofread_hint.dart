import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';

/// "Did you mean" under a message box (Jose, 2026-11-05: "Yes, I call you
/// right away"; UI-UX approved the placement).
///
/// A second after typing stops, [path] is asked once about the draft; a
/// clear fix shows under the box, its changed words bold, and goes in only
/// when tapped. Nothing changes by itself and Send is never held. An answer
/// for words that have since changed is dropped.
class ProofreadHint extends StatefulWidget {
  const ProofreadHint({
    super.key,
    required this.api,
    required this.controller,
    required this.path,
    this.enabled = true,
  });

  final ApiClient api;
  final TextEditingController controller;

  /// `POST {text}` answering `{suggestion}`, e.g. `/proofread`.
  final String path;

  /// False where the business switched `leads.proofread` off.
  final bool enabled;

  @override
  State<ProofreadHint> createState() => _ProofreadHintState();
}

class _ProofreadHintState extends State<ProofreadHint> {
  String? _fix;
  String _fixFor = '';
  String _asked = '';
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_later);
  }

  @override
  void dispose() {
    _timer?.cancel();
    widget.controller.removeListener(_later);
    super.dispose();
  }

  void _later() {
    final text = widget.controller.text;
    if (_fix != null && text != _fixFor) setState(() => _fix = null);
    _timer?.cancel();
    if (!widget.enabled || text.trim().length < 3 || text == _asked) return;
    _timer = Timer(const Duration(seconds: 1), () async {
      _asked = text;
      final response = await widget.api.post(widget.path, {'text': text});
      if (!mounted || !response.ok || widget.controller.text != text) return;
      final fix = (response.data as Map?)?['suggestion'] as String?;
      if (fix != null) {
        setState(() {
          _fix = fix;
          _fixFor = text;
        });
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final fix = _fix;
    if (fix == null) return const SizedBox.shrink();
    final theme = Theme.of(context);
    final had = _fixFor.split(RegExp(r'\s+')).toSet();

    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Row(
        children: [
          Expanded(
            child: InkWell(
              key: const ValueKey('reply-proofread'),
              onTap: () {
                widget.controller.value = TextEditingValue(
                  text: fix,
                  selection: TextSelection.collapsed(offset: fix.length),
                );
                setState(() => _fix = null);
              },
              // Two lines at most, so Send stays on a 390pt phone (UI-UX).
              child: Text.rich(
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                TextSpan(
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  children: [
                    TextSpan(
                      text: 'Did you mean: ',
                      style: TextStyle(color: theme.colorScheme.primary),
                    ),
                    for (final (i, w) in fix.split(' ').indexed)
                      TextSpan(
                        text: i == 0 ? w : ' $w',
                        style: had.contains(w)
                            ? null
                            : const TextStyle(fontWeight: FontWeight.w700),
                      ),
                  ],
                ),
              ),
            ),
          ),
          IconButton(
            key: const ValueKey('reply-proofread-dismiss'),
            tooltip: 'Dismiss',
            // A 44pt target around a small icon (UI-UX).
            constraints: const BoxConstraints(minWidth: 44, minHeight: 44),
            iconSize: 16,
            onPressed: () => setState(() => _fix = null),
            icon: const Icon(Icons.close),
          ),
        ],
      ),
    );
  }
}
