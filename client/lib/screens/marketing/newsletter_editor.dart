import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/theme.dart';
import 'newsletter_rich_text.dart';

import '../../widgets/search_picker.dart';
import '../email/message_html.dart';
import 'clock_label.dart';
import 'media_library_panel.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/refused.dart';
import '../../core/api_client.dart';
import 'newsletter_blocks.dart';
import 'newsletter_brand_saved.dart';
import 'newsletter_import_panel.dart';
import 'newsletter_preflight.dart';

/// The three steps of the composer, in the order a campaign is built.
enum NewsletterStep {
  setup('Setup', 'newsletter-step-setup'),
  design('Design', 'newsletter-step-design'),
  review('Review & schedule', 'newsletter-step-review');

  const NewsletterStep(this.label, this.key);
  final String label;
  final String key;
}

/// Where the open newsletter's save stands, as the header says it.
enum NewsletterSaveState { saved, saving, failed, conflict }

/// Newsletter › Composer: Setup → Design → Review & schedule.
///
/// ── Redesign stage 1 (Rodrigo, 2026-11-17) ──────────────────────
///
/// The old composer put blocks, the email, and subject/audience/schedule/test
/// in three columns at once. It is now three steps under one compact header:
/// Setup (subject, sender, who receives it), Design (the block editor and
/// preview, unchanged in capability) and Review (a summary, the server's
/// preflight as fixable items, and the one Send/Schedule action). Preview &
/// test is one panel from the header, bound to the saved revision.
///
/// ── Why the blocks are still a stack ────────────────────────────
///
/// An email IS a stack of rows — that is what every mail client renders and
/// what `BlockRenderer` emits. Columns are the one place two things sit side
/// by side, because that is the one place email supports it.
///
/// ── Read-only once it has gone ──────────────────────────────────
///
/// The server refuses edits to a scheduled, sending or sent newsletter. The
/// screen says so up front ([readOnlyBecause]) rather than letting somebody
/// type a paragraph that the next autosave throws away.
class NewsletterEditor extends StatefulWidget {
  const NewsletterEditor({
    super.key,
    required this.subject,
    required this.name,
    required this.preheader,
    required this.blocks,
    required this.audiences,
    required this.audienceId,
    required this.when,
    required this.viewName,
    required this.company,
    this.initialHtml,
    required this.scheduledTimezone,
    required this.session,
    required this.sendingRefusedBecause,
    required this.readOnlyBecause,
    required this.saveError,
    required this.saveState,
    required this.previewHtml,
    required this.onChanged,
    required this.onAudience,
    this.onNewList,
    required this.onHtml,
    required this.onSend,
    this.api,
    this.newsletterId,
    this.onSaveNow,
    this.rasterise = rasteriseSvg,
    this.status = 'draft',
    this.revision = 1,
    this.lastTest,
    this.onTested,
    this.onRetry,
    this.onReload,
    this.onDuplicate,
    this.onDelete,
    this.confirmingDelete = false,
    this.onAudienceCreated,
    this.onOpenSettings,
    this.onBack,
    this.canUndo = false,
    this.canRedo = false,
    this.onUndo,
    this.onRedo,
    this.historyGeneration = 0,
    this.onUnschedule,
    this.onHistory,
    this.onNew,
    this.onCompare,
    this.onSaveAsCopy,
    this.localCopyNote,
    this.onRestoreLocal,
    this.onDiscardLocal,
    this.brand,
    this.onBrand,
  });

  /// The brand applied to this newsletter (P1b), or null.
  final Map<String, dynamic>? brand;

  /// Applies (a map) or removes (null) the brand; null hides the Brand tab.
  final ValueChanged<Map<String, dynamic>?>? onBrand;

  /// The internal campaign name (P1a); empty shows the subject instead.
  final TextEditingController name;

  /// The line an inbox shows after the subject.
  final TextEditingController preheader;

  /// Back to the dashboard's list.
  final VoidCallback? onBack;

  /// Undo/redo over the content; the buttons are disabled when false.
  final bool canUndo;
  final bool canRedo;
  final VoidCallback? onUndo;
  final VoidCallback? onRedo;

  /// Bumped whenever the content is replaced from outside (undo, redo,
  /// restore), so fields that hold their own copy are rebuilt.
  final int historyGeneration;

  final VoidCallback? onUnschedule;
  final VoidCallback? onHistory;
  final VoidCallback? onNew;

  /// After a refused (409) save: compare, or keep the local copy as a draft.
  final VoidCallback? onCompare;
  final VoidCallback? onSaveAsCopy;

  /// An unsaved copy found on this device, said as a sentence; null: none.
  final String? localCopyNote;
  final VoidCallback? onRestoreLocal;
  final VoidCallback? onDiscardLocal;

  /// For the import panel, the readiness and Preview & test; null hides them.
  final ApiClient? api;
  final String? newsletterId;

  /// Saves what is on screen now, before a preview or test reads it.
  final Future<void> Function()? onSaveNow;
  final SvgRasteriser rasterise;

  final TextEditingController subject;
  final List<Map<String, dynamic>> blocks;
  final List<NewsletterAudience> audiences;
  final String? audienceId;
  final TextEditingController when;
  final String viewName;

  /// The name the footer carries, as the renderer writes it.
  final String company;

  /// The custom HTML already saved, which opens the HTML box filled.
  final String? initialHtml;

  /// The clock this mailing is already filed against, when it is already
  /// scheduled. Null on a draft, where nothing has picked a clock yet.
  final String? scheduledTimezone;

  final Session session;
  final String? sendingRefusedBecause;

  /// Why nothing here can be changed, or null for a draft.
  final String? readOnlyBecause;
  final String? saveError;

  final NewsletterSaveState saveState;

  /// GET /newsletters/{id}/preview: the exact document that will go out.
  final String previewHtml;

  final VoidCallback onChanged;
  final ValueChanged<String?> onAudience;

  /// Opens the new-list panel (EzBizi customers); null hides it.
  final VoidCallback? onNewList;
  final ValueChanged<String> onHtml;
  final Future<void> Function() onSend;

  /// draft · scheduled · sending · sent · failed.
  final String status;

  /// The saved revision the screen is built on.
  final int revision;
  final NewsletterLastTest? lastTest;
  final ValueChanged<NewsletterLastTest>? onTested;

  /// Save again after a failure.
  final VoidCallback? onRetry;

  /// Throw away the local copy and read the newest version (after a 409).
  final VoidCallback? onReload;

  /// Null when the role may not; the overflow says why.
  final VoidCallback? onDuplicate;
  final VoidCallback? onDelete;

  /// Delete was chosen once; the next press deletes.
  final bool confirmingDelete;

  /// A Mailchimp list picked in Setup became (or already was) an audience.
  final ValueChanged<NewsletterAudience>? onAudienceCreated;

  /// Settings pane, where the sending account and the address are set.
  final VoidCallback? onOpenSettings;

  @override
  State<NewsletterEditor> createState() => _NewsletterEditorState();
}

class _NewsletterEditorState extends State<NewsletterEditor> {
  NewsletterStep _step = NewsletterStep.setup;

  bool _htmlMode = false;
  final TextEditingController _html = TextEditingController();
  bool _scheduling = false;

  /// The block being edited in place, by position.
  int? _selected;
  /// Designing for a phone (P1b): the canvas draws at [_deviceWidth] with
  /// mobile overrides applied, and the inspector edits those overrides.
  bool _phone = false;
  double _deviceWidth = 375;

  /// The Design panel's left tab: add, brand or saved.
  String _leftTab = 'add';

  /// Saved sections and starters; null until loaded.
  List<NewsletterSavedSection>? _sections;

  /// Bumped when a mobile override is reset, so its field redraws.
  int _mobileGeneration = 0;

  /// Show the server's HTML instead of the Flutter drawing, so the exact
  /// output stays checkable.
  bool _serverPreview = false;

  bool get _locked => widget.readOnlyBecause != null;

  /// The import panel is open.
  bool _importing = false;

  /// What was there before the last import or switch, for Undo.
  ({String html, List<Map<String, dynamic>> blocks, bool htmlMode})? _undo;

  /// Custom HTML is what goes out whenever it is set, whichever pane is open.
  bool get _htmlActive => _html.text.trim().isNotEmpty;

  /// The server's preflight for the saved revision; null until it answers.
  NewsletterReadiness? _readiness;
  bool _resolving = false;

  /// The Add content panel is open (it collapses to a rail).
  bool _paletteOpen = true;

  /// Keyboard: Ctrl+Z/Y, and arrows, Delete and Ctrl+D on a selected block.
  final FocusNode _keys = FocusNode(debugLabel: 'newsletter-editor');

  /// The in-place editor of the selected heading or text.
  MarkupEditingController? _inline;
  Map<String, dynamic>? _inlineBlock;
  int _inlineGeneration = -1;

  /// A crop chosen in the inspector but not applied yet.
  ({String aspect, double x, double y})? _pendingCrop;
  bool _cropping = false;

  /// The connected Mailchimp account's lists, or null before they load.
  List<Map<String, dynamic>>? _lists;

  /// Why there are no lists ("No Mailchimp account is connected here.").
  String? _listsMessage;

  void _snapshot() {
    _undo = (
      html: _html.text,
      blocks: [
        for (final b in widget.blocks)
          Map<String, dynamic>.from(jsonDecode(jsonEncode(b)) as Map),
      ],
      htmlMode: _htmlMode,
    );
  }

  void _useHtml(String html) {
    setState(() {
      _snapshot();
      _html.text = html;
      _htmlMode = true;
      _importing = false;
      _selected = null;
    });
    widget.onHtml(html);
  }

  void _useBlocks(List<Map<String, dynamic>> blocks) {
    setState(() {
      _snapshot();
      widget.blocks
        ..clear()
        ..addAll(blocks);
      _html.clear();
      _htmlMode = false;
      _importing = false;
      _selected = null;
    });
    widget.onHtml('');
    widget.onChanged();
  }

  /// Back to blocks clears the HTML, so the blocks are what goes out. The
  /// HTML is kept for Undo rather than silently discarded.
  void _switchToBlocks() {
    final hadHtml = _htmlActive;
    setState(() {
      if (hadHtml) _snapshot();
      _html.clear();
      _htmlMode = false;
      _selected = null;
    });
    if (hadHtml) widget.onHtml('');
  }

  void _restore() {
    final undo = _undo;
    if (undo == null) return;
    setState(() {
      widget.blocks
        ..clear()
        ..addAll(undo.blocks);
      _html.text = undo.html;
      _htmlMode = undo.htmlMode;
      _undo = null;
      _selected = null;
    });
    widget.onHtml(undo.html);
    widget.onChanged();
  }

  @override
  void initState() {
    super.initState();
    // Scheduled or sent: nothing to set up, so it opens where its time,
    // audience and checks are read.
    if (widget.readOnlyBecause != null) _step = NewsletterStep.review;
    _scheduling = widget.when.text.trim().isNotEmpty;
    final html = widget.initialHtml ?? '';
    if (html.isNotEmpty) {
      _html.text = html;
      _htmlMode = true;
    }
    _resolve();
    _loadLists();
    _loadSections();
  }

  Future<void> _loadSections() async {
    final api = widget.api;
    if (api == null) return;
    final found = await loadSavedSections(api);
    if (!mounted) return;
    setState(() => _sections = found ?? const []);
  }

