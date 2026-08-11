import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/contracts.dart';
import '../../core/document_html.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/tab_bar_like.dart';
import 'contract_document_page.dart';

/// Writing the contract, beside the page it makes.
///
/// ── The thing that was missing ───────────────────────────────────
///
/// The owner, looking at the shipped designer:
///
///   *"We are missing something critical which is the contract writing itself,
///    you have the shortcodes but nowhere to put them."*
///
/// They were right. The designer defined the questions and the document was
/// generated from them — headings and a definition list of answers. There was
/// nowhere to write *"This Employment Agreement is made on {{start_date}}
/// between {{business_name}} and {{first_name}} {{last_name}}…"*, which is what
/// an employment contract actually is. The fields were merge tags with no
/// document to merge into.
///
/// So a version carries a `body`, and this is where it is written.
///
/// ── Half and half, and why the right half is white ───────────────
///
///   *"on Contracts it should be 50% on one side and 50% showing the actual
///    contract, and how it looks with white background as that's the color of
///    pages"*
///
/// Both halves are here: the text on the left, the page on the right. The page
/// is white in every theme — the reasoning is written out on
/// [ContractDocumentPage], and it is the one surface in this app that does not
/// follow the theme.
///
/// ── It is a text area, deliberately, and not a rich-text editor ──
///
/// Asked whether it should be one, the answer is no, on four grounds:
///
///  1. THE DOCUMENT HAS NO MARKUP LANGUAGE. What is stored is the body text
///     with tags in it, and the server escapes every character of it when it
///     renders — as it must, because a tenant's contract text is somebody
///     else's input and the finished document is served from this
///     application's own origin. A rich-text editor would produce markup that
///     comes back out as visible angle brackets. Making it work means
///     inventing a document format, teaching the renderer to trust part of it,
///     and picking apart which part — over a file people sign.
///  2. MERGE TAGS HAVE TO SURVIVE EXACTLY. `{{start_date}}` is eleven
///     characters that must arrive at the server unbroken. Every rich editor
///     splits text into styled runs as you type and formats across them; a tag
///     with a bold `date` in the middle is a tag that silently stops
///     resolving, and the person who did it saw no difference on screen.
///  3. NOTHING IS IN PUBSPEC THAT COULD DRAW ONE, and writing one is not a
///     feature-branch decision — it is the same argument DocumentRenderer
///     makes about adding a PDF writer.
///  4. IT IS NOT WHAT IS MISSING. The complaint was that there was nowhere to
///     put the shortcodes. Bold and italic were not the gap, and a toolbar of
///     them would make the gap look filled.
///
/// If a contract ever needs headings and emphasis, the honest way in is a
/// declared markup the renderer owns — not a WYSIWYG surface over a field that
/// is escaped at the far end.
class ContractBodyEditor extends StatefulWidget {
  const ContractBodyEditor({
    super.key,
    required this.api,
    required this.vocabulary,
    required this.body,
    required this.contractId,
    required this.fields,
    required this.payload,
    required this.canEdit,
  });

  final ApiClient api;
  final ContractVocabulary vocabulary;

  /// The document's text. Owned by the designer, because it is part of what
  /// gets saved and the publish panel has to send it.
  final TextEditingController body;

  /// The contract this is being written for, or null while creating one — see
  /// [_render] for what that changes.
  final String? contractId;

  /// The questions as they stand this instant, for the list of insertable tags.
  /// A function rather than a list: somebody adds a question in step 3 and it
  /// has to be insertable here without this widget being rebuilt with new
  /// arguments.
  final List<ContractField> Function() fields;

  /// The same questions in the shape the wire wants, for the preview request.
  /// The designer owns that shape — one place decides what a field looks like
  /// on the wire, and it is not this file.
  final List<Map<String, dynamic>> Function() payload;

  final bool canEdit;

  @override
  State<ContractBodyEditor> createState() => _ContractBodyEditorState();
}

class _ContractBodyEditorState extends State<ContractBodyEditor> {
  /// Long enough that a sentence is not four requests, short enough that the
  /// page moves while somebody is still looking at what they typed.
  static const Duration _settle = Duration(milliseconds: 400);

  /// Below this there is not room for two readable columns — see [build].
  static const double _splitAbove = 900;

  /// Tall enough to write in. The designer is a scrolling page and this is a
  /// fixed region inside it, which is the one shape that lets both halves keep
  /// their own scroll instead of the page growing to the length of the
  /// contract.
  static const double _tall = 620;
  static const double _tallNarrow = 540;

  final FocusNode _writing = FocusNode();

  Timer? _debounce;

  /// Which render owns the pane. Keystrokes overlap requests, and an early
  /// answer arriving late would redraw the page as it was two words ago.
  int _request = 0;

