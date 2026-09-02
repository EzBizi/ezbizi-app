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
/// Arrangement is `docs/previews/suggestions-1.html` #1 — compose first, the
/// "What you have sent" receipt below it, newest first exactly as the server
/// sends it (`Suggestion::query()->latest('created_at')`, never resorted here).
class SuggestionsScreen extends StatefulWidget {
  const SuggestionsScreen({super.key, required this.api});

  final ApiClient api;

  @override
  State<SuggestionsScreen> createState() => _SuggestionsScreenState();
}

class _SuggestionsScreenState extends State<SuggestionsScreen> {
  static const int _maxLength = 2000;

  bool _loading = true;

  /// Which load owns the screen, the same rule as ClientVersionsScreen: a send,
  /// an edit or a withdraw all end in a refetch while the list is still on
  /// screen, so two requests can be in flight and whichever answers last must
  /// not be allowed to lose to one asked for earlier.
  int _request = 0;

  String? _error;
  List<Map<String, dynamic>> _rows = [];

  final _composeController = TextEditingController();
  bool _sending = false;

  /// One slot for every action's refusal — sending, saving an edit, or
  /// withdrawing — kept on the screen rather than a snack bar, because the
  /// server's sentences here explain a rule and are written to be read.
  String? _actionError;

  String? _editingId;
  final _editController = TextEditingController();
  bool _editSending = false;

  String? _withdrawingId;

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
      if (response.ok) {
        _rows = List<Map<String, dynamic>>.from(
          response.data as List? ?? const [],
        );
      } else {
        _error = response.message;
      }
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
    if (_withdrawingId != null) return;

    setState(() {
      _withdrawingId = id;
      _actionError = null;
    });

    final response = await widget.api.delete('/suggestions/$id');

    if (!mounted) return;

    setState(() => _withdrawingId = null);

    if (!response.ok) {
      setState(() => _actionError = response.message);
      return;
    }

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

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
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 760),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (_rows.isEmpty) ...[
                      _emptyState(context),
                      const SizedBox(height: 16),
                    ],
                    _composeBox(
                      context,
                      controller: _composeController,
                      sending: _sending,
                      sendLabel: 'Send it',
                      autofocus: _rows.isEmpty,
                      onSend: _send,
                    ),
                    if (_rows.isEmpty) ...[
                      const SizedBox(height: 10),
                      Text(
                        'Up to 2000 characters. You can correct it or take '
                        'it back until somebody reads it.',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                    if (_actionError != null) ...[
                      const SizedBox(height: 12),
                      ErrorNote(_actionError!),
                    ],
                    if (_rows.isNotEmpty) ...[
                      const SizedBox(height: 26),
                      Text(
                        'What you have sent',
                        style: theme.textTheme.titleMedium,
                      ),
                      const SizedBox(height: 10),
                      for (final row in _rows) ...[
                        _row(context, row),
                        const SizedBox(height: 12),
                      ],
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _emptyState(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.fromLTRB(24, 26, 24, 26),
      decoration: BoxDecoration(
        color: theme.scaffoldBackgroundColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      child: Column(
        children: [
          Text(
            'Nothing sent yet',
            textAlign: TextAlign.center,
            style: theme.textTheme.titleLarge,
          ),
          const SizedBox(height: 8),
          Text(
            'Anything you would change — a step that takes too many clicks, '
            'a number that is never right, something this app should do and '
            'does not. It goes to the people who build EzBizi, and they '
            'answer.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium,
          ),
        ],
      ),
    );
  }

  /// One compose box, reused for a new suggestion (top of the screen) and for
  /// correcting an existing one in place (inside its own card) — the same
  /// shape both times, just a different label on the primary button and an
  /// extra Cancel beside it when correcting.
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
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
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

  Widget _row(BuildContext context, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final id = row['id'] as String;
    final status = row['status'] as String? ?? 'new';
    final body = row['body'] as String? ?? '';
    final note = row['decision_note'] as String?;
    final createdAt = formatStamp(
      row['created_at'],
      'an unrecorded time',
      formats: DisplayFormats.fallback,
    );
    final decidedAt = formatStamp(
      row['decided_at'],
      'an unrecorded time',
      formats: DisplayFormats.fallback,
    );
    final editingThis = _editingId == id;
    final busy = _withdrawingId != null;

    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.fromLTRB(16, 11, 16, 11),
            decoration: BoxDecoration(
              color: theme.scaffoldBackgroundColor,
              border: Border(
                bottom: BorderSide(color: EzBiziTheme.edge(context)),
              ),
            ),
            child: Row(
              children: [
                _pill(context, status),
                const Spacer(),
                Text(createdAt, style: theme.textTheme.bodySmall),
              ],
            ),
          ),
          if (editingThis)
            Padding(
              padding: const EdgeInsets.all(12),
              child: _composeBox(
                context,
                controller: _editController,
                sending: _editSending,
                sendLabel: 'Save',
                autofocus: true,
                onCancel: _cancelEdit,
                onSend: () => _saveEdit(id),
              ),
            )
          else ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
              child: Text(
                body,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurface,
                ),
              ),
            ),
            if (note != null)
              _reply(
                context,
                status: status,
                note: note,
                decidedAt: decidedAt,
              ),
            if (status == 'new')
              Container(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
                decoration: BoxDecoration(
                  border: Border(
                    top: BorderSide(color: EzBiziTheme.edge(context)),
                  ),
                ),
                child: Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(
                      'Nobody has read it yet, so you can still change it '
                      'or take it back.',
                      style: theme.textTheme.bodySmall,
                    ),
                    OutlinedButton.icon(
                      onPressed: busy ? null : () => _startEdit(row),
                      icon: const Icon(Icons.edit_outlined, size: 16),
                      label: const Text('Edit'),
                    ),
                    OutlinedButton.icon(
                      onPressed: (busy || _withdrawingId == id)
                          ? null
                          : () => _withdraw(id),
                      icon: const Icon(Icons.delete_outline, size: 16),
                      label: const Text('Withdraw'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: theme.colorScheme.error,
                        side: BorderSide(
                          color: theme.colorScheme.error.withValues(
                            alpha: 0.5,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              )
            else
              Container(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
                decoration: BoxDecoration(
                  color: theme.scaffoldBackgroundColor,
                  border: Border(
                    top: BorderSide(color: EzBiziTheme.edge(context)),
                  ),
                ),
                child: Text(
                  '${_frozenReason(status)} Send another if there is more '
                  'to say.',
                  style: theme.textTheme.bodySmall,
                ),
              ),
          ],
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
    final stripe = approved
        ? EzBiziTheme.clockIn(context)
        : EzBiziTheme.clockOut(context);
    final headingColor = approved
        ? EzBiziTheme.clockIn(context)
        : theme.colorScheme.error;
    final background = approved
        ? EzBiziTheme.clockIn(context).withValues(alpha: 0.12)
        : theme.colorScheme.errorContainer;

    return Container(
      padding: const EdgeInsets.fromLTRB(14, 14, 16, 14),
      decoration: BoxDecoration(
        color: background,
        border: Border(
          top: BorderSide(color: EzBiziTheme.edge(context)),
          left: BorderSide(color: stripe, width: 4),
        ),
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
      'denied' => ('Denied', scheme.error, scheme.errorContainer, false),
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
