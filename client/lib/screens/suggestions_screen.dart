import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';

/// The author's own tab — anybody signed in, correcting or withdrawing a
/// suggestion while nobody has read it yet, and seeing EzBizi's answer once
/// somebody has.
///
/// ── Why there is no author field and no reviewer name ────────────
///
/// `GET /suggestions` carries no author (it is always the caller's own row)
/// and no reviewer — `decided_by` is stored server-side and deliberately not
/// returned, so what a person reads here is EzBizi's word rather than a named
/// colleague's. Nothing in this screen invents one.
///
/// ── Why Edit and Withdraw appear only on `status == 'new'` ───────
///
/// `SuggestionsController::update` and `::destroy` both return 422 the moment
/// somebody starts reading a suggestion — see `SuggestionsController::frozen`.
/// Offering a control the server will refuse is a screen that looks broken, so
/// the three other states show the server's own sentence in the footer slot
/// the buttons vacate instead.
///
/// Arrangement is `docs/previews/suggestions-2.html` #2 — JOSE'S CHOICE,
/// 2026-09-04 ("i like 2 for both"), overruling the provisional #1 this screen
/// shipped with. "What happened to what you sent": a rail split into Waiting
/// on EzBizi / Answered with a Write-a-suggestion button above it, and the
/// selected suggestion in full in a detail pane. The rail's bands are this
/// client's own arrangement — `GET /suggestions` returns newest first and
/// nothing else (`Suggestion::query()->latest('created_at')`), so each band
/// keeps the server's order rather than resorting within itself.
///
/// ── Why the first suggestion is preselected ──────────────────────
///
/// Same ruling as `SuggestionsQueueScreen`: a master-detail layout with
/// nothing selected shows an empty pane, and a test that asserts a value
/// appears exactly once would force that emptiness rather than degrade. Here
/// the layout wins — the newest suggestion opens on load, the same way the
/// queue opens on its own first row.
class SuggestionsScreen extends StatefulWidget {
  const SuggestionsScreen({super.key, required this.api, required this.formats});

  final ApiClient api;

  /// The organization's own date and clock, from `/me`'s `display` block.
  ///
  /// Taken as a parameter rather than reached for, the same way `OnboardingForm`
  /// takes it one destination above this one in `main.dart`. This screen is a
  /// tenant screen and the times on it are the tenant's — [DisplayFormats.fallback]
  /// here was the exact bug `formatStamp`'s docblock says the required parameter
  /// exists to prevent: it compiles, it looks right to whoever wrote it, and it
  /// ignores the setting an owner changed.
  final DisplayFormats formats;

  @override
  State<SuggestionsScreen> createState() => _SuggestionsScreenState();
}

class _SuggestionsScreenState extends State<SuggestionsScreen> {
  static const int _maxLength = 2000;

  /// Below this the rail and the detail pane cannot both fit — the same
  /// threshold `SuggestionsQueueScreen` uses for its own split.
  static const double _splitAbove = 860;
  static const double _railWidth = 340;

  bool _loading = true;

  /// Which load owns the screen, the same rule as ClientVersionsScreen: a send,
  /// an edit or a withdraw all end in a refetch while the list is still on
  /// screen, so two requests can be in flight and whichever answers last must
  /// not be allowed to lose to one asked for earlier.
  int _request = 0;

  String? _error;
  List<Map<String, dynamic>> _rows = [];

  String? _selectedId;

  /// True while the detail pane is showing the compose form rather than a
  /// selected suggestion — forced on whenever [_rows] is empty (there is
  /// nothing else it could show), and available on demand from the rail's
  /// "Write a suggestion" button otherwise.
  bool _composing = false;

  final _composeController = TextEditingController();
  bool _sending = false;

