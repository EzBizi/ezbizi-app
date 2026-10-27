import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/ai_mark.dart';
import '../widgets/async_body.dart';
import '../widgets/connect_account.dart';
import '../widgets/connected_account.dart';
import '../widgets/search_picker.dart';
import '../widgets/open_destination.dart';
import '../widgets/status_pill.dart';
import 'customer_experience/review_stats_band.dart';

/// Reviews — what the public said, the answer, and the work behind it.
///
/// ── UI queue 145 (Jose, 2026-10-26: "Looks good", 1 and 4) ─────
///
/// An inbox: a summary strip (average this month against last, new this
/// month, unanswered, 1–2 stars still to answer; each tile sets its filter),
/// search with quick chips and a More filters panel, the reviews as a list on
/// the left and the open one on the right with its reply box. Table and Board
/// (UI queue 101) stay behind the view toggle. On a phone the list is the
/// screen and a review opens in place with a back arrow.
///
/// ── Unanswered is the filter that ships switched on ──────────
///
/// Somebody opening this tab is almost never browsing. They are looking for
/// the ones nobody has replied to, because an unanswered one-star sits at the
/// top of a Google listing being the first thing a stranger reads.
///
/// ── Save draft and Post reply are two buttons for one reason ─
///
/// A draft is text nobody outside the building can see. Posting puts it on
/// Google under the business's name, in public, permanently. They carry
/// different permissions, and posting asks once more, inline.
class ReviewsScreen extends StatefulWidget {
  const ReviewsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Wider than this the opened review sits beside the list; narrower it
  /// replaces the list, with a back arrow.
  static const double sideAbove = 900;

  /// The inbox list's width beside the open review.
  static const double listWidth = 420;

  @override
  State<ReviewsScreen> createState() => _ReviewsScreenState();
}

/// Where working a review has got to, as the server stores it.
const _statuses = {
  'cant_locate': ("Can't locate", Tone.caution),
  'working': ('Working on it', Tone.info),
  'awaiting_report': ('Awaiting report', Tone.review),
  'done': ('Done', Tone.ok),
};

/// The time filter's "since the 1st of this month", which the summary's first
/// two tiles set. The other values are a number of days back.
const _thisMonth = -1;

/// What the summary strip counts. Each figure says where it came from.
class _Summary {
  const _Summary({
    this.averageThis,
    this.averageLast,
    this.newThis = 0,
    this.counted = 0,
    this.total = 0,
    this.low,
  });

  final double? averageThis;
  final double? averageLast;
  final int newThis;

  /// Reviews since the 1st of last month that were read, of [total] there are.
  final int counted;
  final int total;

  /// Unanswered 1- and 2-star reviews, from the server's own totals.
  final int? low;

  /// The server caps a page at 200, so more than that is a sample.
  bool get partial => total > counted;
}

class _ReviewsScreenState extends State<ReviewsScreen> {
  bool _loading = true;
  bool _syncing = false;
  String? _error;
  String? _notice;

  /// The rows GET /reviews returned for the current filters.
  List<Map<String, dynamic>> _loaded = [];
  List<Map<String, dynamic>> _connections = [];
  List<Map<String, dynamic>> _locations = [];
  int _total = 0;
  int _unanswered = 0;
  _Summary? _summary;

  /// `meta.stats` of the list as filtered: histogram and replies (design 154 #7).
  Map<String, dynamic>? _stats;
  bool _drafting = false;

  /// Opens on unanswered — see the class note.
  bool _onlyUnanswered = true;

  /// The "1–2 stars" chip. The server's rating filter has no "2 or fewer", so
  /// this asks for 1–3 and keeps the 1s and 2s.
  bool _low = false;
  String _rating = 'any';
  int? _days;
  String _sort = 'newest';
  String? _location;
  String _matched = 'any';
  String? _status;

  /// 'inbox' (the default), 'table' or 'board'.
  String _view = 'inbox';
  bool _moreOpen = false;

  /// The search box. It is sent to the server as `q`, debounced.
  final _search = TextEditingController();
  Timer? _debounce;
  String _q = '';

  int _request = 0;

  /// Up and down in the inbox move the selection.
  final _listFocus = FocusNode(debugLabel: 'reviews-list');

  /// The review open in the panel, refreshed from GET /reviews/{id}.
  Map<String, dynamic>? _open;
  bool _confirmingPublish = false;
  final _draft = TextEditingController();
  final _note = TextEditingController();
  final _reason = TextEditingController();
  final _match = TextEditingController();
  List<Map<String, dynamic>>? _suggestions;
  bool _reporting = false;

  /// The words AI suggested, while the box still holds them unchanged.
  String? _aiDraft;
  bool _busy = false;

  bool _can(String key) => widget.session.me?.can(key) ?? false;

  @override
  void initState() {
    super.initState();
    _load();
    _loadSummary();
    _takeLink();
    DeepLinks.pending.addListener(_takeLink);
  }

