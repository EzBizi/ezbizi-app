import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';
import '../email/message_html.dart';
import 'newsletter_blocks.dart';
import 'newsletter_dashboard.dart';
import 'newsletter_editor.dart';
import 'newsletter_settings.dart';

/// Marketing › Newsletter — write it on the left, see the real email on the
/// right.
///
/// ── Layout 3, and why the preview never hides ───────────────────
///
/// Jose picked it, and the reason it is the right pick is that a newsletter is
/// the one thing in this product where the artefact and the editor look
/// nothing alike. A form that yields an email you cannot see until you send it
/// is how a broken mailing reaches twelve hundred people. The preview is the
/// work, so it holds half the screen and never collapses.
///
/// Below 900 points there is not room for two panes, so the preview moves under
/// the editor rather than disappearing — the same rule the Posts pane follows,
/// and for the same reason.
///
/// ── The preview is the SERVER's HTML ────────────────────────────
///
/// Rendered by `BlockRenderer` and shown in the sandboxed iframe the Email
/// module already uses for HTML mail. Nothing here builds email HTML. A Dart
/// copy of that renderer would drift from the real one, and the drift would
/// surface as a newsletter that looked right while composing and broke in an
/// inbox — the failure this pane exists to prevent.
class NewsletterTab extends StatefulWidget {
  const NewsletterTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<NewsletterTab> createState() => _NewsletterTabState();
}

class _NewsletterTabState extends State<NewsletterTab> {
  /// Long enough that typing a sentence is one save, short enough that the
  /// preview feels attached to the keyboard.
  static const _settle = Duration(milliseconds: 700);

  /// Dashboard · Composer · Settings, by KEY.
  ///
  /// Dashboard first, deliberately: opening Newsletter and landing in an empty
  /// composer answers no question, whereas "what is going out, and when" is
  /// what somebody arriving actually wants. The composer is one chip away.
  ///
  /// A key, not an index, since each pane became grantable on its own
  /// (2026-09-29). An index means a different pane depending on which panes
  /// this role is shown — Settings found that in three modules, and the
  /// symptom is the wrong pane rather than a refusal, so nothing errors.
  String _tab = 'dashboard';

  static const _panes = [
    ('dashboard', 'Dashboard', Icons.insights_outlined),
    ('composer', 'Composer', Icons.edit_outlined),
    ('settings', 'Settings', Icons.tune_outlined),
  ];

  /// Absent `/me` answers NO: a pane nobody said this person may open is not
  /// drawn. The server refuses the same thing, so this only decides whether
  /// the chip is offered.
  bool _mayOpen(String pane) =>
      widget.session.me?.permissions.contains('newsletter.$pane.view') ?? false;

  List<(String, String, IconData)> get _shownPanes =>
      [for (final pane in _panes) if (_mayOpen(pane.$1)) pane];

  /// The pane actually on screen: the chosen one if it is shown, else the
  /// first that is. Resolved every frame, so a grant changing mid-session
  /// lands on a pane that exists rather than a position that used to.
  String get _current {
    final shown = _shownPanes;
    return shown.any((p) => p.$1 == _tab) ? _tab : shown.first.$1;
  }

  NewsletterStats _stats = const NewsletterStats();

  List<_Newsletter> _all = const [];
  List<NewsletterAudience> _audiences = const [];
  String? _selectedId;

  /// The newsletter whose Delete was tapped once; the next tap deletes it.
  String? _confirmingDelete;
  String? _loadError;
  bool _loading = true;

  /// Why Send cannot work at all, from the server. Null when it can.
  String? _sendingRefusedBecause;

  final TextEditingController _subject = TextEditingController();
  final TextEditingController _when = TextEditingController();
  List<Map<String, dynamic>> _blocks = [];
  String? _htmlOverride;
  String? _audienceId;

  String _previewHtml = '';
  bool _dirty = false;
  bool _saving = false;
  String? _saveError;
  Timer? _settleTimer;

  _Newsletter? get _selected {
    for (final n in _all) {
      if (n.id == _selectedId) return n;
    }
    return null;
  }

  String get _viewName =>
      widget.session.me?.scope.label ??
      widget.session.me?.organization.name ??
      'this view';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _settleTimer?.cancel();
    _subject.dispose();
    _when.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    /*
     * Only what this role's panes read. The list serves Dashboard and
     * Composer; audiences and the preview are Composer's. A Settings-only role
     * asks for neither — the server would refuse both, and the list's refusal
     * would otherwise be drawn as an error over the one pane they CAN use.
     */
    final readsList = _mayOpen('dashboard') || _mayOpen('composer');
    if (!readsList) {
      if (mounted) setState(() => _loading = false);
      return;
    }

