import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/phone_numbers.dart';
import '../core/session.dart';
import '../widgets/ai_mark.dart';
import '../widgets/async_body.dart';
import '../widgets/search_picker.dart';

/// A1Leads GBP — a read-only window onto Rodrigo's A1 Leads, and only that.
///
/// ── What this is NOT ─────────────────────────────────────────────
///
/// It is not a container for EzBizi's Reviews and Business Profile. Those are
/// our own modules, they read our own tables, they live inside Marketing, and
/// they are what a Google reviewer is shown when our own Business Profile API
/// access arrives. This screen must never draw them.
///
/// Jose, 2026-09-23, after an earlier version of this file did exactly that:
/// *"we still need our system in place for when we get OUR GBP Credentials and
/// we'll need the google reviewers to see it. SO PUT IT BACK WHERE IT WAS, THE
/// A1LEADS GBP TAB IS A NEW ENTIRE THING."*
///
/// ── The A1 Tools tab this replaces, and the two things it cannot carry ──
///
/// The layout is A1 Tools' Customer Experience → Google Reviews tab: the same
/// six filters in the same order, the same triage line under every review, the
/// same reply bubble. Somebody who used that tab this morning should not have
/// to learn this one.
///
/// **Reply on Google** is here since 24 September, on Rodrigo's handoff. It
/// does not go through the daily snapshot this tab reads - that is read-only by
/// design - but to his LIVE A1 Leads, which stays the only Google writer, with
/// a reply-only key held by our server. Opening the editor reads that exact
/// review from Google first; the reply is sent against that revision and
/// refused if the reviewer has edited since. The button appears only when the
/// server says every condition holds (connection, company, the tab's own
/// permission - replying needs what reading needs), so
/// it is never a control whose only outcome is a refusal.
///
/// **Match manually / Report spam** stay in A1 Tools: writes nobody has
/// authorised here. The match A1 Leads computed is SHOWN, because that is a
/// read. This whole module is deleted the day EzBizi holds the Google
/// credential itself.
///
/// ── Why the date is the first thing on the screen ────────────────
///
/// The data is a snapshot of another system, installed once a day. A refresh
/// that stops running leaves yesterday's file in place and every request still
/// answers — so the tab would go on drawing a perfectly plausible list that is
/// a week old, with nothing to distinguish it from a quiet week. The capture
/// date is therefore part of the page rather than a detail behind a tooltip,
/// and when it is past the window the banner says so in words.
///
/// The other half of the same rule: an unreachable source is a SENTENCE, never
/// an empty list. An empty list here would say A-1 Chimney has no reviews, and
/// it has over thirteen thousand.
class A1LeadsGbpScreen extends StatefulWidget {
  const A1LeadsGbpScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<A1LeadsGbpScreen> createState() => _A1LeadsGbpScreenState();
}

class _A1LeadsGbpScreenState extends State<A1LeadsGbpScreen> {
  Map<String, dynamic> _source = const {};
  List<Map<String, dynamic>> _reviews = const [];
  List<Map<String, dynamic>> _locations = const [];

  /// Whether A1 Leads' board (Status, Notes, Contact) could be read, and the
  /// server's own words when it could not.
  Map<String, dynamic> _board = const {};
  int _total = 0;

  bool _loading = true;
  bool _loadingMore = false;
  String? _error;

  /// Which load is current. A filter changed twice quickly must not be
  /// overwritten by the slower of the two answers.
  int _request = 0;

  /// Opens on the negative reviews, exactly as the A1 Tools tab does.
  ///
  /// Not a default inherited by accident: nobody opens this to browse thirteen
  /// thousand five-star reviews. They open it because a one-star is sitting at
  /// the top of a Google listing being the first thing a stranger reads.
  String _rating = 'negative';
  String _replied = '';
  String _match = '';
  String _range = '';
  String _sort = 'newest';
  String? _location;

  /// Board or table, the way the A1 Tools bad-review board offers both.
  ///
  /// The table is the one somebody scans: nine columns of one line each, to
  /// find the four that need doing among the day's reviews. The board is the
  /// one somebody READS, because a review is prose and a column cuts it off
  /// mid-sentence. Neither replaces the other, which is why A1 Tools has both.
  ///
  /// The TABLE opens first - Jose, 2026-09-24: "table view should be the
  /// default". Under [_tableFrom] wide there is no table, so a phone still
  /// opens on the board.
  bool _asTable = true;

  /// The table's own order, set by clicking a column heading: the column and
  /// whether it runs ascending. Null follows the server's order, which is the
  /// Sort picker's - newest first by default. Jose, 2026-09-28: "clicking a
  /// column sorts by it, and clicking again reverses it". It sorts the page
  /// already loaded, as A1 Tools' table does.
  (String, bool)? _columnSort;

  /// The table scrolls sideways when the page is narrower than it.
  final ScrollController _tableScroll = ScrollController();

  /// The server's answer to "may this person reply, here, now".
  bool _replyAvailable = false;
  String? _replyBecause;

  /// Rodrigo's review-ops routes are connected: draft, card, match, report.
  bool _opsAvailable = false;
  bool _drafting = false;

  /// The reply Draft with AI wrote, marked as AI's while the editor still
  /// holds exactly it (UI queue 77).
  String? _aiReply;

  /// The review open in the right-hand panel, by its Google name. Jose,
  /// 2026-09-28: "clicking on any review should open up the right sidebar",
  /// as A1 Tools' does.
  String? _panelName;

  /// The review whose reply editor is open, by its Google name.
  String? _activeReplyName;

  /// That review as live Google has it, from the preflight.
  Map<String, dynamic>? _liveReview;

  final TextEditingController _replyDraft = TextEditingController();

  /// Replies posted from this screen, drawn at once rather than after
  /// tomorrow's snapshot - the server's receipts do the same across reloads.
  final Map<String, Map<String, dynamic>> _liveReplies = {};

  bool _checkingReply = false;
  bool _postingReply = false;

  /// The confirmation is armed: the next press posts. House rule, as on
  /// EzBizi's own Reviews screen - publishing under the business's name is
  /// stated before it happens, inline, and answered with a button that names
  /// the act.
  bool _confirmingReply = false;

  String? _replyError;
  String? _replyNotice;
  int _replyRequest = 0;

