import 'package:flutter/material.dart';

import '../../core/contracts.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';

/// What publishing will change, shown before it commits.
///
/// ── Why publishing is not just a button ──────────────────────────
///
/// A contract template is the schema for onboarding. Publishing decides what
/// the next new starter is asked, who is asked it, and what merges into a
/// document somebody signs — and it affects everybody onboarded afterwards,
/// nobody before. "Save" tells the person clicking it none of that. This panel
/// is the difference between an explicit act and a reflex.
///
/// ── Every line here came from the server ─────────────────────────
///
/// The diff is not computed in Dart. The client holds both field lists and
/// could work it out, and then the warning somebody reads and the version that
/// actually gets written would come from two implementations — which agree
/// right up until one of them gains a property. So the designer asks the server
/// to preview its own publish, and this draws the answer. The preview and the
/// publish are the same code path on the server, stopped one line apart.
///
/// The rename is the one line this panel knows by itself, because renaming the
/// template is not part of the version at all — it is a separate PATCH, and it
/// is shown here so that one confirmation covers everything the button is about
/// to do.
class ContractDiffPanel extends StatelessWidget {
  const ContractDiffPanel({
    super.key,
    required this.vocabulary,
    required this.diff,
    required this.version,
    required this.renamedFrom,
    required this.busy,
    required this.error,
    required this.onConfirm,
    this.cover,
  });

  final ContractVocabulary vocabulary;

  /// Null while the preview is still in flight, or when it failed — [error]
  /// says which.
  final ContractDiff? diff;

  /// The number the new version will be given, as the server counted it.
  final int? version;

  /// The template's previous name, when this publish also renames it.
  final String? renamedFrom;

  final bool busy;
  final String? error;

  /// Runs the publish. Null when there is nothing this panel could commit.
  final VoidCallback? onConfirm;

  /// The server's cover answer, `{changed, from, to}` (full-code audit,
  /// 2026-11-27: a cover-only change, or a removed cover, showed nothing).
  final Map<String, dynamic>? cover;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final diff = this.diff;

    final nothingToVersion = diff != null && diff.isEmpty;
    final renameOnly = nothingToVersion && renamedFrom != null;

