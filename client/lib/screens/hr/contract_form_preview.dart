import 'package:flutter/material.dart';

import '../../core/contracts.dart';
import '../../core/timestamps.dart';
import '../../widgets/tag.dart';

/// The form each side will actually be asked to fill in.
///
/// ── Why the designer has this at all ─────────────────────────────
///
/// The owner's whole model is that the Contracts screen defines the onboarding
/// form. Being unable to see that form from here means designing it blind: a
/// list of field cards tells you what you configured, and says nothing about
/// what the person you are hiring will meet.
///
/// ── Why it is also how `filled_by` is made obvious ───────────────
///
/// `filled_by` is the property that decides who sees what, and it is the one a
/// person will get wrong. A column of dropdowns saying "employee" tells you the
/// value; it does not tell you that your new starter is being asked for the
/// wage you meant to set yourself. Drawing the two forms separately makes a
/// misplaced question something you SEE rather than something you read — a
/// question in the wrong list is obvious in a way a wrong dropdown never is.
///
/// A field marked `either` appears in BOTH forms, marked, because that is
/// literally what it does.
///
/// ── The controls are drawn from the type, in Dart ────────────────
///
/// A server cannot send a widget. This is the same carve-out `moduleScreens`
/// takes for an `IconData`: which control draws a `date` is code, and the LABEL
/// of the type, the list of types, and everything about the field itself came
/// from the server. A type this build has never met falls back to a plain box
/// and says so, rather than being dropped from a preview whose whole job is to
/// be complete.
class ContractFormPreview extends StatefulWidget {
  const ContractFormPreview({
    super.key,
    required this.vocabulary,
    required this.fields,
    required this.formats,
  });

  final ContractVocabulary vocabulary;

  /// Which box is first on a date question — the organization's `date_format`.
  final DisplayFormats formats;

  /// The template's questions in form order. Retired ones are filtered out
  /// here, because "what will they be asked" is exactly the question retiring
  /// answers.
  final List<ContractField> fields;

  @override
  State<ContractFormPreview> createState() => _ContractFormPreviewState();
}