  @override
  void dispose() {
    _replyDraft.dispose();
    _tableScroll.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  /// The filters, as the API takes them.
  ///
  /// `from` is computed here rather than sent as `3m`, because the cutoff is a
  /// fact about the reader's clock and the snapshot's clock is a day behind it.
  Map<String, String> get _query {
    final from = _fromForRange(_range);

    return {
      if (_rating.isNotEmpty) 'rating': _rating,
      if (_replied.isNotEmpty) 'replied': _replied,
      if (_match == 'matched') 'matched': 'true',
      if (_match == 'unverified') 'matched': 'false',
      'from': ?from,
      'location': ?_location,
      'sort': _sort,
      'limit': '20',
    };
  }

  String? _fromForRange(String range) {
    final now = DateTime.now();

    final cut = switch (range) {
      'd7' => now.subtract(const Duration(days: 7)),
      'd30' => now.subtract(const Duration(days: 30)),
      '3m' => DateTime(now.year, now.month - 3, now.day),
      '6m' => DateTime(now.year, now.month - 6, now.day),
      '12m' => DateTime(now.year - 1, now.month, now.day),
      'ytd' => DateTime(now.year, 1, 1),
      _ => null,
    };

    return cut?.toUtc().toIso8601String();
  }

  /// The whole tab: source, cities and the first page.
  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/a1leads-gbp', _query);

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      final data = (response.body['data'] as Map?)?.cast<String, dynamic>() ?? const {};
      _source = (data['source'] as Map?)?.cast<String, dynamic>() ?? const {};
      _reviews = _rows(data['reviews']);
      _locations = _rows(data['locations']);
      _board = (data['board'] as Map?)?.cast<String, dynamic>() ?? const {};
      _replyAvailable = data['reply_available'] == true &&
          widget.session.me?.can('a1leads_gbp.reply') == true;
      _replyBecause = data['reply_unavailable_because'] as String?;
      _opsAvailable = data['ops_available'] == true;
      _total = ((data['meta'] as Map?)?['total'] as num?)?.toInt() ?? _reviews.length;
    });
  }

  /// A filter changed: the list only.
  ///
  /// Deliberately not `_load`. Re-asking the whole tab would spend four
  /// upstream reads on a service we were asked to keep under 120 a minute, to
  /// re-fetch a city list that cannot have changed since the dropdown was
  /// drawn from it.
  Future<void> _reload() async {
    final request = ++_request;

    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/a1leads-gbp/reviews', _query);

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      final data = (response.body['data'] as Map?)?.cast<String, dynamic>() ?? const {};
      _source = (data['source'] as Map?)?.cast<String, dynamic>() ?? _source;
      _board = (data['board'] as Map?)?.cast<String, dynamic>() ?? _board;
      _reviews = _rows(data['reviews']);
      _total = ((data['meta'] as Map?)?['total'] as num?)?.toInt() ?? _reviews.length;
    });
  }

  Future<void> _loadMore() async {
    setState(() => _loadingMore = true);

    final response = await widget.api.get('/a1leads-gbp/reviews', {
      ..._query,
      'offset': '${_reviews.length}',
    });

    if (!mounted) return;

    setState(() {
      _loadingMore = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      final data = (response.body['data'] as Map?)?.cast<String, dynamic>() ?? const {};
      _reviews = [..._reviews, ..._rows(data['reviews'])];
      _total = ((data['meta'] as Map?)?['total'] as num?)?.toInt() ?? _total;
    });
  }

  String _replyFailure(Map<String, dynamic> body, String fallback) {
    final error = body['error'];
    if (error is Map && error['message'] is String) return error['message'] as String;
    return body['message'] is String ? body['message'] as String : fallback;
  }

  Map<String, dynamic> _withLiveReply(Map<String, dynamic> review) {
    final live = _liveReplies['${review['google_review_name'] ?? ''}'];
    return live == null ? review : {...review, 'reply': live['text'], 'reply_at': live['sentAt']};
  }

  /// Open the editor, and read the review from LIVE Google before it may be
  /// used. The snapshot is a day old and is never proof the review is still
  /// unanswered or unchanged.
  Future<void> _openReply(Map<String, dynamic> review) async {
    if (!_replyAvailable) return;
    final name = '${review['google_review_name'] ?? ''}';
    if (name.isEmpty) return;

    final request = ++_replyRequest;
    setState(() {
      _activeReplyName = name;
      _checkingReply = true;
      _confirmingReply = false;
      _liveReview = null;
      _replyError = null;
      _replyNotice = null;
      _replyDraft.clear();
      // The view stays where it is: the table opens the editor under the row
      // (Jose, 2026-09-24: switching to the board on Reply "is not good UI").
    });

    final response = await widget.api.get('/a1leads-gbp/reply/preflight', {
      'googleReviewName': name,
    });
    if (!mounted || request != _replyRequest) return;

    setState(() {
      _checkingReply = false;
      if (!response.ok) {
        _replyError = _replyFailure(response.body, response.message);
        return;
      }
      _liveReview = (response.body['data'] as Map?)?.cast<String, dynamic>();
      if (_liveReview == null) {
        _replyError = 'Could not verify this review. Check it again before replying.';
      } else if (_liveReview!['canReply'] != true) {
        _replyError = _liveReview!['replyStatus'] == 'sent'
            ? 'This review already has a public reply.'
            : 'This review changed or is unavailable. Refresh before replying.';
        final sent = '${_liveReview!['replyText'] ?? ''}'.trim();
        if (sent.isNotEmpty) {
          _liveReplies[name] = {'text': sent, 'sentAt': null};
        }
      }
    });
  }

  Future<void> _publishReply() async {
    final live = _liveReview;
    final name = _activeReplyName;
    final text = _replyDraft.text.trim();
    final revision = '${live?['reviewUpdatedAt'] ?? ''}';
    if (!_replyAvailable || _postingReply || live?['canReply'] != true ||
        name == null || text.isEmpty || revision.isEmpty) {
      return;
    }

    setState(() {
      _postingReply = true;
      _confirmingReply = false;
      _replyError = null;
      _replyNotice = null;
    });

    final response = await widget.api.post('/a1leads-gbp/reply', {
      'googleReviewName': name,
      'text': text,
      'expectedReviewUpdatedAt': revision,
    });
    if (!mounted || name != _activeReplyName) return;

    setState(() {
      _postingReply = false;
      if (!response.ok) {
        // A failed or uncertain send must be checked live again, never retried
        // blind: a dropped connection may still have posted.
        _liveReview = null;
        _replyError = '${_replyFailure(response.body, response.message)} '
            'Check the live status before trying again.';
        return;
      }
      final data = (response.body['data'] as Map?)?.cast<String, dynamic>() ?? const {};
      _liveReplies[name] = {'text': data['text'] ?? text, 'sentAt': data['sentAt']};
      _liveReview = {...live!, 'canReply': false, 'replyStatus': 'sent', 'replyText': text};
      _replyNotice = data['receiptPersisted'] == false
          ? 'Posted on Google, but this view could not save the receipt. '
              'Check live status before any further action.'
          : 'Public reply posted on Google. The daily snapshot will catch up.';
    });
  }

  List<Map<String, dynamic>> _rows(Object? value) => List<Map<String, dynamic>>.from(
        (value as List? ?? const []).whereType<Map>().map((m) => m.cast<String, dynamic>()),
      );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // The source is unusable: say which kind of unusable, and never fall
    // through to a list. `because` carries the server's own words.
    if (!_loading &&
        _error == null &&
        _source.isNotEmpty &&
        (_source['configured'] != true || _source['reachable'] != true)) {
      return _sentence(
        theme,
        icon: Icons.link_off,
        title: _source['configured'] == true
            ? 'A1 Leads is not answering'
            : 'A1 Leads is not connected here',
        body:
            '${_source['because'] ?? 'The connection to A1 Leads is not available.'}\n\n'
            'Nothing is missing and nothing is broken here — there is simply '
            'nothing to show until the connection is in place. EzBizi\'s own '
            'Reviews and Business Profile are in Customer Experience, unchanged.',
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_source.isNotEmpty) _sourceBand(theme),
        _filters(theme),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            children: [
              Positioned.fill(
                child: AsyncBody(
                  loading: _loading,
                  error: _error,
                  onRetry: _load,
                  child: _reviews.isEmpty
                      ? _empty(theme)
                      : (_asTable && MediaQuery.sizeOf(context).width >= _tableFrom
                          ? _table(theme)
                          : _list(theme)),
                ),
              ),
              if (_panelReview != null) ...[
                // The list dims behind the panel; a tap on it closes the panel.
                Positioned.fill(
                  child: GestureDetector(
                    key: const ValueKey('gbp-panel-scrim'),
                    onTap: () => setState(() => _panelName = null),
                    child: ColoredBox(color: Colors.black.withValues(alpha: 0.35)),
                  ),
                ),
                Positioned(
                  top: 0,
                  right: 0,
                  bottom: 0,
                  width: MediaQuery.sizeOf(context).width < 520 ? MediaQuery.sizeOf(context).width : 460,
                  child: _panel(theme, _panelReview!),
                ),
              ],
            ],
          ),
        ),
        if (_asTable && MediaQuery.sizeOf(context).width >= _tableFrom)
          _tableNote(theme),
      ],
    );
  }

  /// What the table cannot show, and why — said once, at the bottom.
  ///
  /// Three different sentences, because they are three different facts. The
  /// board being unreadable is a fault somebody can fix. Contact being empty is
  /// a decision on A1 Leads' side: its feed for EzBizi carries only a saved
  /// customer match, never the name-guessing A1 Tools does, and on 24 September
  /// that left the phone and email empty on all 387 cards. And when there is
  /// no Reply button, the server's own reason: replying not switched on is a
  /// deployment waiting on its key, A-1 Chimney not in view is something the
  /// reader can change. No reason, no sentence - never a guessed one.
  Widget _tableNote(ThemeData theme) {
    final lines = [
      if (_board['available'] == false)
        'Status and Notes could not be read from A1 Leads: ${_board['because'] ?? 'no reason given'}.',
      if (_board['available'] == true && !_anyContact)
        'Contact is empty because A1 Leads does not send customer phone or email to EzBizi yet.',
      if (!_replyAvailable && _replyBecause == 'not_switched_on')
        'Replying from EzBizi is not switched on yet.',
      if (!_replyAvailable && _replyBecause == 'no_permission')
        'Replying needs the A1Leads GBP Reply permission.',
      if (!_replyAvailable && _replyBecause == 'company_not_in_view')
        'Replying here needs A-1 Chimney as the company in view.',
    ];

    // Nothing to say is no band, not an info icon beside a blank.
    if (lines.isEmpty) return const SizedBox.shrink();

    return Material(
      color: theme.colorScheme.surfaceContainerHighest,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.info_outline, size: 16, color: theme.colorScheme.onSurfaceVariant),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                lines.join(' '),
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Whether any card on this page carries a way to reach the customer.
  ///
  /// When none does, the note below the table says why: the feed does not
  /// carry it, which is not the same as "these customers have no phone".
  bool get _anyContact => _reviews.any((r) => _contactLines(r).isNotEmpty);

  /// The review's customer contact, as A1 Tools' Contact column shows it.
  ///
  /// The card's own contact first; its phone is empty on every card A1 Leads
  /// sends EzBizi (24 September: 0 of 387), so the phone of the customer A1
  /// Leads MATCHED stands in - the same customer the panel names. The matched
  /// customer carries no email.
  ///
  /// A US phone reads (513) 390-5722 (Rodrigo, 6 October: "make the contacts
  /// ... friendlier").
  static List<String> _contactLines(Map<String, dynamic> review) {
    final card = (review['board'] as Map?)?.cast<String, dynamic>();
    final contact = (card?['contact'] as Map?) ?? const {};
    final customer = _customerOf(review) ?? const {};

    return <String>{
      for (final value in [
        ...((customer['phones'] as List?) ?? const []),
        contact['phone'],
        customer['phone'],
        customer['email'],
        contact['email'],
      ])
        if (value is String && value.trim().isNotEmpty) gbpContactText(value.trim()),
    }.toList();
  }

  /// The customer A1 Leads matched to this review.
  ///
  /// The live copy sends it on the review itself, card or no card (Rodrigo,
  /// 2 October: most matched reviews have no card). The day-old snapshot only
  /// has it on the card, so that is the fallback.
  static Map<String, dynamic>? _customerOf(Map<String, dynamic> review) =>
      (review['customer'] as Map?)?.cast<String, dynamic>() ??
      ((review['board'] as Map?)?['customer'] as Map?)?.cast<String, dynamic>();

  /// The band that makes the age of the data impossible to miss.
  Widget _sourceBand(ThemeData theme) {
    final stale = _source['stale'] == true;
    final colour =
        stale ? theme.colorScheme.errorContainer : theme.colorScheme.surfaceContainerHighest;
    final onColour =
        stale ? theme.colorScheme.onErrorContainer : theme.colorScheme.onSurfaceVariant;

    return Material(
      color: colour,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Row(
          children: [
            Icon(stale ? Icons.warning_amber : Icons.history, size: 18, color: onColour),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                stale
                    ? 'This data is from ${_short(_source['captured_at'])} and has not '
                        'refreshed since — ${_source['hours_old']} hours old. Treat it as '
                        'historical rather than current.'
                    : _source['live'] == true
                        // The live copy, in step with A1 Leads every minute.
                        ? 'Live from A1 Leads, as of ${_short(_source['captured_at'])}.'
                        : 'Read from A1 Leads. Snapshot captured ${_short(_source['captured_at'])}, '
                            'installed ${_short(_source['installed_at'])}.',
                style: theme.textTheme.bodySmall?.copyWith(color: onColour),
              ),
            ),
            IconButton(
              tooltip: 'Check again',
              icon: const Icon(Icons.refresh, size: 18),
              color: onColour,
              onPressed: _load,
            ),
          ],
        ),
      ),
    );
  }

  /// The same six filters as the A1 Tools tab, in the same order.
  Widget _filters(ThemeData theme) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: Wrap(
        spacing: 12,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          _picker<String>(
            width: 180,
            value: _rating,
            hint: 'Any rating',
            options: const [
              SearchOption(value: '', label: 'Any rating'),
              SearchOption(value: 'positive', label: 'Positive (4–5★)'),
              SearchOption(value: 'negative', label: 'Negative (1–3★)'),
              SearchOption(value: '5', label: '5★'),
              SearchOption(value: '4', label: '4★'),
              SearchOption(value: '3', label: '3★'),
              SearchOption(value: '2', label: '2★'),
              SearchOption(value: '1', label: '1★'),
            ],
            onChanged: (v) => setState(() => _rating = v ?? ''),
          ),
          _picker<String>(
            width: 180,
            value: _replied,
            hint: 'Any reply status',
            options: const [
              SearchOption(value: '', label: 'Any reply status'),
              SearchOption(value: 'false', label: 'Not replied'),
              SearchOption(value: 'true', label: 'Replied'),
            ],
            onChanged: (v) => setState(() => _replied = v ?? ''),
          ),
          _picker<String>(
            width: 170,
            value: _range,
            hint: 'All time',
            options: const [
              SearchOption(value: '', label: 'All time'),
              SearchOption(value: 'd7', label: 'Last 7 days'),
              SearchOption(value: 'd30', label: 'Last 30 days'),
              SearchOption(value: '3m', label: 'Last 3 months'),
              SearchOption(value: '6m', label: 'Last 6 months'),
              SearchOption(value: '12m', label: 'Last 12 months'),
              SearchOption(value: 'ytd', label: 'This year'),
            ],
            onChanged: (v) => setState(() => _range = v ?? ''),
          ),
          _picker<String>(
            width: 160,
            value: _sort,
            hint: 'Newest first',
            options: const [
              SearchOption(value: 'newest', label: 'Newest first'),
              SearchOption(value: 'oldest', label: 'Oldest first'),
              SearchOption(value: 'highest', label: 'Highest rated'),
              SearchOption(value: 'lowest', label: 'Lowest rated'),
            ],
            // Choosing an order here hands the table back to it.
            onChanged: (v) => setState(() {
              _sort = v ?? 'newest';
              _columnSort = null;
            }),
          ),
          // Drawn only when the source could name the listings. A dropdown
          // holding nothing but "All locations" is a control that cannot do
          // anything, and this list comes from another service.
          //
          // One entry per LISTING, filtered on its exact GBP id. Until 24
          // September the only route we held carried no id and this was a
          // city list, which merged two listings in one city; Rodrigo's release
          // c7ac999b added the id to every row.
          if (_locations.isNotEmpty)
            _picker<String>(
              width: 240,
              value: _location ?? '',
              hint: 'All locations',
              options: [
                const SearchOption(value: '', label: 'All locations'),
                for (final l in _locations)
                  SearchOption(value: '${l['name']}', label: '${l['label']}'),
              ],
              onChanged: (v) => setState(() => _location = (v ?? '').isEmpty ? null : v),
            ),
          _picker<String>(
            width: 190,
            value: _match,
            hint: 'Any match',
            options: const [
              SearchOption(value: '', label: 'Any match'),
              SearchOption(value: 'matched', label: 'Matched to customer'),
              SearchOption(value: 'unverified', label: 'Unverified (no match)'),
            ],
            onChanged: (v) => setState(() => _match = v ?? ''),
          ),
          // Only offered where a table can actually be read. Below this width
          // the columns collapse into something narrower than the board it
          // replaced, so the control is absent rather than present and bad.
          if (MediaQuery.sizeOf(context).width >= _tableFrom)
            SegmentedButton<bool>(
              showSelectedIcon: false,
              style: const ButtonStyle(visualDensity: VisualDensity.compact),
              segments: const [
                ButtonSegment(value: false, icon: Icon(Icons.view_agenda_outlined), label: Text('Board')),
                ButtonSegment(value: true, icon: Icon(Icons.table_rows_outlined), label: Text('Table')),
              ],
              selected: {_asTable},
              onSelectionChanged: (v) => setState(() => _asTable = v.first),
            ),
        ],
      ),
    );
  }

  /// Below this the table is narrower than the board it replaces.
  static const double _tableFrom = 900;

  Widget _picker<T>({
    required double width,
    required T? value,
    required String hint,
    required List<SearchOption<T>> options,
    required ValueChanged<T?> onChanged,
  }) {
    return SizedBox(
      width: width,
      child: SearchPickerField<T>(
        value: value,
        hint: hint,
        isDense: true,
        options: options,
        onChanged: (v) {
          onChanged(v);
          unawaited(_reload());
        },
      ),
    );
  }

  /// The table: A1 Tools' bad-review board columns, as far as they can be
  /// filled.
  ///
  /// Status and Notes come from A1 Leads' board feed, joined to each review by
  /// its Google id; a review with no card - most of the thirteen thousand -
  /// shows a dash rather than a blank, because "not on the board" and "on the
  /// board with nothing written" are different things.
  Widget _table(ThemeData theme) {
    final more = _reviews.length < _total;
    final rows = _tableOrder();
    final heading = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w700,
      letterSpacing: 0.4,
    );

    // Narrower than the table - half a desktop screen beside the sidebar -
    // it scrolls sideways rather than squeezing every column into a sliver
    // (Jose, 2026-09-28: at 960 the status read "Awaitin…").
    return LayoutBuilder(
      builder: (context, constraints) => Scrollbar(
        controller: _tableScroll,
        thumbVisibility: constraints.maxWidth < _tableMinWidth,
        child: SingleChildScrollView(
          controller: _tableScroll,
          scrollDirection: Axis.horizontal,
          child: SizedBox(
            width: constraints.maxWidth < _tableMinWidth ? _tableMinWidth : constraints.maxWidth,
            height: constraints.maxHeight,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Material(
                  color: theme.colorScheme.surfaceContainerHighest,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    child: Row(
                      children: [
                        // Two facts share these two columns, so each heading
                        // sorts by either.
                        _column('customer', Wrap(spacing: 8, children: [
                          _sortHeading(heading, 'CUSTOMER', 'name'),
                          _sortHeading(heading, 'LOCATION', 'location'),
                        ])),
                        _column('review', Text('REVIEW', style: heading)),
                        _column('contact', Text('CONTACT', style: heading)),
                        _column('date', _sortHeading(heading, 'DATE', 'date')),
                        _column('rating', _sortHeading(heading, 'RATING', 'rating')),
                        _column('status', Wrap(spacing: 8, children: [
                          _sortHeading(heading, 'STATUS', 'status'),
                          _sortHeading(heading, 'REPLY', 'reply'),
                        ])),
                        _column('notes', Text('NOTES', style: heading)),
                      ],
                    ),
                  ),
                ),
                const Divider(height: 1),
                Expanded(
                  child: ListView.separated(
                    padding: const EdgeInsets.only(bottom: 24),
                    itemCount: rows.length + 1,
                    separatorBuilder: (_, _) => const Divider(height: 1),
                    itemBuilder: (context, index) {
                      if (index == rows.length) {
                        return Padding(
                          padding: const EdgeInsets.all(16),
                          child: Center(child: _footer(theme, more)),
                        );
                      }

                      return _tableRow(theme, rows[index]);
                    },
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// The narrowest the table is drawn; below it, it scrolls sideways.
  ///
  /// 1140 keeps the prose columns readable (a name, three lines of review)
  /// beside the fixed ones, and is still under the page at a 1400-wide window
  /// beside the default sidebar, so a full-width window never scrolls.
  static const double _tableMinWidth = 1140;

  /// Columns with a width of their own: what they hold is short and must be
  /// whole - a phone number, a date, a status - so they are never shared out.
  static const Map<String, double> _fixedColumns = {
    'contact': 140,
    'date': 88,
    'rating': 56,
    'status': 140,
  };

  /// The prose columns share what is left.
  static const Map<String, int> _flexColumns = {'customer': 20, 'review': 38, 'notes': 20};

  /// One table column, the same in the heading and in every row.
  Widget _column(String column, Widget child) {
    final padded = column == 'notes'
        ? child
        : Padding(padding: const EdgeInsets.only(right: 12), child: child);
    final fixed = _fixedColumns[column];

    return fixed == null
        ? Expanded(flex: _flexColumns[column]!, child: padded)
        : SizedBox(width: fixed + 12, child: padded);
  }

  /// The order the table is in: the clicked column, or the Sort picker's.
  (String, bool) get _order =>
      _columnSort ??
      switch (_sort) {
        'oldest' => ('date', true),
        'highest' => ('rating', false),
        'lowest' => ('rating', true),
        _ => ('date', false),
      };

  /// A heading that sorts by [key]; a second click on it reverses the order.
  Widget _sortHeading(TextStyle? style, String label, String key) {
    final (current, ascending) = _order;
    final active = current == key;

    return InkWell(
      key: ValueKey('gbp-sort-$key'),
      onTap: () => setState(() {
        // A first click puts the newest, the highest-rated, and otherwise A
        // to Z - for Reply, the unanswered - on top.
        _columnSort = active ? (key, !ascending) : (key, key != 'date' && key != 'rating');
      }),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Flexible(
          child: Text(label,
              style: active ? style?.copyWith(color: Theme.of(context).colorScheme.primary) : style,
              overflow: TextOverflow.ellipsis),
        ),
        if (active)
          Icon(ascending ? Icons.arrow_upward : Icons.arrow_downward,
              size: 12, color: Theme.of(context).colorScheme.primary),
      ]),
    );
  }

  /// The loaded page in the table's order.
  ///
  /// Unsorted, it is the server's own order. Sorted, a review with nothing to
  /// sort by - no status, no place - goes last whichever way round, and ties
  /// keep the order they had.
  List<Map<String, dynamic>> _tableOrder() {
    final sort = _columnSort;
    if (sort == null) return _reviews;
    final (key, ascending) = sort;

    Comparable<Object>? value(Map<String, dynamic> review) {
      String? text(Object? v) {
        final s = '${v ?? ''}'.trim().toLowerCase();
        return s.isEmpty ? null : s;
      }

      return switch (key) {
        'name' => text(review['reviewer_name']),
        'location' => text(_placeOf(review)),
        'date' => DateTime.tryParse('${review['review_created_at'] ?? ''}'),
        'rating' => review['rating'] as num?,
        'status' => text((review['board'] as Map?)?['status']),
        _ => '${_withLiveReply(review)['reply'] ?? ''}'.trim().isEmpty ? 0 : 1,
      } as Comparable<Object>?;
    }

    final indexed = [
      for (var i = 0; i < _reviews.length; i++) (i, value(_reviews[i]), _reviews[i]),
    ];
    indexed.sort((a, b) {
      final (ai, av, _) = a;
      final (bi, bv, _) = b;
      if (av == null || bv == null) {
        return av == bv ? ai.compareTo(bi) : (av == null ? 1 : -1);
      }
      final order = ascending ? av.compareTo(bv) : bv.compareTo(av);
      return order != 0 ? order : ai.compareTo(bi);
    });

    return [for (final (_, _, review) in indexed) review];
  }

  Widget _tableRow(ThemeData theme, Map<String, dynamic> review) {
    review = _withLiveReply(review);
    final card = (review['board'] as Map?)?.cast<String, dynamic>();
    final contact = _contactLines(review);
    final name = '${review['reviewer_name'] ?? ''}'.trim();
    final comment = '${review['comment'] ?? ''}'.trim();
    final reply = '${review['reply'] ?? ''}'.trim();
    final matchedCustomer = _customerOf(review);
    final matched = matchedCustomer != null ||
        review['match_tier'] == 'high' ||
        review['match_tier'] == 'medium';
    final customer = '${matchedCustomer?['name'] ?? review['match_customer_name'] ?? ''}'.trim();
    final quiet = theme.textTheme.bodySmall
        ?.copyWith(color: theme.colorScheme.onSurfaceVariant);

    return InkWell(
      key: ValueKey('gbp-row-${review['google_review_name']}'),
      onTap: () => setState(() => _panelName = '${review['google_review_name']}'),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _tableCells(theme, review, card, contact, name, comment, reply, matched, customer, quiet),
            // Replying opens here, under its own row, rather than moving the
            // reader to the board - unless the panel holds it.
            if (_activeReplyName == review['google_review_name'] && _panelName != _activeReplyName)
              _replyEditor(theme),
          ],
        ),
      ),
    );
  }

  Widget _tableCells(
    ThemeData theme,
    Map<String, dynamic> review,
    Map<String, dynamic>? card,
    List<String> contact,
    String name,
    String comment,
    String reply,
    bool matched,
    String customer,
    TextStyle? quiet,
  ) {
    return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _column(
            'customer',
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name.isEmpty ? 'A Google user' : name,
                  style: theme.textTheme.titleSmall,
                  overflow: TextOverflow.ellipsis,
                ),
                if (matched && customer.isNotEmpty)
                  Text(
                    'Matched: $customer',
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: theme.colorScheme.tertiary),
                    overflow: TextOverflow.ellipsis,
                  ),
                if (_placeOf(review).isNotEmpty)
                  Text(_placeOf(review), style: quiet, overflow: TextOverflow.ellipsis),
                if (_jobLine(review, card).isNotEmpty)
                  Text(_jobLine(review, card), style: quiet, overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          _column(
            'review',
            Text(
              comment.isEmpty ? 'No comment' : comment,
              // Three lines, like A1 Tools' board. A column that shows one
              // line of a five-line complaint is a column nobody can triage
              // from; one that shows all five is not a table any more.
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
          ),
          _column(
            'contact',
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final line in contact)
                  Text(line, style: theme.textTheme.bodySmall, overflow: TextOverflow.ellipsis),
                if (contact.isEmpty) Text('—', style: quiet),
              ],
            ),
          ),
          _column('date', Text(_short(review['review_created_at']), style: quiet)),
          // A compact "★ 2" rather than five stars: the table's rating column
          // is narrower than five 15px icons at the widths it is offered at,
          // and A1 Tools' board draws it the same way.
          _column('rating', _ratingChip(theme, (review['rating'] as num?)?.toInt())),
          _column(
            'status',
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                card == null || card['status'] == null
                    ? Text('—', style: quiet)
                    : _canChangeStatus(review, card)
                        // Tap to change, as in A1 Tools (Jose, 2026-09-28).
                        ? InkWell(
                            key: ValueKey('gbp-status-pill-${review['google_review_name']}'),
                            borderRadius: BorderRadius.circular(12),
                            onTap: () => unawaited(_pickStatus(review, card)),
                            child: Row(mainAxisSize: MainAxisSize.min, children: [
                              Flexible(child: _statusPill(theme, '${card['status']}')),
                              Icon(Icons.arrow_drop_down, size: 16, color: theme.colorScheme.onSurfaceVariant),
                            ]),
                          )
                        : _statusPill(theme, '${card['status']}'),
                const SizedBox(height: 4),
                // The reply state stays visible beside the board status: it is
                // Google's fact, where the status is A-1's process.
                if (reply.isNotEmpty)
                  Text('Replied', style: theme.textTheme.labelSmall)
                else if (_replyAvailable && review['deleted_at'] == null)
                  TextButton(
                    style: TextButton.styleFrom(
                      padding: EdgeInsets.zero,
                      visualDensity: VisualDensity.compact,
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    onPressed: () => unawaited(_openReply(review)),
                    child: const Text('Reply'),
                  )
                else
                  Text('No reply',
                      style: theme.textTheme.labelSmall
                          ?.copyWith(color: theme.colorScheme.error)),
              ],
            ),
          ),
          _column(
            'notes',
            Text(
              '${card?['note'] ?? '—'}',
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: card?['note'] == null ? quiet : theme.textTheme.bodySmall,
            ),
          ),
        ],
    );
  }

  /// A-1's board status and note, in the board view, when the review has a
  /// card. Nothing at all when it has none - most reviews are not on the board.
  Widget _boardLine(ThemeData theme, Map<String, dynamic> review) {
    final card = (review['board'] as Map?)?.cast<String, dynamic>();

    if (card == null || (card['status'] == null && card['note'] == null)) {
      return const SizedBox.shrink();
    }

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (card['status'] != null) _statusPill(theme, '${card['status']}'),
          if (card['note'] != null) ...[
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                '${card['note']}',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// A1 Leads' draft for the review being replied to, into the editor.
  ///
  /// Rodrigo, 25 September: "Never send a draft just because it was
  /// generated." It lands in the text box to be read and edited, and goes
  /// out only through the same Post and confirm as a reply somebody typed.
  Future<void> _draftWithAi() async {
    final review = _reviews.firstWhere(
      (r) => r['google_review_name'] == _activeReplyName,
      orElse: () => const {},
    );
    final id = review['id'];
    if (id == null) return;

    setState(() {
      _drafting = true;
      _replyError = null;
    });
    final response = await widget.api.post('/a1leads-gbp/reviews/$id/draft', const {});
    if (!mounted) return;

    final data = (response.body['data'] as Map?)?.cast<String, dynamic>() ?? const {};
    final text = (data['text'] ?? data['draft'] ?? data['reply'])?.toString().trim() ?? '';
    setState(() {
      _drafting = false;
      if (!response.ok || text.isEmpty) {
        _replyError = response.ok ? 'A1 Leads returned no draft.' : response.message;
        return;
      }
      _replyDraft.text = text;
      _aiReply = text;
      _confirmingReply = false;
    });
  }

  /// The operations A1 Tools offered on a review, now here: its card status,
  /// a note on its timeline, its Workiz match, and a report to Google.
  ///
  /// Only with the manage permission and the routes connected, and never on a
  /// review Google has removed. Each change goes to A1 Leads, which owns the
  /// card, and the page reloads to show what A1 Leads now holds.
  Widget _opsActions(ThemeData theme, Map<String, dynamic> review) {
    if (!_opsAvailable ||
        widget.session.me?.can('a1leads_gbp.manage') != true ||
        review['deleted_at'] != null ||
        review['id'] == null) {
      return const SizedBox.shrink();
    }

    final name = '${review['google_review_name']}';
    final dense = TextButton.styleFrom(
      visualDensity: VisualDensity.compact,
      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
    );

    return Padding(
      key: ValueKey('gbp-ops-$name'),
      padding: const EdgeInsets.only(top: 4),
      child: Wrap(
        spacing: 4,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          // A search-bar picker, not a popup menu - Jose's rule for every
          // choice in the app (2026-09-17).
          SizedBox(
            width: 190,
            child: SearchPickerField<String>(
              key: ValueKey('gbp-status-$name'),
              value: const ["Can't locate", 'Working on it', 'Awaiting report', 'Done']
                      .contains((review['board'] as Map?)?['status'])
                  ? (review['board'] as Map)['status'] as String
                  : null,
              label: 'Status',
              isDense: true,
              options: [
                for (final s in const ["Can't locate", 'Working on it', 'Awaiting report', 'Done'])
                  SearchOption<String>(value: s, label: s),
              ],
              onChanged: (status) {
                if (status != null) unawaited(_changeCard(name, {'status': status}));
              },
            ),
          ),
          TextButton.icon(
            style: dense,
            onPressed: () => unawaited(_addNote(name)),
            icon: const Icon(Icons.note_add_outlined, size: 16),
            label: const Text('Add note'),
          ),
          TextButton.icon(
            style: dense,
            onPressed: () => unawaited(_matchCustomer(review)),
            icon: const Icon(Icons.person_search_outlined, size: 16),
            label: const Text('Customer match'),
          ),
          TextButton.icon(
            style: dense,
            onPressed: () => unawaited(_reportReview(review)),
            icon: const Icon(Icons.outlined_flag, size: 16),
            label: const Text('Report to Google'),
          ),
        ],
      ),
    );
  }

  Future<void> _changeCard(String name, Map<String, dynamic> change) async {
    final response = await widget.api.post('/a1leads-gbp/cards/mark', {'ref': name, ...change});
    _afterOp(response, change.containsKey('status') ? 'Status set to ${change['status']}.' : 'Note added.');
  }

  Future<void> _addNote(String name) async {
    final controller = TextEditingController();
    final note = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Add a note'),
        content: TextField(
          controller: controller,
          autofocus: true,
          minLines: 2,
          maxLines: 6,
          maxLength: 4000,
          decoration: const InputDecoration(hintText: "What happened - it goes on the card's timeline"),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const Text('Add note'),
          ),
        ],
      ),
    );
    controller.dispose();
    if (note == null || note.isEmpty) return;
    await _changeCard(name, {'add_update': note});
  }

  /// Pin the review to a Workiz job A1 Leads suggests, or clear the pin so it
  /// can rematch. Only suggestions are offered - nobody types a job number.
  Future<void> _matchCustomer(Map<String, dynamic> review) async {
    final id = review['id'];
    final picked = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (context) => _MatchDialog(api: widget.api, reviewId: '$id'),
    );
    if (picked == null) return;

    final response = await widget.api.post('/a1leads-gbp/reviews/$id/match', picked);
    _afterOp(response, picked['clear'] == true ? 'Match cleared.' : 'Customer matched.');
  }

  /// Queue a policy report. A1 Leads files it, paces it, and says when Google
  /// acknowledged it; "reported" is never claimed here before that.
  Future<void> _reportReview(Map<String, dynamic> review) async {
    final status = await widget.api.get('/a1leads-gbp/report/status', const {});
    if (!mounted) return;
    final data = (status.body['data'] as Map?)?.cast<String, dynamic>() ?? const {};
    final reasons = ((data['reasons'] as List?) ?? const ['fake_or_deceptive']).map((r) => '$r').toList();

    final reason = await showDialog<String>(
      context: context,
      builder: (context) => _ReportDialog(reasons: reasons),
    );
    if (reason == null) return;

    final response = await widget.api.post('/a1leads-gbp/report', {
      'reviewIds': [review['id']],
      'reason': reason,
      'confirmed': true,
    });
    _afterOp(response, 'Report queued. A1 Leads files it and records when Google acknowledges it.');
  }

  void _afterOp(ApiResponse response, String done) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(response.ok ? done : response.message)),
    );
    if (response.ok) unawaited(_load());
  }

  /// The review shown in the panel, from the page as it stands now.
  Map<String, dynamic>? get _panelReview {
    final name = _panelName;
    if (name == null) return null;
    for (final r in _reviews) {
      if (r['google_review_name'] == name) return _withLiveReply(r);
    }
    return null;
  }

  bool _canChangeStatus(Map<String, dynamic> review, Map<String, dynamic> card) =>
      _opsAvailable &&
      widget.session.me?.can('a1leads_gbp.manage') == true &&
      review['deleted_at'] == null &&
      // Removed and Reported are A1 Leads' own stages, never an operator's.
      card['status'] != 'Removed';

  Future<void> _pickStatus(Map<String, dynamic> review, Map<String, dynamic> card) async {
    const statuses = ["Can't locate", 'Working on it', 'Awaiting report', 'Done'];
    final picked = await showSearchPicker<String>(
      context,
      title: 'Status',
      value: statuses.contains(card['status']) ? card['status'] as String : null,
      options: [for (final s in statuses) SearchOption<String>(value: s, label: s)],
    );
    if (picked == null || picked == card['status']) return;
    await _changeCard('${review['google_review_name']}', {'status': picked});
  }

  /// The right-hand panel: one review, whole, with everything that can be
  /// done to it. Jose, 2026-09-28, pointing at A1 Tools' own.
  Widget _panel(ThemeData theme, Map<String, dynamic> review) {
    final name = '${review['reviewer_name'] ?? ''}'.trim();
    final comment = '${review['comment'] ?? ''}'.trim();
    final reply = '${review['reply'] ?? ''}'.trim();
    final card = (review['board'] as Map?)?.cast<String, dynamic>();
    final reviewName = '${review['google_review_name']}';
    final quiet = theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    final heading = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w700,
      letterSpacing: 0.4,
    );

    Widget fact(String label, String value) => Padding(
          padding: const EdgeInsets.only(bottom: 4),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SizedBox(width: 90, child: Text(label, style: quiet)),
            Expanded(child: Text(value, style: theme.textTheme.bodySmall)),
          ]),
        );

    Widget bubble({required String title, required String body, String? when, bool ours = false}) => Container(
          width: double.infinity,
          margin: const EdgeInsets.only(bottom: 10),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: ours ? theme.colorScheme.primaryContainer : theme.colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: theme.textTheme.titleSmall),
            const SizedBox(height: 6),
            Text(body, style: theme.textTheme.bodyMedium),
            if (when != null) ...[const SizedBox(height: 6), Text(when, style: quiet)],
          ]),
        );

    return Material(
      key: const ValueKey('gbp-panel'),
      elevation: 8,
      color: theme.colorScheme.surface,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(
              child: Text(name.isEmpty ? 'A Google user' : name, style: theme.textTheme.titleLarge),
            ),
            IconButton(
              tooltip: 'Close',
              icon: const Icon(Icons.close),
              onPressed: () => setState(() => _panelName = null),
            ),
          ]),
          Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            _ratingChip(theme, (review['rating'] as num?)?.toInt()),
            if (card?['status'] != null)
              _canChangeStatus(review, card!)
                  ? InkWell(
                      borderRadius: BorderRadius.circular(12),
                      onTap: () => unawaited(_pickStatus(review, card)),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        _statusPill(theme, '${card['status']}'),
                        Icon(Icons.arrow_drop_down, size: 16, color: theme.colorScheme.onSurfaceVariant),
                      ]),
                    )
                  : _statusPill(theme, '${card['status']}'),
          ]),
          const SizedBox(height: 16),
          Text('REVIEW DETAILS', style: heading),
          const SizedBox(height: 6),
          fact('Date', _short(review['review_created_at'])),
          if (_placeOf(review).isNotEmpty) fact('Location', _placeOf(review)),
          if (review['deleted_at'] != null) fact('Google', 'Removed from Google'),
          const SizedBox(height: 12),
          Text('CUSTOMER', style: heading),
          _matchLine(theme, review),
          _customerContext(theme, review),
          _opsActions(theme, review),
          const SizedBox(height: 16),
          Text('HISTORY', style: heading),
          const SizedBox(height: 8),
          bubble(
            title: name.isEmpty ? 'The review' : name,
            body: comment.isEmpty ? '(A rating with no words.)' : comment,
            when: _short(review['review_created_at']),
          ),
          if (reply.isNotEmpty)
            bubble(
              title: 'Our reply',
              body: reply,
              when: review['reply_at'] == null ? null : _short(review['reply_at']),
              ours: true,
            ),
          if (card?['note'] != null) bubble(title: 'Note', body: '${card!['note']}'),
          if (_activeReplyName == reviewName)
            _replyEditor(theme)
          else if (_replyAvailable && reply.isEmpty && review['deleted_at'] == null)
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.icon(
                key: const ValueKey('gbp-panel-reply'),
                onPressed: () => unawaited(_openReply(review)),
                icon: const Icon(Icons.reply_outlined, size: 18),
                label: const Text('Reply on Google'),
              ),
            ),
        ],
      ),
    );
  }

  /// The customer's last job and who has the card, in one line.
  ///
  /// A1 Leads says whether the job went to a person or a team; a team is
  /// never written as if it were one technician.
  static String _jobLine(Map<String, dynamic> review, Map<String, dynamic>? card) {
    final customer = _customerOf(review);

    return [
      if (customer?['workiz_account'] != null) 'Workiz ${customer!['workiz_account']}',
      if (customer?['job_number'] != null) 'Job #${customer!['job_number']}',
      if (customer?['job_date'] != null) '${customer!['job_date']}',
      if (customer?['technician'] != null)
        '${customer!['assignee_kind'] == 'team' ? 'Team' : 'Tech'}: ${customer['technician']}',
      if (card?['assignee'] != null) 'Assigned to ${card!['assignee']}',
    ].join(' · ');
  }

  /// A-1's customer and job for this review, on the board card.
  ///
  /// Rodrigo's cutover checklist, row 5: "a compact expandable customer/job
  /// history on the review card". Only the customer A1 Leads MATCHED - never
  /// its candidates - with their last job, a link to it in Workiz, who has the
  /// card, and the operator updates folded away. Nothing when the card has
  /// none of it, which is most reviews.
  Widget _customerContext(ThemeData theme, Map<String, dynamic> review) {
    final card = (review['board'] as Map?)?.cast<String, dynamic>();
    final customer = _customerOf(review);
    final updates = ((card?['updates'] as List?) ?? const []).whereType<Map>().toList();
    final job = _jobLine(review, card);
    final url = customer?['workiz_url'] as String?;

    if (customer == null && job.isEmpty && updates.isEmpty) return const SizedBox.shrink();

    final quiet = theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    final who = [
      customer?['name'],
      if (customer?['manual'] == true) 'matched by hand',
      if (customer?['manual'] == false) 'matched automatically',
      ..._contactLines(review),
      customer?['address'],
    ].whereType<String>().join(' · ');

    return Padding(
      key: ValueKey('gbp-customer-${review['google_review_name']}'),
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (who.isNotEmpty)
            Row(children: [
              Icon(Icons.person_outline, size: 14, color: theme.colorScheme.onSurfaceVariant),
              const SizedBox(width: 4),
              Flexible(child: Text(who, style: theme.textTheme.bodySmall)),
            ]),
          if (job.isNotEmpty || url != null)
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 8,
              children: [
                if (job.isNotEmpty) Text(job, style: quiet),
                if (url != null)
                  TextButton(
                    style: TextButton.styleFrom(
                      padding: EdgeInsets.zero,
                      visualDensity: VisualDensity.compact,
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    onPressed: () =>
                        unawaited(launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication)),
                    child: const Text('Open in Workiz'),
                  ),
              ],
            ),
          if (updates.isNotEmpty)
            Theme(
              // An ExpansionTile draws dividers above and below by default.
              data: theme.copyWith(dividerColor: Colors.transparent),
              child: ExpansionTile(
                tilePadding: EdgeInsets.zero,
                dense: true,
                visualDensity: VisualDensity.compact,
                expandedCrossAxisAlignment: CrossAxisAlignment.start,
                title: Text(
                  updates.length == 1 ? '1 update' : '${updates.length} updates',
                  style: theme.textTheme.labelMedium,
                ),
                children: [
                  for (final u in updates)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Text.rich(TextSpan(children: [
                        TextSpan(
                          text: [u['author'], if (u['at'] != null) _short(u['at'])]
                              .whereType<String>()
                              .join(' · '),
                          style: quiet,
                        ),
                        const TextSpan(text: '\n'),
                        TextSpan(text: '${u['body'] ?? ''}', style: theme.textTheme.bodySmall),
                      ])),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  /// A-1's words for where a card is, drawn verbatim.
  ///
  /// Not remapped to EzBizi's own vocabulary: "Awaiting report" and "Reported -
  /// Failed" are A1 Leads' lanes, and a dispatcher reading both screens must
  /// see the same word in both. Colour follows the one distinction that is
  /// safe to draw without owning their workflow: finished or not.
  Widget _statusPill(ThemeData theme, String status) {
    final done = status == 'Removed';
    final bg = done ? theme.colorScheme.tertiaryContainer : theme.colorScheme.secondaryContainer;
    final fg = done ? theme.colorScheme.onTertiaryContainer : theme.colorScheme.onSecondaryContainer;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(10)),
      child: Text(status,
          style: theme.textTheme.labelSmall?.copyWith(color: fg), overflow: TextOverflow.ellipsis),
    );
  }

  Widget _footer(ThemeData theme, bool more) {
    if (!more) {
      return Text(
        'All $_total shown',
        style: theme.textTheme.bodySmall
            ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
      );
    }

    return OutlinedButton.icon(
      icon: _loadingMore
          ? const SizedBox(
              width: 16,
              height: 16,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : const Icon(Icons.expand_more, size: 18),
      label: Text(
        _loadingMore ? 'Loading…' : 'Load more (${_reviews.length} of $_total)',
      ),
      onPressed: _loadingMore ? null : _loadMore,
    );
  }

  Widget _list(ThemeData theme) {
    final more = _reviews.length < _total;

    return ListView.separated(
      padding: const EdgeInsets.only(bottom: 24),
      itemCount: _reviews.length + 1,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == _reviews.length) {
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Center(child: _footer(theme, more)),
          );
        }

        return _row(theme, _reviews[index]);
      },
    );
  }

  Widget _row(ThemeData theme, Map<String, dynamic> review) {
    return InkWell(
      key: ValueKey('gbp-card-${review['google_review_name']}'),
      onTap: () => setState(() => _panelName = '${review['google_review_name']}'),
      child: _rowBody(theme, review),
    );
  }

  Widget _rowBody(ThemeData theme, Map<String, dynamic> review) {
    review = _withLiveReply(review);
    final reply = '${review['reply'] ?? ''}'.trim();
    final comment = '${review['comment'] ?? ''}'.trim();
    final name = '${review['reviewer_name'] ?? ''}'.trim();

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              _stars(theme, (review['rating'] as num?)?.toInt()),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  name.isEmpty ? 'A Google user' : name,
                  style: theme.textTheme.titleSmall,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (reply.isEmpty)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                    color: theme.colorScheme.errorContainer,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(
                    'No reply',
                    style: theme.textTheme.labelSmall
                        ?.copyWith(color: theme.colorScheme.onErrorContainer),
                  ),
                ),
            ],
          ),
          if (comment.isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(comment, style: theme.textTheme.bodyMedium),
          ],
          const SizedBox(height: 6),
          Text(
            [
              _placeOf(review),
              if (review['review_created_at'] != null) _short(review['review_created_at']),
            ].where((s) => s.isNotEmpty).join(' · '),
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
          _matchLine(theme, review),
          _boardLine(theme, review),
          _customerContext(theme, review),
          _opsActions(theme, review),
          if (_replyAvailable && reply.isEmpty && review['deleted_at'] == null &&
              _activeReplyName != review['google_review_name']) ...[
            const SizedBox(height: 8),
            TextButton.icon(
              onPressed: () => unawaited(_openReply(review)),
              icon: const Icon(Icons.reply_outlined, size: 18),
              label: const Text('Reply on Google'),
            ),
          ],
          if (_activeReplyName == review['google_review_name'] && _panelName != _activeReplyName)
            _replyEditor(theme),
          if (reply.isNotEmpty) ...[
            const SizedBox(height: 10),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: theme.colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          'Replied on Google',
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.labelSmall,
                        ),
                      ),
                      if (review['reply_at'] != null) ...[
                        const SizedBox(width: 8),
                        Text(
                          _short(review['reply_at']),
                          style: theme.textTheme.labelSmall
                              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(reply, style: theme.textTheme.bodySmall),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// The reply editor: the review as live Google has it, the draft, and a
  /// confirmation that says what posting does before it does it.
  Widget _replyEditor(ThemeData theme) {
    final live = _liveReview;
    final canSend = live?['canReply'] == true && '${live?['reviewUpdatedAt'] ?? ''}'.isNotEmpty;

    return Container(
      key: const ValueKey('gbp-reply-editor'),
      width: double.infinity,
      margin: const EdgeInsets.only(top: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Expanded(child: Text('Live Google review', style: theme.textTheme.titleSmall)),
            IconButton(
              tooltip: 'Close reply',
              onPressed: _postingReply
                  ? null
                  : () => setState(() {
                        ++_replyRequest;
                        _activeReplyName = null;
                        _liveReview = null;
                        _confirmingReply = false;
                      }),
              icon: const Icon(Icons.close, size: 18),
            ),
          ]),
          if (_checkingReply) const LinearProgressIndicator(),
          if (live != null) ...[
            Text(
              '${live['reviewerName'] ?? 'Google reviewer'} · '
              '${live['rating'] ?? '?'}★ · updated ${_short(live['reviewUpdatedAt'])}',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 6),
            Text('${live['comment'] ?? ''}', style: theme.textTheme.bodyMedium),
          ],
          if (_replyError != null) ...[
            const SizedBox(height: 8),
            Text(_replyError!, style: TextStyle(color: theme.colorScheme.error)),
            TextButton(
              onPressed: _postingReply
                  ? null
                  : () {
                      final review = _reviews.firstWhere(
                        (r) => r['google_review_name'] == _activeReplyName,
                        orElse: () => {'google_review_name': _activeReplyName},
                      );
                      unawaited(_openReply(review));
                    },
              child: const Text('Check live status again'),
            ),
          ],
          if (_replyNotice != null) ...[
            const SizedBox(height: 8),
            Text(_replyNotice!, style: TextStyle(color: theme.colorScheme.primary)),
          ],
          if (canSend) ...[
            if (_opsAvailable) ...[
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton.icon(
                  key: const ValueKey('gbp-reply-draft'),
                  onPressed: _drafting || _postingReply ? null : () => unawaited(_draftWithAi()),
                  icon: const Icon(Icons.auto_awesome_outlined, size: 18),
                  label: Text(_drafting ? 'Drafting...' : 'Draft with AI'),
                ),
              ),
            ],
            if (_aiReply != null && _replyDraft.text == _aiReply) ...[
              const SizedBox(height: 10),
              const Align(
                alignment: Alignment.centerLeft,
                child: AiMark.badge(
                  'Draft by AI',
                  key: ValueKey('gbp-reply-ai-draft'),
                  tooltip: 'Written by AI. Read it before posting: nothing goes '
                      'until you press Post and confirm.',
                ),
              ),
            ],
            const SizedBox(height: 10),
            TextField(
              controller: _replyDraft,
              minLines: 3,
              maxLines: 8,
              maxLength: 4000,
              enabled: !_postingReply,
              onChanged: (_) {
                // Also redraws the AI mark, which goes at the first change.
                if (_confirmingReply || _aiReply != null) {
                  setState(() => _confirmingReply = false);
                }
              },
              decoration: const InputDecoration(
                labelText: 'Public reply',
                hintText: 'Write the response this reviewer will see on Google',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 6),
            if (!_confirmingReply)
              FilledButton.icon(
                key: const ValueKey('gbp-reply-post'),
                onPressed: _postingReply
                    ? null
                    : () {
                        if (_replyDraft.text.trim().isEmpty) return;
                        setState(() => _confirmingReply = true);
                      },
                icon: _postingReply
                    ? const SizedBox(
                        width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.send_outlined, size: 18),
                label: Text(_postingReply ? 'Posting…' : 'Post public reply'),
              )
            else
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'This goes live on Google under A-1 Chimney\'s name, where anybody '
                    'reading the review will see it. Google keeps one reply per review.',
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 8,
                    children: [
                      TextButton(
                        onPressed: () => setState(() => _confirmingReply = false),
                        child: const Text('Not yet'),
                      ),
                      FilledButton(
                        key: const ValueKey('gbp-reply-confirm'),
                        onPressed: _publishReply,
                        // Names the act, so the last button pressed says what it does.
                        child: const Text('Post it'),
                      ),
                    ],
                  ),
                ],
              ),
          ],
        ],
      ),
    );
  }

  /// A-1's own triage, shown and not recomputed.
  ///
  /// `match_tier` is A1 Leads' answer to "is this reviewer a customer we have a
  /// record of". A null tier means it has not been computed, which is a third
  /// state and not a no — drawing it as "unverified" would put real customers
  /// in the possible-spam queue.
  Widget _matchLine(ThemeData theme, Map<String, dynamic> review) {
    final tier = review['match_tier'] as String?;

    if (tier == null) return const SizedBox.shrink();

    final matched = tier == 'high' || tier == 'medium';
    final customer = '${review['match_customer_name'] ?? ''}'.trim();
    final tenant = '${review['workiz_tenant'] ?? ''}'.trim();

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Row(
        children: [
          Icon(
            matched ? Icons.verified_user : Icons.help_outline,
            size: 14,
            color: matched ? theme.colorScheme.tertiary : theme.colorScheme.onSurfaceVariant,
          ),
          const SizedBox(width: 5),
          Flexible(
            child: Text(
              matched
                  ? (customer.isEmpty ? 'Matched customer' : 'Matched: $customer')
                  : 'Unverified — no customer match',
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall?.copyWith(
                color: matched ? theme.colorScheme.tertiary : theme.colorScheme.onSurfaceVariant,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          // The Workiz job is NAMED and not linked. The link A1 Leads returns
          // only resolves for whoever already has that sub-account open, so it
          // is a fact about where the job is rather than somewhere to go.
          if (matched && tenant.isNotEmpty) ...[
            const SizedBox(width: 8),
            Flexible(
              child: Text(
                'Workiz · $tenant',
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
            ),
          ],
        ],
      ),
    );
  }

  String _placeOf(Map<String, dynamic> review) {
    final address = '${review['location_address'] ?? ''}'.trim();
    final title = '${review['location_title'] ?? ''}'.trim();

    if (address.isNotEmpty) return _cityState(address) ?? address;
    if (title.isNotEmpty) return title;

    return '';
  }

  /// "204 Ark Rd, Mt Laurel Township, NJ, 08054" → "Mt Laurel Township, NJ".
  String? _cityState(String address) {
    final parts = address
        .split(',')
        .map((s) => s.trim())
        .where((s) => s.isNotEmpty)
        .toList();

    for (var i = 1; i < parts.length; i++) {
      final match = RegExp(r'^([A-Z]{2})\b').firstMatch(parts[i]);
      if (match != null) return '${parts[i - 1]}, ${match.group(1)}';
    }

    return null;
  }

  Widget _ratingChip(ThemeData theme, int? rating) {
    if (rating == null) {
      return Text('—', style: theme.textTheme.bodySmall);
    }

    final low = rating <= 3;

    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
        decoration: BoxDecoration(
          color: low ? theme.colorScheme.errorContainer : theme.colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(6),
        ),
        child: Text(
          '★ $rating',
          style: theme.textTheme.labelSmall?.copyWith(
            color: low ? theme.colorScheme.onErrorContainer : theme.colorScheme.onSurfaceVariant,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }

  Widget _stars(ThemeData theme, int? rating) {
    if (rating == null) {
      return Text('no rating', style: theme.textTheme.bodySmall);
    }

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 1; i <= 5; i++)
          Icon(
            i <= rating ? Icons.star : Icons.star_border,
            size: 15,
            color: i <= rating
                ? theme.colorScheme.primary
                : theme.colorScheme.outlineVariant,
          ),
      ],
    );
  }

  /// Nothing came back — and which nothing it is matters.
  ///
  /// "No reviews at all" is never one of the answers this can give: the source
  /// being unusable is handled above, so anything empty here is a filter.
  Widget _empty(ThemeData theme) {
    return _sentence(
      theme,
      icon: Icons.filter_alt_off_outlined,
      title: 'Nothing matches these filters',
      body: 'The snapshot captured ${_short(_source['captured_at'])} has no review '
          'matching all of them. Widen the rating or the date range to see more.',
    );
  }

  Widget _sentence(
    ThemeData theme, {
    required IconData icon,
    required String title,
    required String body,
  }) {
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 520),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 40, color: theme.colorScheme.outline),
              const SizedBox(height: 12),
              Text(title, style: theme.textTheme.titleMedium, textAlign: TextAlign.center),
              const SizedBox(height: 8),
              Text(
                body,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// A date a person reads, from whatever the server sent.
  ///
  /// Null-tolerant on purpose: the receipt can be missing a field when a
  /// refresh has never run, and a screen that throws on that is a screen that
  /// breaks precisely when something is already wrong.
  String _short(Object? iso) {
    final parsed = DateTime.tryParse('$iso');
    if (parsed == null) return 'an unknown date';

    final local = parsed.toLocal();
    final d = local.day.toString().padLeft(2, '0');
    final m = local.month.toString().padLeft(2, '0');
    final h = local.hour.toString().padLeft(2, '0');
    final min = local.minute.toString().padLeft(2, '0');

    return '$d/$m $h:$min';
  }
}


/// A1 Leads' Workiz suggestions for one review, to pick one or clear the pin.
class _MatchDialog extends StatefulWidget {
  const _MatchDialog({required this.api, required this.reviewId});

  final ApiClient api;
  final String reviewId;

  @override
  State<_MatchDialog> createState() => _MatchDialogState();
}

class _MatchDialogState extends State<_MatchDialog> {
  final _query = TextEditingController();
  List<Map<String, dynamic>> _suggestions = const [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    unawaited(_search());
  }

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final response = await widget.api.get(
      '/a1leads-gbp/reviews/${widget.reviewId}/suggestions',
      {if (_query.text.trim().isNotEmpty) 'q': _query.text.trim()},
    );
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      // Measured on the live route, 2026-09-25: `data.jobs`, each with name,
      // date, team (a list), jobNumber, tenant and jobId. Nothing comes back
      // until something is searched for.
      final data = (response.body['data'] as Map?)?.cast<String, dynamic>() ?? const {};
      _suggestions = ((data['jobs'] as List?) ?? const [])
          .whereType<Map>()
          .map((m) => m.cast<String, dynamic>())
          .toList();
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return AlertDialog(
      title: const Text('Customer match'),
      content: SizedBox(
        width: 480,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              controller: _query,
              decoration: InputDecoration(
                hintText: 'Search by name, phone or address',
                suffixIcon: IconButton(icon: const Icon(Icons.search), onPressed: () => unawaited(_search())),
              ),
              onSubmitted: (_) => unawaited(_search()),
            ),
            const SizedBox(height: 8),
            if (_loading)
              const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()))
            else if (_error != null)
              Text(_error!, style: TextStyle(color: theme.colorScheme.error))
            else if (_suggestions.isEmpty)
              Text(_query.text.trim().isEmpty
                  ? "Search for the customer's name, phone or address."
                  : 'No Workiz job matches that search.')
            else
              Flexible(
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    for (final s in _suggestions)
                      ListTile(
                        dense: true,
                        title: Text('${s['name'] ?? 'Workiz job ${s['jobNumber'] ?? s['jobId'] ?? ''}'}'),
                        subtitle: Text([
                          if (s['jobNumber'] != null) 'Job #${s['jobNumber']}',
                          if (s['date'] != null) '${s['date']}',
                          if (s['team'] is List && (s['team'] as List).isNotEmpty)
                            'Tech: ${(s['team'] as List).join(', ')}',
                          if (s['tenant'] != null) '${s['tenant']}',
                        ].join(' · ')),
                        onTap: s['jobId'] == null || s['tenant'] == null
                            ? null
                            : () => Navigator.pop(context, {'tenant': '${s['tenant']}', 'jobId': '${s['jobId']}'}),
                      ),
                  ],
                ),
              ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, const {'clear': true}),
          child: const Text('Clear match'),
        ),
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
      ],
    );
  }
}

