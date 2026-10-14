import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/contracts.dart';
import '../../core/document_html.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
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
/// ── Half the TAB, not half of a step ─────────────────────────────
///
///   *"I specifically told you to do 50% each, as in the whole tab, not inside
///    the form […] on one side of the tab it should be the form editor, and on
///    the other side of the tab the contract"*
///
/// The first build put both halves inside step 4 of a stacked form, so the
/// document was a 620px panel between two form sections — a preview widget,
/// which is not what was asked for and reads as one. The split belongs to the
/// screen: the whole designer down the left, the whole page down the right, and
/// the page in view the entire time somebody is naming the contract or adding a
/// question, not only while they are scrolled to the wording.
///
/// That is why this file is three pieces rather than one widget. The two halves
/// are no longer siblings — the writing surface sits inside the left column's
/// scroll view and the page is a full-height pane beside it — so the render
/// state cannot live in a common ancestor widget. [ContractPageController] owns
/// it, and both halves attach to the same instance.
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
class ContractPageController extends ChangeNotifier {
  ContractPageController({
    required this.api,
    required this.vocabulary,
    required this.body,
    required this.contractId,
    required this.payload,
    this.resolvable = const [],
  }) {
    body.addListener(_typed);
    _renderedAgainst = _signature;
    _render();
  }

  final ApiClient api;

  /// Held for one thing only: the delimiters a merge tag is written between,
  /// so the page can mark one without `{{` being written in Dart.
  final ContractVocabulary vocabulary;

  TagDelimiters get _delimiters =>
      TagDelimiters(vocabulary.mergeTags.open, vocabulary.mergeTags.close);

  /// The document's text. Owned by the designer, because it is part of what
  /// gets saved and the publish panel has to send it.
  final TextEditingController body;

  /// The contract this is being written for, or null while creating one — see
  /// [_render] for what that changes.
  final String? contractId;

  /// The questions in the shape the wire wants, for the preview request. The
  /// designer owns that shape — one place decides what a field looks like on
  /// the wire, and it is not this file. A function rather than a list:
  /// somebody adds a question in step 3 and the page has to follow without
  /// this object being rebuilt with new arguments.
  final List<Map<String, dynamic>> Function() payload;

  /// Long enough that a sentence is not four requests, short enough that the
  /// page moves while somebody is still looking at what they typed.
  static const Duration _settle = Duration(milliseconds: 400);

  /// Focus for the writing surface, held here because [insert] has to give it
  /// back and the chip that calls [insert] is drawn by the other half.
  final FocusNode writing = FocusNode();

  Timer? _debounce;

  /// Which render owns the pane. Keystrokes overlap requests, and an early
  /// answer arriving late would redraw the page as it was two words ago.
  int _request = 0;

  List<DocumentBlock> document = const [];
  bool rendering = false;
  String? error;

  /// The page below the error is from BEFORE the change that was refused.
  ///
  /// ── Why the stale page is kept and labelled, not cleared ─────
  ///
  /// `preview()` validates the body before it renders a character of it, so a
  /// mistyped tag comes back as a refusal naming the tag and there is no
  /// document in the answer at all. This used to set the error and return,
  /// leaving the last good page on screen with nothing saying it was no longer
  /// what the text says — the one state a preview must never be in, because the
  /// whole promise of the pane is "this is your contract".
  ///
  /// Clearing it instead would be worse in the common case: a tag is mistyped
  /// halfway through writing, and blanking four pages of document over one
  /// keystroke loses the place somebody was reading. So it stays, and it says
  /// what it is.
  bool stale = false;

  /// Every tag that resolves for the questions as they stand — the server's own
  /// list, out of `merge_tags`.
  ///
  /// This is `MergeTags::resolvable()`, which is the registry. The preview
  /// answer carries it and so re-states it on every render, because every
  /// render sends the current field list: adding a question in step 3 puts its
  /// tag in the picker on the same round trip that re-renders the page.
  ///
  /// It STARTS from `GET /hr/contracts/{contract}`, which publishes the same
  /// registry for the version in force and is the response the designer opened
  /// with. That mattered more than it looks. Before it was read, this was empty
  /// until the first preview answered, and an empty list is what makes
  /// [ContractWriting] assemble the own-question chips in Dart out of the
  /// questions on screen — the four server rules restated on this side that
  /// [MergeTag] exists to keep off it. The gap is a few hundred milliseconds
  /// when the preview succeeds and PERMANENT when it is refused, which is
  /// precisely when somebody is reaching for a chip to fix the tag that was
  /// refused.
  ///
  /// Empty only on the create path, where there is neither a contract to
  /// preview against nor one to have loaded — see [ContractWriting] for what is
  /// offered then and why it is not the same thing.
  List<MergeTag> resolvable;

