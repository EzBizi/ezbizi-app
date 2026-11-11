import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';
import 'newsletter_new_list_panel.dart';
import 'newsletter_blocks.dart';
import 'newsletter_dashboard.dart';
import 'newsletter_editor.dart';
import 'newsletter_settings.dart';

/// Marketing › Newsletter — the dashboard, the composer and the settings.
///
/// ── The email is never out of sight ─────────────────────────────
///
/// A newsletter is the one thing in this product where the artefact and a
/// form for it look nothing alike, and a form that yields an email you cannot
/// see until you send it is how a broken mailing reaches twelve hundred
/// people. Layout A (Mia, 2026-10-02) goes further than the old side-by-side
/// preview: the email IS the editor — see newsletter_editor.dart.
///
/// ── The server's HTML is still one tap away ─────────────────────
///
/// The composer draws the blocks in Flutter so they can be edited in place,
/// and keeps a "Server preview" switch that shows GET /newsletters/{id}/preview
/// — `BlockRenderer`'s own output in the Email module's sandboxed viewer. That
/// is the document that goes out; nothing here builds email HTML.
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

  /// The dashboard tile picked (`sent`, `scheduled`, `draft`), and the rows
  /// the server returned for it. Null rows while that answer is on its way,
  /// when the table shows the matching rows of the list already held.
  String? _dashStatus;
  List<_Newsletter>? _dashLetters;

  /// Which filter request owns the table; an answer for a tile no longer
  /// picked is dropped.
  int _dashRequest = 0;
  String? _selectedId;

  /// The dashboard table's search box, sent as `q`, debounced.
  final _search = TextEditingController();
  Timer? _searchDebounce;
  String _q = '';

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

  /// The save under way, so Send can wait for it rather than race it.
  Future<void>? _saveInFlight;

  /// Counts edits, so a save knows whether something was typed while it ran.
  int _edits = 0;

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
    _searchDebounce?.cancel();
    _search.dispose();
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

    if (_dashStatus != null || _q.isNotEmpty) await _filter(_dashStatus);
    if (_selectedId != null) await _refreshPreview();
  }

  /// A dashboard tile was picked (or cleared).
  ///
  /// Asked of the SERVER, because the list is capped at fifty and the tiles
  /// count every newsletter: filtering the fifty would show some drafts under
  /// a tile counting all of them (Mia, 2026-10-02).
  Future<void> _filter(String? status) async {
    final request = ++_dashRequest;
    setState(() {
      _dashStatus = status;
      _dashLetters = null;
    });
    if (status == null && _q.isEmpty) return;

    final response = await widget.api.get('/newsletters', {
      'status': ?status,
      if (_q.isNotEmpty) 'q': _q,
    });
    if (!mounted || request != _dashRequest || !response.ok) return;

    setState(() {
      _dashLetters = ((response.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_Newsletter.fromJson)
          .toList();
    });
  }

  void _searched(String text) {
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      _q = text.trim();
      _filter(_dashStatus);
    });
  }

  /// The table's rows: the server's answer for the tile, or, until it lands,
  /// the matching rows already held.
  ///
  /// NewslettersController@index does not read `q` yet (it returns the newest
  /// fifty), so the rows are also narrowed here by subject, mailing list and
  /// status. Once the server honours `q` that part is a no-op.
  List<NewsletterRow> get _tableRows {
    final q = _q.toLowerCase();
    return [
      for (final n in _dashLetters ?? _all)
        if ((_dashStatus == null || n.row.status == _dashStatus) &&
            (q.isEmpty ||
                [
                  n.row.subject,
                  n.row.audienceName,
                  n.row.status,
                ].whereType<String>().join(' ').toLowerCase().contains(q)))
          n.row,
    ];
  }

  /// Open a newsletter from the dashboard in the composer.
  ///
  /// A filtered row can be older than the fifty the picker holds, so it is
  /// added to that list rather than failing to open.
  void _openFromDashboard(String id) {
    final picked = [
      ..._all.where((n) => n.id == id),
      ...?_dashLetters?.where((n) => n.id == id),
    ];
    if (picked.isEmpty) return;

    setState(() {
      if (!_all.any((n) => n.id == id)) _all = [..._all, picked.first];
      _open(picked.first);
      _tab = 'composer';
    });
    _refreshPreview();
  }

  void _open(_Newsletter n) {
    _selectedId = n.id;
    _subject.text = n.subject;
    _when.text = n.scheduledFor ?? '';
    _blocks = n.blocks.map((b) => Map<String, dynamic>.from(b)).toList();
    _htmlOverride = n.htmlOverride;
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
    _edits++;
    setState(() => _dirty = true);
    _settleTimer?.cancel();
    _settleTimer = Timer(_settle, _save);
  }

  /// One save at a time: a second call while one runs waits for that one.
  Future<void> _save() {
    final running = _saveInFlight;
    if (running != null) return running;
    final next = _saveNow().whenComplete(() => _saveInFlight = null);
    _saveInFlight = next;
    return next;
  }

  Future<void> _saveNow() async {
    final id = _selectedId;
    if (id == null) return;
    final edits = _edits;

    setState(() {
      _saving = true;
      _saveError = null;
    });

    final response = await widget.api.put('/newsletters/$id', {
      'subject': _subject.text.trim().isEmpty ? 'Untitled' : _subject.text.trim(),
      'blocks': _blocks,
      // Always sent: the server keeps what it holds only when this is absent.
      'html_override': _htmlOverride,
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
      // Typing during the save left something this save did not carry.
      _dirty = _edits != edits;
    });
    if (_dirty) {
      _settleTimer?.cancel();
      _settleTimer = Timer(_settle, _save);
    }

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
      final fresh = ((letters.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_Newsletter.fromJson)
          .toList();
      // One opened from a dashboard filter can be older than the newest
      // fifty; it stays in the picker while it is open.
      final open = _selected;
      _all = open == null || fresh.any((n) => n.id == open.id)
          ? fresh
          : [...fresh, open];
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
      if (made.isNotEmpty) {
        setState(() {
          _open(made.first);
          // From the dashboard's New newsletter, land where it is written.
          if (_mayOpen('composer')) _tab = 'composer';
        });
      }
      await _refreshPreview();
    }
  }

  /// Why the open newsletter cannot be changed, or null for a draft. The
  /// server refuses these edits; saying so here stops a paragraph being typed
  /// only for the autosave to throw it away.
  String? get _readOnlyBecause => switch (_selected?.status) {
    'scheduled' =>
      'This newsletter is scheduled, so it cannot be changed. Delete it to '
          'stop it, or start a new one.',
    'sending' => 'This newsletter is being sent, so it cannot be changed.',
    'sent' =>
      'This newsletter has gone out, so it cannot be changed — the copy in '
          'people’s inboxes is the real one.',
    _ => null,
  };

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
            key: const ValueKey('tour-newsletter-none'),
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
          key: const ValueKey('tour-marketing-newsletter-panes'),
          level: TabLevel.secondary,
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
        session: widget.session,
        rows: _tableRows,
        status: _dashStatus,
        onStatus: _filter,
        query: _q,
        search: TextField(
          key: const Key('newsletters-search'),
          controller: _search,
          onChanged: _searched,
          // bodyMedium for the text and the hint, as the dense pickers draw:
          // 53 points tall, as they are; the theme's 16-point hint makes 56.
          style: Theme.of(context).textTheme.bodyMedium,
          decoration: InputDecoration(
            isDense: true,
            prefixIcon: const Icon(Icons.search, size: 18),
            hintText: 'Subject or mailing list',
            hintStyle: Theme.of(context).textTheme.bodyMedium?.copyWith(
              color: Theme.of(context).inputDecorationTheme.hintStyle?.color,
            ),
          ),
        ),
        onNew: _create,
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
        // A row or a calendar entry opens it in the composer, because
        // "review them or edit them" is the reason to list them at all — a
        // date with no way in is a poster. Without the Composer pane it is a
        // line to read rather than a door, so there is nothing to tap.
        onOpen: _mayOpen('composer') ? _openFromDashboard : null,
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
            key: const ValueKey('tour-newsletter-pick'),
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
          // The save state moved beside Send in layout A (Mia, 2026-10-02),
          // in the composer's right column, which exists only while a
          // newsletter is open — so it still never reads "Saved" about nothing.
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
                key: const ValueKey('tour-newsletter-delete'),
                // Red: it destroys the mailing (phone audit 2026-11-04).
                style: TextButton.styleFrom(
                  foregroundColor: Theme.of(context).colorScheme.error,
                ),
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
              key: const ValueKey('tour-newsletter-new'),
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
      key: const ValueKey('tour-newsletter-composer-empty'),
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

  /// The three columns. Keyed by the open newsletter, so switching to
  /// another one starts with nothing selected and its own When.
  Widget _split(BuildContext context) {
    return NewsletterEditor(
      key: ValueKey(_selectedId),
      subject: _subject,
      blocks: _blocks,
      audiences: _audiences,
      audienceId: _audienceId,
      when: _when,
      viewName: _viewName,
      company: widget.session.me?.organization.name ?? 'EzBizi',
      initialHtml: _htmlOverride,
      scheduledTimezone: _selected?.scheduledTimezone,
      session: widget.session,
      sendingRefusedBecause: _sendingRefusedBecause,
      readOnlyBecause: _readOnlyBecause,
      saveError: _saveError,
      saveState: _dirty
          ? 'Unsaved'
          : _saving
              ? 'Saving…'
              : 'Saved',
      previewHtml: _previewHtml,
      onChanged: _touched,
      onAudience: (id) {
        setState(() => _audienceId = id);
        _touched();
      },
      onNewList: _mayOpen('composer') ? _newList : null,
      onHtml: (html) {
        setState(() => _htmlOverride = html);
        _touched();
      },
      onSend: _send,
      api: widget.api,
      newsletterId: _selectedId,
      onSaveNow: () async {
        _settleTimer?.cancel();
        await _saveInFlight;
        if (mounted) await _save();
      },
    );
  }

  /// UI queue 173: create a list in a side panel, then pick it.
  Future<void> _newList() async {
    final made = await showNewListPanel(context, api: widget.api);
    if (made == null || !mounted) return;
    setState(() {
      _audiences = [..._audiences, made];
      _audienceId = made.id;
    });
    _touched();
  }

  Future<void> _send() async {
    final id = _selectedId;
    if (id == null) return;

    // Save first, or the mailing that goes out is the one before the last
    // sentence anybody typed.
    // A save already running is waited for, then the latest text is saved
    // again; a failed or incomplete save stops here rather than sending the
    // older saved version.
    _settleTimer?.cancel();
    await _saveInFlight;
    if (!mounted) return;
    await _save();
    if (!mounted) return;
    if (_saveError != null || _dirty) {
      setState(() => _saveError ??=
          'The latest changes are not saved yet, so nothing was sent. Try again.');
      return;
    }

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
    required this.row,
    this.audienceId,
    this.scheduledFor,
    this.scheduledTimezone,
    this.htmlOverride,
  });

  /// The same newsletter as the dashboard's table reads it.
  final NewsletterRow row;

  final String id;
  final String subject;
  final List<Map<String, dynamic>> blocks;
  final String status;
  final String? audienceId;
  final String? scheduledFor;

  /// The whole-mailing HTML, when somebody wrote one instead of blocks.
  final String? htmlOverride;

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
      row: NewsletterRow.fromJson(json),
      audienceId: audience is Map ? audience['id'] as String? : null,
      scheduledFor: json['scheduled_for'] as String?,
      scheduledTimezone: json['scheduled_timezone'] as String?,
      htmlOverride: json['html_override'] as String?,
      blocks: ((json['blocks'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList(),
    );
  }
}