/// A policy reason and an explicit confirmation - A1 Leads refuses either
/// missing, and so does EzBizi before it asks.
class _ReportDialog extends StatefulWidget {
  const _ReportDialog({required this.reasons});

  final List<String> reasons;

  @override
  State<_ReportDialog> createState() => _ReportDialogState();
}

class _ReportDialogState extends State<_ReportDialog> {
  late String _reason = widget.reasons.first;
  bool _confirmed = false;

  static String _label(String reason) {
    final words = reason.replaceAll('_', ' ');
    return words.isEmpty ? reason : words[0].toUpperCase() + words.substring(1);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Report to Google'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Google removes a review only for a policy reason, not for criticism.'),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            value: _reason,
            label: 'Policy reason',
            options: [for (final r in widget.reasons) SearchOption<String>(value: r, label: _label(r))],
            onChanged: (v) => setState(() => _reason = v ?? _reason),
          ),
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            value: _confirmed,
            onChanged: (v) => setState(() => _confirmed = v ?? false),
            title: const Text('This review breaks that policy'),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        FilledButton(
          key: const ValueKey('gbp-report-confirm'),
          onPressed: _confirmed ? () => Navigator.pop(context, _reason) : null,
          child: const Text('Queue report'),
        ),
      ],
    );
  }
}

/// A US number in the shapes A1 Leads sends it - ten digits, eleven with the
/// leading 1, or +1 and ten - written as A1 Tools does, (513) 390-5722.
/// Anything else (an email, an extension, a number from another country) is
/// shown exactly as it came; the app's own formatter decides what is a valid
/// US number.
@visibleForTesting
String gbpContactText(String line) {
  final digits = RegExp(r'^(?:\+?1)?(\d{10})$').firstMatch(line)?.group(1);
  if (digits == null) return line;
  final pretty = formatPhoneNumber('+1$digits');
  return pretty.startsWith('+1 ') ? pretty.substring(3) : line;
}