  /// Which version this draft would BE, from the preview's `version`.
  ///
  /// Published so the pane can say it. A preview is of the next version by
  /// definition, and "the page, with sample answers" over a document that is
  /// four edits ahead of the one in force is a picture without a date on it.
  int? previewVersion;

  bool get draft => contractId == null;

  /// The questions as they stood when the page was last asked for.
  ///
  /// The page does not only depend on the text. `{{overtime_rate}}` is an
  /// unresolvable tag right up until somebody adds the question that answers
  /// it — in step 3, which is a different part of the screen and does not
  /// touch this controller. Without this the pane would keep saying the tag is
  /// broken until the next keystroke in the body, and the person who had just
  /// fixed it would be looking at a stale accusation.
  String _renderedAgainst = '';

  bool _disposed = false;

  /// Enough of the questions to know whether the page would come back
  /// different: the key a tag names, the wording, the kind of answer, and
  /// whether it is still asked at all — retiring a question is precisely the
  /// act that can take a tag's answer away again.
  ///
  /// Deliberately not the whole payload. The sensitive flag and the order of
  /// the form do not change a word of the document, and re-asking the server
  /// over a reshuffle would be one request per press of an arrow.
  String get _signature => [
    for (final field in payload())
      '${field['key']} ${field['label']} ${field['type']} ${field['retired']}',
  ].join(' ');

  /// Called from the designer's build: a question may have changed somewhere
  /// else on the screen, which the body's own listener cannot see.
  ///
  /// Scheduling the same debounce a keystroke schedules, rather than rendering
  /// on the spot: this runs during a build, nothing about a build may set
  /// state, and a timer does not.
  void syncQuestions() {
    final signature = _signature;

    if (signature == _renderedAgainst) return;

    _renderedAgainst = signature;
    _typed();
  }

  @override
  void dispose() {
    _disposed = true;
    _debounce?.cancel();
    body.removeListener(_typed);
    writing.dispose();
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
    final id = contractId;

    if (id == null) {
      if (_disposed) return;

      document = readDraftText(body.text, _delimiters);
      resolvable = const [];
      previewVersion = null;
      stale = false;
      rendering = false;
      error = null;
      notifyListeners();

      return;
    }

    final request = ++_request;

    rendering = true;
    notifyListeners();

    final response = await api.post('/hr/contracts/$id/preview', {
      'body': body.text,
      'fields': payload(),
    });

    if (_disposed || request != _request) return;

    rendering = false;

    if (!response.ok) {
      error = response.reason;
      // Whatever is on screen is now older than the text beside it. The refusal
      // itself is legible — `ApiResponse.reason` lifts the server's field-level
      // sentence out of `errors`, and for a body that is the one naming the tag
      // and saying what to do about it — but a legible refusal over a page that
      // silently disagrees with the wording is still a lie by arrangement.
      stale = document.isNotEmpty;
      notifyListeners();

      return;
    }

    final html = _htmlOf(response.data);

    // Answered, and with nothing this build can find a document in. Said out
    // loud rather than drawn as an empty page: "nothing written yet" over a
    // contract somebody has just written is the one message that would send
    // them looking for lost work.
    if (html.isEmpty && body.text.trim().isNotEmpty) {
      error =
          'The server answered without a document in it, so there is '
          'nothing to draw here. Your wording is untouched — this pane is '
          'the only thing affected.';
      document = const [];
      stale = false;
      notifyListeners();

      return;
    }

    final data = response.data;

    error = null;
    stale = false;
    document = readDocumentHtml(html, _delimiters);

    if (data is Map) {
      resolvable = MergeTag.listFrom(data['merge_tags'], mergeSourceField);
      previewVersion = (data['version'] as num?)?.toInt();
    }

    notifyListeners();
  }