class _ContractFormPreviewState extends State<ContractFormPreview> {
  /// Opens on the employee's side. The plan asks for "a preview of what the
  /// employee will actually be asked", and it is also the side whose form the
  /// designer cannot otherwise see — the employer's half is the one they are
  /// standing in.
  String _side = filledByEmployee;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          children: [
            for (final side in [filledByEmployee, filledByEmployer])
              ChoiceChip(
                label: Text(_sideTitle(side)),
                selected: _side == side,
                onSelected: (_) => setState(() => _side = side),
              ),
          ],
        ),
        const SizedBox(height: 14),
        Container(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 20),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest.withValues(
              alpha: 0.35,
            ),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: _questions(context),
          ),
        ),
      ],
    );
  }

  String _sideTitle(String side) =>
      side == filledByEmployee ? 'What they are asked' : 'What you fill in';

  List<Widget> _questions(BuildContext context) {
    final theme = Theme.of(context);

    final core = [
      for (final field in widget.vocabulary.core)
        // The catalogue's reserved keys name no side today, and a core field
        // with no stated side is asked of BOTH — which is what the plan
        // describes: the employer may fill these in when inviting and does not
        // have to, and whatever is left blank the person completes. The side is
        // honoured where one is sent, so the day the catalogue starts saying
        // which, this stops guessing rather than needing changing.
        if (field.filledBy == null ||
            field.filledBy == _side ||
            field.filledBy == filledByEither)
          field,
    ];

    final own = [
      for (final field in widget.fields)
        if (!field.retired &&
            (field.filledBy == _side || field.filledBy == filledByEither))
          field,
    ];

    if (core.isEmpty && own.isEmpty) {
      return [
        Text(
          _side == filledByEmployee
              ? 'They are asked nothing at all. Every question on this contract '
                    'is one you fill in yourself.'
              : 'You fill in nothing. Every question on this contract is theirs '
                    'to answer.',
          style: theme.textTheme.bodyMedium,
        ),
      ];
    }

    return [
      if (core.isNotEmpty) ...[
        _heading(context, 'Always asked'),
        for (final field in core) _coreQuestion(context, field),
        if (own.isNotEmpty) const SizedBox(height: 22),
      ],
      if (own.isNotEmpty) ...[
        _heading(context, 'This contract'),
        for (final field in own) _question(context, field),
      ],
    ];
  }

  Widget _heading(BuildContext context, String text) => Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: Text(
      text.toUpperCase(),
      style: Theme.of(context).textTheme.labelSmall?.copyWith(
        letterSpacing: 1.1,
        color: Theme.of(context).colorScheme.onSurfaceVariant,
      ),
    ),
  );

  Widget _coreQuestion(BuildContext context, CoreField field) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Flexible(
                child: Text(
                  field.label,
                  style: Theme.of(context).textTheme.titleSmall,
                ),
              ),
              if (field.filledBy == filledByEither) const Tag('Either of you'),
            ],
          ),
          const SizedBox(height: 6),
          _control(context, field.type ?? 'text', const []),
        ],
      ),
    );
  }

  Widget _question(BuildContext context, ContractField field) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Flexible(
                child: Text(
                  field.required ? '${field.label} *' : field.label,
                  style: Theme.of(context).textTheme.titleSmall,
                ),
              ),
              // The one thing a side's own form cannot show by position: that
              // the other side may have answered it already.
              if (field.filledBy == filledByEither) const Tag('Either of you'),
              if (field.sensitive) const Tag('Sensitive'),
            ],
          ),
          const SizedBox(height: 6),
          _control(context, field.type, field.options),
        ],
      ),
    );
  }

  /// A dead control that looks like the real one. Never enabled: this is a
  /// picture of a form, and something a person could type into here would be
  /// typing into nothing.
  Widget _control(BuildContext context, String type, List<String> options) {
    final theme = Theme.of(context);

    if (!widget.vocabulary.knowsType(type)) {
      return _box(
        context,
        Text(
          'This build does not know how “$type” is asked, so the real form may '
          'not look like this box.',
          style: theme.textTheme.bodySmall,
        ),
      );
    }

    return switch (type) {
      'rate_steps' => _box(
        context,
        Text(
          'After N days, a new hourly rate',
          style: theme.textTheme.bodyMedium,
        ),
      ),
      'checklist' => _box(
        context,
        Text(
          options.isEmpty ? 'No items yet' : '${options.length} items, quantity and date',
          style: theme.textTheme.bodyMedium,
        ),
      ),
      'choice' => _box(
        context,
        Row(
          children: [
            Expanded(
              child: Text(
                options.isEmpty ? 'Nothing to choose from yet' : options.first,
                style: theme.textTheme.bodyMedium,
              ),
            ),
            const Icon(Icons.arrow_drop_down, size: 20),
          ],
        ),
      ),
      'date' => Row(
        children: [
          for (var i = 0; i < widget.formats.dateParts.length; i++) ...[
            if (i > 0)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: Text(
                  widget.formats.dateFormat == 'YYYY-MM-DD' ? '-' : '/',
                  style: theme.textTheme.bodyMedium,
                ),
              ),
            _mini(context, switch (widget.formats.dateParts[i]) {
              DatePart.month => 'MM',
              DatePart.day => 'DD',
              DatePart.year => 'YYYY',
            }),
          ],
          const SizedBox(width: 4),
          Icon(
            Icons.calendar_today_outlined,
            size: 16,
            color: theme.colorScheme.outline,
          ),
        ],
      ),
      'file' => OutlinedButton.icon(
        onPressed: null,
        icon: const Icon(Icons.upload_file_outlined, size: 18),
        label: const Text('Choose a file'),
      ),
      'signature' => Container(
        height: 64,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          border: Border.all(color: theme.colorScheme.outlineVariant),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text('Sign here', style: theme.textTheme.bodySmall),
      ),
      _ => _box(context, const SizedBox(height: 20)),
    };
  }

  Widget _mini(BuildContext context, String hint) => Container(
    width: 56,
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.surface,
      border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
      borderRadius: BorderRadius.circular(8),
    ),
    child: Text(hint, style: Theme.of(context).textTheme.bodySmall),
  );

  Widget _box(BuildContext context, Widget child) => Container(
    width: double.infinity,
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.surface,
      border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
      borderRadius: BorderRadius.circular(8),
    ),
    child: child,
  );
}
