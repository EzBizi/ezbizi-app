import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/staff_session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/suggestion_files.dart';

/// Development › Suggestions — the queue EzBizi staff work from (Task 8).
///
/// The author's own tab is `SuggestionsScreen`, scoped to one account. This
/// reads across every tenant, the same control-plane shape as
/// `PhoneRequestsScreen`: a queue with a decision that carries a mandatory
/// sentence, behind `StaffSession` rather than a bare `ApiClient`.
///
/// Arrangement is `docs/previews/suggestions-queue-2.html` #2 — "list beside
/// the answer": a rail grouped into Waiting / Being read / Answered on the
/// left, the selected suggestion in full on the right, and the note field in
/// that pane's own footer so the text being answered never scrolls off while
/// the reply is written. That choice is design's, not this screen's — it is
/// built as drawn rather than adjusted.
///
/// ── Why nothing is selected when the queue first opens ───────────
///
/// The mockup's own stills open on a preselected row, because a still has to
/// show *something* in the detail pane. A live queue with one suggestion in
/// it would then repeat that suggestion's organization, author and body in
/// both the rail and the detail pane at once — the same words rendered
/// twice for no reason. Opening empty, and asking for a tap, does not.
///
/// ── "Mark in review" never reads the note box ────────────────────
///
/// The note field is shared with Approve and Deny because layout 2 draws it
/// that way — the same footer, same box, for every status. But moving a
/// suggestion to `reviewing` is deliberately silent (no notification reaches
/// the author), and the server would accept a note with that status anyway —
/// `GET /suggestions` returns `decision_note` whatever the status is, so the
/// author's own tab would render it under "EzBizi answered" with no bell to
/// send them there. So nothing here sends one. Closing that at the server is
/// somebody else's call, and the containment is this screen's until they make
/// it.
///
/// It used to reach that outcome by clearing the note on the way out, which
/// bought the invariant with a worse one: a reviewer who typed "Good idea,
/// scoping it for October" and pressed "Mark in review" lost the sentence with
/// no message. Text a person typed does not vanish without a word. So the
/// action is UNAVAILABLE while the box has content, and says so beside itself —
/// the note is still there to approve or deny with, or to clear. `_decide`
/// keeps blanking the note for `reviewing` as the backstop for a request that
/// should no longer be reachable.
class SuggestionsQueueScreen extends StatefulWidget {
  const SuggestionsQueueScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<SuggestionsQueueScreen> createState() => _SuggestionsQueueScreenState();
}

class _SuggestionsQueueScreenState extends State<SuggestionsQueueScreen> {
  /// Below this the rail and the detail pane cannot both fit — the same rule
  /// `EmailScreen` uses for its own mailbox list beside a reading pane.
  static const double _splitAbove = 860;
  static const double _railWidth = 340;
  static const int _maxNoteLength = 2000;

  bool _loading = true;
  int _request = 0;
  String? _error;
  List<Map<String, dynamic>> _rows = [];

  String? _selectedId;
  final _note = TextEditingController();
  bool _saving = false;
  String? _formError;

  /// Narrow layout only: which of the two panes is on screen. Ignored once
  /// the layout is wide enough to show both at once.
  bool _openNarrow = false;

  final _search = TextEditingController();
  String _query = '';
  Timer? _debounce;