  void _say(String message) {
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(message)));
  }

  /// Inserts blocks under the selection (or at the end) and selects the first.
  void _insertBlocks(List<Map<String, dynamic>> blocks) {
    if (blocks.isEmpty) return;
    setState(() {
      final at = _selected == null ? widget.blocks.length : _selected! + 1;
      widget.blocks.insertAll(at, blocks);
      _selected = at;
      _serverPreview = false;
    });
    widget.onChanged();
  }

  /// "Save as section" from the block toolbar.
  Future<void> _saveSection(int i) async {
    final api = widget.api;
    if (api == null) return;
    final asked = await askSectionName(context);
    if (asked == null || !mounted) return;
    final r = await api.post('/newsletter/sections', {
      'name': asked.name,
      'mode': asked.mode,
      'blocks': [widget.blocks[i]],
    });
    if (!mounted) return;
    _say(r.ok ? 'Saved as "${asked.name}".' : r.reason);
    if (r.ok) await _loadSections();
  }

  /// Publishes the selected block as the section's next version. Linked
  /// newsletters see "Update available"; none changes by itself.
  Future<void> _publishSection(NewsletterSavedSection section) async {
    final api = widget.api;
    final i = _selected;
    if (api == null || i == null) return;
    final r = await api.put('/newsletter/sections/${section.id}', {
      'blocks': [widget.blocks[i]],
    });
    if (!mounted) return;
    _say(r.ok ? 'Published a new version of "${section.name}".' : r.reason);
    if (r.ok) await _loadSections();
  }

  @override
  void didUpdateWidget(covariant NewsletterEditor old) {
    super.didUpdateWidget(old);
    // A new saved revision or a different audience: what preflight said is
    // about something else now.
    if (old.revision != widget.revision || old.audienceId != widget.audienceId) {
      _resolve();
    }
    // Undo, redo or a restore replaced the content under the editor.
    if (old.historyGeneration != widget.historyGeneration) {
      final html = widget.initialHtml ?? '';
      if (_html.text != html) _html.text = html;
      if (html.isEmpty && _htmlMode) _htmlMode = false;
      if (_selected != null && _selected! >= widget.blocks.length) _selected = null;
      _pendingCrop = null;
    }
  }

  @override
  void dispose() {
    _keys.dispose();
    _inline?.dispose();
    _html.dispose();
    super.dispose();
  }

  Future<void> _resolve() async {
    final api = widget.api;
    final id = widget.newsletterId;
    if (api == null || id == null) return;
    setState(() => _resolving = true);
    final response = await api.get('/newsletters/$id/preflight');
    if (!mounted) return;
    final data = response.ok ? response.data : null;
    setState(() {
      _resolving = false;
      _readiness = data is Map<String, dynamic>
          ? NewsletterReadiness.fromJson(data)
          : NewsletterReadiness.error;
    });
  }

  Future<void> _loadLists() async {
    final api = widget.api;
    if (api == null) return;
    final response = await api.get('/newsletter/mailchimp/lists');
    if (!mounted) return;
    setState(() {
      _lists = response.ok
          ? [
              for (final l in (response.body['data'] as List? ?? const []))
                if (l is Map) Map<String, dynamic>.from(l),
            ]
          : const [];
      _listsMessage = response.ok
          ? response.body['message'] as String?
          : response.reason;
    });
  }

  /// Why Send cannot be pressed, or null when it can.
  ///
  /// Permission first, because it is the one nothing on this screen can fix.
  /// Then whether it has already gone, then the server's own sentence about
  /// the connection, then the things the person can actually do something
  /// about.
  String? get _sendRefusal {
    final unpermitted = widget.session.me?.refusal(
      'newsletter.send',
      'send a newsletter',
    );
    if (unpermitted != null) return unpermitted;

    if (widget.readOnlyBecause != null) return widget.readOnlyBecause;

    if (widget.sendingRefusedBecause != null) return widget.sendingRefusedBecause;

    if (widget.saveState == NewsletterSaveState.conflict) {
      return 'This newsletter changed elsewhere. Reload it before sending.';
    }
    if (widget.audienceId == null) {
      return 'Choose who this goes to first — choose an audience to continue.';
    }
    if (widget.subject.text.trim().isEmpty) {
      return 'A newsletter needs a subject line — it is the first thing anybody sees.';
    }
    if (widget.blocks.isEmpty && !_htmlActive) {
      return 'There is nothing in this newsletter yet.';
    }
    if (_scheduling && _scheduleProblem != null) return _scheduleProblem;

    return null;
  }

  /// A Later time that is empty or already past, said as the fix.
  String? get _scheduleProblem {
    final text = widget.when.text.trim();
    if (text.isEmpty) return 'Pick the day and time it should go out.';
    final at = DateTime.tryParse(text.replaceFirst(' ', 'T'));
    if (at == null) return 'That is not a time this can read. Pick it again.';
    if (at.isBefore(DateTime.now())) {
      return 'That time has already passed. Pick a time in the future.';
    }
    return null;
  }

  void _go(NewsletterStep step) => setState(() {
    _step = step;
    _selected = null;
    _pendingCrop = null;
  });

  /// Design, with block [index] selected and scrolled into view (a Review
  /// issue's "Show block").
  void _jumpTo(int? index) {
    setState(() {
      _step = NewsletterStep.design;
      _serverPreview = false;
      _pendingCrop = null;
      _selected = index != null && index >= 0 && index < widget.blocks.length
          ? index
          : null;
    });
    final i = _selected;
    if (i == null) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      BuildContext? found;
      void visit(Element e) {
        if (found != null) return;
        if (e.widget.key == Key('newsletter-block-$i')) {
          found = e;
          return;
        }
        e.visitChildElements(visit);
      }

      context.visitChildElements(visit);
      if (found != null) Scrollable.ensureVisible(found!, alignment: 0.2);
    });
  }

  /// Is somebody typing in a field? Then the keys are the field's.
  bool get _typing {
    final ctx = FocusManager.instance.primaryFocus?.context;
    return ctx != null &&
        (ctx.widget is EditableText ||
            ctx.findAncestorStateOfType<EditableTextState>() != null);
  }

  KeyEventResult _onKey(FocusNode node, KeyEvent e) {
    if (e is! KeyDownEvent && e is! KeyRepeatEvent) return KeyEventResult.ignored;
    if (_typing) return KeyEventResult.ignored;
    final k = HardwareKeyboard.instance;
    final ctrl = k.isControlPressed || k.isMetaPressed;
    final key = e.logicalKey;

    if (ctrl && key == LogicalKeyboardKey.keyZ) {
      if (k.isShiftPressed) {
        if (widget.canRedo && !_locked) widget.onRedo?.call();
      } else if (widget.canUndo && !_locked) {
        widget.onUndo?.call();
      }
      return KeyEventResult.handled;
    }
    if (ctrl && key == LogicalKeyboardKey.keyY) {
      if (widget.canRedo && !_locked) widget.onRedo?.call();
      return KeyEventResult.handled;
    }

    final i = _selected;
    if (i == null || _locked || _htmlMode || _step != NewsletterStep.design ||
        i >= widget.blocks.length) {
      return KeyEventResult.ignored;
    }
    final moveKey = ctrl || k.isAltPressed;
    if (moveKey && key == LogicalKeyboardKey.arrowUp) {
      if (i > 0) _move(i, i - 1);
      return KeyEventResult.handled;
    }
    if (moveKey && key == LogicalKeyboardKey.arrowDown) {
      if (i < widget.blocks.length - 1) _move(i, i + 1);
      return KeyEventResult.handled;
    }
    if (key == LogicalKeyboardKey.delete || key == LogicalKeyboardKey.backspace) {
      _remove(i);
      return KeyEventResult.handled;
    }
    if (ctrl && key == LogicalKeyboardKey.keyD) {
      _duplicate(i);
      return KeyEventResult.handled;
    }
    if (key == LogicalKeyboardKey.escape) {
      setState(() => _selected = null);
      return KeyEventResult.handled;
    }
    return KeyEventResult.ignored;
  }

  @override
  Widget build(BuildContext context) {
    return Focus(
      focusNode: _keys,
      onKeyEvent: _onKey,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final narrow = constraints.maxWidth < 900;
          final top = [
            _header(context, narrow: narrow),
            ..._banners(context),
            _stepBar(context),
            const Divider(height: 1),
          ];

          if (narrow) {
            return ListView(
              padding: EdgeInsets.zero,
              children: [
                ...top,
                Padding(
                  padding: const EdgeInsets.all(12),
                  child: _stepBody(context, bounded: false),
                ),
              ],
            );
          }

          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              ...top,
              Expanded(
                child: Padding(
                  padding: _step == NewsletterStep.design
                      ? EdgeInsets.zero
                      : const EdgeInsets.all(12),
                  child: _stepBody(context, bounded: true),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _stepBody(BuildContext context, {required bool bounded}) {
    return switch (_step) {
      NewsletterStep.setup => _setup(context, bounded: bounded),
      NewsletterStep.design => _design(context, bounded: bounded),
      NewsletterStep.review => _review(context, bounded: bounded),
    };
  }

  // ── header ───────────────────────────────────────────────────

  String get _statusWord => switch (widget.status) {
    'scheduled' => 'Scheduled',
    'sending' => 'Sending',
    'sent' => 'Sent',
    'failed' => 'Failed',
    _ => 'Draft',
  };

  /// The one campaign toolbar (P1a): back, the editable internal name, status,
  /// save state, undo/redo, Preview & test, one primary action and More.
  /// The subject is shown once, in Setup, and not repeated here.
  Widget _header(BuildContext context, {required bool narrow}) {
    final theme = Theme.of(context);
    final subject = widget.subject.text.trim();
    final shown = widget.name.text.trim().isNotEmpty
        ? widget.name.text.trim()
        : subject.isEmpty
        ? 'Untitled newsletter'
        : subject;

    final primary = switch (_step) {
      NewsletterStep.setup => FilledButton.icon(
        key: const ValueKey('newsletter-next'),
        onPressed: () => _go(NewsletterStep.design),
        icon: const Icon(Icons.arrow_forward, size: 18),
        label: const Text('Continue to design'),
      ),
      NewsletterStep.design => FilledButton.icon(
        key: const ValueKey('newsletter-next'),
        onPressed: () => _go(NewsletterStep.review),
        icon: const Icon(Icons.arrow_forward, size: 18),
        label: const Text('Review campaign'),
      ),
      NewsletterStep.review => _sendButton(),
    };

    final name = _locked
        ? Text(
            shown,
            key: const ValueKey('newsletter-title'),
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.titleMedium,
          )
        : TextField(
            key: const ValueKey('newsletter-name'),
            controller: widget.name,
            style: theme.textTheme.titleMedium,
            maxLength: 255,
            decoration: InputDecoration(
              isDense: true,
              counterText: '',
              border: InputBorder.none,
              hintText: subject.isEmpty ? 'Untitled newsletter' : subject,
            ),
            onChanged: (_) {
              setState(() {});
              widget.onChanged();
            },
          );

    final unscheduleRefusal = widget.session.me?.refusal(
      'newsletter.send',
      'unschedule a newsletter',
    );

    return Padding(
      key: const ValueKey('newsletter-header'),
      padding: const EdgeInsets.fromLTRB(4, 6, 8, 6),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        alignment: WrapAlignment.spaceBetween,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (widget.onBack != null)
                IconButton(
                  key: const ValueKey('newsletter-back'),
                  tooltip: 'All newsletters',
                  onPressed: widget.onBack,
                  icon: const Icon(Icons.arrow_back),
                ),
              if (!narrow) ...[
                Text(
                  'Newsletter /',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(width: 8),
              ],
              SizedBox(
                key: const ValueKey('tour-newsletter-pick'),
                width: narrow ? 150 : 280,
                child: Tooltip(
                  message: 'Internal name: only your team sees it',
                  child: name,
                ),
              ),
              const SizedBox(width: 8),
              Chip(
                key: const ValueKey('newsletter-status'),
                label: Text(_statusWord),
                visualDensity: VisualDensity.compact,
              ),
            ],
          ),
          Wrap(
            spacing: 4,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              _saveStateView(context),
              IconButton(
                key: const ValueKey('newsletter-undo-edit'),
                tooltip: 'Undo (Ctrl+Z)',
                onPressed: widget.canUndo && !_locked ? widget.onUndo : null,
                icon: const Icon(Icons.undo),
              ),
              IconButton(
                key: const ValueKey('newsletter-redo-edit'),
                tooltip: 'Redo (Ctrl+Shift+Z)',
                onPressed: widget.canRedo && !_locked ? widget.onRedo : null,
                icon: const Icon(Icons.redo),
              ),
              if (widget.onUnschedule != null)
                Refused(
                  reason: unscheduleRefusal,
                  child: OutlinedButton.icon(
                    key: const ValueKey('newsletter-unschedule'),
                    onPressed: unscheduleRefusal == null ? widget.onUnschedule : null,
                    icon: const Icon(Icons.edit_calendar_outlined, size: 18),
                    label: const Text('Unschedule'),
                  ),
                ),
              if (widget.api != null && widget.newsletterId != null)
                OutlinedButton.icon(
                  key: const ValueKey('newsletter-preview-test'),
                  onPressed: _openPreviewAndTest,
                  icon: const Icon(Icons.visibility_outlined, size: 18),
                  label: const Text('Preview & test'),
                ),
              if (widget.confirmingDelete)
                TextButton(
                  key: const ValueKey('newsletter-delete-confirm'),
                  style: TextButton.styleFrom(foregroundColor: theme.colorScheme.error),
                  onPressed: widget.onDelete,
                  child: const Text('Tap again to delete'),
                ),
              primary,
              _overflow(context),
            ],
          ),
        ],
      ),
    );
  }

  Widget _saveStateView(BuildContext context) {
    final theme = Theme.of(context);
    return switch (widget.saveState) {
      NewsletterSaveState.saved => Text(
        'Saved',
        key: const ValueKey('newsletter-save-state'),
        style: theme.textTheme.bodySmall,
      ),
      NewsletterSaveState.saving => Text(
        'Saving…',
        key: const ValueKey('newsletter-save-state'),
        style: theme.textTheme.bodySmall,
      ),
      NewsletterSaveState.failed => TextButton(
        key: const ValueKey('newsletter-save-retry'),
        style: TextButton.styleFrom(foregroundColor: theme.colorScheme.error),
        onPressed: widget.onRetry,
        child: const Text('Not saved — Retry'),
      ),
      NewsletterSaveState.conflict => TextButton(
        key: const ValueKey('newsletter-save-reload'),
        style: TextButton.styleFrom(foregroundColor: theme.colorScheme.error),
        onPressed: widget.onReload,
        child: const Text('Changed elsewhere — Reload'),
      ),
    };
  }

  Widget _overflow(BuildContext context) {
    final me = widget.session.me;
    final dupRefusal = me?.refusal('newsletter.create', 'write a newsletter');
    final delRefusal = me?.refusal('newsletter.delete', 'delete a newsletter');

    return PopupMenuButton<String>(
      key: const ValueKey('tour-newsletter-delete'),
      tooltip: 'More',
      icon: const Icon(Icons.more_vert),
      onSelected: (v) {
        if (v == 'history') widget.onHistory?.call();
        if (v == 'new') widget.onNew?.call();
        if (v == 'duplicate') widget.onDuplicate?.call();
        if (v == 'delete') widget.onDelete?.call();
      },
      itemBuilder: (context) => [
        PopupMenuItem(
          key: const ValueKey('newsletter-history'),
          value: 'history',
          enabled: widget.onHistory != null,
          child: const ListTile(
            dense: true,
            leading: Icon(Icons.history, size: 18),
            title: Text('Version history'),
          ),
        ),
        PopupMenuItem(
          key: const ValueKey('newsletter-new'),
          value: 'new',
          enabled: widget.onNew != null,
          child: const ListTile(
            dense: true,
            leading: Icon(Icons.add, size: 18),
            title: Text('New newsletter'),
          ),
        ),
        PopupMenuItem(
          key: const ValueKey('newsletter-duplicate'),
          value: 'duplicate',
          enabled: widget.onDuplicate != null,
          child: Tooltip(
            message: dupRefusal ?? '',
            child: const ListTile(
              dense: true,
              leading: Icon(Icons.copy_outlined, size: 18),
              title: Text('Duplicate'),
            ),
          ),
        ),
        PopupMenuItem(
          key: const ValueKey('newsletter-delete'),
          value: 'delete',
          enabled: widget.onDelete != null,
          child: Tooltip(
            message: delRefusal ?? '',
            child: ListTile(
              dense: true,
              leading: Icon(
                Icons.delete_outline,
                size: 18,
                color: Theme.of(context).colorScheme.error,
              ),
              title: Text(
                'Delete',
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          ),
        ),
      ],
    );
  }

  List<Widget> _banners(BuildContext context) {
    return [
      if (widget.saveState == NewsletterSaveState.conflict)
        MaterialBanner(
          key: const ValueKey('newsletter-conflict-banner'),
          content: const Text(
            'This newsletter changed in another tab or by someone else, so '
            'saving is paused. What you see is a local copy, not saved: it is '
            'kept on this device until you reload or save it as a copy.',
          ),
          actions: [
            if (widget.onCompare != null)
              TextButton(
                key: const ValueKey('newsletter-conflict-compare'),
                onPressed: widget.onCompare,
                child: const Text('Compare'),
              ),
            if (widget.onSaveAsCopy != null)
              TextButton(
                key: const ValueKey('newsletter-conflict-copy'),
                onPressed: widget.onSaveAsCopy,
                child: const Text('Save as copy'),
              ),
            TextButton(
              key: const ValueKey('newsletter-conflict-reload'),
              onPressed: widget.onReload,
              child: const Text('Reload'),
            ),
          ],
        )
      else if (widget.saveState == NewsletterSaveState.failed &&
          widget.saveError != null)
        MaterialBanner(
          key: const ValueKey('newsletter-save-error-banner'),
          content: Text(
            '${widget.saveError!} Your edits are a local copy, not saved yet.',
          ),
          actions: [
            TextButton(onPressed: widget.onRetry, child: const Text('Retry')),
          ],
        ),
      if (widget.localCopyNote != null)
        MaterialBanner(
          key: const ValueKey('newsletter-local-banner'),
          content: Text(widget.localCopyNote!),
          actions: [
            TextButton(
              key: const ValueKey('newsletter-local-restore'),
              onPressed: widget.onRestoreLocal,
              child: const Text('Restore it'),
            ),
            TextButton(
              key: const ValueKey('newsletter-local-discard'),
              onPressed: widget.onDiscardLocal,
              child: const Text('Discard'),
            ),
          ],
        ),
      if (_locked)
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: DashboardNote(widget.readOnlyBecause!),
        ),
    ];
  }

  Widget _stepBar(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      key: const ValueKey('newsletter-steps'),
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
      child: Wrap(
        spacing: 6,
        runSpacing: 6,
        children: [
          for (final step in NewsletterStep.values)
            ChoiceChip(
              key: ValueKey(step.key),
              avatar: CircleAvatar(
                radius: 10,
                backgroundColor: step == _step
                    ? theme.colorScheme.primary
                    : theme.colorScheme.surfaceContainerHighest,
                child: Text(
                  '${step.index + 1}',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: step == _step
                        ? theme.colorScheme.onPrimary
                        : theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
              label: Text(step.label),
              selected: step == _step,
              showCheckmark: false,
              onSelected: (_) => _go(step),
            ),
        ],
      ),
    );
  }

  Future<void> _openPreviewAndTest() async {
    await showNewsletterPreviewAndTest(
      context,
      api: widget.api!,
      newsletterId: widget.newsletterId!,
      onSaveNow: widget.onSaveNow ?? () async {},
      revision: () => widget.revision,
      lastTest: widget.lastTest,
      mayTest: widget.session.me?.refusal('newsletter.send', 'send a test newsletter'),
      onTested: (t) {
        widget.onTested?.call(t);
        _resolve();
      },
    );
  }

  Widget _sendButton() {
    final refusal = _sendRefusal;
    return Refused(
      reason: refusal,
      child: FilledButton.icon(
        key: const ValueKey('tour-newsletter-send'),
        onPressed: refusal == null ? () => widget.onSend() : null,
        icon: const Icon(Icons.send, size: 18),
        label: Text(
          _scheduling && widget.when.text.trim().isNotEmpty
              ? 'Schedule'
              : 'Send now',
        ),
      ),
    );
  }

  // ── step 1: setup ────────────────────────────────────────────

  Widget _setup(BuildContext context, {required bool bounded}) {
    final main = [
      _subjectCard(context),
      const SizedBox(height: 12),
      _senderCard(context),
      const SizedBox(height: 12),
      _recipientsCard(context),
    ];

    if (!bounded) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [...main, const SizedBox(height: 12), _inboxCard(context)],
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: main,
            ),
          ),
        ),
        const SizedBox(width: 12),
        SizedBox(width: 320, child: _inboxCard(context)),
      ],
    );
  }

  Widget _subjectCard(BuildContext context) {
    return DashboardCard(
      title: 'Subject and preheader',
      icon: Icons.title,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const ValueKey('newsletter-subject'),
            controller: widget.subject,
            readOnly: _locked,
            maxLength: 255,
            decoration: const InputDecoration(
              labelText: 'Subject',
              hintText: 'What lands in the inbox',
              helperText: 'Under about 60 characters shows in full on most phones.',
            ),
            onChanged: (_) {
              setState(() {});
              widget.onChanged();
            },
          ),
          const SizedBox(height: 8),
          TextField(
            key: const ValueKey('newsletter-preheader'),
            controller: widget.preheader,
            readOnly: _locked,
            maxLength: 255,
            decoration: const InputDecoration(
              labelText: 'Preheader',
              hintText: 'The line shown after the subject',
              helperText: 'Hidden in the email itself. Blank shows its first words instead.',
            ),
            onChanged: (_) {
              setState(() {});
              widget.onChanged();
            },
          ),
        ],
      ),
    );
  }

  Widget _senderCard(BuildContext context) {
    final r = _readiness;
    final theme = Theme.of(context);
    final missing = r != null && (r.fromName == null || r.fromAddress == null);

    Widget row(String label, String? value) => Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 150,
            child: Text(label, style: theme.textTheme.labelMedium),
          ),
          Expanded(child: Text(value ?? '—')),
        ],
      ),
    );

    return DashboardCard(
      key: const ValueKey('newsletter-sender'),
      title: 'Sender',
      icon: Icons.person_outline,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (r == null)
            const LinearProgressIndicator()
          else ...[
            row('From name', r.fromName),
            row('From / reply address', r.fromAddress),
            const DashboardNote(
              'Mailchimp sends from one verified address, which is also where '
              'replies go. Change it in your Mailchimp account, where it is '
              'verified.',
            ),
          ],
          if (missing && widget.onOpenSettings != null)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: widget.onOpenSettings,
                icon: const Icon(Icons.tune_outlined, size: 18),
                label: const Text('Set up sending in Settings'),
              ),
            ),
        ],
      ),
    );
  }

  /// The audience picker's value for a Mailchimp list not yet an audience.
  static const _listPrefix = 'mailchimp-list:';

  Widget _recipientsCard(BuildContext context) {
    final theme = Theme.of(context);
    final r = _readiness;
    final lists = _lists;
    final connected = lists != null && _listsMessage == null;

    // A list already chosen as a plain (untagged) audience is offered once,
    // as that audience.
    final takenLists = {
      for (final a in widget.audiences)
        if (a.kind == 'mailchimp' && (a.tag == null || a.tag!.isEmpty))
          a.mailchimpListId,
    };

    final options = [
      for (final l in lists ?? const <Map<String, dynamic>>[])
        if (!takenLists.contains(l['id']))
          SearchOption(
            value: '$_listPrefix${l['id']}',
            label: '${l['name']} · Mailchimp list',
          ),
      for (final a in widget.audiences)
        SearchOption(value: a.id, label: '${a.label} · ${a.where}'),
    ];

    NewsletterAudience? chosen;
    for (final a in widget.audiences) {
      if (a.id == widget.audienceId) chosen = a;
    }
    int? members;
    for (final l in lists ?? const <Map<String, dynamic>>[]) {
      if (chosen != null && l['id'] == chosen.mailchimpListId) {
        members = (l['members'] as num?)?.toInt();
      }
    }

    Widget label(String text) => Padding(
      padding: const EdgeInsets.only(top: 12, bottom: 4),
      child: Text(
        text.toUpperCase(),
        style: theme.textTheme.labelSmall?.copyWith(
          letterSpacing: 0.8,
          fontWeight: FontWeight.w700,
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );

    return DashboardCard(
      key: const ValueKey('tour-newsletter-details'),
      title: 'Who should receive it?',
      icon: Icons.groups_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          label('Company'),
          Text(widget.viewName, key: const ValueKey('newsletter-company')),
          label('Sending provider'),
          Row(
            children: [
              Expanded(
                child: Text(
                  lists == null
                      ? 'Checking…'
                      : connected
                      ? 'Mailchimp${r?.account == null ? '' : ' · ${r!.account}'}'
                      : 'Not connected',
                  key: const ValueKey('newsletter-provider'),
                ),
              ),
              if (lists != null && !connected && widget.onOpenSettings != null)
                TextButton(
                  onPressed: widget.onOpenSettings,
                  child: const Text('Connect Mailchimp'),
                ),
            ],
          ),
          label('Audience'),
          const DashboardNote(
            'A Mailchimp list is who the email actually goes to. EzBizi '
            'customers are added to a Mailchimp list just before sending.',
            key: ValueKey('newsletter-audience-explainer'),
          ),
          const SizedBox(height: 8),
          if (options.isEmpty)
            DashboardNote(
              'No mailing list in ${widget.viewName} yet. Connect Mailchimp, or '
              'select EzBizi customers.',
            )
          else
            SearchPickerField<String>(
              key: const ValueKey('newsletter-audience-picker'),
              value: widget.audienceId,
              label: 'Audience',
              isDense: true,
              options: options,
              onChanged: _locked ? null : _pickAudience,
            ),
          if (chosen != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: DashboardNote(
                chosen.mailchimpListId == null || chosen.mailchimpListId!.isEmpty
                    ? '${chosen.name} has no Mailchimp list behind it yet.'
                    : '${chosen.name} (EzBizi, ${chosen.where}) → Mailchimp list '
                          '${chosen.mailchimpListId}',
                key: const ValueKey('newsletter-audience-mapping'),
              ),
            ),
          if (widget.onNewList != null && !_locked)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                key: const ValueKey('newsletter-new-list'),
                onPressed: widget.onNewList,
                icon: const Icon(Icons.person_search_outlined, size: 18),
                label: const Text('Select EzBizi customers'),
              ),
            ),
          if (widget.audienceId != null) ...[
            label('Recipients'),
            _counts(context, members),
          ],
        ],
      ),
    );
  }

  /// Eligible is a number only when the server could count it. A Mailchimp
  /// list's member total is labelled as what it is, never as recipients.
  Widget _counts(BuildContext context, int? members) {
    final r = _readiness;
    final theme = Theme.of(context);
    final String eligible;
    if (r == null || _resolving) {
      eligible = 'Counting…';
    } else if (r.eligibleState == 'known' && r.eligible != null) {
      eligible = 'Eligible: ${r.eligible}';
    } else if (r.eligibleState == 'error') {
      eligible = 'Eligible recipients: could not be counted right now.';
    } else {
      eligible =
          'Eligible recipients: unknown — Mailchimp applies its own '
          'subscription rules';
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(eligible, key: const ValueKey('newsletter-eligible')),
            ),
            TextButton.icon(
              key: const ValueKey('newsletter-eligible-refresh'),
              onPressed: _resolving ? null : _resolve,
              icon: const Icon(Icons.refresh, size: 16),
              label: const Text('Refresh'),
            ),
          ],
        ),
        if (members != null)
          Text(
            'Members in Mailchimp list: $members',
            key: const ValueKey('newsletter-members'),
            style: theme.textTheme.bodySmall,
          ),
      ],
    );
  }

  Future<void> _pickAudience(String? value) async {
    if (value == null || !value.startsWith(_listPrefix)) {
      widget.onAudience(value);
      return;
    }
    final listId = value.substring(_listPrefix.length);
    final list = (_lists ?? const <Map<String, dynamic>>[]).firstWhere(
      (l) => l['id'] == listId,
      orElse: () => const {},
    );
    // Idempotent on the server: a list already chosen here comes back as the
    // same audience rather than a twin.
    final response = await widget.api!.post('/newsletter/audiences', {
      'name': '${list['name'] ?? 'Mailchimp list'}',
      'kind': 'mailchimp',
      'mailchimp_list_id': listId,
    });
    if (!mounted) return;
    final data = response.ok ? response.data : null;
    if (data is! Map<String, dynamic>) {
      ScaffoldMessenger.maybeOf(context)
          ?.showSnackBar(SnackBar(content: Text(response.reason)));
      return;
    }
    final audience = NewsletterAudience.fromJson(data);
    widget.onAudienceCreated?.call(audience);
    widget.onAudience(audience.id);
  }

  /// The first line of words in the email, as an inbox shows under the subject.
  String get _firstLine {
    if (_htmlActive) {
      return _html.text
          .replaceAll(RegExp(r'<[^>]*>'), ' ')
          .replaceAll(RegExp(r'\s+'), ' ')
          .trim();
    }
    for (final b in widget.blocks) {
      final text = '${b['text'] ?? ''}'
          .replaceAll(RegExp(r'<[^>]*>'), ' ')
          .replaceAll(RegExp(r'\s+'), ' ')
          .trim();
      if (text.isNotEmpty) return text;
    }
    return '';
  }

  Widget _inboxCard(BuildContext context) {
    final theme = Theme.of(context);
    final from = _readiness?.fromName ?? widget.company;
    final subject = widget.subject.text.trim();
    final pre = widget.preheader.text.trim();
    final line = pre.isNotEmpty ? pre : _firstLine;

    return DashboardCard(
      key: const ValueKey('newsletter-inbox-preview'),
      title: 'In their inbox',
      icon: Icons.inbox_outlined,
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          border: Border.all(color: theme.colorScheme.outlineVariant),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(from, style: theme.textTheme.titleSmall),
            const SizedBox(height: 2),
            Text(
              subject.isEmpty ? '(no subject)' : subject,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              line.isEmpty ? 'The first words of the email show here.' : line,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── step 2: design ───────────────────────────────────────────

  /// The canvas takes the room: Add content on the left (collapsible), the
  /// email in the middle, and the inspector on the right only while a block
  /// is selected.
  Widget _design(BuildContext context, {required bool bounded}) {
    final inspector = _inspector(context);
    if (!bounded) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _leftPanel(context, wrap: true),
          const SizedBox(height: 12),
          _email(context, bounded: false),
          if (inspector != null) ...[const SizedBox(height: 12), inspector],
        ],
      );
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_paletteOpen)
          SizedBox(
            width: 248,
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(12),
              child: _leftPanel(context, wrap: false),
            ),
          )
        else
          SizedBox(
            width: 48,
            child: Column(
              children: [
                const SizedBox(height: 8),
                IconButton(
                  key: const ValueKey('newsletter-palette-toggle'),
                  tooltip: 'Show Add content',
                  onPressed: () => setState(() => _paletteOpen = true),
                  icon: const Icon(Icons.add_box_outlined),
                ),
              ],
            ),
          ),
        const VerticalDivider(width: 1),
        Expanded(child: _email(context, bounded: true)),
        if (inspector != null) ...[
          const VerticalDivider(width: 1),
          SizedBox(
            width: 320,
            child: SingleChildScrollView(child: inspector),
          ),
        ],
      ],
    );
  }

  // ── step 3: review & schedule ────────────────────────────────

  /// Which step fixes a failed preflight check.
  static NewsletterStep _stepFor(String key) => switch (key) {
    'links' => NewsletterStep.design,
    _ => NewsletterStep.setup,
  };

  Widget _review(BuildContext context, {required bool bounded}) {
    final children = [
      _summaryCard(context),
      const SizedBox(height: 12),
      _checksCard(context),
      const SizedBox(height: 12),
      _scheduleCard(context),
    ];

    final column = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: children,
    );

    return bounded
        ? SingleChildScrollView(
            child: Align(
              alignment: Alignment.topCenter,
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 860),
                child: column,
              ),
            ),
          )
        : column;
  }

  Widget _summaryCard(BuildContext context) {
    final theme = Theme.of(context);
    final r = _readiness;
    NewsletterAudience? chosen;
    for (final a in widget.audiences) {
      if (a.id == widget.audienceId) chosen = a;
    }
    final recipients = r == null
        ? 'Counting…'
        : r.eligibleState == 'known' && r.eligible != null
        ? '${r.eligible} eligible'
        : r.eligibleState == 'error'
        ? 'Could not be counted right now'
        : 'Unknown — Mailchimp applies its own subscription rules';

    Widget row(String label, String value) => Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 150,
            child: Text(label, style: theme.textTheme.labelMedium),
          ),
          Expanded(child: Text(value)),
        ],
      ),
    );

    final from = [
      r?.fromName,
      if (r?.fromAddress != null) '<${r!.fromAddress}>',
    ].whereType<String>().join(' ');

    return DashboardCard(
      key: const ValueKey('newsletter-summary'),
      title: 'Summary',
      icon: Icons.summarize_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          row('Company', widget.viewName),
          row('Subject', widget.subject.text.trim().isEmpty ? '—' : widget.subject.text.trim()),
          row('From', from.isEmpty ? '—' : from),
          row('Audience', chosen == null ? 'None chosen' : '${chosen.label} · ${chosen.where}'),
          row('Recipients', recipients),
          row('Email format', _htmlActive ? 'Custom HTML' : 'Blocks'),
          row('Revision', '${widget.revision}'),
        ],
      ),
    );
  }

  /// The per-block issues a check carries (links, P1a).
  static List<Map<String, dynamic>> _issues(Map<String, dynamic> check) => [
    for (final i in (check['issues'] as List? ?? const []))
      if (i is Map) Map<String, dynamic>.from(i),
  ];

  static int? _issueIndex(Map<String, dynamic> issue) =>
      (issue['index'] as num?)?.toInt();

  Widget _checksCard(BuildContext context) {
    final theme = Theme.of(context);
    final r = _readiness;

    return DashboardCard(
      key: const ValueKey('newsletter-preflight'),
      title: 'Before it goes',
      icon: Icons.fact_check_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (r == null)
            const LinearProgressIndicator()
          else if (r.checks.isEmpty)
            const DashboardNote('The checks could not be read right now.')
          else
            for (final c in r.checks)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(
                      c['ok'] == true
                          ? Icons.check_circle_outline
                          : Icons.error_outline,
                      size: 18,
                      color: c['ok'] == true
                          ? theme.colorScheme.primary
                          : theme.colorScheme.error,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        '${c['label']}: ${c['detail']}',
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                    if (c['ok'] != true)
                      TextButton(
                        key: ValueKey('newsletter-fix-${c['key']}'),
                        onPressed: () => _stepFor('${c['key']}') == NewsletterStep.design
                            ? _jumpTo(_issues(c).isEmpty ? null : _issueIndex(_issues(c).first))
                            : _go(_stepFor('${c['key']}')),
                        child: Text('Fix in ${_stepFor('${c['key']}').label}'),
                      ),
                  ],
                ),
              ),
          if (r != null)
            for (final c in r.checks)
              if (c['ok'] != true)
                for (final (n, issue) in _issues(c).indexed)
                  if (_issueIndex(issue) != null)
                    Padding(
                      padding: const EdgeInsets.only(left: 26, bottom: 4),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${issue['where']}: “${issue['url']}”',
                              style: theme.textTheme.bodySmall,
                            ),
                          ),
                          TextButton(
                            key: ValueKey('newsletter-fix-link-$n'),
                            onPressed: () => _jumpTo(_issueIndex(issue)),
                            child: Text('Show block ${_issueIndex(issue)! + 1}'),
                          ),
                        ],
                      ),
                    ),
          if (r != null && widget.lastTest != null)
            Text(
              r.testCurrent
                  ? widget.lastTest!.sentence
                  : '${widget.lastTest!.sentence} — stale, content changed since this test.',
              key: const ValueKey('newsletter-review-last-test'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: r.testCurrent
                    ? theme.colorScheme.primary
                    : theme.colorScheme.error,
              ),
            ),
        ],
      ),
    );
  }

  Widget _scheduleCard(BuildContext context) {
    final theme = Theme.of(context);
    final refusal = _sendRefusal;
    final zone = widget.scheduledTimezone ?? _readiness?.timezone;

    return DashboardCard(
      key: const ValueKey('newsletter-schedule'),
      title: 'When',
      icon: Icons.schedule,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<bool>(
              segments: const [
                ButtonSegment(value: false, label: Text('Now')),
                ButtonSegment(value: true, label: Text('Later')),
              ],
              selected: {_scheduling},
              showSelectedIcon: false,
              onSelectionChanged: _locked
                  ? null
                  : (s) {
                      setState(() => _scheduling = s.first);
                      if (!_scheduling) {
                        widget.when.clear();
                        widget.onChanged();
                      }
                    },
            ),
          ),
          if (_scheduling) ...[
            const SizedBox(height: 8),
            CalendarDayField(
              controller: widget.when,
              formats: widget.session.me?.display ?? DisplayFormats.fallback,
              label: 'Send at',
              withTime: true,
              enabled: !_locked,
              firstDate: DateTime.now(),
              onChanged: (_) {
                setState(() {});
                widget.onChanged();
              },
            ),
            const SizedBox(height: 6),
            // Promises a wall time on the clock the SERVER picked, and names
            // that clock: a fixed offset is still selectable, so naming the
            // place alone would be wrong for half the year.
            DashboardNote(
              zone == null
                  ? 'This view’s clock — ${widget.viewName}. The time you pick is '
                        'filed against this view’s own clock, and the schedule '
                        'names which one once it is saved.'
                  : 'This view’s clock — ${widget.viewName}, '
                        '${clockLabel(zone)}. The time you pick is a wall time '
                        'on that clock.',
              key: const ValueKey('newsletter-timezone'),
            ),
          ],
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: refusal == null
                    ? Text(
                        'Ready. A newsletter cannot be unsent.',
                        style: theme.textTheme.bodySmall,
                      )
                    : Text(
                        refusal,
                        key: const ValueKey('newsletter-send-blocker'),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.error,
                        ),
                      ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // ── left: what can be added ──────────────────────────────────

  Widget _palette(BuildContext context, {required bool wrap}) {
    final theme = Theme.of(context);
    final adding = !_locked && !_htmlMode;
    final tiles = [
      for (final kind in BlockKind.values)
        _dragNew(
          kind,
          adding,
          SizedBox(
            width: 90,
            height: 72,
            child: OutlinedButton(
              onPressed: adding ? () => _add(kind) : null,
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                minimumSize: Size.zero,
                tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(kind.icon, size: 20),
                  const SizedBox(height: 4),
                  Text(kind.label, style: const TextStyle(fontSize: 12)),
                ],
              ),
            ),
          ),
        ),
    ];

    return Column(
      key: const ValueKey('tour-newsletter-blocks'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(child: Text('Add content', style: theme.textTheme.titleSmall)),
            if (!wrap)
              IconButton(
                key: const ValueKey('newsletter-palette-toggle'),
                tooltip: 'Hide Add content',
                onPressed: () => setState(() => _paletteOpen = false),
                icon: const Icon(Icons.chevron_left),
              ),
          ],
        ),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: tiles),
        const SizedBox(height: 16),
        OutlinedButton.icon(
          onPressed: _locked
              ? null
              : _htmlMode
              ? _switchToBlocks
              : () => setState(() {
                  _htmlMode = true;
                  _selected = null;
                }),
          icon: Icon(_htmlMode ? Icons.dashboard_outlined : Icons.code, size: 16),
          label: Text(_htmlMode ? 'Back to blocks' : 'Write HTML'),
        ),
        if (widget.api != null) ...[
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const ValueKey('newsletter-import'),
            onPressed: _locked ? null : () => setState(() => _importing = true),
            icon: const Icon(Icons.upload_file, size: 16),
            label: const Text('Import HTML or SVG'),
          ),
        ],
      ],
    );
  }

  /// Add content, Brand and Saved (P1b).
  Widget _leftPanel(BuildContext context, {required bool wrap}) {
    final api = widget.api;
    final tabs = SegmentedButton<String>(
      key: const ValueKey('newsletter-left-tabs'),
      segments: [
        const ButtonSegment(value: 'add', label: Text('Add')),
        if (api != null && widget.onBrand != null)
          const ButtonSegment(value: 'brand', label: Text('Brand')),
        if (api != null) const ButtonSegment(value: 'saved', label: Text('Saved')),
      ],
      selected: {_leftTab},
      showSelectedIcon: false,
      onSelectionChanged: (s) => setState(() => _leftTab = s.first),
    );
    final Widget body = switch (_leftTab) {
      'brand' when api != null && widget.onBrand != null => NewsletterBrandPanel(
        api: api,
        applied: widget.brand,
        locked: _locked,
        onApply: widget.onBrand!,
      ),
      'saved' when api != null => NewsletterSavedPanel(
        sections: _sections,
        locked: _locked || _htmlMode,
        onInsertCopy: (s) => _insertBlocks(s.copyBlocks()),
        onInsertLinked: (s) => _insertBlocks([s.reference()]),
        onPublishFromSelection: _selected == null ||
                widget.blocks[_selected!]['type'] == 'section_ref'
            ? null
            : _publishSection,
      ),
      _ => _palette(context, wrap: wrap),
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (api != null) ...[
          FittedBox(fit: BoxFit.scaleDown, child: tabs),
          const SizedBox(height: 10),
        ],
        body,
      ],
    );
  }

  /// A new block goes where it was dropped, else under the selected one, or at
  /// the end, and is selected so its fields are open where it landed.
  void _add(BlockKind kind, [int? dropped]) {
    setState(() {
      final at = dropped ?? (_selected == null ? widget.blocks.length : _selected! + 1);
      widget.blocks.insert(at, kind.blank());
      _selected = at;
      _serverPreview = false;
    });
    widget.onChanged();
  }

  /// A palette button that can also be dragged into the email, landing above
  /// the block it is dropped on (or at the end, on the footer).
  Widget _dragNew(BlockKind kind, bool adding, Widget button) {
    if (!adding) return button;
    return Draggable<BlockKind>(
      key: Key('newsletter-add-${kind.name}'),
      data: kind,
      feedback: Material(
        elevation: 4,
        borderRadius: BorderRadius.circular(6),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [Icon(kind.icon, size: 16), const SizedBox(width: 6), Text(kind.label)],
          ),
        ),
      ),
      child: button,
    );
  }

  // ── middle: the email ────────────────────────────────────────

  Widget _email(BuildContext context, {required bool bounded}) {
    final theme = Theme.of(context);
    final controls = Wrap(
      spacing: 8,
      runSpacing: 6,
      alignment: WrapAlignment.center,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        // Designing, not previewing (P1b): Mobile edits the phone overrides
        // the email carries. Preview & test is where the recipient's copy is.
        SegmentedButton<bool>(
          key: const ValueKey('newsletter-device'),
          segments: const [
            ButtonSegment(
              value: false,
              label: Text('Desktop'),
              icon: Icon(Icons.desktop_windows_outlined, size: 16),
            ),
            ButtonSegment(
              value: true,
              label: Text('Mobile'),
              icon: Icon(Icons.phone_iphone, size: 16),
            ),
          ],
          selected: {_phone},
          onSelectionChanged: (s) => setState(() => _phone = s.first),
          showSelectedIcon: false,
        ),
        if (_phone) ...[
          for (final w in const [320.0, 375.0, 414.0])
            ChoiceChip(
              key: ValueKey('newsletter-device-${w.toInt()}'),
              label: Text('${w.toInt()}'),
              selected: _deviceWidth == w,
              onSelected: (_) => setState(() => _deviceWidth = w),
            ),
          Text(
            'Editing mobile styles',
            key: const ValueKey('newsletter-editing-mobile'),
            style: theme.textTheme.labelMedium,
          ),
        ],
        if (!_htmlMode)
          FilterChip(
            tooltip: 'Shows the email exactly as the server will send it',
            label: const Text('Server preview'),
            selected: _serverPreview,
            onSelected: (on) => setState(() {
              _serverPreview = on;
              _selected = null;
            }),
          ),
      ],
    );

    final Widget body;
    if (_htmlMode) {
      body = _htmlPane(context, bounded: bounded);
    } else if (_serverPreview) {
      body = bounded
          ? Expanded(child: _serverView())
          : SizedBox(height: 520, child: _serverView());
    } else {
      final email = NewsletterEmail(
        blocks: widget.blocks,
        company: widget.company,
        phone: _phone,
        deviceWidth: _deviceWidth,
        brand: widget.brand,
        selected: _selected,
        onSelect: _locked ? null : _select,
        editorFor: _locked ? null : _inlineEditor,
        chromeFor: _locked ? null : _chrome,
        onReorder: _locked ? null : _move,
        onDropNew: _locked ? null : _add,
      );
      body = bounded
          ? Expanded(child: SingleChildScrollView(child: email))
          : email;
    }

    return Container(
      key: const ValueKey('tour-newsletter-email'),
      color: theme.colorScheme.surfaceContainerLowest,
      padding: EdgeInsets.all(bounded ? 12 : 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: bounded ? MainAxisSize.max : MainAxisSize.min,
        children: [
          _modeBar(context),
          const SizedBox(height: 8),
          controls,
          const SizedBox(height: 8),
          if (_importing && !_locked) ...[
            NewsletterImportPanel(
              api: widget.api,
              rasterise: widget.rasterise,
              onUseHtml: _useHtml,
              onUseBlocks: _useBlocks,
              onClose: () => setState(() => _importing = false),
            ),
            const SizedBox(height: 10),
          ],
          body,
        ],
      ),
    );
  }

  /// Which version goes out — blocks or custom HTML — and the way back.
  ///
  /// Audit 2026-11-10, item 2: an imported document silently replaced the
  /// blocks with nothing on screen saying which one a send would use.
  Widget _modeBar(BuildContext context) {
    final theme = Theme.of(context);
    final active = _htmlActive ? 'custom HTML' : 'the blocks';

    return Container(
      key: const ValueKey('newsletter-mode'),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(
                value: false,
                label: Text('Blocks'),
                icon: Icon(Icons.dashboard_outlined, size: 16),
              ),
              ButtonSegment(
                value: true,
                label: Text('Custom HTML'),
                icon: Icon(Icons.code, size: 16),
              ),
            ],
            selected: {_htmlMode},
            showSelectedIcon: false,
            onSelectionChanged: _locked
                ? null
                : (s) => s.first
                      ? setState(() {
                          _htmlMode = true;
                          _selected = null;
                        })
                      : _switchToBlocks(),
          ),
          Text(
            'Sending: $active',
            key: const ValueKey('newsletter-mode-active'),
            style: theme.textTheme.labelLarge,
          ),
          if (_undo != null && !_locked)
            TextButton.icon(
              key: const ValueKey('newsletter-undo'),
              onPressed: _restore,
              icon: const Icon(Icons.undo, size: 16),
              label: const Text('Undo'),
            ),
        ],
      ),
    );
  }

  /// The server's HTML, at the width the Desktop/Phone switch picked.
  Widget _serverView() {
    if (widget.previewHtml.isEmpty) {
      return const Center(child: DashboardNote('Nothing to show yet.'));
    }

    return Align(
      alignment: Alignment.topCenter,
      child: SizedBox(
        width: _phone ? _deviceWidth : double.infinity,
        child: MessageHtmlView(html: widget.previewHtml, ownHtml: true),
      ),
    );
  }

  /// Raw HTML for the whole mailing, under the server's rendering of it.
  ///
  /// It replaces the blocks rather than sitting beside them, and says so —
  /// two sources of truth for one email is how somebody edits the markup, adds
  /// a block, and silently loses the markup.
  Widget _htmlPane(BuildContext context, {required bool bounded}) {
    final box = TextField(
      controller: _html,
      minLines: 6,
      maxLines: 12,
      style: const TextStyle(fontFamily: 'monospace', fontSize: 13),
      decoration: const InputDecoration(
        hintText: '<table>…</table>',
        alignLabelWithHint: true,
      ),
      onChanged: (html) {
        setState(() {});
        widget.onHtml(html);
      },
    );

    final children = <Widget>[
      const DashboardNote(
        'Write the email yourself. While this is set it is what goes out — '
        'the blocks are kept but not used. Back to blocks clears this HTML, '
        'and Undo brings it back. The address and unsubscribe footer is added '
        'unless the HTML has its own *|UNSUB|* link.',
      ),
      const SizedBox(height: 10),
      if (bounded)
        Expanded(child: _serverView())
      else
        SizedBox(height: 520, child: _serverView()),
      const SizedBox(height: 10),
      box,
    ];

    return bounded
        ? Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: children,
            ),
          )
        : Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: children,
          );
  }

  void _select(int i) {
    setState(() {
      _selected = i;
      _pendingCrop = null;
    });
    final kind = BlockKind.forType(widget.blocks[i]['type'] as String? ?? '');
    if (kind != BlockKind.heading && kind != BlockKind.text) _keys.requestFocus();
  }

  void _duplicate(int i) {
    setState(() {
      final copy = Map<String, dynamic>.from(
        jsonDecode(jsonEncode(widget.blocks[i])) as Map,
      );
      widget.blocks.insert(i + 1, copy);
      _selected = i + 1;
    });
    widget.onChanged();
  }

  void _remove(int i) {
    setState(() {
      widget.blocks.removeAt(i);
      _selected = null;
    });
    widget.onChanged();
    _keys.requestFocus();
  }

  /// The small toolbar over the selected block: drag, up, down, duplicate,
  /// delete. The only per-block controls on the canvas.
  Widget _chrome(int i) {
    final theme = Theme.of(context);
    Widget button(String key, IconData icon, String tip, VoidCallback? on) => IconButton(
      key: ValueKey(key),
      tooltip: tip,
      onPressed: on,
      icon: Icon(icon, size: 16),
      padding: EdgeInsets.zero,
      constraints: const BoxConstraints.tightFor(width: 30, height: 30),
    );

    return Material(
      key: const ValueKey('newsletter-block-chrome'),
      elevation: 2,
      color: theme.colorScheme.surface,
      borderRadius: BorderRadius.circular(6),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          ReorderableDragStartListener(
            index: i,
            child: MouseRegion(
              cursor: SystemMouseCursors.grab,
              child: Tooltip(
                message: 'Drag to move',
                child: Padding(
                  padding: const EdgeInsets.all(6),
                  child: Icon(
                    Icons.drag_indicator,
                    key: Key('newsletter-block-handle-$i'),
                    size: 16,
                  ),
                ),
              ),
            ),
          ),
          button('newsletter-block-up', Icons.arrow_upward, 'Move up (Ctrl+Up)',
              i == 0 ? null : () => _move(i, i - 1)),
          button('newsletter-block-down', Icons.arrow_downward, 'Move down (Ctrl+Down)',
              i >= widget.blocks.length - 1 ? null : () => _move(i, i + 1)),
          button('newsletter-block-duplicate', Icons.copy_outlined, 'Duplicate (Ctrl+D)',
              () => _duplicate(i)),
          if (widget.api != null && widget.blocks[i]['type'] != 'section_ref')
            button('newsletter-block-save-section', Icons.bookmark_add_outlined, 'Save as section',
                () => _saveSection(i)),
          button('newsletter-block-delete', Icons.delete_outline, 'Delete (Del)',
              () => _remove(i)),
        ],
      ),
    );
  }

  MarkupEditingController _controllerFor(Map<String, dynamic> block) {
    if (_inline == null ||
        !identical(_inlineBlock, block) ||
        _inlineGeneration != widget.historyGeneration) {
      final old = _inline;
      if (old != null) {
        WidgetsBinding.instance.addPostFrameCallback((_) => old.dispose());
      }
      _inline = MarkupEditingController(text: '${block['text'] ?? ''}');
      _inlineBlock = block;
      _inlineGeneration = widget.historyGeneration;
    }
    return _inline!;
  }

  void _writeInline(Map<String, dynamic> block, String text, {bool rich = false}) {
    setState(() {
      block['text'] = text;
      if (rich || hasMarkup(text)) block['rich'] = true;
    });
    widget.onChanged();
  }

  /// A selected heading or text is typed into where it sits, with bold,
  /// italic, link and (text) list. Everything else edits in the inspector.
  Widget? _inlineEditor(int i) {
    final block = widget.blocks[i];
    if (isHandWritten(block)) return null;
    final kind = BlockKind.forType(block['type'] as String? ?? '');
    if (kind != BlockKind.heading && kind != BlockKind.text) return null;

    final controller = _controllerFor(block);
    final style = kind == BlockKind.heading
        ? EmailBlockView.headingStyle(block)
        : EmailBlockView.textStyle(block);
    final align = switch (block['align']) {
      'center' => TextAlign.center,
      'right' => TextAlign.right,
      _ => TextAlign.left,
    };
    final spacing = block['spacing'] is num ? (block['spacing'] as num).toDouble() : 14.0;

    void format(TextEditingValue Function(TextEditingValue) change) {
      controller.value = change(controller.value);
      _writeInline(block, controller.text, rich: true);
    }

    Widget tool(String key, IconData icon, String tip, VoidCallback on) => IconButton(
      key: ValueKey(key),
      tooltip: tip,
      onPressed: on,
      icon: Icon(icon, size: 16, color: EmailInk.body),
      padding: EdgeInsets.zero,
      constraints: const BoxConstraints.tightFor(width: 28, height: 28),
    );

    return Padding(
      padding: EdgeInsets.fromLTRB(20, spacing, 20, spacing),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            key: const ValueKey('newsletter-format-bar'),
            children: [
              tool('newsletter-format-bold', Icons.format_bold, 'Bold',
                  () => format((v) => wrapSelection(v, '<b>', '</b>'))),
              tool('newsletter-format-italic', Icons.format_italic, 'Italic',
                  () => format((v) => wrapSelection(v, '<i>', '</i>'))),
              tool('newsletter-format-link', Icons.link, 'Link', () => format(insertLink)),
              if (kind == BlockKind.text)
                tool('newsletter-format-list', Icons.format_list_bulleted, 'List',
                    () => format(makeList)),
            ],
          ),
          TextField(
            key: ValueKey('newsletter-inline-$i'),
            controller: controller,
            autofocus: true,
            maxLines: null,
            textAlign: align,
            style: style,
            cursorColor: EmailInk.heading,
            decoration: const InputDecoration.collapsed(hintText: 'Type here'),
            onChanged: (v) => _writeInline(block, v),
          ),
        ],
      ),
    );
  }

  /// The inspector: only the settings that belong to the selected block's
  /// type, and only while a block is selected.
  Widget? _inspector(BuildContext context) {
    final i = _selected;
    if (i == null || i >= widget.blocks.length || _locked || _htmlMode || _serverPreview) {
      return null;
    }
    final block = widget.blocks[i];
    final kind = BlockKind.forType(block['type'] as String? ?? '');
    final theme = Theme.of(context);

    return Container(
      key: const ValueKey('newsletter-inspector'),
      color: theme.colorScheme.surface,
      padding: const EdgeInsets.all(12),
      child: KeyedSubtree(
        key: ValueKey('inspector-${widget.historyGeneration}-$i-${identityHashCode(block)}'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(kind?.icon ?? Icons.help_outline, size: 18),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    '${kind?.label ?? (block['type'] == 'section_ref' ? 'Linked section' : block['type'] as String? ?? 'Block')} '
                    '${_phone ? 'mobile settings' : 'settings'}',
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                IconButton(
                  key: const ValueKey('newsletter-inspector-close'),
                  tooltip: 'Done',
                  onPressed: () => setState(() => _selected = null),
                  icon: const Icon(Icons.close, size: 18),
                ),
              ],
            ),
            if (isHandWritten(block))
              const DashboardNote(
                'This block was written as HTML and is kept exactly as typed.',
              )
            else if (block['type'] == 'section_ref')
              ..._linkedFields(i, block)
            else if (_phone)
              ..._mobileFields(block, kind)
            else
              ..._fieldsFor(context, block, kind),
          ],
        ),
      ),
    );
  }

  /// A linked section (P1b): which version it is pinned to, and the explicit
  /// update when a newer one is published. Nothing updates by itself.
  List<Widget> _linkedFields(int i, Map<String, dynamic> block) {
    NewsletterSavedSection? latest;
    for (final s in _sections ?? const <NewsletterSavedSection>[]) {
      if (!s.starter && s.id == block['section_id']) latest = s;
    }
    final pinned = (block['version'] as num?)?.toInt() ?? 0;
    return [
      Text('${block['name'] ?? 'Saved section'} · version $pinned'),
      const SizedBox(height: 6),
      if (latest != null && latest.version > pinned) ...[
        Text(
          'Update available: version ${latest.version}',
          key: const ValueKey('newsletter-linked-update-available'),
        ),
        const SizedBox(height: 6),
        FilledButton(
          key: const ValueKey('newsletter-linked-update'),
          onPressed: () {
            final ref = latest!.reference();
            setState(() => block
              ..['version'] = ref['version']
              ..['name'] = ref['name']
              ..['preview_blocks'] = ref['preview_blocks']);
            widget.onChanged();
          },
          child: const Text('Update to latest'),
        ),
      ] else
        const DashboardNote('Up to date with the saved section.'),
      const SizedBox(height: 6),
      OutlinedButton(
        key: const ValueKey('newsletter-linked-detach'),
        onPressed: () {
          setState(() {
            widget.blocks
              ..removeAt(i)
              ..insertAll(i, [
                for (final b in (block['preview_blocks'] as List? ?? const []))
                  if (b is Map) Map<String, dynamic>.from(jsonDecode(jsonEncode(b)) as Map),
              ]);
            _selected = null;
          });
          widget.onChanged();
        },
        child: const Text('Detach as an editable copy'),
      ),
    ];
  }

  /// The block's mobile overrides (P1b). Each field shows the desktop value
  /// greyed as "from desktop" until it is overridden; an override has a reset.
  List<Widget> _mobileFields(Map<String, dynamic> block, BlockKind? kind) {
    final fields = <String>[
      if (kind == BlockKind.heading || kind == BlockKind.text || kind == BlockKind.button) 'font_size',
      if (kind != BlockKind.divider && kind != BlockKind.spacer) 'padding',
      if (kind == BlockKind.heading || kind == BlockKind.text || kind == BlockKind.image || kind == BlockKind.button)
        'align',
      if (kind == BlockKind.button) 'button_full_width',
      if (kind == BlockKind.columns) 'stack',
      'hidden',
    ];
    return [
      const DashboardNote('Phone overrides. Anything not overridden comes from desktop.'),
      for (final f in fields) _mobileField(block, kind, f),
    ];
  }

  Object? _desktopValue(Map<String, dynamic> block, BlockKind? kind, String f) => switch (f) {
    'font_size' => block['size'] ?? (kind == BlockKind.heading ? 24 : 15),
    'padding' => block['spacing'] ?? 14,
    'align' => block['align'] ?? 'left',
    'button_full_width' => block['full_width'] == true,
    'stack' => block['stack'] != false,
    _ => false,
  };

  void _setMobile(Map<String, dynamic> block, String f, Object? v, {bool redraw = false}) {
    setState(() {
      final m = Map<String, dynamic>.from(block['mobile'] as Map? ?? const {});
      if (v == null) {
        m.remove(f);
      } else {
        m[f] = v;
      }
      if (m.isEmpty) {
        block.remove('mobile');
      } else {
        block['mobile'] = m;
      }
      if (redraw) _mobileGeneration++;
    });
    widget.onChanged();
  }

  Widget _mobileField(Map<String, dynamic> block, BlockKind? kind, String f) {
    final theme = Theme.of(context);
    final mobile = block['mobile'] is Map ? block['mobile'] as Map : const {};
    final overridden = mobile.containsKey(f);
    final desktop = _desktopValue(block, kind, f);
    final value = overridden ? mobile[f] : desktop;
    const labels = {
      'font_size': 'Text size (px)',
      'padding': 'Space above and below (px)',
      'align': 'Alignment',
      'button_full_width': 'Full width',
      'stack': 'Stack columns',
      'hidden': 'Hide on mobile',
    };

    final Widget control = switch (f) {
      'font_size' || 'padding' => TextFormField(
        key: ValueKey('newsletter-mobile-$f'),
        initialValue: overridden ? '$value' : '',
        keyboardType: TextInputType.number,
        decoration: InputDecoration(isDense: true, hintText: '$desktop'),
        onChanged: (t) => _setMobile(block, f, int.tryParse(t.trim())),
      ),
      'align' => SegmentedButton<String>(
        key: ValueKey('newsletter-mobile-$f'),
        segments: const [
          ButtonSegment(value: 'left', icon: Icon(Icons.format_align_left, size: 16)),
          ButtonSegment(value: 'center', icon: Icon(Icons.format_align_center, size: 16)),
          ButtonSegment(value: 'right', icon: Icon(Icons.format_align_right, size: 16)),
        ],
        selected: {'$value'},
        showSelectedIcon: false,
        onSelectionChanged: (s) => _setMobile(block, f, s.first, redraw: true),
      ),
      _ => Switch(
        key: ValueKey('newsletter-mobile-$f'),
        value: value == true,
        onChanged: (v) => _setMobile(block, f, v, redraw: true),
      ),
    };

    return KeyedSubtree(
      key: ValueKey('mobile-$f-$_mobileGeneration'),
      child: Padding(
        padding: const EdgeInsets.only(top: 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(labels[f]!, style: theme.textTheme.labelMedium)),
                if (overridden)
                  TextButton(
                    key: ValueKey('newsletter-mobile-reset-$f'),
                    onPressed: () => _setMobile(block, f, null, redraw: true),
                    child: const Text('Reset'),
                  )
                else if (f != 'hidden')
                  Text(
                    'from desktop',
                    key: ValueKey('newsletter-mobile-inherited-$f'),
                    style: theme.textTheme.labelSmall?.copyWith(color: theme.disabledColor),
                  ),
              ],
            ),
            Opacity(opacity: overridden || f == 'hidden' ? 1 : 0.6, child: control),
          ],
        ),
      ),
    );
  }

  List<Widget> _fieldsFor(
    BuildContext context,
    Map<String, dynamic> block,
    BlockKind? kind,
  ) {
    return switch (kind) {
      BlockKind.heading => [
        const DashboardNote('Type the words on the canvas.'),
        _number(block, 'size', 'Size in pixels'),
        _align(block),
        _lineHeight(block),
        _colorField(block, 'color', 'Text colour'),
        _number(block, 'spacing', 'Space above and below (px)'),
      ],
      BlockKind.text => [
        const DashboardNote('Type the words on the canvas.'),
        _number(block, 'size', 'Size in pixels'),
        _align(block),
        _lineHeight(block),
        _colorField(block, 'color', 'Text colour'),
        _number(block, 'spacing', 'Space above and below (px)'),
      ],
      BlockKind.image => [
        ..._imageFields(context, block),
        _number(block, 'spacing', 'Space above and below (px)'),
      ],
      BlockKind.button => [
        _text(block, 'text', 'Label'),
        _text(block, 'url', 'Where it goes'),
        _colorField(block, 'background', 'Button colour'),
        _colorField(block, 'color', 'Label colour'),
        SwitchListTile(
          key: const ValueKey('newsletter-field-full_width'),
          contentPadding: EdgeInsets.zero,
          dense: true,
          title: const Text('Full width'),
          value: block['full_width'] == true,
          onChanged: (v) {
            setState(() => block['full_width'] = v);
            widget.onChanged();
          },
        ),
        if (block['full_width'] != true) _align(block),
        _number(block, 'spacing', 'Space above and below (px)'),
      ],
      BlockKind.spacer => [_number(block, 'height', 'Height in points')],
      BlockKind.columns => [_columns(context, block)],
      BlockKind.divider => const [
        Padding(
          padding: EdgeInsets.only(top: 6),
          child: DashboardNote('A line across the email. Nothing to set.'),
        ),
      ],
      _ => const [],
    };
  }

  Widget _lineHeight(Map<String, dynamic> block) {
    const options = ['1.2', '1.55', '1.8'];
    final raw = block['line_height'];
    final current = raw is num && options.contains('$raw') ? '$raw' : null;
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Align(
        alignment: Alignment.centerLeft,
        child: SegmentedButton<String>(
          key: const ValueKey('newsletter-field-line_height'),
          emptySelectionAllowed: true,
          segments: const [
            ButtonSegment(value: '1.2', label: Text('Tight')),
            ButtonSegment(value: '1.55', label: Text('Normal')),
            ButtonSegment(value: '1.8', label: Text('Loose')),
          ],
          selected: {?current},
          showSelectedIcon: false,
          onSelectionChanged: (s) {
            setState(() {
              if (s.isEmpty) {
                block.remove('line_height');
              } else {
                block['line_height'] = double.parse(s.first);
              }
            });
            widget.onChanged();
          },
        ),
      ),
    );
  }

  Widget _colorField(Map<String, dynamic> block, String field, String label) {
    const swatches = ['#00708F', '#060808', '#3A4650', '#FFFFFF', '#B3261E', '#E67E22', '#2E7D32', '#F5F8FA'];
    final theme = Theme.of(context);
    final current = '${block[field] ?? ''}'.toUpperCase();
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: theme.textTheme.labelMedium),
          const SizedBox(height: 4),
          Wrap(
            key: ValueKey('newsletter-field-$field'),
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              for (final sw in swatches)
                Tooltip(
                  message: sw,
                  child: InkWell(
                    customBorder: const CircleBorder(),
                    onTap: () {
                      setState(() => block[field] = sw);
                      widget.onChanged();
                    },
                    child: Container(
                      width: 24,
                      height: 24,
                      decoration: BoxDecoration(
                        color: blockColor({'c': sw}, 'c', Colors.transparent),
                        shape: BoxShape.circle,
                        border: Border.all(
                          color: current == sw
                              ? theme.colorScheme.primary
                              : theme.colorScheme.outline,
                          width: current == sw ? 3 : 1,
                        ),
                      ),
                    ),
                  ),
                ),
              TextButton(
                onPressed: block.containsKey(field)
                    ? () {
                        setState(() => block.remove(field));
                        widget.onChanged();
                      }
                    : null,
                child: const Text('Default'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// A row of columns, each holding its own blocks.
  ///
  /// Two levels deep and no further. Columns inside columns is a layout email
  /// clients disagree about, and the preview would stop being a promise.
  Widget _columns(BuildContext context, Map<String, dynamic> block) {
    final columns = ((block['columns'] as List<dynamic>?) ?? [])
        .whereType<Map<String, dynamic>>()
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 6),
        if (columns.length == 2)
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<String>(
              key: const ValueKey('newsletter-field-ratio'),
              segments: const [
                ButtonSegment(value: '1:1', label: Text('Equal')),
                ButtonSegment(value: '1:2', label: Text('1 : 2')),
                ButtonSegment(value: '2:1', label: Text('2 : 1')),
              ],
              selected: {
                ['1:2', '2:1'].contains(block['ratio']) ? block['ratio'] as String : '1:1',
              },
              showSelectedIcon: false,
              onSelectionChanged: (s) {
                setState(() {
                  if (s.first == '1:1') {
                    block.remove('ratio');
                  } else {
                    block['ratio'] = s.first;
                  }
                });
                widget.onChanged();
              },
            ),
          ),
        _number(block, 'padding', 'Padding inside each column (px)'),
        SwitchListTile(
          key: const ValueKey('newsletter-field-stack'),
          contentPadding: EdgeInsets.zero,
          dense: true,
          title: const Text('Stack on phones'),
          value: block['stack'] != false,
          onChanged: (v) {
            setState(() => block['stack'] = v);
            widget.onChanged();
          },
        ),
        const SizedBox(height: 8),
        for (var c = 0; c < columns.length; c++)
          _column(context, block, columns, c),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: columns.length >= 3
                ? null
                : () {
                    setState(() {
                      columns.add({'blocks': <Map<String, dynamic>>[]});
                      block['columns'] = columns;
                    });
                    widget.onChanged();
                  },
            icon: const Icon(Icons.add, size: 16),
            // Three is where a 600-point email runs out of room; a fourth
            // column is 130 points wide and unreadable on a phone.
            label: Text(columns.length >= 3 ? 'Three columns is the most' : 'Add a column'),
          ),
        ),
      ],
    );
  }

  Widget _column(
    BuildContext context,
    Map<String, dynamic> parent,
    List<Map<String, dynamic>> columns,
    int index,
  ) {
    final inner = ((columns[index]['blocks'] as List<dynamic>?) ?? [])
        .whereType<Map<String, dynamic>>()
        .toList();

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Column ${index + 1}',
                  style: Theme.of(context).textTheme.labelMedium,
                ),
              ),
              IconButton(
                tooltip: 'Remove column',
                onPressed: columns.length <= 1
                    ? null
                    : () {
                        setState(() {
                          columns.removeAt(index);
                          parent['columns'] = columns;
                        });
                        widget.onChanged();
                      },
                icon: const Icon(Icons.close, size: 14),
              ),
            ],
          ),
          for (var i = 0; i < inner.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(
                children: [
                  Expanded(child: _text(inner[i], 'text', BlockKind.forType(inner[i]['type'] as String? ?? '')?.label ?? 'Text')),
                  IconButton(
                    tooltip: 'Remove',
                    onPressed: () {
                      setState(() {
                        inner.removeAt(i);
                        columns[index]['blocks'] = inner;
                      });
                      widget.onChanged();
                    },
                    icon: const Icon(Icons.close, size: 14),
                  ),
                ],
              ),
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: Wrap(
              spacing: 4,
              children: [
                for (final kind in [BlockKind.heading, BlockKind.text, BlockKind.image, BlockKind.button])
                  TextButton(
                    onPressed: () {
                      setState(() {
                        inner.add(kind.blank());
                        columns[index]['blocks'] = inner;
                      });
                      widget.onChanged();
                    },
                    child: Text('+ ${kind.label}', style: const TextStyle(fontSize: 12)),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  void _move(int from, int to) {
    setState(() {
      final block = widget.blocks.removeAt(from);
      widget.blocks.insert(to, block);
      _selected = to;
    });
    widget.onChanged();
  }

  /// How many pictures were chosen from the library; keys the address field.
  int _picks = 0;

  /// The image block's inspector (newsletter P0, 2026-11-17): Upload images
  /// and Browse library at once on an empty block; Replace on a full one
  /// (cancelling keeps the picture); alt text or Decorative; link;
  /// alignment; displayed width. Crop and focal point are not offered yet.
  List<Widget> _imageFields(BuildContext context, Map<String, dynamic> block) {
    final empty = '${block['src'] ?? ''}'.trim().isEmpty;
    final decorative = block['decorative'] == true;
    final editable = widget.api != null && widget.readOnlyBecause == null;
    return [
      if (editable)
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              if (empty) ...[
                FilledButton.icon(
                  key: const ValueKey('newsletter-image-upload'),
                  onPressed: () =>
                      _chooseFromLibrary(context, block, upload: true),
                  icon: const Icon(Icons.upload, size: 18),
                  label: const Text('Upload images'),
                ),
                OutlinedButton.icon(
                  key: const ValueKey('newsletter-image-library'),
                  onPressed: () => _chooseFromLibrary(context, block),
                  icon: const Icon(Icons.photo_library_outlined, size: 18),
                  label: const Text('Browse library'),
                ),
              ] else
                OutlinedButton.icon(
                  key: const ValueKey('newsletter-image-replace'),
                  onPressed: () => _chooseFromLibrary(context, block),
                  icon: const Icon(Icons.swap_horiz, size: 18),
                  label: const Text('Replace'),
                ),
            ],
          ),
        ),
      // Keyed by each pick, so the field shows the address a pick wrote.
      KeyedSubtree(
        key: ValueKey('newsletter-image-src-$_picks'),
        child: _text(block, 'src', 'Image address'),
      ),
      Padding(
        padding: const EdgeInsets.only(top: 6),
        child: SwitchListTile(
          key: const ValueKey('newsletter-image-decorative'),
          contentPadding: EdgeInsets.zero,
          dense: true,
          title: const Text('Decorative'),
          subtitle: const Text('Screen readers skip it; no description is sent'),
          value: decorative,
          onChanged: (v) {
            setState(() => block['decorative'] = v);
            widget.onChanged();
          },
        ),
      ),
      if (!decorative)
        KeyedSubtree(
          key: ValueKey('newsletter-image-alt-$_picks'),
          child: _text(block, 'alt', 'Describe the picture (alt text)'),
        ),
      _text(block, 'href', 'Link (optional)'),
      _align(block),
      _number(block, 'width', 'Displayed width in pixels (up to 560; blank = full)'),
      if (!empty && editable) _cropControls(context, block),
    ];
  }

  /// Crop by shape and focal point (P1a). The server makes a cropped copy;
  /// the block keeps the original's address, so Original goes back to it.
  Widget _cropControls(BuildContext context, Map<String, dynamic> block) {
    final theme = Theme.of(context);
    final original = '${block['original_src'] ?? block['src'] ?? ''}';
    final ours = original.contains('/api/v1/newsletter/artwork/');
    final saved = block['crop'] is Map ? block['crop'] as Map : const {};
    final c = _pendingCrop ??
        (
          aspect: '${saved['aspect'] ?? 'original'}',
          x: (saved['x'] as num?)?.toDouble() ?? 0.5,
          y: (saved['y'] as num?)?.toDouble() ?? 0.5,
        );

    return Padding(
      key: const ValueKey('newsletter-crop'),
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Crop', style: theme.textTheme.labelLarge),
          const SizedBox(height: 6),
          if (!ours)
            const DashboardNote('Crop works on pictures uploaded or chosen from the library.')
          else ...[
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final a in ['original', '1:1', '4:3', '3:2', '16:9'])
                  ChoiceChip(
                    key: ValueKey('newsletter-crop-$a'),
                    label: Text(a == 'original' ? 'Original' : a),
                    selected: c.aspect == a,
                    onSelected: (_) => setState(() => _pendingCrop = (aspect: a, x: c.x, y: c.y)),
                  ),
              ],
            ),
            if (c.aspect != 'original') ...[
              const SizedBox(height: 6),
              Text('Focal point: what stays in frame', style: theme.textTheme.bodySmall),
              Slider(
                key: const ValueKey('newsletter-crop-x'),
                value: c.x.clamp(0, 1).toDouble(),
                label: 'Left to right',
                onChanged: (v) => setState(() => _pendingCrop = (aspect: c.aspect, x: v, y: c.y)),
              ),
              Slider(
                key: const ValueKey('newsletter-crop-y'),
                value: c.y.clamp(0, 1).toDouble(),
                label: 'Top to bottom',
                onChanged: (v) => setState(() => _pendingCrop = (aspect: c.aspect, x: c.x, y: v)),
              ),
            ],
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.tonal(
                key: const ValueKey('newsletter-crop-apply'),
                onPressed: _cropping ? null : () => _applyCrop(block, c),
                child: Text(c.aspect == 'original' ? 'Use the original' : 'Apply crop'),
              ),
            ),
            const SizedBox(height: 4),
            Text(
              'The original picture is kept; you can go back to it at any time.',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _applyCrop(
    Map<String, dynamic> block,
    ({String aspect, double x, double y}) c,
  ) async {
    final original = '${block['original_src'] ?? block['src'] ?? ''}';
    if (c.aspect == 'original') {
      setState(() {
        block['src'] = original;
        block.remove('original_src');
        block.remove('crop');
        _picks++;
        _pendingCrop = null;
      });
      widget.onChanged();
      return;
    }
    setState(() => _cropping = true);
    final response = await widget.api!.post('/newsletter/images/crop', {
      'src': original,
      'aspect': c.aspect,
      'focal_x': c.x,
      'focal_y': c.y,
    });
    if (!mounted) return;
    final data = response.ok ? response.data : null;
    final url = data is Map ? data['url'] as String? : null;
    setState(() {
      _cropping = false;
      if (url != null) {
        block['original_src'] = original;
        block['src'] = url;
        block['crop'] = {'aspect': c.aspect, 'x': c.x, 'y': c.y};
        _picks++;
        _pendingCrop = null;
      }
    });
    if (url != null) {
      widget.onChanged();
    } else {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(response.reason)));
    }
  }

  /// A picture from the media library, copied to an address an inbox can
  /// load (review 2026-11-09 A29), instead of a typed URL.
  Future<void> _chooseFromLibrary(
    BuildContext context,
    Map<String, dynamic> block, {
    bool upload = false,
  }) async {
    final api = widget.api!;
    final item = await showMediaLibraryPanel(
      context,
      api: api,
      pickMedia: () async => null,
      canEdit: false,
      uploadPath: '/newsletter/images',
      initialKind: 'image',
      uploadFirst: upload,
    );
    // Cancelled: the block keeps the picture it had.
    if (item == null || !mounted) return;
    String? url = item['url'] as String?;
    var reason = '';
    if (url == null) {
      final response = await api.post(
        '/newsletter/import/library/${item['id']}',
        const {},
      );
      if (!mounted) return;
      final data = response.ok ? response.data : null;
      url = data is Map ? data['url'] as String? : null;
      reason = response.reason;
    }
    if (url == null) {
      ScaffoldMessenger.maybeOf(
        this.context,
      )?.showSnackBar(SnackBar(content: Text(reason)));
      return;
    }
    setState(() {
      block['src'] = url;
      // A new picture starts uncropped.
      block.remove('original_src');
      block.remove('crop');
      final name = '${item['name'] ?? ''}'.trim();
      if ('${block['alt'] ?? ''}'.trim().isEmpty && name.isNotEmpty) {
        block['alt'] = name;
      }
      _picks++;
    });
    widget.onChanged();
  }

  Widget _text(Map<String, dynamic> block, String field, String label, {int lines = 1}) {
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: TextFormField(
        key: ValueKey('newsletter-field-$field'),
        initialValue: block[field] as String? ?? '',
        minLines: lines,
        maxLines: lines == 1 ? 1 : lines + 4,
        decoration: InputDecoration(labelText: label, isDense: true),
        onChanged: (v) {
          // setState so the drawing under the cursor follows the typing.
          setState(() => block[field] = v);
          widget.onChanged();
        },
      ),
    );
  }

  Widget _number(Map<String, dynamic> block, String field, String label) {
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: TextFormField(
        key: ValueKey('newsletter-field-$field'),
        initialValue: '${block[field] ?? ''}',
        keyboardType: TextInputType.number,
        decoration: InputDecoration(labelText: label, isDense: true),
        onChanged: (v) {
          setState(() {
            final n = int.tryParse(v);
            if (n == null) {
              block.remove(field);
            } else {
              block[field] = n;
            }
          });
          widget.onChanged();
        },
      ),
    );
  }

  Widget _align(Map<String, dynamic> block) {
    const options = ['left', 'center', 'right'];
    final current = options.contains(block['align']) ? block['align'] as String : 'left';

    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Align(
        alignment: Alignment.centerLeft,
        child: SegmentedButton<String>(
          key: const ValueKey('newsletter-field-align'),
          segments: const [
            ButtonSegment(value: 'left', icon: Icon(Icons.format_align_left, size: 15)),
            ButtonSegment(value: 'center', icon: Icon(Icons.format_align_center, size: 15)),
            ButtonSegment(value: 'right', icon: Icon(Icons.format_align_right, size: 15)),
          ],
          selected: {current},
          showSelectedIcon: false,
          onSelectionChanged: (s) {
            setState(() => block['align'] = s.first);
            widget.onChanged();
          },
        ),
      ),
    );
  }
}