  List<DocumentBlock> _document = const [];
  List<String> _unresolved = const [];
  bool _rendering = false;
  String? _error;

  /// Which half is on screen when there is only room for one.
  bool _previewing = false;

  /// The questions as they stood when the page was last asked for.
  ///
  /// The page does not only depend on the text. `{{overtime_rate}}` is an
  /// unresolvable tag right up until somebody adds the question that answers
  /// it — in step 3, which is a different part of the screen and does not
  /// touch this controller. Without this the pane would keep saying the tag is
  /// broken until the next keystroke in the body, and the person who had just
  /// fixed it would be looking at a stale accusation.
  String _renderedAgainst = '';

  bool get _draft => widget.contractId == null;

  /// Enough of the questions to know whether the page would come back
  /// different: the key a tag names, the wording, the kind of answer, and
  /// whether it is still asked at all — retiring a question is precisely the
  /// act that can take a tag's answer away again.
  ///
  /// Deliberately not the whole payload. The sensitive flag and the order of
  /// the form do not change a word of the document, and re-asking the server
  /// over a reshuffle would be one request per press of an arrow.
  String get _signature => [
    for (final field in widget.payload())
      '${field['key']} ${field['label']} ${field['type']} '
      '${field['retired']}',
  ].join(' ');

  @override
  void initState() {
    super.initState();
    widget.body.addListener(_typed);
    _renderedAgainst = _signature;
    _render();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    widget.body.removeListener(_typed);
    _writing.dispose();
    super.dispose();
  }

  void _typed() {
    _debounce?.cancel();
    _debounce = Timer(_settle, _render);
  }

  /// The page, as the server renders it.
  ///
  /// ── Except while the contract does not exist yet ─────────────
  ///
  /// The preview endpoint is `POST /hr/contracts/{contract}/preview`, and a
  /// contract being created has no id to put in it. So the draft is drawn from
  /// the text itself, with every tag shown AS a tag and nothing merged — which
  /// is honest about what it is, and the pane says so.
  ///
  /// What it deliberately does not do is resolve anything locally. A client
  /// that filled in `{{first_name}}` with a name of its own would be a second
  /// implementation of the merge, agreeing with the document right up until the
  /// day it did not — and the day it did not, somebody would have signed.
  Future<void> _render() async {
    final id = widget.contractId;

    if (id == null) {
      if (!mounted) return;

      setState(() {
        _document = readDraftText(widget.body.text);
        _unresolved = const [];
        _rendering = false;
        _error = null;
      });

      return;
    }

    final request = ++_request;

    setState(() => _rendering = true);

    final response = await widget.api.post('/hr/contracts/$id/preview', {
      'body': widget.body.text,
      'fields': widget.payload(),
    });

    if (!mounted || request != _request) return;

    setState(() {
      _rendering = false;

      if (!response.ok) {
        _error = response.reason;
        return;
      }

      final html = _htmlOf(response.data);

      // Answered, and with nothing this build can find a document in. Said out
      // loud rather than drawn as an empty page: "nothing written yet" over a
      // contract somebody has just written is the one message that would send
      // them looking for lost work.
      if (html.isEmpty && widget.body.text.trim().isNotEmpty) {
        _error =
            'The server answered without a document in it, so there is '
            'nothing to draw here. Your wording is untouched — this pane is '
            'the only thing affected.';
        _document = const [];
        _unresolved = const [];
        return;
      }

      _error = null;
      _document = readDocumentHtml(html);
      _unresolved = _unresolvedIn(response.data, _document);
    });
  }

  /// The document out of the answer.
  ///
  /// Two readings of one thing, for the reason `ContractVocabulary.fromJson`
  /// gives: a wording difference between the two halves of a contract should
  /// not become a blank pane that says nothing about why.
  static String _htmlOf(Object? data) {
    if (data is String) return data;

    if (data is Map) {
      final html = data['html'] ?? data['document'] ?? data['content'];

      if (html is String) return html;
    }

    return '';
  }

  /// Which tags did not resolve.
  ///
  /// The server's own list when it publishes one. Otherwise the tags still
  /// sitting in what it rendered — the same fact read from the other end, since
  /// a tag it resolved is not in the output any more. Neither branch decides
  /// what CAN resolve; that is the server's list and it never appears here.
  static List<String> _unresolvedIn(Object? data, List<DocumentBlock> document) {
    if (data is Map && data['unresolved'] is List) {
      return [
        for (final entry in data['unresolved'] as List)
          if (entry is String)
            entry
          else if (entry is Map && entry['key'] is String)
            entry['key'] as String,
      ];
    }

    return tagsIn(document);
  }

  // ── inserting one ────────────────────────────────────────────────