    final letters = await widget.api.get('/newsletters');
    final audiences = _mayOpen('composer')
        ? await widget.api.get('/newsletter/audiences')
        : null;
    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!letters.ok) {
        _loadError = letters.message;
        return;
      }

      _loadError = null;
      _all = ((letters.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_Newsletter.fromJson)
          .toList();

      final sending = letters.body['sending'];
      _sendingRefusedBecause = sending is Map
          ? sending['refused_because'] as String?
          : null;

      final stats = letters.body['stats'];
      _stats = stats is Map<String, dynamic>
          ? NewsletterStats.fromJson(stats)
          : const NewsletterStats();

      _audiences = audiences == null || !audiences.ok
          ? const []
          : ((audiences.body['data'] as List<dynamic>?) ?? const [])
              .whereType<Map<String, dynamic>>()
              .map(NewsletterAudience.fromJson)
              .toList();

      if (_selectedId == null && _all.isNotEmpty) _open(_all.first);
    });

    if (_selectedId != null) await _refreshPreview();
  }

  void _open(_Newsletter n) {
    _selectedId = n.id;
    _subject.text = n.subject;
    _when.text = n.scheduledFor ?? '';
    _blocks = n.blocks.map((b) => Map<String, dynamic>.from(b)).toList();
    _htmlOverride = null;
    _audienceId = n.audienceId;
    _dirty = false;
    _saveError = null;
  }

  /// Typing changed something. Save shortly, then re-render.
  ///
  /// Autosave rather than a Save button, because a draft that loses an
  /// afternoon's writing to a closed tab is the kind of thing people never
  /// forgive — and because the preview has to come from a saved row, so the
  /// save was going to happen anyway.
  void _touched() {
    setState(() => _dirty = true);
    _settleTimer?.cancel();
    _settleTimer = Timer(_settle, _save);
  }

  Future<void> _save() async {
    final id = _selectedId;
    if (id == null || _saving) return;

    setState(() {
      _saving = true;
      _saveError = null;
    });

    final response = await widget.api.put('/newsletters/$id', {
      'subject': _subject.text.trim().isEmpty ? 'Untitled' : _subject.text.trim(),
      'blocks': _blocks,
      if (_htmlOverride != null) 'html_override': _htmlOverride,
      'audience_id': _audienceId,
      'scheduled_for': _when.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _saveError = response.reason;
      });
      return;
    }

    setState(() {
      _saving = false;
      _dirty = false;
    });

    await _refreshPreview();
    await _reloadList();
  }

  Future<void> _refreshPreview() async {
    final id = _selectedId;
    if (id == null || !_mayOpen('composer')) return;

    final response = await widget.api.get('/newsletters/$id/preview');
    if (!mounted || !response.ok) return;

    final data = response.body['data'];
    setState(() => _previewHtml = data is Map ? (data['html'] as String? ?? '') : '');
  }

  /// The list only, so saving does not reset the editor under the cursor.
  Future<void> _reloadList() async {
    final letters = await widget.api.get('/newsletters');
    if (!mounted || !letters.ok) return;

    setState(() {
      _all = ((letters.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_Newsletter.fromJson)
          .toList();
    });
  }

  /// Delete the open newsletter, after asking.
  ///
  /// `newsletter.delete` was declared and grantable with nothing to press —
  /// the server's destroy() has existed all along. The server keeps the rules
  /// (a mailing being sent cannot be deleted; out of view is 404); this only
  /// asks first and reports what it said.
  Future<void> _delete() async {
    final id = _selectedId;
    if (id == null) return;

    // A second tap, not a dialog (no modal dialogs: DEVELOPMENT-RULES). Keyed
    // by id, so opening another newsletter disarms it.
    if (_confirmingDelete != id) {
      setState(() => _confirmingDelete = id);
      return;
    }
    setState(() => _confirmingDelete = null);

    final response = await widget.api.delete('/newsletters/$id');
    if (!mounted) return;

    if (!response.ok) {
      setState(() => _saveError = response.reason);
      return;
    }

    setState(() {
      _selectedId = null;
      _previewHtml = '';
    });
    await _load();
  }

  Future<void> _create() async {
    final response = await widget.api.post('/newsletters', {
      'subject': 'Untitled newsletter',
      'blocks': [BlockKind.heading.blank(), BlockKind.text.blank()],
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _saveError = response.reason);
      return;
    }

    await _load();

    final data = response.body['data'];
    if (data is Map && data['id'] is String) {
      final made = _all.where((n) => n.id == data['id']).toList();
      if (made.isNotEmpty) setState(() => _open(made.first));
      await _refreshPreview();
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_loadError != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: DashboardNote(_loadError!),
        ),
      );
    }

    final shown = _shownPanes;

    if (shown.isEmpty) {
      // Newsletter reached, and none of its three panes granted. Said out loud
      // rather than drawn as an empty frame, which reads as the app broken.
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            "None of Newsletter's tabs are available to your role.",
            style: Theme.of(context).textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    final current = _current;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TabBarLike(
          labels: [for (final pane in shown) (pane.$2, pane.$3)],
          selected: shown.indexWhere((p) => p.$1 == current),
          onSelected: (i) => setState(() => _tab = shown[i].$1),
        ),
        const Divider(height: 1),
        Expanded(child: _body(context, current)),
      ],
    );
  }

  Widget _body(BuildContext context, String current) {
    return switch (current) {
      'dashboard' => NewsletterDashboard(
        stats: _stats,
        viewName: _viewName,
        scheduled: [
          for (final n in _all)
            if (n.status == 'scheduled' && n.scheduledFor != null)
              ScheduledNewsletter(
                id: n.id,
                subject: n.subject,
                wall: n.scheduledFor!,
                zone: n.scheduledTimezone,
                at: DateTime.tryParse(n.scheduledFor!.replaceFirst(' ', 'T')),
              ),
        ],
        // Tapping a scheduled mailing opens it in the composer, because
        // "review them or edit them" is the reason to draw the calendar at
        // all — a date with no way in is a poster.
        onOpen: (id) {
          // Opening a mailing lands in the Composer, so without that pane the
          // entry is a date to read rather than a door — tapping it must not
          // switch to a pane this role cannot see.
          if (!_mayOpen('composer')) return;

          final picked = _all.where((n) => n.id == id).toList();
          if (picked.isEmpty) return;
          setState(() {
            _open(picked.first);
            _tab = 'composer';
          });
          _refreshPreview();
        },
      ),
      'composer' => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _bar(context),
          const Divider(height: 1),
          Expanded(
            child: _selected == null ? _empty(context) : _split(context),
          ),
        ],
      ),
      _ => NewsletterSettings(
        api: widget.api,
        session: widget.session,
        viewName: _viewName,
        // Connecting an account changes whether Send can work at all, and
        // that answer is carried on the newsletter list.
        onChanged: _load,
      ),
    };
  }

  Widget _bar(BuildContext context) {
    final can = widget.session.me?.can('newsletter.create') ?? false;

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      child: Row(
        children: [
          Expanded(
            child: _all.isEmpty
                ? Text('No newsletters in $_viewName yet.')
                : DropdownButtonHideUnderline(
                    child: SearchPickerField<String>(
                      value: _selectedId,
                      border: InputBorder.none,
                      isDense: true,
                      options: [
                        for (final n in _all)
                          SearchOption(
                            value: n.id,
                            // The subject searches; the state is what you read
                            // once you have found it.
                            label: n.subject,
                            detail: n.statusWord,
                          ),
                      ],
                      onChanged: (id) {
                        final picked = _all.where((n) => n.id == id).toList();
                        if (picked.isEmpty) return;
                        setState(() => _open(picked.first));
                        _refreshPreview();
                      },
                    ),
                  ),
          ),
          // The save state describes THE OPEN NEWSLETTER, so with none open it
          // has nothing to describe. It used to read "Saved" beside "No
          // newsletters in … yet", which UI/UX found on a phone as a stray word:
          // a status without its noun is a claim about nothing.
          if (_selectedId != null) ...[
            const SizedBox(width: 12),
            Text(
              _dirty
                  ? 'Unsaved'
                  : _saving
                      ? 'Saving…'
                      : 'Saved',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
          // Delete the open one. Offered only when something is open, and
          // refused in the server's words without `newsletter.delete`; a
          // mailing already going out is refused by the server, which says so.
          if (_selectedId != null) ...[
            const SizedBox(width: 12),
            Refused(
              reason: widget.session.me?.refusal(
                'newsletter.delete',
                'delete a newsletter',
              ),
              child: TextButton.icon(
                onPressed: (widget.session.me?.can('newsletter.delete') ?? false) &&
                        _selected?.status != 'sending'
                    ? _delete
                    : null,
                icon: const Icon(Icons.delete_outline, size: 18),
                label: Text(
                  _confirmingDelete == _selectedId
                      ? 'Tap again to delete'
                      : 'Delete',
                ),
              ),
            ),
          ],
          const SizedBox(width: 12),
          Refused(
            reason: widget.session.me?.refusal(
              'newsletter.create',
              'write a newsletter',
            ),
            child: FilledButton.icon(
              onPressed: can ? _create : null,
              icon: const Icon(Icons.add, size: 18),
              label: const Text('New'),
            ),
          ),
        ],
      ),
    );
  }

  Widget _empty(BuildContext context) {
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 460),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const DashboardNote(
                'A newsletter is written here and sent through the mailing '
                'account this company has connected. Nothing goes out until '
                'somebody presses Send.',
              ),
              if (_sendingRefusedBecause != null) ...[
                const SizedBox(height: 10),
                DashboardNote(_sendingRefusedBecause!),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _split(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final editor = _editorPane(context);
        final preview = _previewPane(context);

        // Under 900 the preview goes BELOW rather than away. A newsletter you
        // cannot see is the thing this layout exists to prevent, and a phone
        // does not change that.
        if (constraints.maxWidth < 900) {
          return ListView(
            padding: const EdgeInsets.all(12),
            children: [
              editor,
              const SizedBox(height: 12),
              SizedBox(height: 520, child: preview),
            ],
          );
        }

        return Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(flex: 5, child: SingleChildScrollView(child: editor)),
              const SizedBox(width: 12),
              Expanded(flex: 4, child: preview),
            ],
          ),
        );
      },
    );
  }

  Widget _editorPane(BuildContext context) {
    return NewsletterEditor(
      subject: _subject,
      blocks: _blocks,
      audiences: _audiences,
      audienceId: _audienceId,
      when: _when,
      viewName: _viewName,
      scheduledTimezone: _selected?.scheduledTimezone,
      session: widget.session,
      sendingRefusedBecause: _sendingRefusedBecause,
      saveError: _saveError,
      onChanged: _touched,
      onAudience: (id) {
        setState(() => _audienceId = id);
        _touched();
      },
      onHtml: (html) {
        setState(() => _htmlOverride = html);
        _touched();
      },
      onSend: _send,
    );
  }

  Widget _previewPane(BuildContext context) {
    return DashboardCard(
      title: 'What they will get',
      icon: Icons.mark_email_read_outlined,
      /*
       * `expand` is what makes the preview VISIBLE, and its absence is why
       * this pane was blank on the web.
       *
       * The card sizes to its contents unless told otherwise. The contents
       * here are a sandboxed iframe wrapped in `SizedBox.expand`, which
       * collapses to nothing when its height is unbounded — so the card drew
       * its header, stretched to the row, and showed an empty body. Nothing
       * errored and nothing looked wrong except the part that mattered.
       *
       * The flag's own docblock names exactly this case: turn it on when the
       * parent is a bounded slot and the body should use the leftover space.
       * Both parents here are bounded — `Expanded` in the wide row, a fixed
       * 520 box in the narrow list — so the condition was met the whole time
       * and only the flag was missing.
       *
       * No height of its own, still. The parent decides; this says "use all
       * of what the parent gave you" rather than naming a number, which is
       * the mistake the 640 made before it.
       */
      expand: true,
      child: _previewHtml.isEmpty
          ? const Center(child: DashboardNote('Nothing to show yet.'))
          : MessageHtmlView(html: _previewHtml),
    );
  }

  Future<void> _send() async {
    final id = _selectedId;
    if (id == null) return;

    // Save first, or the mailing that goes out is the one before the last
    // sentence anybody typed.
    _settleTimer?.cancel();
    await _save();
    if (!mounted) return;

    final go = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Send this newsletter?',
        subtitle: _subject.text,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Not yet'),
          ),
          const SizedBox(width: 8),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Send'),
          ),
        ],
        child: const Text(
          'It goes out through the mailing account this company connected, to '
          'the audience chosen above. A newsletter cannot be unsent.',
        ),
      ),
    );

    if (go != true || !mounted) return;

    final response = await widget.api.post('/newsletters/$id/send', {});
    if (!mounted) return;

    setState(() => _saveError = response.ok ? null : response.reason);
    await _load();
  }
}

