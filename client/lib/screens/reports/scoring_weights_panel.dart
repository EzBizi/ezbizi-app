import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// What the Scoring score weighs: conversion and the AI's seven checks, as
/// whole numbers that add up to 100 (Reports 051fa5e6; UI queue 97, desktop 1
/// and phone 4). A slider and a number for each, the split as a bar, and a
/// total that says what is left; Save waits until it is 100, as the server
/// refuses anything else. Pops `true` when saved.
class ScoringWeightsPanel extends StatefulWidget {
  const ScoringWeightsPanel({
    super.key,
    required this.api,
    required this.labels,
  });

  final ApiClient api;

  /// Each weight's key and words, in the order to show them.
  final Map<String, String> labels;

  @override
  State<ScoringWeightsPanel> createState() => _ScoringWeightsPanelState();
}

class _ScoringWeightsPanelState extends State<ScoringWeightsPanel> {
  Map<String, int>? _weights;
  Map<String, int> _saved = const {};
  Map<String, int> _defaults = const {};
  final _fields = <String, TextEditingController>{};
  String? _error;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _fields.values) {
      c.dispose();
    }
    super.dispose();
  }

  static Map<String, int> _ints(Object? raw) => {
    for (final e in ((raw as Map?) ?? const {}).entries)
      '${e.key}': (e.value as num?)?.toInt() ?? 0,
  };

  Future<void> _load() async {
    final res = await widget.api.get('/reports/scoring/weights');
    if (!mounted) return;
    final data = (res.body['data'] as Map?) ?? const {};
    setState(() {
      if (!res.ok) {
        _error = res.message;
        return;
      }
      _saved = _ints(data['weights']);
      _defaults = _ints(data['defaults']);
      _set({..._saved});
    });
  }

  void _set(Map<String, int> weights) {
    _weights = weights;
    for (final key in widget.labels.keys) {
      final text = '${weights[key] ?? 0}';
      final field = _fields.putIfAbsent(key, TextEditingController.new);
      if (field.text != text) field.text = text;
    }
  }

  int get _total =>
      widget.labels.keys.fold(0, (s, k) => s + (_weights?[k] ?? 0));

  bool get _changed =>
      widget.labels.keys.any((k) => (_weights?[k] ?? 0) != (_saved[k] ?? 0));

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final res = await widget.api.put('/reports/scoring/weights', {
      'weights': {for (final k in widget.labels.keys) k: _weights?[k] ?? 0},
    });
    if (!mounted) return;
    if (res.ok) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _saving = false;
      _error = res.reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final weights = _weights;
    final total = _total;
    final keys = widget.labels.keys.toList();
    // Nine weights, eight palette colours: the ninth takes the theme's own.
    final colours = [...EzBiziTheme.avatarPalette, theme.colorScheme.tertiary];
    Color colour(String key) => colours[keys.indexOf(key) % colours.length];

    return SidePanel(
      title: 'What the score weighs',
      subtitle:
          'Conversion is Performance\'s booked-from-calls rate, the AI call '
          'score is its mark for each call, and the rest are its checks on '
          'each call. The weights add up to 100.',
      actions: [
        TextButton(
          key: const Key('scoring-weights-defaults'),
          onPressed: weights == null || _saving
              ? null
              : () => setState(() => _set({..._defaults})),
          child: const Text('Back to the defaults'),
        ),
        FilledButton(
          key: const Key('scoring-weights-save'),
          onPressed: total == 100 && _changed && !_saving ? _save : null,
          child: Text(_saving ? 'Saving…' : 'Save'),
        ),
      ],
      child: weights == null
          ? (_error == null
                ? const Center(child: CircularProgressIndicator())
                : ErrorNote(_error!))
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_error != null) ...[
                  ErrorNote(_error!),
                  const SizedBox(height: 12),
                ],
                // The split at a glance.
                ClipRRect(
                  key: const Key('scoring-weights-bar'),
                  borderRadius: BorderRadius.circular(8),
                  child: SizedBox(
                    height: 16,
                    // Stretch: a childless ColoredBox takes the smallest
                    // height a Row allows, which is 0, so without it no
                    // segment painted (the PmBar and PTO bar bug).
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (final k in keys)
                          if ((weights[k] ?? 0) > 0)
                            Expanded(
                              flex: weights[k]!,
                              child: ColoredBox(color: colour(k)),
                            ),
                        if (total < 100)
                          Expanded(
                            flex: 100 - total,
                            child: ColoredBox(
                              color: theme.colorScheme.surfaceContainerHighest,
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                for (final k in keys)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 2),
                    child: Row(
                      children: [
                        Container(
                          width: 10,
                          height: 10,
                          decoration: BoxDecoration(
                            color: colour(k),
                            borderRadius: BorderRadius.circular(3),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            widget.labels[k]!,
                            style: k == 'conversion' || k == 'quality'
                                ? const TextStyle(fontWeight: FontWeight.w700)
                                : null,
                          ),
                        ),
                        SizedBox(
                          width: 120,
                          child: Slider(
                            key: ValueKey('scoring-weight-slider-$k'),
                            value: (weights[k] ?? 0).clamp(0, 100).toDouble(),
                            max: 100,
                            divisions: 100,
                            onChanged: _saving
                                ? null
                                : (v) => setState(
                                    () => _set({...weights, k: v.round()}),
                                  ),
                          ),
                        ),
                        SizedBox(
                          width: 60,
                          child: TextField(
                            key: ValueKey('scoring-weight-$k'),
                            controller: _fields[k],
                            enabled: !_saving,
                            textAlign: TextAlign.end,
                            keyboardType: TextInputType.number,
                            inputFormatters: [
                              FilteringTextInputFormatter.digitsOnly,
                              LengthLimitingTextInputFormatter(3),
                            ],
                            decoration: const InputDecoration(isDense: true),
                            onChanged: (v) => setState(
                              () => _weights = {
                                ...weights,
                                k: (int.tryParse(v) ?? 0).clamp(0, 100),
                              },
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                const SizedBox(height: 12),
                Align(
                  alignment: Alignment.centerLeft,
                  child: StatusPill(
                    total == 100
                        ? '✓ 100 of 100'
                        : total < 100
                        ? '$total of 100: add ${100 - total}'
                        : '$total of 100: take off ${total - 100}',
                    tone: total == 100 ? Tone.ok : Tone.caution,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'Each is a whole number from 0 to 100. Save waits until they '
                  'add up to exactly 100.',
                  style: muted,
                ),
              ],
            ),
    );
  }
}