  /// The document out of the answer.
  ///
  /// `content`, and nothing else. This read `html`, then `document`, then
  /// `content` — two names no server sends, on the theory that tolerance about
  /// spelling is free. It is not: the same habit in
  /// `ContractVocabulary.fromJson` read three invented names for the
  /// always-available facts and produced an empty list that looked exactly like
  /// a server declaring none, and the feature was missing in production for as
  /// long as nobody looked. An alternative spelling is worth carrying when both
  /// spellings exist; these did not.
  static String _htmlOf(Object? data) {
    if (data is Map && data['content'] is String) {
      return data['content'] as String;
    }

    return '';
  }

  /// The whole point of the exercise: a tag lands where the cursor is.
  ///
  /// Where the cursor IS, not on the end. Somebody who has just written "made
  /// on  between the parties" and put the caret in the gap means the gap.
  ///
  /// An untouched box has no cursor at all — `selection` is offset -1 — and
  /// then the end of the text is the only sensible place, rather than throwing
  /// a range error over somebody clicking a chip first.
  /// [tag] is the exact text to insert, assembled by the server — this end
  /// never wraps a key in delimiters of its own. See [MergeTag.tag].
  void insert(String tag) {
    final value = body.value;
    final selection = value.selection;

    final start = selection.isValid ? selection.start : value.text.length;
    final end = selection.isValid ? selection.end : value.text.length;

    body.value = TextEditingValue(
      text: value.text.replaceRange(start, end, tag),
      selection: TextSelection.collapsed(offset: start + tag.length),
    );

    // Straight back to writing. Clicking a chip should feel like typing the
    // tag, and typing does not lose the caret.
    writing.requestFocus();
  }
}

// ── the left half: the tags, then the writing ──────────────────────

/// The writing surface, for step 4 of the form down the left.
class ContractWriting extends StatelessWidget {
  const ContractWriting({
    super.key,
    required this.page,
    required this.vocabulary,
    required this.fields,
    required this.canEdit,
  });

  final ContractPageController page;
  final ContractVocabulary vocabulary;

  /// The questions as they stand this instant, for the list of insertable
  /// tags — a function for the same reason the payload is one.
  final List<ContractField> Function() fields;

  final bool canEdit;

