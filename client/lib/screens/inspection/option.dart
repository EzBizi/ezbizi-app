/// One `choice` option, in whichever of the two shapes it arrived in.
///
/// ── Why both shapes, and for how long ────────────────────────────
///
/// Options used to be plain strings. They are objects now — value, label,
/// variant, and any fields the option reveals — but a definition stored before
/// that change keeps its strings until somebody saves it through the Builder,
/// and a live client must not break in between.
///
/// So this reads both, and it is not a temporary shim: a plain string is still
/// a perfectly good way to write an option that has no colour and reveals
/// nothing, and the Builder keeps writing them for simple lists.
library;

class InspectionOption {
  const InspectionOption({
    required this.value,
    required this.label,
    required this.variant,
    required this.fields,
  });

  final String value;
  final String label;

  /// `neutral`, `pass`, `fail`, or `not_applicable`. Drives colour only.
  final String variant;

  /// Fields shown while this option is chosen. Empty for a plain option.
  final List<Map<String, dynamic>> fields;

  static InspectionOption? from(dynamic raw) {
    if (raw is String || raw is num) {
      final value = '$raw'.trim();
      if (value.isEmpty) return null;
      return InspectionOption(
        value: value,
        label: value,
        variant: 'neutral',
        fields: const [],
      );
    }

    if (raw is! Map) return null;

    final value = '${raw['value'] ?? ''}'.trim();
    if (value.isEmpty) return null;

    final label = '${raw['label'] ?? ''}'.trim();

    return InspectionOption(
      value: value,
      // An option with no label shows its value, which is what a plain string
      // option has always done.
      label: label.isEmpty ? value : label,
      variant: '${raw['variant'] ?? 'neutral'}',
      fields: [
        for (final f in (raw['fields'] as List? ?? const []))
          if (f is Map) Map<String, dynamic>.from(f),
      ],
    );
  }

  /// Every option on a field, in order, skipping anything unreadable.
  static List<InspectionOption> allOf(dynamic rawOptions) => [
        for (final o in (rawOptions as List? ?? const [])) ?from(o),
      ];

  /// The fields the chosen answer reveals.
  ///
  /// A multi-choice reveals EVERY chosen option's fields — "cracked bricks" and
  /// "loose mortar" both selected has to show both follow-ups. Mirrors the
  /// server's OptionSet::revealedBy, which is what actually decides whether a
  /// nested answer blocks Submit and whether it is frozen into the report.
  static List<Map<String, dynamic>> revealedBy(
    Map<String, dynamic> field,
    Map<String, dynamic> answers,
  ) {
    if (field['type'] != 'choice') return const [];

    final answer = answers['${field['key'] ?? ''}'];
    if (answer == null) return const [];

    final chosen = answer is List
        ? answer.map((v) => '$v').toList()
        : <String>['$answer'];

    if (chosen.isEmpty || (chosen.length == 1 && chosen.first.isEmpty)) {
      return const [];
    }

    return [
      for (final option in allOf(field['options']))
        if (chosen.contains(option.value)) ...option.fields,
    ];
  }
}
