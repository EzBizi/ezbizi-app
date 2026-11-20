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
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../../core/api_client.dart';
import 'newsletter_blocks.dart';
import 'newsletter_brand_saved.dart';
import 'newsletter_import_panel.dart';
import 'newsletter_preflight.dart';
import 'newsletter_studio.dart';
import 'newsletter_templates.dart';

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
    this.initialActiveDesign,
    this.onActiveDesign,
    this.onEditableCopy,
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
    this.onImportedAsNew,
    this.localCopyNote,
    this.onRestoreLocal,
    this.onDiscardLocal,
    this.brand,
    this.onBrand,
    this.initialStep,
    this.initialSelected,
  });

  /// The stage it opens on (a link into Design or Review); null: Setup.
  final NewsletterStep? initialStep;

  /// The block selected when it opens on Design.
  final int? initialSelected;

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

  /// The import wizard saved a new draft with this id: open it.
  final ValueChanged<String>? onImportedAsNew;

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

  /// 'html' or 'blocks': the saved design preview, test and send render.
  final String? initialActiveDesign;

  /// "Use this design": the only way the active design changes.
  final ValueChanged<String>? onActiveDesign;

  /// The draft after "Create editable copy" saved blocks beside the HTML.
  final ValueChanged<Map<String, dynamic>>? onEditableCopy;

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

  /// The block being saved as a section (its name form is in the inspector).
  int? _namingSection;
  final TextEditingController _sectionName = TextEditingController();
  bool _sectionLinked = false;

  /// Bumped when a mobile override is reset, so its field redraws.
  int _mobileGeneration = 0;

  /// Show the server's HTML instead of the Flutter drawing, so the exact
  /// output stays checkable.
  bool _serverPreview = false;

  bool get _locked => widget.readOnlyBecause != null;

  /// The import panel is open.
  bool _importing = false;

  /// What was there before the last import or switch, for Undo.
  ({String html, List<Map<String, dynamic>> blocks, bool htmlMode, String active})? _undo;

  /// The design that goes out: changed only by "Use this design", never by
  /// switching views (suggestion 01a1f9ed). Both designs are kept.
  String _active = 'blocks';

  bool get _hasHtml => _html.text.trim().isNotEmpty;

  /// Custom HTML goes out when it is the active design and has content.
  bool get _htmlActive => _active == 'html' && _hasHtml;

  void _useDesign(String design) {
    setState(() => _active = design);
    widget.onActiveDesign?.call(design);
  }

  /// The server's preflight for the saved revision; null until it answers.
  NewsletterReadiness? _readiness;
  bool _resolving = false;

  /// The library is open (it collapses to a labelled rail); null follows
  /// the width: open when the library and inspector both fit.
  bool? _paletteOpen;

  /// The proof in Setup and Review: drawn at a phone's width.
  bool _proofPhone = false;

  /// Design canvas: the email scaled to the canvas width (Fit).
  bool _fit = false;

  /// One column (narrow): Setup and Review show the proof instead of the form.
  bool _narrowPreview = false;

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
      active: _active,
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
    // Applying an import is an explicit choice of the design it made.
    _useDesign('html');
  }

  /// Before an import replaces the content (P1c): what is on screen is saved,
  /// then that saved version is kept in history as "Before import", so the
  /// import can be undone from History as well as with Undo. False stops it.
  Future<bool> _beforeImport() async {
    final api = widget.api;
    final id = widget.newsletterId;
    if (api == null || id == null) return true;
    await widget.onSaveNow?.call();
    final response = await api.post('/newsletters/$id/checkpoint', {'label': 'Before import'});
    return response.ok;
  }

  void _useBlocks(List<Map<String, dynamic>> blocks) {
    setState(() {
      _snapshot();
      widget.blocks
        ..clear()
        ..addAll(blocks);
      _htmlMode = false;
      _importing = false;
      _selected = null;
    });
    widget.onChanged();
    _useDesign('blocks');
  }

  /// Switching views is navigation: the HTML stays saved and stays the
  /// active design until "Use this design" says otherwise.
  void _switchToBlocks() {
    setState(() {
      _htmlMode = false;
      _selected = null;
    });
  }

  /// "Create editable copy": the server converts the HTML, the side panel
  /// shows the original beside the blocks and what will not carry over, and
  /// only Create copy saves the blocks. The HTML stays active.
  Future<void> _editableCopy() async {
    final api = widget.api;
    final id = widget.newsletterId;
    if (api == null || id == null) return;
    await widget.onSaveNow?.call();
    final r = await api.post('/newsletters/$id/editable-copy', {});
    if (!mounted) return;
    if (!r.ok || r.data is! Map) {
      _say(r.reason);
      return;
    }
    final data = Map<String, dynamic>.from(r.data as Map);
    final create = await showSidePanel<bool>(
      context,
      builder: (_) => EditableCopyPanel(preview: data),
    );
    if (create != true || !mounted) return;
    final made = await api.post('/newsletters/$id/editable-copy', {
      'confirm': true,
      'revision': widget.revision,
    });
    if (!mounted) return;
    if (!made.ok || made.data is! Map) {
      _say(made.reason);
      return;
    }
    widget.onEditableCopy?.call(Map<String, dynamic>.from(made.data as Map));
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
    _useDesign(undo.active);
  }

  @override
  void initState() {
    super.initState();
    // Scheduled or sent: nothing to set up, so it opens where its time,
    // audience and checks are read.
    if (widget.initialStep != null) _step = widget.initialStep!;
    final pick = widget.initialSelected;
    if (pick != null && pick >= 0 && pick < widget.blocks.length) _selected = pick;
    if (widget.readOnlyBecause != null) _step = NewsletterStep.review;
    _scheduling = widget.when.text.trim().isNotEmpty;
    final html = widget.initialHtml ?? '';
    _active = widget.initialActiveDesign ?? (html.isNotEmpty ? 'html' : 'blocks');
    if (html.isNotEmpty) _html.text = html;
    _htmlMode = _active == 'html' && html.isNotEmpty;
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
    final name = _sectionName.text.trim();
    if (name.isEmpty) return;
    final r = await api.post('/newsletter/sections', {
      'name': name,
      'mode': _sectionLinked ? 'linked' : 'copy',
      'blocks': [widget.blocks[i]],
    });
    if (!mounted) return;
    _say(r.ok ? 'Saved as "$name".' : r.reason);
    if (r.ok) {
      setState(() {
        _namingSection = null;
        _sectionName.clear();
      });
      await _loadSections();
    }
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
      final active = widget.initialActiveDesign;
      if (active != null) _active = active;
      if (_selected != null && _selected! >= widget.blocks.length) _selected = null;
      _pendingCrop = null;
    }
  }

  @override
  void dispose() {
    _keys.dispose();
    _subjectFocus.dispose();
    _audienceFocus.dispose();
    _inline?.dispose();
    _sectionName.dispose();
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
    if (widget.blocks.isEmpty && !_hasHtml) {
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
          final narrow = constraints.maxWidth < Studio.stacked;
          final top = [
            _header(context, narrow: narrow),
            ..._banners(context),
            _stepBar(context, narrow: narrow),
          ];

          if (narrow) {
            return ColoredBox(
              color: Studio.pane(context),
              child: ListView(
                padding: EdgeInsets.zero,
                children: [
                  ...top,
                  Padding(
                    padding: const EdgeInsets.all(Studio.grid * 2),
                    child: _stepBody(context, bounded: false),
                  ),
                ],
              ),
            );
          }

          return ColoredBox(
            color: Studio.pane(context),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                ...top,
                Expanded(child: _stepBody(context, bounded: true)),
              ],
            ),
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

    final titleStyle =
        (narrow ? theme.textTheme.titleLarge : theme.textTheme.headlineSmall)
            ?.copyWith(fontWeight: FontWeight.w700);
    final name = _locked
        ? Text(
            shown,
            key: const ValueKey('newsletter-title'),
            overflow: TextOverflow.ellipsis,
            style: titleStyle,
          )
        : Semantics(
            label: 'Internal name: $shown',
            textField: true,
            child: TextField(
              key: const ValueKey('newsletter-name'),
              controller: widget.name,
              style: titleStyle,
              maxLength: 255,
              decoration: InputDecoration(
                isDense: true,
                counterText: '',
                filled: false,
                border: InputBorder.none,
                enabledBorder: InputBorder.none,
                contentPadding: const EdgeInsets.symmetric(vertical: 4),
                hintText: subject.isEmpty ? 'Untitled newsletter' : subject,
              ),
              onChanged: (_) {
                setState(() {});
                widget.onChanged();
              },
            ),
          );

    final unscheduleRefusal = widget.session.me?.refusal(
      'newsletter.send',
      'unschedule a newsletter',
    );

    final dot = switch (widget.status) {
      'scheduled' || 'sending' => theme.colorScheme.primary,
      'sent' => theme.colorScheme.tertiary,
      'failed' => theme.colorScheme.error,
      _ => theme.colorScheme.outline,
    };

    final title = Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (widget.onBack != null)
          Padding(
            padding: const EdgeInsets.only(right: 4),
            child: IconButton(
              key: const ValueKey('newsletter-back'),
              tooltip: 'All newsletters',
              onPressed: widget.onBack,
              icon: const Icon(Icons.arrow_back),
            ),
          ),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Tooltip(
                key: const ValueKey('tour-newsletter-pick'),
                message: 'Internal name: only your team sees it',
                child: name,
              ),
              Wrap(
                spacing: 12,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Row(
                    key: const ValueKey('newsletter-status'),
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 7,
                        height: 7,
                        decoration: BoxDecoration(
                          color: dot,
                          shape: BoxShape.circle,
                        ),
                      ),
                      const SizedBox(width: 6),
                      Text(_statusWord, style: theme.textTheme.bodySmall),
                    ],
                  ),
                  _saveStateView(context),
                ],
              ),
            ],
          ),
        ),
      ],
    );

    final actions = Wrap(
      spacing: Studio.grid,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        IconButton.outlined(
          key: const ValueKey('newsletter-undo-edit'),
          tooltip: 'Undo (Ctrl+Z)',
          onPressed: widget.canUndo && !_locked ? widget.onUndo : null,
          icon: const Icon(Icons.undo, size: 18),
        ),
        IconButton.outlined(
          key: const ValueKey('newsletter-redo-edit'),
          tooltip: 'Redo (Ctrl+Shift+Z)',
          onPressed: widget.canRedo && !_locked ? widget.onRedo : null,
          icon: const Icon(Icons.redo, size: 18),
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
            style: TextButton.styleFrom(
              foregroundColor: theme.colorScheme.error,
            ),
            onPressed: widget.onDelete,
            child: const Text('Tap again to delete'),
          ),
        primary,
        _overflow(context),
      ],
    );

    return Container(
      key: const ValueKey('newsletter-header'),
      color: theme.colorScheme.surface,
      padding: EdgeInsets.fromLTRB(
        narrow ? Studio.grid : Studio.grid * 2,
        Studio.grid * 1.5,
        narrow ? Studio.grid : Studio.grid * 2,
        Studio.grid,
      ),
      child: narrow
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                title,
                const SizedBox(height: Studio.grid),
                actions,
              ],
            )
          : Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Expanded(child: title),
                const SizedBox(width: Studio.grid * 2),
                actions,
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

  /// Setup, Design and Review on one quiet strip; the active one is
  /// underlined in teal.
  Widget _stepBar(BuildContext context, {required bool narrow}) {
    final theme = Theme.of(context);
    return Container(
      key: const ValueKey('newsletter-steps'),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border(bottom: BorderSide(color: Studio.line(context))),
      ),
      padding: EdgeInsets.symmetric(
        horizontal: narrow ? Studio.grid : Studio.grid * 2,
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: [
            for (final step in NewsletterStep.values) ...[
              StudioStepTab(
                key: ValueKey(step.key),
                number: step.index + 1,
                label: step.label,
                active: step == _step,
                onTap: () => _go(step),
              ),
              const SizedBox(width: Studio.grid * 3),
            ],
          ],
        ),
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

  /// Fields a Review "Fix" returns focus to.
  final FocusNode _subjectFocus = FocusNode(debugLabel: 'newsletter-subject');
  final FocusNode _audienceFocus = FocusNode(debugLabel: 'newsletter-audience');

  /// Form and proof side by side (Composer Studio): the form on the left in
  /// unboxed rows, the inbox preview over the rendered email on the right.
  Widget _setup(BuildContext context, {required bool bounded}) {
    final form = Column(
      key: const ValueKey('newsletter-setup-form'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _stageTitle(
          context,
          'Email setup',
          'Write the inbox copy and choose the sender and audience.',
        ),
        _subjectCard(context),
        const SizedBox(height: Studio.grid),
        _senderCard(context),
        _recipientsCard(context),
      ],
    );
    return _formAndProof(context, bounded: bounded, form: form, inbox: true);
  }

  /// The two-pane frame Setup and Review share. One column below
  /// [Studio.stacked], with explicit Edit / Preview.
  Widget _formAndProof(
    BuildContext context, {
    required bool bounded,
    required Widget form,
    required bool inbox,
  }) {
    if (!bounded) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<bool>(
              key: const ValueKey('newsletter-narrow-view'),
              segments: const [
                ButtonSegment(
                  value: false,
                  label: Text('Edit'),
                  icon: Icon(Icons.edit_outlined, size: 16),
                ),
                ButtonSegment(
                  value: true,
                  label: Text('Preview'),
                  icon: Icon(Icons.visibility_outlined, size: 16),
                ),
              ],
              selected: {_narrowPreview},
              showSelectedIcon: false,
              onSelectionChanged: (v) =>
                  setState(() => _narrowPreview = v.first),
            ),
          ),
          const SizedBox(height: Studio.grid * 2),
          // The form stays built (Offstage) so draft values and focus survive
          // switching to the preview.
          Offstage(offstage: _narrowPreview, child: form),
          if (_narrowPreview) _proof(context, inbox: inbox),
          if (!_narrowPreview && inbox) ...[
            const SizedBox(height: Studio.grid * 2),
            _inboxCard(context),
          ],
        ],
      );
    }

    return LayoutBuilder(
      builder: (context, c) {
        final proofWidth = Studio.proofPaneWidth(c.maxWidth);
        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: ColoredBox(
                color: Studio.pane(context),
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(40, 32, 40, 40),
                  child: Align(
                    alignment: Alignment.topLeft,
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 560),
                      child: form,
                    ),
                  ),
                ),
              ),
            ),
            Container(
              key: const ValueKey('newsletter-proof-pane'),
              width: proofWidth,
              decoration: BoxDecoration(
                color: Studio.canvas(context),
                border: Border(left: BorderSide(color: Studio.line(context))),
              ),
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(Studio.grid * 3),
                child: _proof(context, inbox: inbox),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _stageTitle(BuildContext context, String title, String sub) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: Studio.grid * 3),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(
            header: true,
            child: Text(
              title,
              style: theme.textTheme.headlineMedium?.copyWith(
                fontFamily: 'serif',
                fontWeight: FontWeight.w400,
              ),
            ),
          ),
          const SizedBox(height: 4),
          Text(
            sub,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }

  Widget _subjectCard(BuildContext context) {
    final theme = Theme.of(context);
    Widget label(String text) => Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Text(text, style: theme.textTheme.titleSmall),
    );
    final subjectFailed =
        _readiness?.checks.any(
          (c) => c['key'] == 'subject' && c['ok'] != true,
        ) ==
        true;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        label('Subject line'),
        TextField(
          key: const ValueKey('newsletter-subject'),
          controller: widget.subject,
          focusNode: _subjectFocus,
          readOnly: _locked,
          maxLength: 255,
          minLines: 1,
          maxLines: 3,
          decoration: InputDecoration(
            hintText: 'What lands in the inbox',
            helperText: 'Keep it around 60 characters for phones.',
            errorText: subjectFailed && widget.subject.text.trim().isEmpty
                ? 'A subject is needed before it can go out.'
                : null,
          ),
          onChanged: (_) {
            setState(() {});
            widget.onChanged();
          },
        ),
        const SizedBox(height: Studio.grid),
        label('Preview text'),
        TextField(
          key: const ValueKey('newsletter-preheader'),
          controller: widget.preheader,
          readOnly: _locked,
          maxLength: 255,
          minLines: 2,
          maxLines: 3,
          decoration: const InputDecoration(
            hintText: 'The line shown after the subject',
            helperText:
                'Appears after the subject in the inbox. Blank shows the first words of the email.',
          ),
          onChanged: (_) {
            setState(() {});
            widget.onChanged();
          },
        ),
      ],
    );
  }

  Widget _senderCard(BuildContext context) {
    final r = _readiness;
    final theme = Theme.of(context);
    final missing = r != null && (r.fromName == null || r.fromAddress == null);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return StudioRow(
      key: const ValueKey('newsletter-sender'),
      label: 'Sender',
      attention: missing ? 'Not set up' : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (r == null)
            const LinearProgressIndicator()
          else ...[
            Text(
              r.fromName ?? 'No From name yet',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 2),
            // Mailchimp's one verified address: From and reply-to together.
            Text(
              r.fromAddress == null
                  ? 'No verified address'
                  : '${r.fromAddress} · From and reply-to',
              key: const ValueKey('newsletter-from-address'),
              style: muted,
            ),
            if (r.account != null)
              Text('Verified in Mailchimp · ${r.account}', style: muted),
          ],
          if (missing && widget.onOpenSettings != null)
            TextButton.icon(
              key: const ValueKey('newsletter-sender-settings'),
              onPressed: widget.onOpenSettings,
              icon: const Icon(Icons.tune_outlined, size: 18),
              label: const Text('Set up sending in Settings'),
            ),
          StudioDetails(
            key: const ValueKey('newsletter-sender-details'),
            children: [
              Text(
                'Company: ${widget.viewName}',
                key: const ValueKey('newsletter-company'),
                style: muted,
              ),
              const SizedBox(height: 4),
              Text(
                'Mailchimp sends from one verified address, which is also where '
                'replies go. Change it in your Mailchimp account, where it is '
                'verified.',
                style: muted,
              ),
            ],
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

    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final audienceFailed =
        r?.checks.any((c) => c['key'] == 'audience' && c['ok'] != true) == true;

    return StudioRow(
      key: const ValueKey('tour-newsletter-details'),
      label: 'Audience',
      attention: audienceFailed ? 'Choose one' : null,
      // A Review "Fix" lands here; the picker inside takes it from there.
      child: Focus(
        focusNode: _audienceFocus,
        skipTraversal: true,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (lists != null && !connected)
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'Mailchimp: not connected',
                      key: const ValueKey('newsletter-provider'),
                      style: muted,
                    ),
                  ),
                  if (widget.onOpenSettings != null)
                    TextButton(
                      onPressed: widget.onOpenSettings,
                      child: const Text('Connect Mailchimp'),
                    ),
                ],
              ),
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
            if (widget.audienceId != null) ...[
              const SizedBox(height: Studio.grid),
              _counts(context, members),
            ],
            const SizedBox(height: 4),
            Text(
              'A Mailchimp list is who the email actually goes to. EzBizi '
              'customers are added to a Mailchimp list just before sending.',
              key: const ValueKey('newsletter-audience-explainer'),
              style: muted,
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
            StudioDetails(
              key: const ValueKey('newsletter-audience-details'),
              children: [
                if (lists == null)
                  Text('Sending provider: checking…', style: muted)
                else if (connected)
                  Text(
                    'Sending provider: Mailchimp${r?.account == null ? '' : ' · ${r!.account}'}',
                    key: const ValueKey('newsletter-provider'),
                    style: muted,
                  ),
                if (chosen != null)
                  Text(
                    chosen.mailchimpListId == null ||
                            chosen.mailchimpListId!.isEmpty
                        ? '${chosen.name} has no Mailchimp list behind it yet.'
                        : '${chosen.name} (EzBizi, ${chosen.where}) → Mailchimp list '
                              '${chosen.mailchimpListId}',
                    key: const ValueKey('newsletter-audience-mapping'),
                    style: muted,
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// List membership and eligible recipients, each labelled as what it is.
  /// Eligible is a number only when the server could count it; unknown stays
  /// unknown.
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
    final at = r?.resolvedAt;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (members != null)
          Text(
            'Members in Mailchimp list: $members',
            key: const ValueKey('newsletter-members'),
            style: theme.textTheme.bodySmall,
          ),
        Row(
          children: [
            Expanded(
              child: Text(
                eligible,
                key: const ValueKey('newsletter-eligible'),
                style: theme.textTheme.bodySmall,
              ),
            ),
            TextButton.icon(
              key: const ValueKey('newsletter-eligible-refresh'),
              onPressed: _resolving ? null : _resolve,
              icon: const Icon(Icons.refresh, size: 16),
              label: const Text('Refresh'),
            ),
          ],
        ),
        if (at != null && at.isNotEmpty)
          Text(
            DateTime.tryParse(at) == null
                ? 'Counted $at'
                : 'Counted ${formatAgo(DateTime.parse(at))}',
            key: const ValueKey('newsletter-eligible-at'),
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
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

  /// A card on the proof pane: a quiet header strip over white content.
  Widget _proofCard(
    BuildContext context, {
    Key? key,
    required IconData icon,
    required String title,
    List<Widget> trailing = const [],
    required Widget child,
  }) {
    final theme = Theme.of(context);
    return Container(
      key: key,
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Studio.line(context)),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            color: theme.colorScheme.surfaceContainerLow,
            constraints: const BoxConstraints(minHeight: 48),
            padding: const EdgeInsets.symmetric(
              horizontal: Studio.grid * 2,
              vertical: 6,
            ),
            child: LayoutBuilder(
              builder: (context, c) {
                final head = [
                  Icon(
                    icon,
                    size: 18,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  const SizedBox(width: Studio.grid),
                ];
                if (c.maxWidth >= 400 || trailing.isEmpty) {
                  return Row(
                    children: [
                      ...head,
                      Expanded(
                        child: Text(title, style: theme.textTheme.titleSmall),
                      ),
                      ...trailing,
                    ],
                  );
                }
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Row(
                        children: [
                          ...head,
                          Text(title, style: theme.textTheme.titleSmall),
                        ],
                      ),
                    ),
                    Wrap(
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: trailing,
                    ),
                  ],
                );
              },
            ),
          ),
          Divider(height: 1, color: Studio.line(context)),
          child,
        ],
      ),
    );
  }

  /// The inbox as a recipient sees it: sender, subject and preheader in
  /// context, updated as they are typed. The neighbouring rows are blank
  /// placeholders, not invented mail.
  Widget _inboxCard(BuildContext context) {
    final theme = Theme.of(context);
    final from = _readiness?.fromName ?? widget.company;
    final subject = widget.subject.text.trim();
    final pre = widget.preheader.text.trim();
    final line = pre.isNotEmpty ? pre : _firstLine;
    final muted = theme.colorScheme.onSurfaceVariant;

    Widget placeholder() => Padding(
      padding: const EdgeInsets.symmetric(
        horizontal: Studio.grid * 2,
        vertical: 10,
      ),
      child: ExcludeSemantics(
        child: Row(
          children: [
            Icon(
              Icons.mail_outline,
              size: 18,
              color: muted.withValues(alpha: 0.4),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  for (final w in const [0.35, 0.55, 0.75])
                    FractionallySizedBox(
                      widthFactor: w,
                      child: Container(
                        height: 6,
                        margin: const EdgeInsets.symmetric(vertical: 3),
                        decoration: BoxDecoration(
                          color: muted.withValues(alpha: 0.15),
                          borderRadius: BorderRadius.circular(3),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );

    final message = Container(
      key: const ValueKey('newsletter-inbox-message'),
      margin: const EdgeInsets.all(Studio.grid),
      padding: const EdgeInsets.all(Studio.grid * 1.5),
      decoration: BoxDecoration(
        color: theme.colorScheme.primaryContainer.withValues(alpha: 0.35),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(
          color: theme.colorScheme.primary.withValues(alpha: 0.35),
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.mail_outline, size: 18, color: theme.colorScheme.primary),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        from,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleSmall,
                      ),
                    ),
                    Text(
                      'Now',
                      style: theme.textTheme.labelSmall?.copyWith(color: muted),
                    ),
                  ],
                ),
                Text(
                  subject.isEmpty ? '(no subject)' : subject,
                  key: const ValueKey('newsletter-inbox-subject'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                Text(
                  line.isEmpty
                      ? 'The first words of the email show here.'
                      : line,
                  key: const ValueKey('newsletter-inbox-line'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ],
            ),
          ),
        ],
      ),
    );

    return _proofCard(
      context,
      key: const ValueKey('newsletter-inbox-preview'),
      icon: Icons.inbox_outlined,
      title: 'Inbox preview',
      child: Align(
        alignment: Alignment.topCenter,
        // Truncates at the chosen device's width, not the editor's.
        child: SizedBox(
          width: _proofPhone ? 375 : double.infinity,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [message, placeholder(), placeholder()],
          ),
        ),
      ),
    );
  }

  /// The proof Setup and Review share: the inbox (Setup only) over the
  /// opened email.
  Widget _proof(BuildContext context, {required bool inbox}) {
    return Column(
      key: const ValueKey('newsletter-proof'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (inbox) ...[
          _inboxCard(context),
          const SizedBox(height: Studio.grid * 2),
        ],
        _openedEmail(context),
      ],
    );
  }

  bool get _emptyContent => widget.blocks.isEmpty && !_hasHtml;

  /// The opened email: subject and sender as the mail client shows them,
  /// then the email itself. Blocks are drawn by the same renderer layout as
  /// the canvas; custom HTML shows the server's rendering of the saved
  /// revision. Its space is held while a save refreshes it.
  Widget _openedEmail(BuildContext context) {
    final theme = Theme.of(context);
    final r = _readiness;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final subject = widget.subject.text.trim();
    final from = [
      r?.fromName ?? widget.company,
      if (r?.fromAddress != null) '<${r!.fromAddress}>',
    ].join(' ');

    final state = switch (widget.saveState) {
      NewsletterSaveState.saving => Text(
        'Updating…',
        key: const ValueKey('newsletter-proof-state'),
        style: muted,
      ),
      NewsletterSaveState.failed => TextButton(
        key: const ValueKey('newsletter-proof-retry'),
        style: TextButton.styleFrom(foregroundColor: theme.colorScheme.error),
        onPressed: widget.onRetry,
        child: const Text('Not saved · Retry'),
      ),
      NewsletterSaveState.conflict => TextButton(
        key: const ValueKey('newsletter-proof-reload'),
        style: TextButton.styleFrom(foregroundColor: theme.colorScheme.error),
        onPressed: widget.onReload,
        child: const Text('Stale · Reload'),
      ),
      NewsletterSaveState.saved => Text(
        'Revision ${widget.revision}',
        key: const ValueKey('newsletter-proof-state'),
        style: muted,
      ),
    };

    final Widget body;
    if (_emptyContent) {
      body = Padding(
        key: const ValueKey('newsletter-proof-empty'),
        padding: const EdgeInsets.all(Studio.grid * 4),
        child: Column(
          children: [
            Icon(
              Icons.drafts_outlined,
              size: 32,
              color: theme.colorScheme.onSurfaceVariant,
            ),
            const SizedBox(height: Studio.grid),
            Text(
              'This email has no content yet.',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: Studio.grid * 2),
            if (!_locked)
              Wrap(
                spacing: Studio.grid,
                runSpacing: Studio.grid,
                alignment: WrapAlignment.center,
                children: [
                  FilledButton.icon(
                    key: const ValueKey('newsletter-proof-add'),
                    onPressed: () => _go(NewsletterStep.design),
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('Add a block'),
                  ),
                  if (widget.api != null)
                    OutlinedButton.icon(
                      key: const ValueKey('newsletter-proof-import'),
                      onPressed: () => setState(() {
                        _step = NewsletterStep.design;
                        _importing = true;
                      }),
                      icon: const Icon(Icons.upload_file, size: 18),
                      label: const Text('Import HTML or SVG'),
                    ),
                ],
              ),
          ],
        ),
      );
    } else if (_htmlActive) {
      body = widget.previewHtml.isEmpty
          ? Padding(
              padding: const EdgeInsets.all(Studio.grid * 3),
              child: Text(
                'The rendering appears once this revision is saved.',
                style: muted,
              ),
            )
          : Align(
              alignment: Alignment.topCenter,
              child: SizedBox(
                width: _proofPhone ? 375 : double.infinity,
                height: 720,
                child: MessageHtmlView(html: widget.previewHtml, ownHtml: true),
              ),
            );
    } else {
      body = NewsletterEmail(
        key: const ValueKey('newsletter-proof-email'),
        blocks: widget.blocks,
        company: widget.company,
        phone: _proofPhone,
        deviceWidth: 375,
        brand: widget.brand,
      );
    }

    return _proofCard(
      context,
      key: const ValueKey('newsletter-opened-email'),
      icon: Icons.mail_outline,
      title: 'Opened email',
      trailing: [
        state,
        const SizedBox(width: Studio.grid),
        StudioDeviceToggle(
          value: _proofPhone ? 'phone' : 'desktop',
          onChanged: (v) => setState(() => _proofPhone = v == 'phone'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(
              Studio.grid * 2,
              12,
              Studio.grid * 2,
              12,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  subject.isEmpty ? '(no subject)' : subject,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                Text(from, style: muted),
              ],
            ),
          ),
          Divider(height: 1, color: Studio.line(context)),
          // Held in place while a save refreshes it, so nothing jumps.
          AnimatedOpacity(
            duration: Studio.motion(context),
            opacity: widget.saveState == NewsletterSaveState.saving ? 0.6 : 1,
            child: body,
          ),
        ],
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
          // A labelled drawer above the canvas on narrow screens.
          Card(
            margin: EdgeInsets.zero,
            child: ExpansionTile(
              key: const ValueKey('newsletter-library-drawer'),
              title: const Text('Library'),
              leading: const Icon(Icons.widgets_outlined),
              initiallyExpanded: _paletteOpen ?? false,
              onExpansionChanged: (v) => _paletteOpen = v,
              childrenPadding: const EdgeInsets.all(12),
              children: [_leftPanel(context, wrap: true)],
            ),
          ),
          const SizedBox(height: 12),
          _email(context, bounded: false),
          if (inspector != null) ...[const SizedBox(height: 12), inspector],
        ],
      );
    }

    return LayoutBuilder(
      builder: (context, c) {
        final roomy = c.maxWidth >= Studio.roomy;
        final libraryOpen = _paletteOpen ?? roomy;
        // When both cannot fit, one of them is a labelled rail: the library
        // gives way to a selected block unless it was opened on purpose.
        final squeeze = !roomy && libraryOpen && inspector != null;
        final showLibrary = libraryOpen && !(squeeze && _paletteOpen != true);
        final showInspector =
            inspector != null && !(squeeze && _paletteOpen == true);

        Widget rail(Key key, IconData icon, String label, VoidCallback on) =>
            Container(
              width: 64,
              color: Theme.of(context).colorScheme.surface,
              padding: const EdgeInsets.only(top: Studio.grid),
              child: Column(
                children: [
                  Tooltip(
                    message: 'Show $label',
                    child: InkWell(
                      key: key,
                      onTap: on,
                      borderRadius: BorderRadius.circular(8),
                      child: Container(
                        width: 56,
                        constraints: const BoxConstraints(minHeight: 56),
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(icon, size: 20),
                            const SizedBox(height: 2),
                            Text(
                              label,
                              style: Theme.of(context).textTheme.labelSmall,
                              textAlign: TextAlign.center,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            );

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (showLibrary)
              Container(
                key: const ValueKey('newsletter-library'),
                width: Studio.libraryWidth,
                color: Theme.of(context).colorScheme.surface,
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(Studio.grid * 1.5),
                  child: _leftPanel(context, wrap: false),
                ),
              )
            else
              rail(
                const ValueKey('newsletter-palette-toggle'),
                Icons.widgets_outlined,
                'Library',
                () => setState(() => _paletteOpen = true),
              ),
            VerticalDivider(width: 1, color: Studio.line(context)),
            Expanded(child: _email(context, bounded: true)),
            if (inspector != null) ...[
              VerticalDivider(width: 1, color: Studio.line(context)),
              if (showInspector)
                Container(
                  width: Studio.inspectorWidth,
                  color: Theme.of(context).colorScheme.surface,
                  child: SingleChildScrollView(child: inspector),
                )
              else
                rail(
                  const ValueKey('newsletter-inspector-toggle'),
                  Icons.tune,
                  'Block settings',
                  () => setState(() => _paletteOpen = null),
                ),
            ],
          ],
        );
      },
    );
  }

  // ── step 3: review & schedule ────────────────────────────────

  /// Which step fixes a failed preflight check.
  static NewsletterStep _stepFor(String key) => switch (key) {
    'links' || 'images' => NewsletterStep.design,
    _ => NewsletterStep.setup,
  };

  /// A failed check's Fix: the step that fixes it, with focus on the field.
  void _fix(Map<String, dynamic> check) {
    final key = '${check['key']}';
    if (_stepFor(key) == NewsletterStep.design) {
      final issues = _issues(check);
      _jumpTo(issues.isEmpty ? null : _issueIndex(issues.first));
      return;
    }
    _go(NewsletterStep.setup);
    final focus = switch (key) {
      'subject' => _subjectFocus,
      'audience' => _audienceFocus,
      _ => null,
    };
    if (focus != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) focus.requestFocus();
      });
    }
  }

  /// The saved proof beside a concise checklist, the facts the send rests
  /// on, and the send time.
  Widget _review(BuildContext context, {required bool bounded}) {
    final form = Column(
      key: const ValueKey('newsletter-review-form'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _stageTitle(
          context,
          'Review & schedule',
          'Check the draft, sender and audience before choosing a send time.',
        ),
        _checksCard(context),
        const SizedBox(height: Studio.grid * 2),
        _summaryCard(context),
        const SizedBox(height: Studio.grid * 2),
        _scheduleCard(context),
      ],
    );
    return _formAndProof(context, bounded: bounded, form: form, inbox: false);
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
    final zone = widget.scheduledTimezone ?? r?.timezone;

    Widget row(String label, String value) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 112,
            child: Text(
              label,
              style: theme.textTheme.labelMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          Expanded(child: Text(value, style: theme.textTheme.bodySmall)),
        ],
      ),
    );

    final from = [
      r?.fromName,
      if (r?.fromAddress != null) '<${r!.fromAddress}>',
    ].whereType<String>().join(' ');

    return Column(
      key: const ValueKey('newsletter-summary'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        row('Company', widget.viewName),
        row('From', from.isEmpty ? '—' : from),
        row(
          'Audience',
          chosen == null ? 'None chosen' : '${chosen.label} · ${chosen.where}',
        ),
        row('Recipients', recipients),
        if (zone != null) row('Timezone', clockLabel(zone)),
        row('Email format', _htmlActive ? 'Custom HTML' : 'Blocks'),
        row('Revision', '${widget.revision}'),
      ],
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
    final line = BorderSide(color: Studio.line(context));

    Widget item(Map<String, dynamic> c) {
      final ok = c['ok'] == true;
      final step = _stepFor('${c['key']}');
      return Container(
        decoration: BoxDecoration(border: Border(top: line)),
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Icon(
                    ok ? Icons.check : Icons.error_outline,
                    size: 18,
                    semanticLabel: ok ? 'Passed' : 'Needs attention',
                    color: ok
                        ? theme.colorScheme.primary
                        : theme.colorScheme.error,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: '${c['label']}',
                          style: theme.textTheme.titleSmall,
                        ),
                        TextSpan(
                          text: ': ${c['detail']}',
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                if (!ok)
                  OutlinedButton(
                    key: ValueKey('newsletter-fix-${c['key']}'),
                    onPressed: () => _fix(c),
                    child: Text('Fix in ${step.label}'),
                  ),
              ],
            ),
            if (!ok)
              for (final (n, issue) in _issues(c).indexed)
                if (_issueIndex(issue) != null)
                  Padding(
                    padding: const EdgeInsets.only(left: 30, top: 4),
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
          ],
        ),
      );
    }

    return Container(
      key: const ValueKey('newsletter-preflight'),
      decoration: BoxDecoration(border: Border(bottom: line)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (r == null)
            const LinearProgressIndicator()
          else if (r.checks.isEmpty)
            const DashboardNote('The checks could not be read right now.')
          else
            // Failed items first: they are what to act on.
            for (final c in [
              ...r.checks.where((c) => c['ok'] != true),
              ...r.checks.where((c) => c['ok'] == true),
            ])
              item(c),
          if (r != null && widget.lastTest != null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Text(
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
            ),
        ],
      ),
    );
  }

  Widget _scheduleCard(BuildContext context) {
    final theme = Theme.of(context);
    final refusal = _sendRefusal;
    final zone = widget.scheduledTimezone ?? _readiness?.timezone;

    return Container(
      key: const ValueKey('newsletter-schedule'),
      padding: const EdgeInsets.all(Studio.grid * 2),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainer,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Studio.line(context)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Send time', style: theme.textTheme.titleSmall),
          const SizedBox(height: Studio.grid),
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

    return Column(
      key: const ValueKey('tour-newsletter-blocks'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          _selected == null
              ? 'Click a block to add it at the end.'
              : 'Adds a block after your selection.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: Studio.grid),
        LayoutBuilder(
          builder: (context, c) {
            final per = c.maxWidth >= 300 ? 3 : 2;
            final w = (c.maxWidth - Studio.grid * (per - 1)) / per;
            return Wrap(
              spacing: Studio.grid,
              runSpacing: Studio.grid,
              children: [
                for (final kind in BlockKind.values)
                  SizedBox(
                    width: w,
                    child: _dragNew(
                      kind,
                      adding,
                      StudioBlockTile(
                        kind: kind,
                        onTap: adding ? () => _add(kind) : null,
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
        const SizedBox(height: Studio.grid * 2),
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
          const SizedBox(height: Studio.grid),
          OutlinedButton.icon(
            key: const ValueKey('newsletter-import'),
            onPressed: _locked ? null : () => setState(() => _importing = true),
            icon: const Icon(Icons.upload_file, size: 16),
            label: const Text('Import HTML or SVG'),
          ),
        ],
        if (!_htmlMode && widget.blocks.isNotEmpty) ...[
          const SizedBox(height: Studio.grid * 2),
          Divider(height: 1, color: Studio.line(context)),
          const SizedBox(height: Studio.grid),
          _layers(context),
        ],
      ],
    );
  }

  /// A starter into the open draft: what is on screen is saved and kept in
  /// history first (as an import is), so Undo or History brings it back.
  /// Subject and preview text are filled only where empty.
  Future<void> _applyStarter(NewsletterStarter s, List<Map<String, dynamic>> blocks) async {
    if (!await _beforeImport() || !mounted) return;
    final subject = widget.subject.text.trim();
    if (subject.isEmpty || subject == 'Untitled newsletter') widget.subject.text = s.subject;
    if (widget.preheader.text.trim().isEmpty) widget.preheader.text = s.preheader;
    _useBlocks(blocks);
    setState(() => _leftTab = 'add');
  }

  /// One line of a block for the outline: its words, else its kind.
  String _layerLabel(Map<String, dynamic> block) {
    final kind = BlockKind.forType(block['type'] as String? ?? '');
    final words = '${block['text'] ?? block['alt'] ?? ''}'
        .replaceAll(RegExp(r'<[^>]*>'), ' ')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();
    if (words.isNotEmpty) return words;
    if (block['type'] == 'section_ref') return 'Linked section';
    return kind?.label ?? 'Block';
  }

  /// Layers: the email's blocks in order; selecting one selects it on the
  /// canvas and the other way round.
  Widget _layers(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      key: const ValueKey('newsletter-layers'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 4),
          child: Text('Layers', style: theme.textTheme.labelLarge),
        ),
        for (var i = 0; i < widget.blocks.length; i++)
          Builder(
            builder: (context) {
              final on = i == _selected;
              final kind = BlockKind.forType(
                widget.blocks[i]['type'] as String? ?? '',
              );
              return Semantics(
                selected: on,
                child: Material(
                  color: on
                      ? theme.colorScheme.primaryContainer.withValues(
                          alpha: 0.5,
                        )
                      : Colors.transparent,
                  borderRadius: BorderRadius.circular(6),
                  child: InkWell(
                    key: ValueKey('newsletter-layer-$i'),
                    borderRadius: BorderRadius.circular(6),
                    onTap: _locked ? null : () => _select(i),
                    child: Container(
                      constraints: const BoxConstraints(minHeight: 36),
                      padding: const EdgeInsets.symmetric(
                        horizontal: Studio.grid,
                      ),
                      child: Row(
                        children: [
                          Icon(
                            kind?.icon ?? Icons.link,
                            size: 16,
                            color: on
                                ? theme.colorScheme.primary
                                : theme.colorScheme.onSurfaceVariant,
                          ),
                          const SizedBox(width: Studio.grid),
                          Expanded(
                            child: Text(
                              _layerLabel(widget.blocks[i]),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.bodySmall?.copyWith(
                                fontWeight: on
                                    ? FontWeight.w600
                                    : FontWeight.w400,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              );
            },
          ),
      ],
    );
  }

  /// Assets: upload or pick a picture. Into the selected image block, or a
  /// new image block after the selection.
  Widget _assets(BuildContext context) {
    final theme = Theme.of(context);
    final selected = _selected;
    final target =
        selected != null &&
            selected < widget.blocks.length &&
            widget.blocks[selected]['type'] == 'image'
        ? widget.blocks[selected]
        : null;
    final editable = widget.api != null && !_locked && !_htmlMode;

    Map<String, dynamic> into() {
      if (target != null) return target;
      _add(BlockKind.image);
      return widget.blocks[_selected!];
    }

    return Column(
      key: const ValueKey('newsletter-assets'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          target != null
              ? 'Replaces the picture in the selected image block.'
              : 'Adds an image block after your selection.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: Studio.grid),
        FilledButton.icon(
          key: const ValueKey('newsletter-assets-upload'),
          onPressed: editable
              ? () => _chooseFromLibrary(context, into(), upload: true)
              : null,
          icon: const Icon(Icons.upload, size: 18),
          label: const Text('Upload image'),
        ),
        const SizedBox(height: Studio.grid),
        OutlinedButton.icon(
          key: const ValueKey('newsletter-assets-library'),
          onPressed: editable
              ? () => _chooseFromLibrary(context, into())
              : null,
          icon: const Icon(Icons.photo_library_outlined, size: 18),
          label: const Text('Browse library'),
        ),
        const SizedBox(height: Studio.grid),
        Text(
          'Browse, drop or paste a picture in the upload panel. It is stored '
          'for this company and the email links to its shared address.',
          style: theme.textTheme.labelSmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ],
    );
  }

  /// The library: Blocks (with layers), Assets, Brand and Saved.
  Widget _leftPanel(BuildContext context, {required bool wrap}) {
    final api = widget.api;
    final theme = Theme.of(context);
    final tabs = SegmentedButton<String>(
      key: const ValueKey('newsletter-left-tabs'),
      style: const ButtonStyle(visualDensity: VisualDensity.compact),
      segments: [
        const ButtonSegment(value: 'add', label: Text('Blocks')),
        if (api != null)
          const ButtonSegment(value: 'assets', label: Text('Assets')),
        if (api != null && widget.onBrand != null)
          const ButtonSegment(value: 'brand', label: Text('Brand')),
        if (api != null) const ButtonSegment(value: 'saved', label: Text('Saved')),
        if (NewsletterStarters.forCompany(widget.viewName).isNotEmpty)
          const ButtonSegment(value: 'templates', label: Text('Templates')),
      ],
      selected: {_leftTab},
      showSelectedIcon: false,
      onSelectionChanged: (s) => setState(() => _leftTab = s.first),
    );
    final Widget body = switch (_leftTab) {
      'assets' when api != null => _assets(context),
      'templates' => NewsletterTemplateGallery(
        company: widget.viewName,
        useLabel: 'Use in this draft',
        note: widget.blocks.isEmpty && !_hasHtml
            ? null
            : 'Replaces this draft’s content. Undo or Version history brings it back.',
        api: widget.api,
        onUse: _locked ? null : _applyStarter,
      ),
      'brand' when api != null && widget.onBrand != null =>
        NewsletterBrandPanel(
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
        if (!wrap)
          Row(
            children: [
              Expanded(
                child: Text('Library', style: theme.textTheme.titleMedium),
              ),
              IconButton(
                key: const ValueKey('newsletter-palette-toggle'),
                tooltip: 'Hide library',
                onPressed: () => setState(() => _paletteOpen = false),
                icon: const Icon(Icons.view_sidebar_outlined, size: 18),
              ),
            ],
          ),
        if (api != null) ...[
          FittedBox(fit: BoxFit.scaleDown, child: tabs),
          const SizedBox(height: Studio.grid * 1.5),
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
    final device = _phone
        ? 'phone'
        : _fit
        ? 'fit'
        : 'desktop';
    final controls = Wrap(
      spacing: Studio.grid,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        // Designing, not previewing (P1b): Phone edits the phone overrides
        // the email carries. Preview & test is where the recipient's copy is.
        StudioDeviceToggle(
          key: const ValueKey('newsletter-device'),
          keyPrefix: 'newsletter-canvas',
          withFit: true,
          value: device,
          onChanged: (v) => setState(() {
            _phone = v == 'phone';
            _fit = v == 'fit';
          }),
        ),
        if (_phone) ...[
          for (final w in const [320.0, 375.0, 414.0])
            ChoiceChip(
              key: ValueKey('newsletter-device-${w.toInt()}'),
              label: Text('${w.toInt()}'),
              visualDensity: VisualDensity.compact,
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
            visualDensity: VisualDensity.compact,
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
    } else if (widget.blocks.isEmpty && _htmlActive) {
      final readOnly = Column(
        key: const ValueKey('newsletter-blocks-readonly'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.all(Studio.grid),
            child: Wrap(
              spacing: Studio.grid,
              runSpacing: Studio.grid,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                const Text(
                  "This draft's design is Custom HTML. Make an editable copy to work in blocks.",
                ),
                OutlinedButton.icon(
                  key: const ValueKey('newsletter-editable-copy'),
                  onPressed: _locked || widget.api == null ? null : _editableCopy,
                  icon: const Icon(Icons.copy_all_outlined, size: 16),
                  label: const Text('Create editable copy'),
                ),
              ],
            ),
          ),
          if (bounded) Expanded(child: _serverView()) else SizedBox(height: 520, child: _serverView()),
        ],
      );
      body = bounded ? Expanded(child: readOnly) : readOnly;
    } else if (_serverPreview) {
      body = bounded
          ? Expanded(child: _serverView())
          : SizedBox(height: 520, child: _serverView());
    } else {
      Widget email = NewsletterEmail(
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
      if (_fit && !_phone) {
        // The 600px email scaled to the canvas width.
        email = FittedBox(
          key: const ValueKey('newsletter-canvas-fitted'),
          fit: BoxFit.fitWidth,
          alignment: Alignment.topCenter,
          child: SizedBox(
            width: NewsletterEmail.desktopWidth + 36,
            child: email,
          ),
        );
      }
      email = Padding(
        padding: const EdgeInsets.symmetric(vertical: Studio.grid * 2),
        child: email,
      );
      body = bounded
          ? Expanded(child: SingleChildScrollView(child: email))
          : email;
    }

    final where = _htmlMode
        ? 'Custom HTML'
        : _serverPreview
        ? 'Server preview'
        : _phone
        ? 'Phone · ${_deviceWidth.toInt()}px'
        : _fit
        ? 'Fit'
        : 'Desktop · 600px';

    final toolbar = Container(
      color: theme.colorScheme.surface,
      padding: const EdgeInsets.symmetric(
        horizontal: Studio.grid * 1.5,
        vertical: 6,
      ),
      child: Wrap(
        spacing: Studio.grid * 2,
        runSpacing: 6,
        alignment: WrapAlignment.spaceBetween,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text.rich(
            TextSpan(
              children: [
                TextSpan(
                  text: 'Email canvas',
                  style: theme.textTheme.titleSmall,
                ),
                TextSpan(
                  text: '  /  $where',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          controls,
        ],
      ),
    );

    return Container(
      key: const ValueKey('tour-newsletter-email'),
      color: Studio.canvas(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: bounded ? MainAxisSize.max : MainAxisSize.min,
        children: [
          toolbar,
          Divider(height: 1, color: Studio.line(context)),
          Padding(
            padding: EdgeInsets.fromLTRB(
              bounded ? 12 : 0,
              8,
              bounded ? 12 : 0,
              0,
            ),
            child: _modeBar(context),
          ),
          if (_importing && !_locked)
            Padding(
              padding: EdgeInsets.fromLTRB(
                bounded ? 12 : 0,
                8,
                bounded ? 12 : 0,
                0,
              ),
              child: NewsletterImportPanel(
                api: widget.api,
                rasterise: widget.rasterise,
                onUseHtml: _useHtml,
                onUseBlocks: _useBlocks,
                onClose: () => setState(() => _importing = false),
                newsletterId: widget.newsletterId,
                subject: widget.subject.text,
                onBeforeApply: _beforeImport,
                onSavedAsNew: widget.onImportedAsNew,
              ),
            ),
          if (_htmlMode && bounded)
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [body],
                ),
              ),
            )
          else
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
    final viewIsActive = _htmlMode == _htmlActive;
    final mayUseOpenView = _htmlMode ? _hasHtml : true;

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
          StatusPill(
            _htmlActive ? 'Active: Custom HTML' : 'Active: Blocks',
            key: const ValueKey('newsletter-active-design'),
            tone: viewIsActive ? Tone.ok : Tone.neutral,
          ),
          if (!viewIsActive && !_locked && mayUseOpenView)
            Tooltip(
              message: _htmlMode
                  ? 'Sends this custom HTML instead of the blocks. The blocks stay saved.'
                  : 'Sends the blocks instead of the custom HTML. The HTML stays saved.',
              child: OutlinedButton(
                key: const ValueKey('newsletter-use-design'),
                onPressed: () => _useDesign(_htmlMode ? 'html' : 'blocks'),
                child: const Text('Use this design'),
              ),
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
                () => setState(() {
                      _namingSection = i;
                      _sectionName.clear();
                      _sectionLinked = false;
                    })),
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
      padding: const EdgeInsets.all(16),
      child: KeyedSubtree(
        key: ValueKey('inspector-${widget.historyGeneration}-$i-${identityHashCode(block)}'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    _phone ? 'Block settings · phone' : 'Block settings',
                    style: theme.textTheme.titleMedium,
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
            Container(
              key: const ValueKey('newsletter-inspector-kind'),
              margin: const EdgeInsets.only(top: 4, bottom: 8),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(
                color: theme.colorScheme.primaryContainer.withValues(
                  alpha: 0.45,
                ),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(
                children: [
                  Icon(
                    kind?.icon ?? Icons.link,
                    size: 18,
                    color: theme.colorScheme.primary,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      kind?.label ??
                          (block['type'] == 'section_ref'
                              ? 'Linked section'
                              : block['type'] as String? ?? 'Block'),
                      style: theme.textTheme.titleSmall,
                    ),
                  ),
                ],
              ),
            ),
            if (_namingSection == i) _sectionForm(i),
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
            const SizedBox(height: 16),
            Divider(height: 1, color: Studio.line(context)),
            const SizedBox(height: 8),
            Text('Block controls', style: theme.textTheme.labelMedium),
            const SizedBox(height: 6),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton.icon(
                  key: const ValueKey('newsletter-inspector-up'),
                  onPressed: i == 0 ? null : () => _move(i, i - 1),
                  icon: const Icon(Icons.arrow_upward, size: 16),
                  label: const Text('Move up'),
                ),
                OutlinedButton.icon(
                  key: const ValueKey('newsletter-inspector-down'),
                  onPressed: i >= widget.blocks.length - 1
                      ? null
                      : () => _move(i, i + 1),
                  icon: const Icon(Icons.arrow_downward, size: 16),
                  label: const Text('Move down'),
                ),
                OutlinedButton.icon(
                  key: const ValueKey('newsletter-inspector-remove'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: theme.colorScheme.error,
                  ),
                  onPressed: () => _remove(i),
                  icon: const Icon(Icons.delete_outline, size: 16),
                  label: const Text('Remove block'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// Name and use of a new saved section, inline (no dialogs in this app).
  Widget _sectionForm(int i) => Card(
    key: const ValueKey('newsletter-section-form'),
    child: Padding(
      padding: const EdgeInsets.all(8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const ValueKey('newsletter-section-name'),
            controller: _sectionName,
            autofocus: true,
            decoration: const InputDecoration(labelText: 'Section name', isDense: true),
          ),
          SwitchListTile(
            key: const ValueKey('newsletter-section-linked'),
            contentPadding: EdgeInsets.zero,
            dense: true,
            title: const Text('Meant for linked use'),
            subtitle: const Text('Linked uses show "Update available"; nothing updates by itself.'),
            value: _sectionLinked,
            onChanged: (v) => setState(() => _sectionLinked = v),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              TextButton(
                onPressed: () => setState(() => _namingSection = null),
                child: const Text('Cancel'),
              ),
              FilledButton(
                key: const ValueKey('newsletter-section-save'),
                onPressed: () => _saveSection(i),
                child: const Text('Save section'),
              ),
            ],
          ),
        ],
      ),
    ),
  );

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

  /// The last upload into this block failed: why, with Retry beside it.
  ({Map<String, dynamic> block, String reason})? _uploadFailed;

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
              ] else ...[
                OutlinedButton.icon(
                  key: const ValueKey('newsletter-image-replace'),
                  onPressed: () => _chooseFromLibrary(context, block),
                  icon: const Icon(Icons.swap_horiz, size: 18),
                  label: const Text('Replace'),
                ),
                OutlinedButton.icon(
                  key: const ValueKey('newsletter-image-upload-new'),
                  onPressed: () =>
                      _chooseFromLibrary(context, block, upload: true),
                  icon: const Icon(Icons.upload, size: 18),
                  label: const Text('Upload new'),
                ),
              ],
            ],
          ),
        ),
      if (_uploadFailed != null && identical(_uploadFailed!.block, block))
        Padding(
          key: const ValueKey('newsletter-image-failed'),
          padding: const EdgeInsets.only(top: 8),
          child: Row(
            children: [
              Icon(
                Icons.error_outline,
                size: 16,
                color: Theme.of(context).colorScheme.error,
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  _uploadFailed!.reason.isEmpty
                      ? 'The upload failed. The picture you had is kept.'
                      : '${_uploadFailed!.reason} The picture you had is kept.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
              TextButton(
                key: const ValueKey('newsletter-image-retry'),
                onPressed: () =>
                    _chooseFromLibrary(context, block, upload: true),
                child: const Text('Retry'),
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
      // The prior picture stays; retry is offered in place.
      setState(() => _uploadFailed = (block: block, reason: reason));
      return;
    }
    setState(() {
      _uploadFailed = null;
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

/// "Create editable copy" (suggestion 01a1f9ed): the original HTML design
/// beside the blocks it converts to, and what will not carry over. Pops true
/// on Create copy; nothing is saved before that.
class EditableCopyPanel extends StatelessWidget {
  const EditableCopyPanel({super.key, required this.preview});

  final Map<String, dynamic> preview;

  @override
  Widget build(BuildContext context) {
    final lost = [
      for (final l in (preview['lost'] as List? ?? const [])) '$l',
      for (final i in (preview['issues'] as List? ?? const []))
        if (i is Map && i['message'] != null) '${i['message']}',
    ];
    Widget pane(String title, String html, Key key) => Column(
      key: key,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(title, style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: Studio.grid),
        Container(
          height: 420,
          clipBehavior: Clip.antiAlias,
          decoration: BoxDecoration(
            border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
            borderRadius: BorderRadius.circular(8),
          ),
          child: MessageHtmlView(html: html, ownHtml: true),
        ),
      ],
    );
    final original = pane('Original (Custom HTML)', '${preview['original_html'] ?? ''}',
        const ValueKey('editable-copy-original'));
    final copy = pane('Editable copy (blocks)', '${preview['preview_html'] ?? ''}',
        const ValueKey('editable-copy-blocks'));

    return SidePanel(
      title: 'Create editable copy',
      subtitle: 'The original stays the active design until you choose "Use this design".',
      width: 900,
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Cancel')),
        FilledButton(
          key: const ValueKey('editable-copy-create'),
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('Create copy'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          LayoutBuilder(
            builder: (context, c) => c.maxWidth < 600
                ? Column(children: [original, const SizedBox(height: Studio.grid * 2), copy])
                : Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(child: original),
                      const SizedBox(width: Studio.grid * 2),
                      Expanded(child: copy),
                    ],
                  ),
          ),
          const SizedBox(height: Studio.grid * 2),
          Text("Won't carry over", style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: Studio.grid),
          if (lost.isEmpty)
            const Text('Everything converts to editable blocks.')
          else
            for (final l in lost) Text('• $l', key: ValueKey('editable-copy-lost-$l')),
        ],
      ),
    );
  }
}