  /// How tall the surface is before anything is written in it.
  ///
  /// ── It grows from here, and why that took a reorder ──────────
  ///
  /// This was a FIXED 360px box, because it sat fourth in a scrolling form and a
  /// surface that grew would have pushed the two steps under it down the page
  /// with every paragraph. The owner's answer was to move the step rather than
  /// accept the box: *"it should grow, which i believe the best idea would be to
  /// move it as the last item, so it does not push the others endlessly."*
  ///
  /// So this is a MINIMUM in lines rather than a height in pixels, and the field
  /// grows past it with the text. Lines, because that is the unit the thing is
  /// measured in — a pixel height has to be re-derived by hand every time the
  /// font or the line height changes, and is wrong in the interval.
  static const int _linesBeforeItGrows = 14;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(12),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(11),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (canEdit) ...[
              // The palette is chrome and takes the tinted surface, so the
              // writing area below reads as the one part of this box you type
              // into rather than the two being one undifferentiated panel.
              ColoredBox(
                color: scheme.surfaceContainerLow,
                child: _tags(context),
              ),
              Divider(height: 1, color: EzBiziTheme.edge(context)),
            ],
            // ── Why this is filled rather than left to the page ────
            //
            // It was transparent, and the owner said so. `filled: false` plus
            // no border was meant to stop it looking like a comment box, and
            // overshot: with nothing painted behind it the scaffold's grey
            // showed through, so the one region on the screen you are supposed
            // to type into was the only one with no surface of its own. A
            // writing surface has to look like paper.
            //
            // `surface` rather than the page's white: this half is chrome and
            // follows the theme, so it is white in light mode and dark in dark
            // mode. The document beside it is white in BOTH, and that
            // difference is the whole boundary — see ContractDocumentPage.
            ColoredBox(
              color: scheme.surface,
              child: TextField(
                controller: page.body,
                focusNode: page.writing,
                // Read-only rather than DISABLED, which is the difference
                // between a reader who can scroll a four-page contract and
                // select a clause out of it and one who is handed a greyed box
                // they cannot even move. The caret is hidden with it, so
                // nothing invites typing that would go nowhere; the palette
                // above is gone for the same reason.
                readOnly: !canEdit,
                showCursor: canEdit,
                // minLines/maxLines rather than `expands`, which needs a
                // bounded height and so cannot grow. The field is as tall as
                // the text, never shorter than the minimum.
                minLines: _linesBeforeItGrows,
                maxLines: null,
                textAlignVertical: TextAlignVertical.top,
                keyboardType: TextInputType.multiline,
                style: Theme.of(
                  context,
                ).textTheme.bodyMedium?.copyWith(height: 1.6, fontSize: 14),
                decoration: InputDecoration(
                  // The FIELD paints nothing — the ColoredBox above is the
                  // surface. Material's own filled styling would draw a
                  // rounded, shaded, underlined control, which around five
                  // hundred words of contract reads as a comment box.
                  filled: false,
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  disabledBorder: InputBorder.none,
                  contentPadding: const EdgeInsets.fromLTRB(18, 16, 18, 24),
                  hintText: _hint(),
                  hintMaxLines: 6,
                ),
              ),
            ),
            if (canEdit && vocabulary.mergeTags.maxBodyLength > 0) ...[
              Divider(height: 1, color: EzBiziTheme.edge(context)),
              _lengthNote(context),
            ],
          ],
        ),
      ),
    );
  }

  /// How long the wording is against the cap, once it is worth saying.
  ///
  /// ── The cap was published and unread, which is the failure ───
  ///
  /// `merge_tags.max_body_length` is 50,000 characters, and the catalogue sends
  /// it for exactly one reason: so nobody types a twenty-page agreement and
  /// finds out at publish. The server's refusal is a good one — it says the
  /// number, it says why a published version cannot be trimmed afterwards, and
  /// it suggests an annexe — but it arrives after the work, which is the
  /// "taught them a rule the hard way" failure the catalogue exists to prevent.
  ///
  /// Silent until the last tenth, because a counter over an empty box is
  /// clutter and a counter that appears when it starts to matter is a warning.
  /// It does not TRUNCATE, and that is deliberate: `maxLength` on the field
  /// would silently eat the end of a pasted contract, which is a worse thing to
  /// do to a legal document than let the server refuse it whole.
  ///
  /// ── Counting what the server counts ──────────────────────────
  ///
  /// `runes`, which is code points, because `MergeTags::validate` refuses on
  /// `mb_strlen` and that is code points too. This counted GRAPHEME CLUSTERS —
  /// what a reader would call a character, and the defensible answer to a
  /// different question. The two disagree on combining marks, on anything
  /// outside the basic plane, and on every emoji joined with a zero-width
  /// joiner: a body of 39,900 clusters can be 50,400 code points, so the
  /// counter said there was room and the publish was refused anyway, quoting a
  /// number the person had just watched a counter contradict. A client
  /// re-implementing a server measure has to re-implement THAT measure.
  Widget _lengthNote(BuildContext context) {
    final theme = Theme.of(context);
    final cap = vocabulary.mergeTags.maxBodyLength;

    return ListenableBuilder(
      listenable: page.body,
      builder: (context, _) {
        final length = page.body.text.runes.length;

        if (length < cap - (cap ~/ 10)) return const SizedBox.shrink();

        final over = length > cap;

        return Padding(
          padding: const EdgeInsets.fromLTRB(18, 8, 18, 8),
          child: Row(
            children: [
              Icon(
                over ? Icons.error_outline : Icons.speaker_notes_outlined,
                size: 15,
                color: over ? theme.colorScheme.error : null,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  over
                      ? 'This wording is $length characters and the limit is '
                            '$cap. Publishing will be refused until it is '
                            'shorter — a published version can never be edited '
                            'or deleted, so anything longer is text nobody '
                            'could take back.'
                      : '$length of $cap characters.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: over ? theme.colorScheme.error : null,
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  /// The empty-box prompt, with a real tag in it.
  ///
  /// The tag comes from `merge_tags.example`, which the catalogue publishes for
  /// this exact purpose. A hint with `{{start_date}}` written into Dart would
  /// be a second opinion about the syntax — and the one somebody copies, since
  /// it is the example in front of them while they learn what a tag looks like.
  /// Without it the sentence simply loses its tag rather than inventing one.
  String _hint() {
    final example = vocabulary.mergeTags.example;

    return '${example.isEmpty ? 'This Employment Agreement is made between…' : 'This Employment Agreement is made on $example between…'}'
        '\n\n'
        'Write the contract here. Click any of the fields above to drop it in '
        'where the cursor is. Headings start a line with #, **bold**, lists with '
        '- , tables with |. Wrap a clause in {{#field}} … {{/field}} to keep it '
        'only when that field is filled, or {{#pay_basis.hourly}} for a choice.';
  }

  /// Everything that can be dropped into the text, grouped by where it comes
  /// from.
  ///
  /// ── The list is the server's, and it is one list ─────────────
  ///
  /// `MergeTags::resolvable()` is the registry, and it arrives in full as
  /// `merge_tags` — this template's own questions included — from the response
  /// that opened the contract and again from every preview. When it is there it
  /// is used for all three groups, split by `source`. Nothing is filtered,
  /// sorted or relabelled on the way through.
  ///
  /// The headings are this end's words. Everything else — which keys exist,
  /// what each is called, the exact characters that get inserted — is the
  /// server's, and the four rules that decide them (live fields only, no
  /// signatures, the core is asked-not-reserved, facts are dotted) are stated
  /// nowhere in Dart. They used to be, badly: three of the four were wrong, so
  /// this offered `{{name}}` and `{{middle_name}}` and signature fields, all of
  /// which are refused by name the moment somebody publishes.
  ///
  /// ── Except while the contract does not exist yet ─────────────
  ///
  /// There is no preview for a contract with no id, so on the create path the
  /// own-questions group is assembled here from the questions on screen. Two
  /// server facts are needed to do it and both are published rather than
  /// guessed: the delimiters, and whether a kind of question merges at all
  /// (`field_types[].merges` — a signature does not). The other two groups do
  /// not depend on the template and come from the catalogue either way.
  Widget _tags(BuildContext context) {
    final theme = Theme.of(context);

    final syntax = vocabulary.mergeTags;
    final offered = page.resolvable;

    List<MergeTag> from(String source) => [
      for (final tag in offered)
        if (tag.source == source) tag,
    ];

    final own = offered.isNotEmpty
        ? from(mergeSourceField)
        : [
            for (final field in fields())
              if (!field.retired &&
                  field.key.isNotEmpty &&
                  syntax.isUsable &&
                  vocabulary.typeMerges(field.type))
                MergeTag(
                  key: field.key,
                  tag: syntax.wrap(field.key),
                  label: field.label.isEmpty ? field.key : field.label,
                  source: mergeSourceField,
                ),
          ];

    final core = offered.isNotEmpty ? from(mergeSourceCore) : syntax.core;
    final facts = offered.isNotEmpty ? from(mergeSourceFact) : syntax.facts;

    return ConstrainedBox(
      // ── Not capped any more, and the cap is why it looked broken ──
      //
      // It was `maxHeight: 152` with a scroll view, and the owner reported the
      // result as an overlay: the palette cut off part-way through "THE
      // BUSINESS AND THE DATE", so a heading was sliced in half and the writing
      // surface below looked like it was sitting on top of it. Nothing overlaid
      // anything — a clip with no scrollbar, no fade and no room to show a
      // second row of a group simply reads as damage.
      //
      // The cap's own reason had also stopped being true. It said a contract
      // with thirty questions "would otherwise push the writing surface off the
      // bottom of the REGION" — true when this was step 4 with two steps
      // beneath it and a fixed-height box. The step is last now and the surface
      // grows, so there is nothing underneath to push.
      //
      // `double.infinity` rather than deleting the ConstrainedBox: the palette
      // stays the one thing that decides its own height, so a future cap is a
      // number here rather than a new widget. If thirty questions ever does
      // become unpleasant, the answer is a scrollbar somebody can see — not a
      // silent clip.
      constraints: const BoxConstraints(maxHeight: double.infinity),
      // A plain Padding, and the scroll view it replaced had to go with the
      // cap. This sits inside the designer's own vertical scroll view, so
      // height arrives unbounded — which the cap used to bound. A nested
      // vertical viewport with no bound does not degrade, it throws.
      child: Padding(
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

  Widget _group(BuildContext context, String title, List<MergeTag> tags) {
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
              for (final tag in tags)
                Tooltip(
                  // The tag itself, because that is what lands in the text and
                  // somebody re-reading their own contract needs to recognise
                  // it. The server's sentence under it where there is one.
                  message: tag.note == null
                      ? tag.tag
                      : '${tag.tag}\n${tag.note}',
                  child: ActionChip(
                    label: Text(tag.label),
                    avatar: const Icon(Icons.data_object, size: 15),
                    onPressed: () => page.insert(tag.tag),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

// ── the right half: the page, the height of the tab ────────────────

/// The document, beside the form rather than inside it.
class ContractPagePane extends StatelessWidget {
  const ContractPagePane({super.key, required this.page});

  final ContractPageController page;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      key: const ValueKey('tour-hr-contract-page'),
      listenable: page,
      builder: (context, _) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _chrome(context),
          Divider(height: 1, color: EzBiziTheme.edge(context)),
          Expanded(
            // The white starts here and runs to the edges — see
            // ContractDocumentPage for why it is white in both themes. The
            // ColoredBox is under the scroll view as well as inside it, so
            // over-scrolling does not flash the theme's surface behind the
            // page.
            child: ColoredBox(
              color: ContractDocumentPage.paper,
              child: SingleChildScrollView(
                child: ContractDocumentPage(
                  blocks: page.document,
                  // The second of these is a BACKSTOP and is not expected to
                  // appear from this endpoint: `preview()` validates before it
                  // renders, so a tag that resolves to nothing is a refusal and
                  // not a document. It is worded for the case
                  // `MergeTags::resolveOne` exists to cover — a version
                  // published before that rule, or a bug in it — and it is here
                  // rather than removed because the alternative is a tag drawn
                  // with no explanation at all if that day ever comes.
                  tagNote: page.draft
                      ? 'A merge tag. Nothing is filled in yet — create the '
                            'contract and the page here fills in with sample '
                            'answers.'
                      : 'This tag did not resolve, so the finished contract '
                            'would carry it exactly like this.',
                  placeholder: page.draft
                      ? 'Nothing written yet. What you type on the left '
                            'appears here as you go.'
                      : 'Nothing written yet. Whatever you type on the left '
                            'is rendered here with sample answers, exactly as '
                            'the page will look.',
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// The strip above the page: what it is, and anything wrong with it.
  ///
  /// Chrome, so it follows the theme like everything else. The page below it
  /// does not, and that line between them is the boundary.
  Widget _chrome(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text(_what(), style: theme.textTheme.bodySmall)),
              if (page.rendering) ...[
                const SizedBox(width: 10),
                const SizedBox(
                  width: 14,
                  height: 14,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ],
            ],
          ),
          if (page.error != null) ...[
            const SizedBox(height: 8),
            ErrorNote(page.error!),
            if (page.stale) ...[const SizedBox(height: 8), _staleNote(context)],
          ],
        ],
      ),
    );
  }

  /// What is in the pane, and which version it is a draft of.
  ///
  /// ── Saying the number, because a preview has one ─────────────
  ///
  /// The answer carries `version`: the number this draft WOULD take, which is
  /// always the next one, because a preview is by definition of something not
  /// yet published. It was ignored, so the pane said "the page" over a document
  /// that is not the document currently in force and did not say which one it
  /// was. On a screen whose whole subject is versioned wording, that is the one
  /// fact worth printing.
  String _what() {
    if (page.draft) {
      return 'Your wording, not yet merged. Sample answers appear here once '
          'the contract exists.';
    }

    final version = page.previewVersion;

    return version == null
        ? 'The page, with sample answers merged in by the server.'
        : 'A draft of version $version, with sample answers merged in by the '
              'server. Nothing is published until you say so.';
  }

  /// The page is older than the wording beside it.
  ///
  /// ── Why this exists, and what it replaced ────────────────────
  ///
  /// There was a note here listing the tags that did not resolve, and it could
  /// never appear. `preview()` validates the body BEFORE it renders — a tag
  /// naming nothing comes back as a refusal quoting the tag, so the answer has
  /// no document in it and never an `unresolved` list, and a document it did
  /// render cannot contain a surviving tag. The note was reading a key no
  /// server sends, from a response that is not sent when the condition holds.
  ///
  /// What actually happens is this: the refusal appears above, and the page
  /// below it is the last one that rendered — from before the typo. The server
  /// names the tag and says what to do about it, which is a better sentence
  /// than the one this end was composing. What was missing is the admission
  /// that the picture underneath has stopped keeping up.
  Widget _staleNote(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.6),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.history_toggle_off, size: 16),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'The page below is the last one that rendered, from before that '
              'change. Your wording is untouched — fix what is named above and '
              'the page catches up on its own.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        ],
      ),
    );
  }
}