    return SidePanel(
      title: 'Publish version ${version ?? '…'}',
      subtitle:
          'It applies to everybody onboarded from now on. Contracts already '
          'signed keep the version that produced them, and nothing about them '
          'changes.',
      width: 560,
      actions: [
        TextButton(
          onPressed: busy ? null : () => Navigator.of(context).pop(),
          child: Text(onConfirm == null ? 'Close' : 'Cancel'),
        ),
        if (onConfirm != null)
          FilledButton(
            onPressed: busy ? null : onConfirm,
            child: Text(
              busy
                  ? 'Publishing…'
                  : renameOnly
                  ? 'Save the new name'
                  : 'Publish it',
            ),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          if (error != null) ...[ErrorNote(error!), const SizedBox(height: 16)],
          if (diff == null && error == null)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Center(child: CircularProgressIndicator()),
            ),
          if (cover?['changed'] == true) ...[
            Text(
              'Front page: ${_coverWord(cover!['from'])} → ${_coverWord(cover!['to'])}',
              key: const ValueKey('contract-diff-cover'),
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 12),
          ],
          if (renamedFrom != null) ...[
            _section(context, 'Renamed', [
              _line(
                context,
                Icons.drive_file_rename_outline,
                'From “$renamedFrom”.',
                null,
              ),
            ]),
          ],
          if (diff != null) ...[
            if (diff.isEmpty)
              Text(
                renamedFrom == null
                    ? 'Nothing has changed, so there is no new version to '
                          'publish. A version identical to the one before it '
                          'would only make the history harder to read.'
                    : 'The name is the only thing that changed. That is not a '
                          'new version — the questions are the version, and '
                          'these are the same ones.',
                style: theme.textTheme.bodyMedium,
              ),
            // First, because it is the contract. Every other line here is
            // about a question on a form; this one is about the words somebody
            // signs, and it should not arrive underneath a reorder.
            if (diff.bodyChanged)
              _section(context, 'The wording', [
                _line(
                  context,
                  Icons.article_outlined,
                  // Named, because `removed` is not a smaller `edited`: it drops
                  // the document back to the generated list of answers, which is
                  // a different document rather than a reworded one.
                  switch (diff.bodyChange) {
                    'added' => 'The contract now has wording of its own.',
                    'removed' =>
                      'The wording has been removed, so this contract goes back '
                          'to listing the answers rather than reading as an '
                          'agreement.',
                    _ => 'The text of the contract itself has changed.',
                  },
                  'Everybody onboarded from now on signs the new wording. '
                      'Contracts already signed keep the words they were '
                      'signed under, which is what the version is for.',
                ),
              ]),
            if (diff.added.isNotEmpty)
              _section(context, 'Added', [
                for (final field in diff.added)
                  _line(
                    context,
                    Icons.add_circle_outline,
                    field.label,
                    _summary(field),
                  ),
              ]),
            if (diff.retired.isNotEmpty)
              _section(context, 'Retired', [
                for (final entry in diff.retired)
                  _line(
                    context,
                    Icons.remove_circle_outline,
                    entry.label,
                    'Stops being asked. Records that already carry an answer '
                    'keep it, and keep showing it.',
                  ),
              ]),
            if (diff.unretired.isNotEmpty)
              _section(context, 'Asked again', [
                for (final entry in diff.unretired)
                  _line(
                    context,
                    Icons.restart_alt,
                    entry.label,
                    'Back on the form for everybody onboarded from now on.',
                  ),
              ]),
            if (diff.changed.isNotEmpty)
              _section(context, 'Changed', [
                for (final field in diff.changed)
                  _line(
                    context,
                    Icons.edit_outlined,
                    field.label,
                    _changes(field),
                  ),
              ]),
            if (diff.reordered)
              _section(context, 'Reordered', [
                _line(
                  context,
                  Icons.swap_vert,
                  'The questions are asked in a different order.',
                  null,
                ),
              ]),
          ],
        ],
      ),
    );
  }

  /// "Text · The employee · has to be answered" — the field's own properties,
  /// in the server's words for each of them.
  String _summary(ContractField field) => [
    vocabulary.typeLabel(field.type),
    vocabulary.filledByLabel(field.filledBy),
    if (field.required) 'has to be answered',
    if (field.sensitive) 'sensitive',
  ].join(' · ');

  /// One line per property that moved, each reading "Who fills it in: The
  /// employer → The employee".
  ///
  /// The values are put through the catalogue rather than printed raw, so the
  /// panel says "One of a list" where the wire says `choice`. A value the
  /// catalogue has no word for prints as it arrived, which is the same answer
  /// [ContractVocabulary.typeLabel] gives everywhere else.
  String _changes(ChangedField field) => [
    for (final entry in field.properties.entries)
      '${fieldPropertyLabel(entry.key)}: '
          '${_value(entry.key, entry.value.$1)} → '
          '${_value(entry.key, entry.value.$2)}',
  ].join('\n');

  String _value(String property, Object? value) => switch (property) {
    'type' => vocabulary.typeLabel('$value'),
    'filled_by' => vocabulary.filledByLabel('$value'),
    'required' => value == true ? 'yes' : 'no',
    'sensitive' => value == true ? 'yes' : 'no',
    'options' => value is List ? value.join(', ') : '$value',
    _ => '$value',
  };

  Widget _section(BuildContext context, String title, List<Widget> lines) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            title.toUpperCase(),
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
              letterSpacing: 1.1,
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 8),
          ...lines,
        ],
      ),
    );
  }

  Widget _line(
    BuildContext context,
    IconData icon,
    String title,
    String? detail,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Icon(icon, size: 17),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.titleSmall),
                if (detail != null && detail.isNotEmpty)
                  Text(detail, style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

String _coverWord(Object? source) => switch (source) {
  'logo' => 'your logo',
  'file' => 'an uploaded image',
  _ => 'none',
};