  /// One slot for every action's refusal — sending, saving an edit, or
  /// withdrawing — kept on the screen rather than a snack bar, because the
  /// server's sentences here explain a rule and are written to be read. Safe
  /// as a single slot because composing and viewing a suggestion are mutually
  /// exclusive panes: only one action is ever reachable at a time.
  String? _actionError;

  String? _editingId;
  final _editController = TextEditingController();
  bool _editSending = false;

  bool _withdrawing = false;

  /// Narrow layout only: which of the rail and the detail pane is on screen.
  /// Ignored once the layout is wide enough to show both at once.
  bool _openNarrow = false;

  /// Narrow layout only: which band the rail's segmented control is showing.
  String _narrowSegment = 'waiting';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _composeController.dispose();
    _editController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody.
      _loading = _rows.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/suggestions');

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _rows = List<Map<String, dynamic>>.from(
        response.data as List? ?? const [],
      );

      final selected = _rowById(_selectedId);
      if (selected == null) {
        if (_rows.isNotEmpty) {
          // Nothing selected yet, or the previous selection is gone (just
          // withdrawn, or just sent — either way the newest row is first).
          // Default to it rather than opening on an empty pane.
          _selectedId = _rows.first['id'] as String?;
          _composing = false;
        } else {
          _selectedId = null;
        }
      }