class _Newsletter {
  const _Newsletter({
    required this.id,
    required this.subject,
    required this.blocks,
    required this.status,
    this.audienceId,
    this.scheduledFor,
    this.scheduledTimezone,
  });

  final String id;
  final String subject;
  final List<Map<String, dynamic>> blocks;
  final String status;
  final String? audienceId;
  final String? scheduledFor;

  /// The clock the SERVER filed that wall time against, which is not always
  /// the clock of the place named in the view. Above franchise scope it is the
  /// platform default, a fixed offset that does not follow daylight saving.
  final String? scheduledTimezone;

  String get statusWord => switch (status) {
    'sent' => 'sent',
    'sending' => 'sending',
    'scheduled' => 'scheduled',
    _ => 'draft',
  };

  factory _Newsletter.fromJson(Map<String, dynamic> json) {
    final audience = json['audience'];

    return _Newsletter(
      id: json['id'] as String? ?? '',
      subject: json['subject'] as String? ?? '',
      status: json['status'] as String? ?? 'draft',
      audienceId: audience is Map ? audience['id'] as String? : null,
      scheduledFor: json['scheduled_for'] as String?,
      scheduledTimezone: json['scheduled_timezone'] as String?,
      blocks: ((json['blocks'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList(),
    );
  }
}

