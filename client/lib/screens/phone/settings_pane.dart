import 'package:flutter/material.dart';

import '../../widgets/error_note.dart';

/// The three parts every Phone Settings pane has (approved layout 1).
///
/// ── Why this is a widget and not a convention ────────────────────
///
/// Four of the thirteen panes had no error recovery at all. When their load
/// failed they showed the failure and offered nothing to do about it, so the
/// only way out was to leave Settings and come back. Nobody decided that — it
/// is what happens when every pane builds its own chrome and the retry is
/// something the author has to remember.
///
/// Putting the head here means a pane inherits retry BY CONSTRUCTION: pass an
/// `error` and an `onRetry` and the control is there. A pane that can fail and
/// cannot recover is now something you would have to write on purpose.
///
/// ── The footer is the load-bearing half ──────────────────────────
///
/// It appears only when a pane has something to save, so its ABSENCE is how you
/// tell a section you read from a section you change. Call flow and Cost have
/// none, and that is the signal rather than an omission — which is why this
/// takes a nullable `footer` rather than a `readOnly` flag that could be left
/// at its default and quietly draw a Save button on a report.
///
/// It also pins, which a trailing Save button inside a scroll view cannot. On a
/// phone a Save at the end of a long form is below the fold on every visit, and
/// "Two changes not saved" is invisible exactly when it matters most.
class SettingsPane extends StatelessWidget {
  const SettingsPane({
    super.key,
    required this.title,
    required this.subtitle,
    this.children = const [],
    this.body,
    this.error,
    this.onRetry,
    this.trailing,
    this.footer,
    this.leading,
  });

  final String title;
  final String subtitle;

  /// A scrolling body, for a pane that is one column of sections.
  final List<Widget> children;

  /// A body that manages its own height and scrolling, for a pane that is a
  /// LIST BESIDE A DETAIL.
  ///
  /// The default body is a ListView, and two independently scrolling columns
  /// cannot live inside one — they would be laid out with unbounded height and
  /// throw. So a split pane replaces the body rather than filling it, and
  /// takes responsibility for scrolling each side itself.
  final Widget? body;

  /// The failure to show, or null. Paired with [onRetry] it draws the control.
  final String? error;
  final VoidCallback? onRetry;

  /// A pane-level action that is not a save — "New mailbox", and the like.
  final Widget? trailing;

  /// Present only when this pane can be saved. See the note above.
  final Widget? footer;

  /// A back control, for a pane showing one record out of a list.
  final Widget? leading;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 24, 24, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (leading != null) ...[leading!, const SizedBox(width: 4)],
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(title, style: theme.textTheme.titleMedium),
                        const SizedBox(height: 4),
                        Text(subtitle, style: theme.textTheme.bodySmall),
                      ],
                    ),
                  ),
                  if (trailing != null) ...[
                    const SizedBox(width: 12),
                    // Flexible, so the trailing arrives with a BOUNDED width.
                    //
                    // As a bare child it was handed `maxWidth: infinity`, and
                    // a trailing that contains an Expanded then either throws
                    // in debug or, in release, quietly takes the whole row and
                    // leaves the title a few pixels wide. Numbers shipped
                    // exactly that. Flexible is loose by default, so a trailing
                    // that sizes itself is unaffected — this only puts a
                    // ceiling on one that does not.
                    Flexible(child: trailing!),
                  ],

                  // Beside the title rather than under the failure, so its
                  // position does not depend on how long the message is.
                  if (error != null && onRetry != null) ...[
                    const SizedBox(width: 8),
                    TextButton.icon(
                      icon: const Icon(Icons.refresh, size: 18),
                      label: const Text('Try again'),
                      onPressed: onRetry,
                    ),
                  ],
                ],
              ),
              if (error != null) ...[
                const SizedBox(height: 12),
                ErrorNote(error!),
              ],
            ],
          ),
        ),
        Expanded(
          child:
              body ??
              ListView(
                padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                children: children,
              ),
        ),
        ?footer,
      ],
    );
  }
}

/// One bordered group inside a pane's body.
///
/// Bordered rather than a hairline because what sits inside these is often a
/// SET — mailboxes, groups, tags — and a set with no edge to contain it turns a
/// long pane into an undifferentiated column.
class SettingsSection extends StatelessWidget {
  const SettingsSection({
    super.key,
    required this.title,
    required this.children,
    this.note,
  });

  final String title;
  final List<Widget> children;

  /// A short status for the group — "Recorded 12 Aug".
  final String? note;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
            child: Row(
              children: [
                Expanded(
                  child: Text(title, style: theme.textTheme.titleSmall),
                ),
                if (note != null) Text(note!, style: theme.textTheme.bodySmall),
              ],
            ),
          ),
          Divider(height: 1, color: theme.colorScheme.outlineVariant),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: children,
            ),
          ),
        ],
      ),
    );
  }
}

/// The pinned footer: what is unsaved, and the two things you can do about it.
class SettingsPaneFooter extends StatelessWidget {
  const SettingsPaneFooter({
    super.key,
    required this.unsaved,
    required this.onSave,
    required this.onDiscard,
    this.saving = false,
    this.discardLabel = 'Discard',
  });

  /// Plain names of what has changed — "greeting", "where messages go".
  ///
  /// Names rather than a bare count, because a count tells somebody they have
  /// unsaved work without telling them what it is, and on a pane with three
  /// sections that is most of the question.
  final List<String> unsaved;

  final VoidCallback? onSave;
  final VoidCallback onDiscard;
  final bool saving;
  final String discardLabel;

  static const _words = [
    'No',
    'One',
    'Two',
    'Three',
    'Four',
    'Five',
    'Six',
    'Seven',
    'Eight',
    'Nine',
  ];

  /// Exposed so a test can assert the sentence without building a frame.
  static String describe(List<String> unsaved) {
    if (unsaved.isEmpty) return 'Everything saved';

    final count = unsaved.length < _words.length
        ? _words[unsaved.length]
        : '${unsaved.length}';

    final noun = unsaved.length == 1 ? 'change' : 'changes';

    final list = switch (unsaved.length) {
      1 => unsaved.first,
      2 => '${unsaved[0]} and ${unsaved[1]}',
      _ =>
        '${unsaved.sublist(0, unsaved.length - 1).join(', ')}, '
            'and ${unsaved.last}',
    };

    return '$count $noun not saved — $list';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dirty = unsaved.isNotEmpty;

    return Container(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 12),
      decoration: BoxDecoration(
        border: Border(
          top: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              dirty ? describe(unsaved) : 'Everything saved',
              style: theme.textTheme.bodySmall?.copyWith(
                // Attention, not alarm. Unsaved work is not an error, and
                // colouring it like one would spend the vocabulary that
                // "this line reaches nobody" needs.
                color: dirty ? theme.colorScheme.tertiary : null,
                fontWeight: dirty ? FontWeight.w600 : null,
              ),
            ),
          ),
          const SizedBox(width: 12),
          TextButton(
            onPressed: dirty && !saving ? onDiscard : null,
            child: Text(discardLabel),
          ),
          const SizedBox(width: 8),
          FilledButton(
            onPressed: onSave == null || saving ? null : onSave,
            child: Text(saving ? 'Saving…' : 'Save'),
          ),
        ],
      ),
    );
  }
}