  /// The suggestions whose visible text contains the search,
  /// case-insensitively.
  ///
  /// Until StaffSuggestionsController@index reads q, the loaded rows are
  /// filtered here too.
  List<Map<String, dynamic>> get _shown {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _rows;
    return _rows.where((row) {
      final text = [
        row['organization']?.toString(),
        row['author']?.toString(),
        row['body']?.toString(),
        row['decision_note']?.toString(),
        switch (row['status']) {
          'reviewing' => 'In review',
          'approved' => 'Approved',
          'denied' => 'Denied',
          _ => 'New',
        },
      ].whereType<String>().join(' ').toLowerCase();
      return text.contains(q);
    }).toList();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing on screen yet — see AsyncBody.
      // Not while searching: a spinner would tear the search field down
      // mid-typing when a search comes back empty.
      _loading = _rows.isEmpty && _query.trim().isEmpty;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/suggestions', {
      if (_query.trim().isNotEmpty) 'q': _query.trim(),
    });

    // Superseded by a newer load.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      final data = response.data;
      _rows = data is List
          ? data.whereType<Map<String, dynamic>>().toList()
          : <Map<String, dynamic>>[];

      final selected = _rowById(_selectedId);
      if (selected != null) {
        _fillNote(selected);
      } else if (_rows.isNotEmpty) {
        // Nothing selected yet, or the previous selection is gone. Default to
        // the first row rather than opening on an empty pane — layout 2's own
        // stills always show a suggestion open, and the whole point of that
        // arrangement is that the suggestion stays on screen while the note
        // is written, not that somebody has to click before it does.
        _selectedId = _rows.first['id'] as String?;
        _fillNote(_rows.first);
      } else {
        _selectedId = null;
      }
    });
  }

  Map<String, dynamic>? _rowById(String? id) {
    if (id == null) return null;
    for (final row in _rows) {
      if (row['id'] == id) return row;
    }
    return null;
  }

  /// Blank for anything nobody has answered yet; the standing answer for
  /// anything already decided, so touching it again is editing rather than
  /// starting over.
  void _fillNote(Map<String, dynamic> row) {
    final status = row['status'] as String? ?? 'new';
    final decided = status == 'approved' || status == 'denied';
    _note.text = decided ? (row['decision_note'] as String? ?? '') : '';
  }

  void _select(Map<String, dynamic> row) {
    setState(() {
      _selectedId = row['id'] as String?;
      _formError = null;
      _fillNote(row);
      _openNarrow = true;
    });
  }

  Future<void> _decide(Map<String, dynamic> row, String status) async {
    if (_saving) return;

    // "Mark in review" is silent by design — see the class comment. Nothing
    // typed travels with it, and the button is disabled the moment the box has
    // anything in it, so this is the backstop rather than the discard it once
    // was: reaching here with a note means the control was somehow pressed
    // while it was unavailable.
    final note = status == 'reviewing' ? '' : _note.text.trim();

    // The button offering this is disabled while the note is empty; this is
    // only the backstop for a request that should never reach it.
    if (status == 'denied' && note.isEmpty) return;

    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.staff.api.post(
      '/staff/suggestions/${row['id']}/decide',
      {'status': status, 'note': note.isEmpty ? null : note},
    );

    if (!mounted) return;

    setState(() => _saving = false);

    if (!response.ok) {
      // `.reason` over `.message`: a denial without a note is a 422 with the
      // field-specific sentence under `errors.note` — "Say why, in a
      // sentence. The person who sent it reads it." — and the generic
      // top-level message throws that away. Same choice ClientVersionsScreen
      // makes for its own save error.
      setState(() => _formError = response.reason);
      return;
    }

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _rows.isEmpty && _query.trim().isEmpty
          ? _empty(context)
          : LayoutBuilder(
              builder: (context, constraints) {
                final wide = constraints.maxWidth >= _splitAbove;
                return wide ? _wide(context) : _narrow(context);
              },
            ),
    );
  }

  Widget _empty(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        'Nobody has sent one in yet.',
        style: Theme.of(context).textTheme.bodyMedium,
      ),
    ),
  );

  Widget _wide(BuildContext context) => Row(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      SizedBox(width: _railWidth, child: _rail(context)),
      VerticalDivider(width: 1, color: EzBiziTheme.edge(context)),
      Expanded(child: _detailArea(context)),
    ],
  );

  Widget _narrow(BuildContext context) =>
      _openNarrow ? _detailArea(context, showBack: true) : _rail(context);

  Widget _detailArea(BuildContext context, {bool showBack = false}) {
    final row = _rowById(_selectedId);

    if (row == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Kept even here: on a narrow screen this is the only way back to
          // the rail if the selected suggestion ever stops being findable.
          if (showBack)
            IconButton(
              icon: const Icon(Icons.arrow_back),
              tooltip: 'Back to the list',
              onPressed: () => setState(() => _openNarrow = false),
            ),
          Expanded(
            child: Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  'Choose a suggestion from the list to read it and decide.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ),
            ),
          ),
        ],
      );
    }

    return _detail(context, row, showBack: showBack);
  }

  Widget _rail(BuildContext context) {
    bool isNew(Map<String, dynamic> r) => r['status'] == 'new';
    bool isReviewing(Map<String, dynamic> r) => r['status'] == 'reviewing';
    bool isDecided(Map<String, dynamic> r) =>
        r['status'] == 'approved' || r['status'] == 'denied';

    // The bands count the whole queue; the rows under them follow the search.
    final shown = _shown;

    return ListView(
      key: const Key('suggestions-rail'),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: SizedBox(
              width: 240,
              child: ListSearch(
                key: const Key('suggestions-queue-search'),
                controller: _search,
                hint: 'Find a suggestion',
                onChanged: (v) {
                    setState(() => _query = v);
                    _debounce?.cancel();
                    _debounce = Timer(const Duration(milliseconds: 400), _load);
                  },
              ),
            ),
          ),
        ),
        if (shown.isEmpty && _query.trim().isNotEmpty)
          Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              'No suggestions match "${_query.trim()}".',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          )
        else ...[
          suggestionBand(context, 'Waiting', _rows.where(isNew).length),
          for (final row in shown.where(isNew)) _railRow(context, row),
          suggestionBand(
            context,
            'Being read',
            _rows.where(isReviewing).length,
          ),
          for (final row in shown.where(isReviewing)) _railRow(context, row),
          suggestionBand(context, 'Answered', _rows.where(isDecided).length),
          for (final row in shown.where(isDecided)) _railRow(context, row),
        ],
      ],
    );
  }

  Widget _railRow(BuildContext context, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final id = row['id'] as String?;
    final selected = id != null && id == _selectedId;
    final status = row['status'] as String? ?? 'new';

    return InkWell(
      onTap: () => _select(row),
      child: Container(
        decoration: BoxDecoration(
          color: selected
              ? theme.colorScheme.primaryContainer.withValues(alpha: 0.25)
              : null,
          border: Border(
            bottom: BorderSide(color: EzBiziTheme.edge(context)),
            left: BorderSide(
              color: selected ? theme.colorScheme.primary : Colors.transparent,
              width: 3,
            ),
          ),
        ),
        padding: const EdgeInsets.fromLTRB(13, 11, 14, 11),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                suggestionPill(context, status),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    formatStamp(
                      row['created_at'],
                      'an unrecorded time',
                      formats: DisplayFormats.platform,
                    ),
                    textAlign: TextAlign.right,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 5),
            Text(
              row['organization']?.toString() ?? 'Unknown',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 2),
            Text(
              row['author']?.toString() ?? 'Somebody who has since left',
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 5),
            Text(
              row['body']?.toString() ?? '',
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurface,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _detail(
    BuildContext context,
    Map<String, dynamic> row, {
    required bool showBack,
  }) {
    final theme = Theme.of(context);
    final status = row['status'] as String? ?? 'new';
    final org = row['organization']?.toString() ?? 'Unknown';
    final author = row['author']?.toString() ?? 'Somebody who has since left';
    final body = row['body']?.toString() ?? '';
    final note = row['decision_note'] as String?;
    final decided = status == 'approved' || status == 'denied';
    final createdAt = formatStamp(
      row['created_at'],
      'an unrecorded time',
      formats: DisplayFormats.platform,
    );
    final decidedAt = formatStamp(
      row['decided_at'],
      'an unrecorded time',
      formats: DisplayFormats.platform,
    );

    return Column(
      key: const Key('suggestion-detail'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 20, 13),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (showBack)
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to the list',
                  onPressed: () => setState(() => _openNarrow = false),
                ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(org, style: theme.textTheme.titleLarge),
                    const SizedBox(height: 2),
                    Text(
                      '$author · sent $createdAt',
                      style: theme.textTheme.bodyMedium,
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  suggestionPill(context, status),
                  if (decided) ...[
                    const SizedBox(height: 6),
                    Text(
                      'answered $decidedAt',
                      style: theme.textTheme.bodyMedium,
                    ),
                  ],
                ],
              ),
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 18),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 700),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    body,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurface,
                    ),
                  ),
                  const SizedBox(height: 14),
                  SuggestionAbout(
                    key: ValueKey('about-${row['id']}'),
                    api: widget.staff.api,
                    base: '/staff/suggestions/${row['id']}',
                    row: row,
                  ),
                  if (note != null) ...[
                    const SizedBox(height: 18),
                    _answeredCallout(
                      context,
                      status: status,
                      note: note,
                      decidedAt: decidedAt,
                      author: author,
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        _footer(
          context,
          row,
          status: status,
          decided: decided,
          author: author,
          org: org,
        ),
      ],
    );
  }

  Widget _answeredCallout(
    BuildContext context, {
    required String status,
    required String note,
    required String decidedAt,
    required String author,
  }) {
    final theme = Theme.of(context);
    final approved = status == 'approved';
    // The green/red stripe down the leading edge is out (Jose, 2026-09-08).
    // Nothing is lost: this row already says approved-or-rejected twice more,
    // in the tinted background and in the heading colour, so the stripe was the
    // third voice rather than the only one.
    final background = approved
        ? EzBiziTheme.clockIn(context).withValues(alpha: 0.12)
        : theme.colorScheme.errorContainer;
    final headingColor = approved
        ? EzBiziTheme.clockIn(context)
        : theme.colorScheme.onErrorContainer;
    final first = author.split(' ').first;

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'What $first was told · $decidedAt',
            style: theme.textTheme.labelSmall?.copyWith(color: headingColor),
          ),
          const SizedBox(height: 6),
          Text(
            note,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurface,
            ),
          ),
        ],
      ),
    );
  }

  Widget _footer(
    BuildContext context,
    Map<String, dynamic> row, {
    required String status,
    required bool decided,
    required String author,
    required String org,
  }) {
    final theme = Theme.of(context);
    final first = author.split(' ').first;
    final hasNote = _note.text.trim().isNotEmpty;

    return Container(
      color: theme.scaffoldBackgroundColor,
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 700),
            child: TextField(
              controller: _note,
              enabled: !_saving,
              minLines: 3,
              maxLines: 6,
              maxLength: _maxNoteLength,
              decoration: InputDecoration(
                labelText: decided
                    ? 'Change the answer'
                    : 'Say why, in a sentence',
                helperText:
                    '$first reads this word for word. Nobody else at $org '
                    'sees any of it.',
                helperMaxLines: 2,
                hintText: 'What you decided, and why.',
              ),
              onChanged: (_) => setState(() {}),
            ),
          ),
          if (_formError != null) ...[
            const SizedBox(height: 8),
            ErrorNote(_formError!),
          ],
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              FilledButton(
                onPressed: _saving ? null : () => _decide(row, 'approved'),
                child: Text(decided ? 'Approve instead' : 'Approve'),
              ),
              OutlinedButton(
                onPressed: (_saving || !hasNote)
                    ? null
                    : () => _decide(row, 'denied'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: theme.colorScheme.error,
                  side: BorderSide(color: theme.colorScheme.error),
                ),
                child: Text(decided ? 'Deny instead' : 'Deny'),
              ),
              // Only offered while nobody has started reading it — once it is
              // `reviewing`, or decided, there is nothing left to mark.
              if (status == 'new') ...[
                const SizedBox(width: 4),
                // Unavailable once the box has content, because this action
                // sends none and silently dropping what somebody typed is the
                // one thing it must not do. The sentence beside it changes to
                // say so, rather than leaving a dead button unexplained.
                OutlinedButton(
                  onPressed: (_saving || hasNote)
                      ? null
                      : () => _decide(row, 'reviewing'),
                  child: const Text('Mark in review'),
                ),
                Text(
                  hasNote
                      ? 'sends no note — approve, deny, or clear the box to use it'
                      : 'tells $first nothing — it only stops them rewriting it',
                  style: theme.textTheme.bodyMedium,
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