  /// The whole point of the exercise: a tag lands where the cursor is.
  ///
  /// Where the cursor IS, not on the end. Somebody who has just written "made
  /// on  between the parties" and put the caret in the gap means the gap.
  ///
  /// An untouched box has no cursor at all — `selection` is offset -1 — and
  /// then the end of the text is the only sensible place, rather than throwing
  /// a range error over somebody clicking a chip first.
  void _insert(String key) {
    final tag = '{{$key}}';
    final value = widget.body.value;
    final selection = value.selection;

    final start = selection.isValid ? selection.start : value.text.length;
    final end = selection.isValid ? selection.end : value.text.length;

    widget.body.value = TextEditingValue(
      text: value.text.replaceRange(start, end, tag),
      selection: TextSelection.collapsed(offset: start + tag.length),
    );

    // Straight back to writing. Clicking a chip should feel like typing the
    // tag, and typing does not lose the caret.
    _writing.requestFocus();
  }

  // ── the two halves ───────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    // A question changed since the last render, so the page is stale — see
    // [_renderedAgainst]. Scheduling the same debounce a keystroke schedules,
    // rather than rendering here: nothing about a build may set state, and a
    // timer does not.
    final signature = _signature;

    if (signature != _renderedAgainst) {
      _renderedAgainst = signature;
      _typed();
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        // R7. Half and half is a DESKTOP layout: a 50/50 split at 375px is two
        // unusable columns, so below the threshold the two halves become one
        // pane with a control that says which you are looking at. Only the
        // chosen half is built — there is no hidden second column off screen.
        final wide = constraints.maxWidth >= _splitAbove;

        return SizedBox(
          height: wide ? _tall : _tallNarrow,
          child: DecoratedBox(
            decoration: BoxDecoration(
              border: Border.all(color: EzBiziTheme.edge(context)),
              borderRadius: BorderRadius.circular(12),
            ),
            child: ClipRRect(
              // The page runs to the edge of its half, so the corner has to be
              // cut or the white squares off the rounded border.
              borderRadius: BorderRadius.circular(11),
              child: wide ? _split(context) : _narrow(context),
            ),
          ),
        );
      },
    );
  }

  Widget _split(BuildContext context) => Row(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      // flex 1 and flex 1. Half and half, as asked for.
      Expanded(child: _writingHalf(context)),
      VerticalDivider(width: 1, thickness: 1, color: EzBiziTheme.edge(context)),
      Expanded(child: _pageHalf(context)),
    ],
  );

  Widget _narrow(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      TabBarLike(
        labels: const [
          ('Write it', Icons.edit_outlined),
          ('The page', Icons.description_outlined),
        ],
        selected: _previewing ? 1 : 0,
        onSelected: (index) => setState(() => _previewing = index == 1),
      ),
      Divider(height: 1, color: EzBiziTheme.edge(context)),
      Expanded(
        child: _previewing ? _pageHalf(context) : _writingHalf(context),
      ),
    ],
  );

  // ── the left half: the tags, then the writing ────────────────────

  Widget _writingHalf(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (widget.canEdit) ...[
          _tags(context),
          Divider(height: 1, color: EzBiziTheme.edge(context)),
        ],
        Expanded(
          child: TextField(
            controller: widget.body,
            focusNode: _writing,
            // Read-only rather than DISABLED, which is the difference between
            // a reader who can scroll a four-page contract and select a clause
            // out of it and one who is handed a greyed box they cannot even
            // move. The caret is hidden with it, so nothing invites typing
            // that would go nowhere; the palette above is gone for the same
            // reason.
            readOnly: !widget.canEdit,
            showCursor: widget.canEdit,
            expands: true,
            maxLines: null,
            textAlignVertical: TextAlignVertical.top,
            keyboardType: TextInputType.multiline,
            style: Theme.of(
              context,
            ).textTheme.bodyMedium?.copyWith(height: 1.6, fontSize: 14),
            decoration: const InputDecoration(
              // A page to write on, not a form control: the app's boxed,
              // filled input styling around a five-hundred-word document makes
              // it look like a comment field.
              filled: false,
              border: InputBorder.none,
              enabledBorder: InputBorder.none,
              focusedBorder: InputBorder.none,
              disabledBorder: InputBorder.none,
              contentPadding: EdgeInsets.fromLTRB(18, 16, 18, 24),
              hintText:
                  'This Employment Agreement is made on {{start_date}} '
                  'between…\n\n'
                  'Write the contract here. Click any of the fields above to '
                  'drop it in where the cursor is.',
              hintMaxLines: 6,
            ),
          ),
        ),
      ],
    );
  }

  /// Everything that can be dropped into the text, grouped by where it comes
  /// from.
  ///
  /// Three groups, and not one of them is a list in Dart: the contract's own
  /// questions are the ones on this screen, the common core and the
  /// always-available facts both arrive from `GET /hr/contracts/catalogue`. A
  /// server that starts publishing a fourth fact offers it here the same day —
  /// and a build that invented `{{organization_name}}` because everybody knows
  /// it exists would put a tag in an employment contract that renders as
  /// itself.
  Widget _tags(BuildContext context) {
    final theme = Theme.of(context);

    final own = [
      for (final field in widget.fields())
        if (!field.retired && field.key.isNotEmpty)
          (field.key, field.label.isEmpty ? field.key : field.label, null),
    ];

    final core = [
      for (final field in widget.vocabulary.core)
        (field.key, field.label, field.note),
    ];

    final facts = [
      for (final tag in widget.vocabulary.documentTags)
        (tag.key, tag.label, tag.note),
    ];

    return ConstrainedBox(
      // Capped and scrolling. A contract with thirty questions would otherwise
      // push the writing surface off the bottom of the region.
      constraints: const BoxConstraints(maxHeight: 152),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (own.isEmpty && core.isEmpty && facts.isEmpty)
              Text(
                'Nothing to insert yet. Add a question in step 3 and it '
                'becomes a tag you can drop into the wording here.',
                style: theme.textTheme.bodySmall,
              ),
            _group(context, 'This contract asks', own),
            _group(context, 'Always asked', core),
            _group(context, 'The business and the date', facts),
          ],
        ),
      ),
    );
  }

  Widget _group(
    BuildContext context,
    String title,
    List<(String, String, String?)> tags,
  ) {
    if (tags.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final (key, label, note) in tags)
                Tooltip(
                  // The key, because that is what lands in the text and
                  // somebody re-reading their own contract needs to recognise
                  // it. The server's sentence under it where there is one.
                  message: note == null ? '{{$key}}' : '{{$key}}\n$note',
                  child: ActionChip(
                    label: Text(label),
                    avatar: const Icon(Icons.data_object, size: 15),
                    onPressed: () => _insert(key),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }

  // ── the right half: the page ─────────────────────────────────────

  Widget _pageHalf(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _pageChrome(context),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Expanded(
          // The white starts here and runs to the edges — see
          // ContractDocumentPage for why it is white in both themes. The
          // ColoredBox is under the scroll view as well as inside it, so
          // over-scrolling does not flash the theme's surface behind the page.
          child: ColoredBox(
            color: ContractDocumentPage.paper,
            child: SingleChildScrollView(
              child: ContractDocumentPage(
                blocks: _document,
                tagNote: _draft
                    ? 'A merge tag. Nothing is filled in yet — create the '
                          'contract and the page here fills in with sample '
                          'answers.'
                    : 'This tag did not resolve, so the finished contract '
                          'would carry it exactly like this.',
                placeholder: _draft
                    ? 'Nothing written yet. What you type on the left appears '
                          'here as you go.'
                    : 'Nothing written yet. Whatever you type on the left is '
                          'rendered here with sample answers, exactly as the '
                          'page will look.',
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// The strip above the page: what it is, and anything wrong with it.
  ///
  /// Chrome, so it follows the theme like everything else. The page below it
  /// does not, and that line between them is the boundary.
  Widget _pageChrome(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  _draft
                      ? 'Your wording, not yet merged. Sample answers appear '
                            'here once the contract exists.'
                      : 'The page, with sample answers merged in by the '
                            'server.',
                  style: theme.textTheme.bodySmall,
                ),
              ),
              if (_rendering) ...[
                const SizedBox(width: 10),
                const SizedBox(
                  width: 14,
                  height: 14,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ],
            ],
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            ErrorNote(_error!),
          ],
          if (_error == null && !_draft && _unresolved.isNotEmpty) ...[
            const SizedBox(height: 8),
            _unresolvedNote(context),
          ],
        ],
      ),
    );
  }

  /// The tags that did not resolve, named, while somebody is typing.
  ///
  /// It is here rather than only on publish because a mistyped tag is a
  /// mistyped tag the moment it is typed, and finding out at the confirmation
  /// panel means going back through a document to look for it. They are marked
  /// in the page itself too — this says how many and which, the page says
  /// where.
  Widget _unresolvedNote(BuildContext context) {
    final theme = Theme.of(context);
    final one = _unresolved.length == 1;

    return Container(
      padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.6),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.warning_amber_rounded, size: 16),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              one
                  ? 'Nothing answers {{${_unresolved.first}}}, so it stays in '
                        'the contract exactly as written. Check the spelling, '
                        'or insert it from the list on the left.'
                  : '${_unresolved.length} tags have nothing to fill them in: '
                        '${_unresolved.map((key) => '{{$key}}').join(', ')}. '
                        'They stay in the contract exactly as written.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        ],
      ),
    );
  }
}