      // Nothing to select when the list is genuinely empty — the empty
      // state's own compose box, not a blank pane. Below the split width
      // that means the narrow layout must default onto the detail pane too:
      // otherwise a first-time phone visitor sees "Nothing here yet." behind
      // the Write-a-suggestion button instead of the compose box layout 1
      // always opened on.
      if (_rows.isEmpty) {
        _composing = true;
        _openNarrow = true;
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

  void _select(Map<String, dynamic> row) {
    setState(() {
      _selectedId = row['id'] as String?;
      _composing = false;
      _editingId = null;
      _actionError = null;
      _openNarrow = true;
    });
  }

  void _startCompose() {
    setState(() {
      _composing = true;
      _editingId = null;
      _actionError = null;
      _composeController.clear();
      _openNarrow = true;
    });
  }

  /// Unavailable when the list is empty — there is nowhere to cancel back to,
  /// and the empty state's compose box has no Cancel beside it for the same
  /// reason.
  void _cancelCompose() {
    setState(() {
      _composing = false;
      _actionError = null;
    });
  }

  Future<void> _send() async {
    final body = _composeController.text.trim();
    if (body.isEmpty || _sending) return;

    setState(() {
      _sending = true;
      _actionError = null;
    });

    final response = await widget.api.post('/suggestions', {'body': body});

    if (!mounted) return;

    setState(() => _sending = false);

    if (!response.ok) {
      setState(() => _actionError = response.message);
      return;
    }

    _composeController.clear();
    setState(() {
      _composing = false;
      // Clears the previous selection so `_load`'s own "nothing selected"
      // branch opens the pane on the row just sent — newest first, so it is
      // `_rows.first` the moment the reload lands.
      _selectedId = null;
    });
    await _load();
  }

  void _startEdit(Map<String, dynamic> row) {
    setState(() {
      _editingId = row['id'] as String;
      _editController.text = row['body'] as String? ?? '';
      _actionError = null;
    });
  }

  void _cancelEdit() {
    setState(() => _editingId = null);
  }

  Future<void> _saveEdit(String id) async {
    final body = _editController.text.trim();
    if (body.isEmpty || _editSending) return;

    setState(() {
      _editSending = true;
      _actionError = null;
    });

    final response = await widget.api.patch('/suggestions/$id', {
      'body': body,
    });

    if (!mounted) return;

    setState(() => _editSending = false);

    if (!response.ok) {
      // The server refuses with 422 the instant a colleague starts reading —
      // `SuggestionsController::frozen` — which a slow save can race into
      // even though this screen never offers the button once it knows that.
      setState(() => _actionError = response.message);
      return;
    }

    setState(() => _editingId = null);
    await _load();
  }

  Future<void> _withdraw(String id) async {
    if (_withdrawing) return;

    setState(() {
      _withdrawing = true;
      _actionError = null;
    });

    final response = await widget.api.delete('/suggestions/$id');

    if (!mounted) return;

    setState(() => _withdrawing = false);

    if (!response.ok) {
      setState(() => _actionError = response.message);
      return;
    }

    // The withdrawn row is gone once `_load` lands; its own "selection is
    // gone" branch picks the new first row, or forces the compose pane back
    // on if that was the only suggestion there was.
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const PanelHeader(
          title: 'Suggestions',
          note:
              'Tell EzBizi what the software should do differently. Only you '
              'and EzBizi staff can read what you write here — nobody at '
              'your own organization can, not even an administrator.',
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: LayoutBuilder(
              builder: (context, constraints) {
                final wide = constraints.maxWidth >= _splitAbove;
                return wide ? _wide(context) : _narrow(context);
              },
            ),
          ),
        ),
      ],
    );
  }

  Widget _wide(BuildContext context) => Row(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      SizedBox(width: _railWidth, child: _rail(context)),
      VerticalDivider(width: 1, color: EzBiziTheme.edge(context)),
      Expanded(child: _detailArea(context)),
    ],
  );

  Widget _narrow(BuildContext context) =>
      _openNarrow ? _detailArea(context, showBack: true) : _narrowList(context);

  Widget _detailArea(BuildContext context, {bool showBack = false}) {
    if (_composing) {
      return _composeArea(context, showBack: showBack);
    }

    final row = _rowById(_selectedId);

    if (row == null) {
      // Only reachable while a load is in flight and the previous selection
      // has not yet been replaced — `_load` never leaves both `_composing`
      // false and the selection unresolved once it lands.
      return const SizedBox.shrink();
    }

    return _detail(context, row, showBack: showBack);
  }

  Widget _rail(BuildContext context) {
    final waiting = _rows
        .where((r) => r['status'] == 'new' || r['status'] == 'reviewing')
        .toList();
    final answered = _rows
        .where((r) => r['status'] == 'approved' || r['status'] == 'denied')
        .toList();

    return ListView(
      key: const Key('suggestions-rail'),
      children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _startCompose,
              child: const Text('Write a suggestion'),
            ),
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        if (_rows.isEmpty)
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              'Nothing here yet.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          )
        else ...[
          _band(context, 'Waiting on EzBizi', waiting.length),
          for (final row in waiting) _railRow(context, row),
          _band(context, 'Answered', answered.length),
          for (final row in answered) _railRow(context, row),
        ],
      ],
    );
  }

  Widget _band(BuildContext context, String label, int count) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 6),
      child: Row(
        children: [
          Text(label, style: theme.textTheme.labelSmall),
          const SizedBox(width: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
            decoration: BoxDecoration(
              border: Border.all(color: EzBiziTheme.edge(context)),
              borderRadius: BorderRadius.circular(999),
            ),
            child: Text('$count', style: theme.textTheme.labelSmall),
          ),
        ],
      ),
    );
  }

  Widget _railRow(BuildContext context, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final id = row['id'] as String?;
    final selected = !_composing && id != null && id == _selectedId;
    final status = row['status'] as String? ?? 'new';
    final note = row['decision_note'] as String?;
    final createdAt = formatStamp(
      row['created_at'],
      'an unrecorded time',
      formats: widget.formats,
    );
    final decidedAt = formatStamp(
      row['decided_at'],
      'an unrecorded time',
      formats: widget.formats,
    );

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
                _pill(context, status),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    createdAt,
                    textAlign: TextAlign.right,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              ],
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
            if (status == 'new') ...[
              const SizedBox(height: 5),
              Text(
                'You can still change this one',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.primary,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ] else if (note != null) ...[
              const SizedBox(height: 5),
              Text(
                'EzBizi answered · $decidedAt',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: status == 'approved'
                      ? EzBiziTheme.clockIn(context)
                      : theme.colorScheme.error,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// Narrow layout's own list pane: a "Write a suggestion" action and a
  /// segmented Waiting / Answered toggle over the same two bands the wide
  /// rail draws side by side, since there is no room here to show both bands
  /// scrolling at once alongside a detail pane.
  Widget _narrowList(BuildContext context) {
    final waiting = _rows
        .where((r) => r['status'] == 'new' || r['status'] == 'reviewing')
        .toList();
    final answered = _rows
        .where((r) => r['status'] == 'approved' || r['status'] == 'denied')
        .toList();
    final shown = _narrowSegment == 'waiting' ? waiting : answered;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
          child: FilledButton(
            onPressed: _startCompose,
            child: const Text('Write a suggestion'),
          ),
        ),
        if (_rows.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
            child: SegmentedButton<String>(
              segments: [
                ButtonSegment(
                  value: 'waiting',
                  label: Text('Waiting · ${waiting.length}'),
                ),
                ButtonSegment(
                  value: 'answered',
                  label: Text('Answered · ${answered.length}'),
                ),
              ],
              selected: {_narrowSegment},
              onSelectionChanged: (next) =>
                  setState(() => _narrowSegment = next.first),
            ),
          ),
        Expanded(
          child: _rows.isEmpty
              ? Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    'Nothing here yet.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                )
              : shown.isEmpty
              ? Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    'Nothing in this one yet.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                )
              : ListView(
                  // Its own key, not `suggestions-rail`: the narrow list only
                  // ever shows one band (`shown`) at a time behind the
                  // segmented control, unlike the wide rail which renders
                  // both bands together. Reusing the wide rail's key would
                  // let a future `_inRail`-style finder written at a narrow
                  // width quietly miss whichever band is not selected.
                  key: const Key('suggestions-narrow-list'),
                  children: [for (final row in shown) _railRow(context, row)],
                ),
        ),
      ],
    );
  }

  /// The compose pane: the empty state's own hero text above the box on the
  /// first visit (there is nothing else this pane could show), or the bare
  /// box with a Cancel back to the previous selection once something has
  /// already been sent.
  Widget _composeArea(BuildContext context, {bool showBack = false}) {
    final theme = Theme.of(context);
    final canCancel = _rows.isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (showBack)
          IconButton(
            icon: const Icon(Icons.arrow_back),
            tooltip: 'Back to the list',
            onPressed: () => setState(() => _openNarrow = false),
          ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 700),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (_rows.isEmpty) ...[
                    Text(
                      'Tell EzBizi what to do differently',
                      style: theme.textTheme.titleLarge,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Anything you would change — a step that takes too '
                      'many clicks, a number that is never right, something '
                      'this app should do and does not. It goes to the '
                      'people who build EzBizi, and they answer.',
                      style: theme.textTheme.bodyMedium,
                    ),
                    const SizedBox(height: 20),
                  ],
                  _composeBox(
                    context,
                    controller: _composeController,
                    sending: _sending,
                    sendLabel: 'Send it',
                    autofocus: true,
                    onCancel: canCancel ? _cancelCompose : null,
                    onSend: _send,
                  ),
                  if (_actionError != null) ...[
                    const SizedBox(height: 12),
                    ErrorNote(_actionError!),
                  ],
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// One compose box, reused for a new suggestion and for correcting an
  /// existing one in place — the same shape both times, just a different
  /// label on the primary button and an extra Cancel beside it when correcting
  /// or when there is somewhere to cancel back to.
  Widget _composeBox(
    BuildContext context, {
    required TextEditingController controller,
    required bool sending,
    required String sendLabel,
    required VoidCallback onSend,
    VoidCallback? onCancel,
    bool autofocus = false,
  }) {
    final theme = Theme.of(context);

    return Container(
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(12),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
            child: TextField(
              controller: controller,
              enabled: !sending,
              autofocus: autofocus,
              minLines: 4,
              maxLines: 10,
              maxLength: _maxLength,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurface,
              ),
              decoration: const InputDecoration(
                isCollapsed: true,
                filled: false,
                border: InputBorder.none,
                hintText: 'What should EzBizi do differently?',
                counterText: '',
              ),
            ),
          ),
          Container(
            decoration: BoxDecoration(
              color: theme.scaffoldBackgroundColor,
              border: Border(top: BorderSide(color: EzBiziTheme.edge(context))),
            ),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: ValueListenableBuilder<TextEditingValue>(
              valueListenable: controller,
              builder: (context, value, _) {
                final hasText = value.text.trim().isNotEmpty;

                return Row(
                  children: [
                    Text(
                      '${value.text.length} / $_maxLength',
                      style: theme.textTheme.bodySmall,
                    ),
                    const Spacer(),
                    if (onCancel != null) ...[
                      TextButton(
                        onPressed: sending ? null : onCancel,
                        child: const Text('Cancel'),
                      ),
                      const SizedBox(width: 8),
                    ],
                    FilledButton(
                      onPressed: (!sending && hasText) ? onSend : null,
                      child: sending
                          ? SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                // FilledButton's own foreground, not a fixed
                                // white: on dark it is ink-on-cyan, and a
                                // hardcoded white spinner would be nearly
                                // invisible against that fill.
                                color: theme.colorScheme.onPrimary,
                              ),
                            )
                          : Text(sendLabel),
                    ),
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _detail(
    BuildContext context,
    Map<String, dynamic> row, {
    required bool showBack,
  }) {
    final theme = Theme.of(context);
    final id = row['id'] as String;
    final status = row['status'] as String? ?? 'new';
    final body = row['body'] as String? ?? '';
    final note = row['decision_note'] as String?;
    final createdAt = formatStamp(
      row['created_at'],
      'an unrecorded time',
      formats: widget.formats,
    );
    final decidedAt = formatStamp(
      row['decided_at'],
      'an unrecorded time',
      formats: widget.formats,
    );
    final editingThis = _editingId == id;

    return Column(
      key: const Key('suggestion-detail'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 20, 13),
          // Pill leading, stamp trailing — `docs/previews/suggestions-2.html`
          // `.dHead` is a single row (pill, then the title, then `.when`
          // pushed right by its own `margin-left:auto`), not the title
          // leading with the pill on the far side. Title and stamp are both
          // flexible (rather than the stamp sitting at its natural width) so
          // the row degrades by eliding text instead of overflowing at
          // phone widths, where the back button leaves little room.
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              if (showBack)
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to the list',
                  onPressed: () => setState(() => _openNarrow = false),
                ),
              _pill(context, status),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Your suggestion',
                  style: theme.textTheme.titleLarge,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const SizedBox(width: 8),
              Flexible(
                child: Text(
                  'Sent $createdAt',
                  style: theme.textTheme.bodyMedium,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.right,
                ),
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
                  if (editingThis)
                    _composeBox(
                      context,
                      controller: _editController,
                      sending: _editSending,
                      sendLabel: 'Save',
                      autofocus: true,
                      onCancel: _cancelEdit,
                      onSend: () => _saveEdit(id),
                    )
                  else
                    Text(
                      body,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.onSurface,
                      ),
                    ),
                  if (editingThis && _actionError != null) ...[
                    const SizedBox(height: 12),
                    ErrorNote(_actionError!),
                  ],
                  if (!editingThis && note != null) ...[
                    const SizedBox(height: 18),
                    _reply(
                      context,
                      status: status,
                      note: note,
                      decidedAt: decidedAt,
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
        if (!editingThis) ...[
          Divider(height: 1, color: EzBiziTheme.edge(context)),
          _footer(context, row, status: status),
        ],
      ],
    );
  }

  Widget _footer(
    BuildContext context,
    Map<String, dynamic> row, {
    required String status,
  }) {
    final theme = Theme.of(context);
    final id = row['id'] as String;

    if (status != 'new') {
      return Container(
        color: theme.scaffoldBackgroundColor,
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 14),
        child: Text(
          // Word for word from `SuggestionsController::frozen` — see
          // `_frozenReason`.
          '${_frozenReason(status)} Send another if there is more to say.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    return Container(
      color: theme.scaffoldBackgroundColor,
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (_actionError != null) ...[
            ErrorNote(_actionError!),
            const SizedBox(height: 8),
          ],
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                'Nobody has read it yet, so you can still change it or take '
                'it back.',
                style: theme.textTheme.bodySmall,
              ),
              OutlinedButton.icon(
                onPressed: _withdrawing ? null : () => _startEdit(row),
                icon: const Icon(Icons.edit_outlined, size: 16),
                label: const Text('Edit'),
              ),
              OutlinedButton.icon(
                onPressed: _withdrawing ? null : () => _withdraw(id),
                icon: const Icon(Icons.delete_outline, size: 16),
                label: const Text('Withdraw'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: theme.colorScheme.error,
                  side: BorderSide(
                    color: theme.colorScheme.error.withValues(alpha: 0.5),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Why it can no longer be touched, word for word from
  /// `SuggestionsController::frozen` — matched, not paraphrased, so nothing
  /// yesterday's server sentence and today's screen say differs by a word.
  String _frozenReason(String status) => status == 'reviewing'
      ? 'Somebody at EzBizi is reading this one, so it is fixed while they '
            'do.'
      : 'This one has been answered, so it stays as it was written.';

  Widget _reply(
    BuildContext context, {
    required String status,
    required String note,
    required String decidedAt,
  }) {
    final theme = Theme.of(context);
    final approved = status == 'approved';
    // The green/red stripe down the leading edge is out (Jose, 2026-09-08).
    // Nothing is lost: this row already says approved-or-rejected twice more,
    // in the tinted background and in the heading colour, so the stripe was the
    // third voice rather than the only one.
    // `onErrorContainer`, not `error`: the background below is the full,
    // opaque container role (not an alpha-blended tint), and that is the
    // foreground Material pairs with it.
    final headingColor = approved
        ? EzBiziTheme.clockIn(context)
        : theme.colorScheme.onErrorContainer;
    final background = approved
        ? EzBiziTheme.clockIn(context).withValues(alpha: 0.12)
        : theme.colorScheme.errorContainer;

    return Container(
      padding: const EdgeInsets.fromLTRB(14, 14, 16, 14),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'EzBizi answered · $decidedAt',
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

  /// The state in words — never the raw key. `new` gets an outline rather
  /// than a fill because it is the one state with nothing decided about it
  /// yet; the other three borrow the same colours the rest of the app already
  /// uses for the same idea (`EzBiziTheme.clockIn` for a good outcome, the
  /// real `errorContainer` for a refusal) rather than inventing new ones.
  Widget _pill(BuildContext context, String status) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    final (label, fg, bg, outlined) = switch (status) {
      'reviewing' => (
        'In review',
        scheme.primary,
        scheme.primaryContainer.withValues(alpha: 0.35),
        false,
      ),
      'approved' => (
        'Approved',
        EzBiziTheme.clockIn(context),
        EzBiziTheme.clockIn(context).withValues(alpha: 0.12),
        false,
      ),
      'denied' => (
        'Denied',
        scheme.onErrorContainer,
        scheme.errorContainer,
        false,
      ),
      _ => ('New', scheme.onSurface, scheme.surface, true),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(999),
        border: outlined ? Border.all(color: scheme.outline) : null,
      ),
      child: Text(label, style: theme.textTheme.labelSmall?.copyWith(color: fg)),
    );
  }
}