  /// A tapped notification about a review opens that review.
  void _takeLink() {
    final link = DeepLinks.take('reviews');
    if (link == null || link.type != 'review') return;
    _openReview({'id': link.id});
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_takeLink);
    _draft.dispose();
    _note.dispose();
    _reason.dispose();
    _match.dispose();
    _search.dispose();
    _listFocus.dispose();
    _debounce?.cancel();
    super.dispose();
  }

  static String _ymd(DateTime d) =>
      '${d.year}-${'${d.month}'.padLeft(2, '0')}-${'${d.day}'.padLeft(2, '0')}';

  DateTime? get _from {
    final days = _days;
    if (days == null) return null;
    final now = DateTime.now();
    if (days == _thisMonth) return DateTime(now.year, now.month);
    return now.subtract(Duration(days: days));
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      _loading = _loaded.isEmpty;
      _error = null;
    });

    final from = _from;
    final response = await widget.api.get('/reviews', {
      if (_onlyUnanswered) 'answered': 'no',
      if (_low)
        'rating': 'negative'
      else if (_rating != 'any')
        'rating': _rating,
      if (_low) 'per_page': '200',
      if (from != null) 'from': _ymd(from),
      if (_sort != 'newest') 'sort': _sort,
      'location': ?_location,
      if (_matched != 'any') 'matched': _matched,
      'status': ?_status,
      if (_q.isNotEmpty) 'q': _q,
    });

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _loaded = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      final meta = response.body['meta'] as Map<String, dynamic>? ?? const {};
      _total = (meta['total'] as num?)?.toInt() ?? _loaded.length;
      _unanswered = (meta['unanswered'] as num?)?.toInt() ?? 0;
      final stats = meta['stats'];
      _stats = stats is Map ? Map<String, dynamic>.from(stats) : null;
      _connections = List<Map<String, dynamic>>.from(
        (meta['connections'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      _locations = List<Map<String, dynamic>>.from(
        (meta['locations'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      // Keep the open one current with what the list now says.
      final open = _open;
      if (open != null) {
        final fresh = _loaded.where((r) => r['id'] == open['id']);
        if (fresh.isNotEmpty) _open = {...open, ...fresh.first};
      }
    });
  }

  /// The summary strip, from the server's own rows and totals.
  ///
  /// ReviewsController has no stats endpoint, so: every review since the 1st
  /// of last month (one page of up to 200) for the two averages and "new",
  /// and the totals of unanswered 1-star and 2-star reviews for the last tile.
  /// Unanswered is the list's own `meta.unanswered`.
  Future<void> _loadSummary() async {
    final now = DateTime.now();
    final thisMonth = DateTime(now.year, now.month);
    final lastMonth = DateTime(now.year, now.month - 1);

    final responses = await Future.wait([
      widget.api.get('/reviews', {'from': _ymd(lastMonth), 'per_page': '200'}),
      for (final stars in const ['1', '2'])
        widget.api.get('/reviews', {
          'answered': 'no',
          'rating': stars,
          'per_page': '1',
        }),
    ]);
    if (!mounted) return;

    final window = responses[0];
    if (!window.ok) return;
    final rows = (window.data as List? ?? const [])
        .whereType<Map<String, dynamic>>();
    final thisRatings = <int>[];
    final lastRatings = <int>[];
    var newThis = 0;
    for (final r in rows) {
      final at = DateTime.tryParse('${r['reviewed_at'] ?? ''}')?.toLocal();
      if (at == null) continue;
      final rating = (r['rating'] as num?)?.toInt();
      if (!at.isBefore(thisMonth)) {
        newThis++;
        if (rating != null) thisRatings.add(rating);
      } else if (!at.isBefore(lastMonth)) {
        if (rating != null) lastRatings.add(rating);
      }
    }
    double? average(List<int> r) =>
        r.isEmpty ? null : r.reduce((a, b) => a + b) / r.length;

    int? totalOf(ApiResponse r) => r.ok
        ? ((r.body['meta'] as Map?)?['total'] as num?)?.toInt() ??
              (r.data as List? ?? const []).length
        : null;
    final one = totalOf(responses[1]);
    final two = totalOf(responses[2]);
    final meta = window.body['meta'] as Map<String, dynamic>? ?? const {};
    final counted = rows.length;

    setState(
      () => _summary = _Summary(
        averageThis: average(thisRatings),
        averageLast: average(lastRatings),
        newThis: newThis,
        counted: counted,
        total: (meta['total'] as num?)?.toInt() ?? counted,
        low: one == null || two == null ? null : one + two,
      ),
    );
  }

  /// The loaded rows the search box and the 1–2 stars chip allow.
  ///
  /// The server reads `q` (c3cf49fdc); narrowing the loaded page as well keeps
  /// the list right while a debounced request is still out.
  List<Map<String, dynamic>> get _reviews {
    final q = _q.toLowerCase();
    return _loaded.where((r) {
      if (_low && ((r['rating'] as num?)?.toInt() ?? 9) > 2) return false;
      if (q.isEmpty) return true;
      final text = [
        r['reviewer_name'],
        r['comment'],
        (r['customer'] as Map?)?['name'],
        (r['reply'] as Map?)?['body'],
      ].whereType<String>().join(' ').toLowerCase();
      return text.contains(q);
    }).toList();
  }

  void _searched(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      _refilter(() => _q = text.trim());
    });
  }

  void _refilter(VoidCallback change) {
    setState(change);
    _load();
  }

  /// The filters in the More filters panel that are not their default. The
  /// quick chips show their own state, so "This week" is not counted twice;
  /// sort is an order, not a filter.
  int get _moreCount => [
    _rating != 'any',
    _days != null && _days != 7,
    _location != null,
    _matched != 'any',
    _status != null,
  ].where((on) => on).length;

  Future<void> _sync() async {
    setState(() {
      _syncing = true;
      _notice = null;
    });

    final response = await widget.api.post('/reviews/sync', const {});

    if (!mounted) return;
    setState(() => _syncing = false);

    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }

    final data = response.data as Map<String, dynamic>? ?? const {};
    final created = (data['created'] as num?)?.toInt() ?? 0;

    setState(
      () => _notice = created > 0
          ? '$created new ${created == 1 ? 'review' : 'reviews'}.'
          : 'Nothing new.',
    );

    await _load();
    await _loadSummary();
  }

  // ------------------------------------------------------------- the panel

  Future<void> _openReview(Map<String, dynamic> review) async {
    setState(() {
      _open = review;
      _moreOpen = false;
      _confirmingPublish = false;
      _reporting = false;
      _suggestions = null;
      _aiDraft = null;
      _draft.text =
          (review['reply'] as Map<String, dynamic>?)?['body'] as String? ?? '';
    });
    await _refresh();
  }

  /// The review's whole record, notes included.
  Future<void> _refresh() async {
    final id = _open?['id'];
    if (id == null) return;
    final response = await widget.api.get('/reviews/$id');
    if (!mounted || _open?['id'] != id) return;
    _take(response);
  }

  /// Every write returns the same body as GET /reviews/{id}.
  void _take(ApiResponse response) {
    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }
    final data = response.data;
    if (data is Map<String, dynamic> && data['id'] == _open?['id']) {
      setState(() => _open = {..._open!, ...data});
    }
  }

  Future<void> _write(Future<ApiResponse> call) async {
    setState(() => _busy = true);
    final response = await call;
    if (!mounted) return;
    setState(() => _busy = false);
    _take(response);
    if (response.ok) await _load();
  }

  /// Grok's words into the box, for a person to edit (AI item 11).
  Future<void> _suggest(Map<String, dynamic> review) async {
    setState(() => _busy = true);
    final response = await widget.api.post(
      '/reviews/${review['id']}/reply/suggest',
      const {},
    );
    if (!mounted) return;
    setState(() => _busy = false);
    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }
    final body = (response.data as Map)['body'] as String? ?? '';
    setState(() {
      _draft.text = body;
      _aiDraft = body;
    });
  }

  /// Unanswered reviews in view with no saved reply: what Draft all writes.
  List<Map<String, dynamic>> get _toDraft => [
    for (final r in _reviews)
      if (r['reply'] == null) r,
  ];

  /// Draft all (design 154 #7): an AI draft saved on each unanswered review in
  /// view. Nothing posts; each one still waits for a person's Post.
  Future<void> _draftAll() async {
    final queue = _toDraft;
    setState(() {
      _drafting = true;
      _notice = null;
    });
    var saved = 0;
    String? refused;
    for (final review in queue) {
      final suggested = await widget.api.post(
        '/reviews/${review['id']}/reply/suggest',
        const {},
      );
      if (!mounted) return;
      final body = suggested.ok
          ? ((suggested.data as Map?)?['body'] as String? ?? '').trim()
          : '';
      if (body.isEmpty) {
        refused ??= suggested.ok ? null : suggested.message;
        continue;
      }
      final put = await widget.api.put('/reviews/${review['id']}/reply', {
        'body': body,
      });
      if (!mounted) return;
      if (put.ok) {
        saved++;
      } else {
        refused ??= put.message;
      }
    }
    setState(() {
      _drafting = false;
      _notice =
          '$saved of ${queue.length} drafted. Nothing is public until you '
          'post it.${refused == null ? '' : ' $refused'}';
    });
    await _load();
  }

  /// The rules whose wait paces published replies live in Automations.
  VoidCallback? get _openSendWindow {
    final open = OpenDestination.of(context);
    if (open == null || !(widget.session.me?.reaches('automations') ?? false)) {
      return null;
    }
    return () => open('automations');
  }

  String? _savedBody(Map<String, dynamic> review) =>
      (review['reply'] as Map<String, dynamic>?)?['body'] as String?;

  /// PUT the box as the draft. False when the server refused it.
  Future<bool> _putDraft(Map<String, dynamic> review) async {
    final response = await widget.api.put('/reviews/${review['id']}/reply', {
      'body': _draft.text.trim(),
    });
    if (!mounted) return false;
    if (!response.ok) setState(() => _notice = response.message);
    return response.ok;
  }

  Future<void> _saveDraft(Map<String, dynamic> review) async {
    if (_draft.text.trim().isEmpty) return;

    setState(() => _busy = true);
    final saved = await _putDraft(review);
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (saved) {
        _notice = 'Draft saved. Nothing is public until you post it.';
      }
    });
    if (!saved) return;
    await _load();
    await _refresh();
  }

  /// Post reply: the box is saved as the draft first when it changed, then
  /// the draft is published. Only after the inline confirm.
  Future<void> _publish(Map<String, dynamic> review) async {
    setState(() {
      _confirmingPublish = false;
      _busy = true;
    });

    if (_draft.text.trim() != (_savedBody(review) ?? '').trim()) {
      if (!await _putDraft(review)) {
        if (mounted) setState(() => _busy = false);
        return;
      }
    }

    final response = await widget.api.post(
      '/reviews/${review['id']}/reply/publish',
      const {},
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      _notice = response.ok ? 'Reply posted on Google.' : response.message;
    });

    await _load();
    await _refresh();
    await _loadSummary();
  }

  Future<void> _findCustomers(Map<String, dynamic> review) async {
    final response = await widget.api.get(
      '/reviews/${review['id']}/customer-suggestions',
      {if (_match.text.trim().isNotEmpty) 'q': _match.text.trim()},
    );
    if (!mounted) return;
    setState(
      () => _suggestions = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      ),
    );
  }

  /// The row's "Link": open the review with the customer search showing.
  Future<void> _link(Map<String, dynamic> review) async {
    await _openReview(review);
    if (!mounted || _open?['id'] != review['id']) return;
    await _findCustomers(_open!);
  }

  bool get _connected => _connections.any((c) => c['status'] == 'connected');

  /// The connected profile itself, so the tab can name it.
  Map<String, dynamic>? get _profile =>
      _connections.isEmpty ? null : _connections.first;

  /// Up and down move the open review through the inbox.
  KeyEventResult _onKey(FocusNode node, KeyEvent event) {
    if (event is! KeyDownEvent && event is! KeyRepeatEvent) {
      return KeyEventResult.ignored;
    }
    final step = event.logicalKey == LogicalKeyboardKey.arrowDown
        ? 1
        : event.logicalKey == LogicalKeyboardKey.arrowUp
        ? -1
        : 0;
    if (step == 0) return KeyEventResult.ignored;
    final rows = _reviews;
    if (rows.isEmpty) return KeyEventResult.handled;
    final at = rows.indexWhere((r) => r['id'] == _open?['id']);
    final next = at < 0 ? 0 : (at + step).clamp(0, rows.length - 1);
    if (next != at) _openReview(rows[next]);
    return KeyEventResult.handled;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, box) {
        final side = box.maxWidth >= ReviewsScreen.sideAbove;
        final open = _open;

        // Phone 4: a review, or the filters, open in place with a back arrow.
        if (!side && _moreOpen) return _morePanel(theme, inPlace: true);
        if (!side && open != null) return _panel(theme, open, inPlace: true);

        final body = AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: _reviews.isEmpty
              ? _empty(theme)
              : switch (_view) {
                  'board' => _boardView(theme),
                  'table' => _table(theme, box),
                  _ => _inbox(theme),
                },
        );

        final Widget? pane = !side
            ? null
            : _moreOpen
            ? _morePanel(theme, inPlace: false)
            : open != null
            ? _panel(theme, open, inPlace: false)
            : null;

        final Widget content;
        if (!side) {
          content = body;
        } else if (_view == 'inbox' && _reviews.isNotEmpty) {
          content = Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: ReviewsScreen.listWidth, child: body),
              const VerticalDivider(width: 1),
              Expanded(child: pane ?? _pickOne(theme)),
            ],
          );
        } else if (pane != null) {
          content = Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: body),
              const VerticalDivider(width: 1),
              SizedBox(width: 380, child: pane),
            ],
          );
        } else {
          content = body;
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (box.maxWidth >= 700) _summaryStrip(theme),
            if (_stats case final stats?)
              ReviewStatsBand(
                stats: stats,
                toDraft: _toDraft.length,
                drafting: _drafting,
                onDraftAll: _draftAll,
                onSendWindow: _openSendWindow,
              ),
            _toolbar(theme, box.maxWidth),
            if (_profile != null && box.maxWidth < 1200)
              ConnectedAccount(
                key: const ValueKey('tour-reviews-profile'),
                connection: _profile!,
              ),
            if (_notice != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(_notice!, style: theme.textTheme.bodySmall),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close, size: 16),
                      onPressed: () => setState(() => _notice = null),
                    ),
                  ],
                ),
              ),
            const Divider(height: 1),
            Expanded(child: content),
          ],
        );
      },
    );
  }

  Widget _pickOne(ThemeData theme) => Center(
    key: const ValueKey('review-pick-one'),
    child: Text(
      'Pick a review to read it and reply.',
      style: theme.textTheme.bodyMedium?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    ),
  );

  // --------------------------------------------------------- the summary

  Widget _summaryStrip(ThemeData theme) {
    final s = _summary;
    final partial = s != null && s.partial
        ? ' Only the newest ${s.counted} of the ${s.total} reviews since the '
              '1st of last month were read, so this is partial: the server '
              'returns at most 200 a page.'
        : '';
    String avg(double? v) => v == null ? '—' : v.toStringAsFixed(1);

    final average = s?.averageThis;
    final last = s?.averageLast;
    final String averageSub;
    if (last == null) {
      averageSub = 'nothing rated last month';
    } else if (average == null) {
      averageSub = '${avg(last)} last month';
    } else {
      final diff = average - last;
      averageSub =
          '${diff >= 0 ? '▲' : '▼'} ${diff.abs().toStringAsFixed(1)} vs '
          '${avg(last)} last month';
    }

    return Padding(
      key: const ValueKey('reviews-summary'),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _tile(
              theme,
              key: 'average',
              label: 'Average rating',
              value: average == null ? '—' : '${avg(average)} / 5',
              sub: averageSub,
              info:
                  'The average star rating of the reviews left since the 1st '
                  'of this month, against the reviews left last month. Every '
                  'place you can see; reviews Google sent no rating for are '
                  'left out.$partial',
              onTap: () => _refilter(() {
                _onlyUnanswered = false;
                _low = false;
                _days = _thisMonth;
              }),
            ),
            const SizedBox(width: 12),
            _tile(
              theme,
              key: 'new',
              label: 'New this month',
              value: s == null ? '—' : '${s.newThis}',
              sub: 'since the 1st',
              info:
                  'Reviews left since the 1st of this month, answered or not, '
                  'at every place you can see.$partial',
              onTap: () => _refilter(() {
                _onlyUnanswered = false;
                _low = false;
                _days = _thisMonth;
              }),
            ),
            const SizedBox(width: 12),
            _tile(
              theme,
              key: 'unanswered',
              label: 'Unanswered',
              value: '$_unanswered',
              sub: 'no reply on Google',
              info:
                  'Every review at the places you can see with no reply '
                  'posted on Google, of any age. A saved draft still counts '
                  'as unanswered. Counted by the server.',
              tone: _unanswered > 0 ? Tone.caution : null,
              onTap: () => _refilter(() {
                _onlyUnanswered = true;
                _low = false;
              }),
            ),
            const SizedBox(width: 12),
            _tile(
              theme,
              key: 'low',
              label: '1–2 stars to answer',
              value: s?.low == null ? '—' : '${s!.low}',
              sub: 'unanswered, 1 or 2 stars',
              info:
                  'Unanswered 1-star and 2-star reviews, of any age, at the '
                  'places you can see. Counted by the server.',
              tone: (s?.low ?? 0) > 0 ? Tone.bad : null,
              onTap: () => _refilter(() {
                _onlyUnanswered = true;
                _low = true;
                _rating = 'any';
              }),
            ),
          ],
        ),
      ),
    );
  }

  Widget _tile(
    ThemeData theme, {
    required String key,
    required String label,
    required String value,
    required String sub,
    required String info,
    required VoidCallback onTap,
    Tone? tone,
  }) {
    final ink = tone == null
        ? theme.colorScheme.onSurface
        : ToneColours.of(context, tone).ink;
    return Expanded(
      child: Material(
        color: theme.colorScheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(10),
          side: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          key: ValueKey('reviews-tile-$key'),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 10, 10, 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        label,
                        style: theme.textTheme.labelMedium?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Tooltip(
                      key: ValueKey('reviews-tile-$key-info'),
                      message: info,
                      child: Icon(
                        Icons.info_outline,
                        size: 16,
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  value,
                  key: ValueKey('reviews-tile-$key-value'),
                  style: theme.textTheme.headlineSmall?.copyWith(
                    color: ink,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                Text(
                  sub,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  // --------------------------------------------------------- the toolbar

  Widget _picker<T>({
    required Key key,
    required T value,
    required List<SearchOption<T>> options,
    required ValueChanged<T> onChanged,
    double width = 268,
    String? label,
  }) => SizedBox(
    width: width,
    child: SearchPickerField<T>(
      key: key,
      value: value,
      label: label,
      isDense: true,
      options: options,
      onChanged: (v) => _refilter(() => onChanged(v as T)),
    ),
  );

  Widget _toolbar(ThemeData theme, double width) {
    final more = _moreCount;
    final left = <Widget>[
      SizedBox(
        width: width < 600 ? width - 32 : 260,
        child: TextField(
          key: const Key('reviews-search'),
          controller: _search,
          onChanged: _searched,
          style: theme.textTheme.bodyMedium,
          decoration: InputDecoration(
            isDense: true,
            prefixIcon: const Icon(Icons.search, size: 18),
            hintText: 'Name or what they said',
            hintStyle: theme.textTheme.bodyMedium?.copyWith(
              color: theme.inputDecorationTheme.hintStyle?.color,
            ),
          ),
        ),
      ),
      FilterChip(
        key: const ValueKey('tour-customer_experience-unanswered'),
        label: Text(
          _unanswered > 0 ? 'Unanswered ($_unanswered)' : 'Unanswered',
        ),
        selected: _onlyUnanswered,
        onSelected: (v) => _refilter(() => _onlyUnanswered = v),
      ),
      FilterChip(
        key: const ValueKey('tour-customer_experience-rating'),
        label: const Text('1–2 stars'),
        selected: _low,
        onSelected: (v) => _refilter(() {
          _low = v;
          if (v) _rating = 'any';
        }),
      ),
      FilterChip(
        key: const ValueKey('reviews-this-week'),
        label: const Text('This week'),
        selected: _days == 7,
        onSelected: (v) => _refilter(() => _days = v ? 7 : null),
      ),
      OutlinedButton.icon(
        key: const ValueKey('reviews-more-filters'),
        icon: const Icon(Icons.tune, size: 18),
        label: Text(more > 0 ? 'More filters ($more)' : 'More filters'),
        onPressed: () => setState(() => _moreOpen = !_moreOpen),
      ),
    ];

    final right = <Widget>[
      if (_profile != null && width >= 1200)
        SizedBox(
          width: 230,
          child: ConnectedAccount(
            key: const ValueKey('tour-reviews-profile'),
            connection: _profile!,
          ),
        ),
      FilledButton.tonalIcon(
        key: const ValueKey('tour-customer_experience-fetch'),
        onPressed: _syncing ? null : _sync,
        icon: _syncing
            ? const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : const Icon(Icons.sync, size: 18),
        label: Text(_syncing ? 'Fetching…' : 'Fetch new'),
      ),
      SegmentedButton<String>(
        key: const ValueKey('reviews-view'),
        showSelectedIcon: false,
        segments: [
          ButtonSegment(
            value: 'inbox',
            icon: const Icon(Icons.inbox_outlined, size: 18),
            label: width < 600 ? null : const Text('Inbox'),
            tooltip: width < 600 ? 'Inbox' : null,
          ),
          ButtonSegment(
            value: 'table',
            icon: const Icon(Icons.table_rows_outlined, size: 18),
            label: width < 600 ? null : const Text('Table'),
            tooltip: width < 600 ? 'Table' : null,
          ),
          ButtonSegment(
            value: 'board',
            icon: const Icon(Icons.view_agenda_outlined, size: 18),
            label: width < 600 ? null : const Text('Board'),
            tooltip: width < 600 ? 'Board' : null,
          ),
        ],
        selected: {_view},
        onSelectionChanged: (v) => setState(() => _view = v.single),
      ),
    ];

    Widget wrap(List<Widget> children) => Wrap(
      spacing: 10,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: children,
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: width >= 1200
          ? Row(
              children: [
                Expanded(child: wrap(left)),
                const SizedBox(width: 12),
                wrap(right),
              ],
            )
          : wrap([...left, ...right]),
    );
  }

  /// Rating, time, sort, location, match and status: the filters the chips
  /// do not carry. Beside the list, or in place on a phone.
  Widget _morePanel(ThemeData theme, {required bool inPlace}) {
    return Material(
      key: const ValueKey('reviews-more-panel'),
      color: theme.colorScheme.surface,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          Row(
            children: [
              IconButton(
                key: const ValueKey('reviews-more-close'),
                tooltip: inPlace ? 'Back to the reviews' : 'Close',
                icon: Icon(inPlace ? Icons.arrow_back : Icons.close),
                onPressed: () => setState(() => _moreOpen = false),
              ),
              Expanded(
                child: Text('More filters', style: theme.textTheme.titleMedium),
              ),
              if (_moreCount > 0)
                TextButton(
                  key: const ValueKey('reviews-more-clear'),
                  onPressed: () => _refilter(() {
                    _rating = 'any';
                    if (_days != 7) _days = null;
                    _location = null;
                    _matched = 'any';
                    _status = null;
                  }),
                  child: const Text('Clear these'),
                ),
            ],
          ),
          const SizedBox(height: 8),
          _picker<String>(
            key: const ValueKey('reviews-rating'),
            label: 'Rating',
            value: _rating,
            options: [
              const SearchOption(value: 'any', label: 'Any rating'),
              const SearchOption(value: 'negative', label: 'Negative (1–3★)'),
              const SearchOption(value: 'positive', label: 'Positive (4–5★)'),
              for (var s = 5; s >= 1; s--)
                SearchOption(value: '$s', label: '$s star${s == 1 ? '' : 's'}'),
            ],
            onChanged: (v) {
              _rating = v;
              if (v != 'any') _low = false;
            },
          ),
          const SizedBox(height: 12),
          _picker<int?>(
            key: const ValueKey('reviews-time'),
            label: 'When',
            value: _days,
            options: const [
              SearchOption(value: null, label: 'All time'),
              SearchOption(value: 7, label: 'Last 7 days'),
              SearchOption(value: _thisMonth, label: 'This month'),
              SearchOption(value: 30, label: 'Last 30 days'),
              SearchOption(value: 91, label: 'Last 3 months'),
              SearchOption(value: 182, label: 'Last 6 months'),
              SearchOption(value: 365, label: 'Last 12 months'),
            ],
            onChanged: (v) => _days = v,
          ),
          const SizedBox(height: 12),
          _picker<String>(
            key: const ValueKey('reviews-sort'),
            label: 'Order',
            value: _sort,
            options: const [
              SearchOption(value: 'newest', label: 'Newest first'),
              SearchOption(value: 'oldest', label: 'Oldest first'),
              SearchOption(value: 'highest', label: 'Highest rated'),
              SearchOption(value: 'lowest', label: 'Lowest rated'),
            ],
            onChanged: (v) => _sort = v,
          ),
          if (_locations.length > 1) ...[
            const SizedBox(height: 12),
            _picker<String?>(
              key: const ValueKey('reviews-location'),
              label: 'Location',
              value: _location,
              options: [
                const SearchOption(value: null, label: 'All locations'),
                for (final l in _locations)
                  SearchOption(value: '${l['id']}', label: '${l['name']}'),
              ],
              onChanged: (v) => _location = v,
            ),
          ],
          const SizedBox(height: 12),
          _picker<String>(
            key: const ValueKey('reviews-matched'),
            label: 'Customer match',
            value: _matched,
            options: const [
              SearchOption(value: 'any', label: 'Any match'),
              SearchOption(value: 'yes', label: 'Matched'),
              SearchOption(value: 'no', label: 'Not matched'),
            ],
            onChanged: (v) => _matched = v,
          ),
          const SizedBox(height: 12),
          _picker<String?>(
            key: const ValueKey('reviews-status'),
            label: 'Status',
            value: _status,
            options: [
              const SearchOption(value: null, label: 'Any status'),
              for (final e in _statuses.entries)
                SearchOption(value: e.key, label: e.value.$1),
            ],
            onChanged: (v) => _status = v,
          ),
        ],
      ),
    );
  }

  /// Three different nothings, and they must not read the same.
  Widget _empty(ThemeData theme) {
    final String title;
    final String body;

    if (_connected && _q.isNotEmpty) {
      return Center(
        key: const ValueKey('reviews-search-empty'),
        child: Text(
          'No review matches "$_q".',
          style: theme.textTheme.bodyMedium,
          textAlign: TextAlign.center,
        ),
      );
    }
    if (!_connected) {
      title = 'No Business Profile connected';
      body =
          'Reviews come from a Google Business Profile this organization '
          'connects itself. Until one is connected there is nothing to show — '
          'this is not a business without reviews.';
    } else if (_onlyUnanswered) {
      title = 'Everything has been answered';
      body =
          'No review is waiting for a reply. Turn off the Unanswered filter '
          'to read the rest.';
    } else {
      title = 'No reviews yet';
      body =
          'Nothing has been left on the connected profile for these filters.';
    }

    return Center(
      key: const ValueKey('tour-reviews-empty'),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                _connected ? Icons.reviews_outlined : Icons.link_off,
                size: 40,
                color: theme.colorScheme.outline,
              ),
              const SizedBox(height: 12),
              Text(
                title,
                style: theme.textTheme.titleMedium,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(
                body,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                textAlign: TextAlign.center,
              ),
              // §4, same as Leads: do not draw a button whose only outcome
              // is a 403.
              if (!_connected && _can('reviews.edit')) ...[
                const SizedBox(height: 18),
                ConnectAccount(
                  api: widget.api,
                  label: 'Business Profile',
                  startPath: '/social/business-profile/start',
                  onConnected: _load,
                ),
              ] else if (!_connected) ...[
                const SizedBox(height: 16),
                Text(
                  'Somebody who can manage Reviews needs to connect the '
                  'profile.',
                  style: theme.textTheme.bodySmall,
                  textAlign: TextAlign.center,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  String _who(Map<String, dynamic> review) =>
      (review['reviewer_name'] as String?)?.trim().isNotEmpty == true
      ? review['reviewer_name'] as String
      : 'A Google user';

  /// "Company · Branch" where the review is a branch's, so twenty "Main"s do
  /// not read the same; the company alone, or the Google location's name.
  String _where(Map<String, dynamic> review) {
    final branch = (review['franchise'] as Map?)?['name'] as String?;
    final company = (review['company'] as Map?)?['name'] as String?;
    if (branch != null && company != null) return '$company · $branch';
    final location = review['location'] as String?;
    final named = _locations.where((l) => l['id'] == location);
    return branch ??
        company ??
        (named.isNotEmpty ? '${named.first['name']}' : null) ??
        '';
  }

  String _date(Map<String, dynamic> review) {
    final at = DateTime.tryParse(review['reviewed_at'] as String? ?? '');
    if (at == null) return '';
    final l = at.toLocal();
    return '${l.month}/${l.day}/${l.year}';
  }

  /// The inbox's short time: minutes, hours, days, then the date.
  String _ago(Map<String, dynamic> review) {
    final at = DateTime.tryParse(review['reviewed_at'] as String? ?? '');
    if (at == null) return '';
    final age = DateTime.now().difference(at);
    if (age.isNegative || age.inMinutes < 1) return 'now';
    if (age.inHours < 1) return '${age.inMinutes}m';
    if (age.inDays < 1) return '${age.inHours}h';
    if (age.inDays < 7) return '${age.inDays}d';
    return _date(review);
  }

  bool _published(Map<String, dynamic> review) =>
      (review['reply'] as Map?)?['status'] == 'published';

  int? _rating0(Map<String, dynamic> review) =>
      (review['rating'] as num?)?.toInt();

  /// An unanswered 1- or 2-star review: the one a stranger reads first.
  bool _needsReply(Map<String, dynamic> review) =>
      !_published(review) && (_rating0(review) ?? 9) <= 2;

  Widget _statusPill(Map<String, dynamic> review) {
    final s = _statuses[review['status']];
    return s == null ? const SizedBox.shrink() : StatusPill(s.$1, tone: s.$2);
  }

  /// "Matched to X" when the reviewer is a customer; nothing otherwise — an
  /// unmatched review is the ordinary case and saying so on every row was
  /// noise.
  Widget _matchLine(ThemeData theme, Map<String, dynamic> review) {
    final customer = review['customer'] as Map<String, dynamic>?;
    final job = review['job'] as Map<String, dynamic>?;
    if (customer == null) return const SizedBox.shrink();
    return Text(
      [
        'Matched to ${customer['name']}',
        if (job?['number'] != null) 'Job #${job!['number']}',
        if (job?['tech'] != null) 'Tech: ${job!['tech']}',
      ].join(' · '),
      style: theme.textTheme.bodySmall?.copyWith(
        color: ToneColours.of(context, Tone.ok).ink,
      ),
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
    );
  }

  Widget _avatar(ThemeData theme, Map<String, dynamic> review, double radius) {
    final name = _who(review);
    var hash = 0;
    for (final unit in name.codeUnits) {
      hash = (hash + unit) & 0x7fffffff;
    }
    const palette = EzBiziTheme.avatarPalette;
    return CircleAvatar(
      radius: radius,
      backgroundColor: palette[hash % palette.length],
      child: Text(
        name.characters.first.toUpperCase(),
        style: theme.textTheme.titleSmall?.copyWith(
          color: theme.colorScheme.onPrimary,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }

  Widget _place(ThemeData theme, String where) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
    decoration: BoxDecoration(
      color: theme.colorScheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(6),
    ),
    child: Text(
      where,
      style: theme.textTheme.labelSmall,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    ),
  );

  // ------------------------------------------------------------- the inbox

  Widget _inbox(ThemeData theme) {
    final rows = _reviews;
    return Focus(
      focusNode: _listFocus,
      onKeyEvent: _onKey,
      child: ListView.separated(
        key: const ValueKey('tour-customer_experience-reviews'),
        padding: const EdgeInsets.only(bottom: 24),
        itemCount: rows.length + 1,
        separatorBuilder: (_, _) => const Divider(height: 1),
        itemBuilder: (context, index) {
          if (index == rows.length) return _count(theme);
          return _inboxRow(theme, rows[index]);
        },
      ),
    );
  }

  Widget _inboxRow(ThemeData theme, Map<String, dynamic> review) {
    final id = review['id'];
    final selected = _open?['id'] == id;
    final small = theme.textTheme.bodySmall;
    final muted = small?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    final where = _where(review);
    final customer = review['customer'] as Map<String, dynamic>?;
    final comment = (review['comment'] as String?)?.trim() ?? '';

    return InkWell(
      key: ValueKey('review-row-$id'),
      onTap: () {
        _listFocus.requestFocus();
        _openReview(review);
      },
      child: Container(
        color: selected ? theme.colorScheme.primaryContainer : null,
        padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _avatar(theme, review, 18),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          _who(review),
                          style: theme.textTheme.titleSmall,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      if (!_published(review))
                        Padding(
                          padding: const EdgeInsets.only(left: 6, right: 6),
                          child: Tooltip(
                            message: 'No reply on Google yet',
                            child: Container(
                              key: ValueKey('review-unanswered-$id'),
                              width: 8,
                              height: 8,
                              decoration: BoxDecoration(
                                color: theme.colorScheme.error,
                                shape: BoxShape.circle,
                              ),
                            ),
                          ),
                        ),
                      Text(_ago(review), style: muted),
                    ],
                  ),
                  const SizedBox(height: 2),
                  Row(
                    children: [
                      _stars(theme, _rating0(review), size: 15),
                      if (_needsReply(review)) ...[
                        const SizedBox(width: 8),
                        StatusPill(
                          'needs a reply',
                          key: ValueKey('review-needs-reply-$id'),
                          tone: Tone.bad,
                        ),
                      ],
                    ],
                  ),
                  if (comment.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      comment,
                      style: small,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                  if (where.isNotEmpty ||
                      customer != null ||
                      _can('reviews.manage')) ...[
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        if (where.isNotEmpty)
                          Flexible(child: _place(theme, where)),
                        const SizedBox(width: 8),
                        if (customer != null)
                          Flexible(
                            child: Text(
                              'Matched to ${customer['name']}',
                              style: small?.copyWith(
                                color: ToneColours.of(context, Tone.ok).ink,
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          )
                        else if (_can('reviews.manage'))
                          InkWell(
                            key: ValueKey('review-link-$id'),
                            onTap: () => _link(review),
                            child: Text(
                              'Link',
                              style: small?.copyWith(
                                color: theme.colorScheme.primary,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------- the table

  Widget _table(ThemeData theme, BoxConstraints box) {
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w800,
    );
    final rows = _reviews;
    // A phone keeps the table, folded to who, stars and status.
    final narrow = box.maxWidth < 700;
    // A column empty on every row says nothing; it is not drawn.
    final hasText = rows.any(
      (r) => (r['comment'] as String?)?.trim().isNotEmpty == true,
    );
    final hasStatus = rows.any((r) => _statuses.containsKey(r['status']));
    final hasNotes = rows.any(
      (r) => ((r['note_count'] as num?)?.toInt() ?? 0) > 0,
    );

    Widget cell(int flex, Widget child) => Expanded(
      flex: flex,
      child: Padding(padding: const EdgeInsets.only(right: 10), child: child),
    );

    return ListView.separated(
      key: const ValueKey('tour-customer_experience-reviews'),
      padding: const EdgeInsets.only(bottom: 24),
      itemCount: rows.length + 2,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == 0) {
          return Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: Row(
              children: [
                cell(3, Text('CUSTOMER · LOCATION', style: head)),
                if (!narrow && hasText) cell(5, Text('REVIEW', style: head)),
                cell(2, Text('DATE', style: head)),
                cell(2, Text('RATING', style: head)),
                if (hasStatus) cell(2, Text('STATUS', style: head)),
                if (!narrow) cell(2, Text('REPLY', style: head)),
                if (!narrow && hasNotes) cell(1, Text('NOTES', style: head)),
              ],
            ),
          );
        }
        if (index == rows.length + 1) return _count(theme);

        final review = rows[index - 1];
        final reply = review['reply'] as Map<String, dynamic>?;
        final selected = _open?['id'] == review['id'];
        final small = theme.textTheme.bodySmall;

        return InkWell(
          key: ValueKey('review-row-${review['id']}'),
          onTap: () => _openReview(review),
          child: Container(
            color: selected ? theme.colorScheme.primaryContainer : null,
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                cell(
                  3,
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(_who(review), style: theme.textTheme.titleSmall),
                      if (_where(review).isNotEmpty)
                        Text(_where(review), style: small),
                      _matchLine(theme, review),
                    ],
                  ),
                ),
                if (!narrow && hasText)
                  cell(
                    5,
                    Text(
                      (review['comment'] as String?)?.trim() ?? '',
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: small,
                    ),
                  ),
                cell(2, Text(_date(review), style: small)),
                cell(
                  2,
                  // Five stars shrink rather than overflow a phone's column.
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: _stars(theme, _rating0(review), size: 18),
                  ),
                ),
                if (hasStatus)
                  cell(
                    2,
                    Align(
                      alignment: Alignment.centerLeft,
                      child: _statusPill(review),
                    ),
                  ),
                if (!narrow)
                  cell(
                    2,
                    Text(
                      _published(review)
                          ? 'Replied'
                          : reply != null
                          ? 'Draft'
                          : 'No reply',
                      style: small,
                    ),
                  ),
                if (!narrow && hasNotes)
                  cell(
                    1,
                    Text(
                      '${review['note_count'] ?? 0}' == '0'
                          ? '—'
                          : '${review['note_count']}',
                      style: small,
                    ),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _count(ThemeData theme) {
    final shown = _reviews.length;
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Text(
        _low
            ? '$shown ${shown == 1 ? 'review' : 'reviews'} with 1 or 2 stars'
            : '$_total ${_total == 1 ? 'review' : 'reviews'}',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  // ------------------------------------------------------------- the board

  Widget _boardView(ThemeData theme) {
    final rows = _reviews;
    return ListView.separated(
      key: const ValueKey('tour-customer_experience-reviews'),
      padding: const EdgeInsets.only(bottom: 24),
      itemCount: rows.length + 1,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == rows.length) return _count(theme);
        final review = rows[index];
        return InkWell(
          key: ValueKey('review-row-${review['id']}'),
          onTap: () => _openReview(review),
          child: Container(
            key: ValueKey('review-card-${review['id']}'),
            color: _open?['id'] == review['id']
                ? theme.colorScheme.primaryContainer
                : null,
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    _stars(theme, _rating0(review)),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        _who(review),
                        style: theme.textTheme.titleSmall,
                      ),
                    ),
                    _statusPill(review),
                    const SizedBox(width: 8),
                    if (_published(review))
                      Icon(
                        Icons.check_circle,
                        size: 16,
                        color: theme.colorScheme.primary,
                      ),
                  ],
                ),
                if ((review['comment'] as String?)?.trim().isNotEmpty ==
                    true) ...[
                  const SizedBox(height: 6),
                  Text(
                    review['comment'] as String,
                    style: theme.textTheme.bodyMedium,
                  ),
                ],
                const SizedBox(height: 4),
                Text(
                  [
                    _where(review),
                    _date(review),
                  ].where((s) => s.isNotEmpty).join(' · '),
                  style: theme.textTheme.bodySmall,
                ),
                _matchLine(theme, review),
              ],
            ),
          ),
        );
      },
    );
  }

  // ------------------------------------------------------------- the panel

  Widget _section(ThemeData theme, String label) => Padding(
    padding: const EdgeInsets.only(top: 16, bottom: 6),
    child: Text(
      label.toUpperCase(),
      style: theme.textTheme.labelSmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
        fontWeight: FontWeight.w800,
        letterSpacing: 0.6,
      ),
    ),
  );

  Widget _chip(ThemeData theme, {required Key key, required Widget child}) =>
      Container(
        key: key,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(
          border: Border.all(color: theme.colorScheme.outlineVariant),
          borderRadius: BorderRadius.circular(10),
        ),
        child: child,
      );

  Widget _panel(
    ThemeData theme,
    Map<String, dynamic> review, {
    required bool inPlace,
  }) {
    final manage = _can('reviews.manage');
    final customer = review['customer'] as Map<String, dynamic>?;
    final job = review['job'] as Map<String, dynamic>?;
    final tech = job?['tech'] as String?;
    final notes = (review['notes'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final small = theme.textTheme.bodySmall;
    final ok = ToneColours.of(context, Tone.ok).ink;

    return Material(
      key: const ValueKey('review-panel'),
      color: theme.colorScheme.surface,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          Row(
            children: [
              IconButton(
                key: const ValueKey('review-panel-close'),
                tooltip: inPlace ? 'Back to the reviews' : 'Close',
                icon: Icon(inPlace ? Icons.arrow_back : Icons.close),
                onPressed: () => setState(() => _open = null),
              ),
              const SizedBox(width: 4),
              _avatar(theme, review, 20),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(_who(review), style: theme.textTheme.titleMedium),
                    Wrap(
                      spacing: 8,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        _stars(theme, _rating0(review), size: 18),
                        Text(
                          [
                            _date(review),
                            _where(review),
                          ].where((s) => s.isNotEmpty).join(' · '),
                          style: small,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (review['was_edited'] == true)
            // A reply written against the original wording can read as a
            // non-sequitur once it is rewritten.
            Text('edited', style: small),
          if ((review['comment'] as String?)?.trim().isNotEmpty == true) ...[
            const SizedBox(height: 12),
            Text(review['comment'] as String, style: theme.textTheme.bodyLarge),
          ],
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              if (tech != null)
                _chip(
                  theme,
                  key: const ValueKey('review-tech'),
                  child: Text('Technician: $tech', style: small),
                ),
              if (customer != null)
                _chip(
                  theme,
                  key: const ValueKey('review-matched'),
                  child: Text(
                    [
                      'Matched to ${customer['name']}',
                      if (job?['number'] != null) 'Job #${job!['number']}',
                    ].join(' · '),
                    style: small?.copyWith(color: ok),
                  ),
                ),
              if (manage && _suggestions == null)
                ActionChip(
                  key: const ValueKey('review-change-match'),
                  avatar: const Icon(Icons.link, size: 16),
                  label: Text(customer == null ? 'Link a customer' : 'Change'),
                  onPressed: _busy ? null : () => _findCustomers(review),
                ),
            ],
          ),
          if (customer != null && '${customer['phone'] ?? ''}'.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text('${customer['phone']}', style: small),
            ),
          if (manage && _suggestions != null) ...[
            const SizedBox(height: 8),
            TextField(
              key: const ValueKey('review-match-search'),
              controller: _match,
              decoration: const InputDecoration(
                isDense: true,
                labelText: 'Search customers',
              ),
              onSubmitted: (_) => _findCustomers(review),
            ),
            if (_suggestions!.isEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text('No customer by that name.', style: small),
              ),
            for (final c in _suggestions!)
              ListTile(
                key: ValueKey('review-match-${c['id']}'),
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text('${c['name']}'),
                subtitle: Text(
                  [
                    c['phone'],
                    c['email'],
                  ].where((v) => v != null && '$v'.isNotEmpty).join(' · '),
                ),
                onTap: () {
                  setState(() => _suggestions = null);
                  _write(
                    widget.api.put('/reviews/${review['id']}/customer', {
                      'customer_id': c['id'],
                    }),
                  );
                },
              ),
            Row(
              children: [
                TextButton(
                  onPressed: () => setState(() => _suggestions = null),
                  child: const Text('Cancel'),
                ),
                if (customer != null)
                  TextButton(
                    key: const ValueKey('review-clear-match'),
                    onPressed: () {
                      setState(() => _suggestions = null);
                      _write(
                        widget.api.put('/reviews/${review['id']}/customer', {
                          'customer_id': null,
                        }),
                      );
                    },
                    child: const Text('Clear match'),
                  ),
              ],
            ),
          ],

          _section(theme, 'Reply'),
          _reply(theme, review),

          _section(theme, 'Status'),
          if (manage)
            Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                width: 220,
                child: SearchPickerField<String?>(
                  key: const ValueKey('review-status'),
                  value: review['status'] as String?,
                  isDense: true,
                  hint: 'No status',
                  options: [
                    const SearchOption(value: null, label: 'No status'),
                    for (final e in _statuses.entries)
                      SearchOption(value: e.key, label: e.value.$1),
                  ],
                  onChanged: (v) => _write(
                    widget.api.patch('/reviews/${review['id']}', {'status': v}),
                  ),
                ),
              ),
            )
          else
            Align(
              alignment: Alignment.centerLeft,
              child: _statuses.containsKey(review['status'])
                  ? _statusPill(review)
                  : Text('No status', style: small),
            ),

          _section(theme, 'Notes'),
          if (notes.isEmpty)
            Text('No notes yet.', style: small)
          else
            for (final n in notes)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Text(
                  '${_date({'reviewed_at': n['at']})}'
                  '${n['by'] == null ? '' : ' · ${n['by']}'}: ${n['text']}',
                  style: small,
                ),
              ),
          if (manage) ...[
            TextField(
              key: const ValueKey('review-note'),
              controller: _note,
              minLines: 1,
              maxLines: 4,
              decoration: const InputDecoration(
                isDense: true,
                labelText: 'Add a note',
              ),
              onChanged: (_) => setState(() {}),
            ),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                key: const ValueKey('review-note-add'),
                onPressed: _busy || _note.text.trim().isEmpty
                    ? null
                    : () {
                        final text = _note.text.trim();
                        _note.clear();
                        _write(
                          widget.api.post('/reviews/${review['id']}/notes', {
                            'text': text,
                          }),
                        );
                      },
                child: const Text('Add note'),
              ),
            ),
          ],

          if (review['reported_at'] != null) ...[
            _section(theme, 'Reported to Google'),
            Text(
              '${_date({'reviewed_at': review['reported_at']})}'
              '${review['report_reason'] == null ? '' : ': ${review['report_reason']}'}',
              style: small,
            ),
          ] else if (manage) ...[
            const SizedBox(height: 16),
            if (!_reporting)
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton.icon(
                  key: const ValueKey('review-report'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: theme.colorScheme.error,
                  ),
                  icon: const Icon(Icons.flag_outlined, size: 18),
                  label: const Text('Report to Google'),
                  onPressed: () => setState(() => _reporting = true),
                ),
              )
            else ...[
              // Google offers no way to flag a review through its API, so the
              // report is made in Google itself; this records that, and why.
              Text(
                'Report it in Google: open the review on your Business Profile '
                'and choose Report review. Then record it here.',
                style: small,
              ),
              const SizedBox(height: 6),
              TextField(
                key: const ValueKey('review-report-reason'),
                controller: _reason,
                decoration: const InputDecoration(
                  isDense: true,
                  labelText: 'Why (for example: not a customer)',
                ),
                onChanged: (_) => setState(() {}),
              ),
              Row(
                children: [
                  TextButton(
                    onPressed: () => setState(() => _reporting = false),
                    child: const Text('Cancel'),
                  ),
                  const Spacer(),
                  FilledButton(
                    key: const ValueKey('review-report-save'),
                    onPressed: _busy || _reason.text.trim().isEmpty
                        ? null
                        : () {
                            final reason = _reason.text.trim();
                            _reason.clear();
                            setState(() => _reporting = false);
                            _write(
                              widget.api.post(
                                '/reviews/${review['id']}/report',
                                {'reason': reason},
                              ),
                            );
                          },
                    child: const Text('Record the report'),
                  ),
                ],
              ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _reply(ThemeData theme, Map<String, dynamic> review) {
    final reply = review['reply'] as Map<String, dynamic>?;
    final published = reply?['status'] == 'published';
    final postedAt = DateTime.tryParse('${reply?['published_at'] ?? ''}');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (published)
          Container(
            key: const ValueKey('review-posted-reply'),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(10),
              border: Border(
                left: BorderSide(color: theme.colorScheme.primary, width: 3),
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  postedAt == null
                      ? 'Your published reply'
                      : 'Your published reply · '
                            '${_date({'reviewed_at': reply!['published_at']})}',
                  style: theme.textTheme.labelSmall,
                ),
                const SizedBox(height: 4),
                Text(
                  reply!['body'] as String? ?? '',
                  style: theme.textTheme.bodyMedium,
                ),
              ],
            ),
          ),
        if (reply != null && reply['failure_reason'] != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              reply['failure_reason'] as String,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        if (!published) ...[
          if (_aiDraft != null && _draft.text == _aiDraft)
            const Padding(
              padding: EdgeInsets.only(bottom: 6),
              child: Align(
                alignment: Alignment.centerLeft,
                child: AiMark.badge(
                  'Draft by AI',
                  tooltip:
                      'Written by AI from the review. Edit it before you post.',
                ),
              ),
            ),
          if (reply != null && reply['status'] != 'published')
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Text(
                'A draft is saved. Nobody outside sees it until you post it.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          TextField(
            key: const ValueKey('review-reply-box'),
            controller: _draft,
            onChanged: (_) => setState(() {}),
            minLines: 3,
            maxLines: 6,
            maxLength: 4096,
            decoration: const InputDecoration(
              border: OutlineInputBorder(),
              isDense: true,
              hintText: 'Write the reply this business will post…',
            ),
          ),
          Wrap(
            spacing: 4,
            runSpacing: 4,
            alignment: WrapAlignment.end,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              TextButton.icon(
                key: ValueKey('review-ai-draft-${review['id']}'),
                icon: const Icon(Icons.auto_awesome, size: 16),
                label: const Text('Draft with AI'),
                onPressed: _busy ? null : () => _suggest(review),
              ),
              TextButton(
                key: const ValueKey('review-save-draft'),
                onPressed: _busy || _draft.text.trim().isEmpty
                    ? null
                    : () => _saveDraft(review),
                child: const Text('Save draft'),
              ),
              FilledButton(
                key: const ValueKey('review-post'),
                onPressed: _busy || _draft.text.trim().isEmpty
                    ? null
                    : () => setState(() => _confirmingPublish = true),
                child: const Text('Post reply'),
              ),
            ],
          ),
          // The confirm is inline, not a dialog: the house rule.
          if (_confirmingPublish)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'This goes live on Google under your business name, where '
                    'anybody reading the review will see it. Google allows one '
                    'reply per review, so posting again replaces it.',
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      TextButton(
                        onPressed: _busy
                            ? null
                            : () => setState(() => _confirmingPublish = false),
                        child: const Text('Not yet'),
                      ),
                      const Spacer(),
                      FilledButton(
                        key: const ValueKey('review-post-confirm'),
                        onPressed: _busy ? null : () => _publish(review),
                        // Names the act rather than saying "Confirm".
                        child: const Text('Post it on Google'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
        ],
      ],
    );
  }

  /// Stars, and an honest blank when Google sent no rating. Amber, and red
  /// for one or two stars: those are the ones a stranger reads first.
  Widget _stars(ThemeData theme, int? rating, {double size = 15}) {
    if (rating == null) {
      return Text('no rating', style: theme.textTheme.bodySmall);
    }
    final fill = rating <= 2 ? theme.colorScheme.error : EzBiziTheme.reviewStar;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 1; i <= 5; i++)
          Icon(
            i <= rating ? Icons.star : Icons.star_border,
            size: size,
            color: i <= rating ? fill : theme.colorScheme.outlineVariant,
          ),
      ],
    );
  }
}
