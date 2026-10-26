import 'dart:typed_data';
import 'dart:async';

import 'package:desktop_drop/desktop_drop.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:video_player/video_player.dart';

import 'clock_label.dart';
import 'instagram_comments_panel.dart';
import 'qr_codes_tab.dart';
import 'tiktok_post_choices.dart';
import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/disconnect_panel.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/list_table.dart';
import '../../widgets/social_network_mark.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';
import '../email/consent_window.dart';
import 'media_library_panel.dart';

/// Marketing › Social Media Management — pages, compose, calendar.
///
/// Layout locked 21 Aug. Connect uses the server catalogue: OAuth popup for
/// networks with an app, handle + app password for Bluesky.
class SocialMediaTab extends StatefulWidget {
  const SocialMediaTab({
    super.key,
    required this.api,
    required this.session,
    this.openConsent = openConsentWindow,
    this.pickMedia = _pickMedia,
  });

  final ApiClient api;
  final Session session;
  final OpenConsentWindow openConsent;

  /// How a file is chosen. Injectable for the same reason [openConsent] is:
  /// `Files.pickBytes` reaches the platform, so a test that could not replace
  /// it could not reach the preview at all — and the preview is the whole
  /// point of attaching a file you are about to publish under your own name.
  final Future<PickedBytes?> Function() pickMedia;

  @override
  State<SocialMediaTab> createState() => _SocialMediaTabState();
}

Future<PickedBytes?> _pickMedia() => Files.pickBytes(accept: 'image/*,video/*');

class _SocialMediaTabState extends State<SocialMediaTab> {
  /// Roughly a minute at three seconds a try. Long enough for a video upload
  /// to a slow network, short enough that a dead worker is reported rather
  /// than drawn as progress forever.
  static const int _pollLimit = 20;

  /// The panes, by KEY: an index would point at a different pane depending on
  /// which ones this role is shown.
  ///
  /// One row since UI queue 76 (Jose, 2026-10-12, "2 definitely"): what was
  /// Posts with Compose / Calendar inside it is three tabs beside Accounts,
  /// each its own grant (`marketing.dashboard.view` and so on). The migration
  /// that retired `marketing.posts.view` gave its holders all three, so no
  /// role lost a pane.
  String _tab = 'dashboard';

  static const _panes = [
    ('dashboard', 'Dashboard', Icons.insights_outlined),
    ('compose', 'Compose', Icons.edit_outlined),
    ('calendar', 'Calendar', Icons.calendar_month_outlined),
    ('accounts', 'Accounts', Icons.link_outlined),
    ('qr_codes', 'QR Codes', Icons.qr_code_2_outlined),
  ];

  /// Absent `/me` answers NO.
  bool _mayOpen(String pane) =>
      widget.session.me?.permissions.contains('marketing.$pane.view') ?? false;

  /// Each of the three post panes reads the posts list; Accounts and QR Codes
  /// do not, and are not made to ask for it.
  bool get _mayReadPosts =>
      _mayOpen('dashboard') || _mayOpen('compose') || _mayOpen('calendar');

  List<(String, String, IconData)> get _shownPanes =>
      [for (final pane in _panes) if (_mayOpen(pane.$1)) pane];

  List<_SocialNetwork> _providers = const [];
  List<_SocialConnection> _connections = const [];
  List<_SentPost> _posts = const [];
  String? _loadError;
  Timer? _poll;
  bool _gaveUpWaiting = false;

  /// The post just published, while it is landing: the composer (desktop) or
  /// the whole pane (phone) shows its accounts resolving. Null is the form.
  String? _justPosted;

  /// What the POST answered, until the list read back carries the post. The
  /// list is read after the POST returns, and that moment is when somebody is
  /// watching hardest.
  _SentPost? _provisional;

  /// The newest post, outlined on the calendar the first time it is seen.
  String? _outlined;

  /// The day "See it on the calendar" asked for; the month calendar opens on it.
  DateTime? _focusDay;

  /// The calendar or the agenda, to scroll to when the layout is stacked.
  final GlobalKey _calendarKey = GlobalKey();

  /// The Posts tiles as the server counted them, across every post this view
  /// can see — the list itself is the twenty newest. Null from a server that
  /// does not count yet; the tiles then count the list they have.
  Map<String, int>? _tiles;

  /// The picked tile, and the server's answer for it. Null [_filtered] while
  /// that answer is on its way.
  String? _filter;
  List<_SentPost>? _filtered;

  /// The timeline's search box, sent to GET /social/posts as `q`, debounced.
  final _search = TextEditingController();
  Timer? _searchDebounce;
  String _q = '';

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
    _poll?.cancel();
    _searchDebounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Map<String, String>? get _postsQuery => _q.isEmpty ? null : {'q': _q};

  void _searched(String text) {
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      setState(() {
        _q = text.trim();
        _filtered = null;
      });
      _loadPosts();
    });
  }

  /// SocialPostsController@index does not read `q` yet (it returns twenty
  /// per tile), so the loaded posts are also narrowed here, by what they
  /// say, who wrote them and where they went. Once the server honours `q`
  /// this is a no-op.
  List<_SentPost> _matching(List<_SentPost> posts) {
    final q = _q.toLowerCase();
    if (q.isEmpty) return posts;
    return [
      for (final p in posts)
        if ([
          p.body,
          p.author,
          for (final t in p.targets) ...[t.account, t.providerLabel],
        ].whereType<String>().join(' ').toLowerCase().contains(q))
          p,
    ];
  }

  Future<void> _load() async {
    final providers = await widget.api.get('/social/providers');
    final connections = await widget.api.get('/social/connections');
    // Not asked for without the Posts pane: the server would refuse it, and a
    // request made only to be refused is noise in every log that reads 403s.
    final posts = _mayReadPosts
        ? await widget.api.get('/social/posts', _postsQuery)
        : null;
    if (!mounted) return;

    setState(() {
      if (!providers.ok) {
        _loadError = providers.message;
        return;
      }
      if (!connections.ok) {
        _loadError = connections.message;
        return;
      }
      _loadError = null;
      _providers = ((providers.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SocialNetwork.fromJson)
          .toList();
      _connections = ((connections.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SocialConnection.fromJson)
          .toList();

      // Deliberately not an error when this one fails. The history of what went
      // out is the least important thing on the tab, and losing the whole
      // screen — Connect included — because a list could not be read would be
      // the wrong trade.
      _posts = posts == null || !posts.ok ? const [] : _readPosts(posts);
      _tiles = posts == null || !posts.ok ? null : _readTiles(posts);
    });

    _watchPending();
    await _loadFiltered();
  }

  /// Just the Sent list. Used by the poll below, which has no business
  /// re-reading the catalogue and the connections every few seconds.
  Future<void> _loadPosts() async {
    if (!_mayReadPosts) return;

    final posts = await widget.api.get('/social/posts', _postsQuery);
    if (!mounted || !posts.ok) return;

    setState(() {
      _posts = _readPosts(posts);
      _tiles = _readTiles(posts);
    });

    await _loadFiltered();
  }

  /// The picked tile's posts, from the server: the list is capped at twenty,
  /// so filtering those twenty here would hide the older ones the tile counts.
  Future<void> _loadFiltered() async {
    final filter = _filter;
    if (filter == null || !_mayReadPosts) return;

    final posts = await widget.api.get('/social/posts', {
      'status': filter,
      ...?_postsQuery,
    });
    if (!mounted || filter != _filter || !posts.ok) return;

    setState(() {
      _filtered = _readPosts(posts);
      _tiles = _readTiles(posts) ?? _tiles;
    });
  }

  void _pickFilter(String? filter) {
    setState(() {
      _filter = filter;
      _filtered = null;
    });
    _loadFiltered();
  }

  static List<_SentPost> _readPosts(ApiResponse response) =>
      ((response.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SentPost.fromJson)
          .toList();

  static Map<String, int>? _readTiles(ApiResponse response) {
    final meta = response.body['meta'];
    final tiles = meta is Map<String, dynamic> ? meta['tiles'] : null;
    if (tiles is! Map<String, dynamic>) return null;

    return {
      for (final entry in tiles.entries)
        if (entry.value is num) entry.key: (entry.value as num).toInt(),
    };
  }

  /// Every tile's count: the server's, or the list's own when it gave none.
  Map<String, int> get _tileCounts =>
      _tiles ??
      {
        for (final key in const ['scheduled', 'sent', 'failed'])
          key: _shown.where((p) => p.tile == key).length,
      };

  /// The table's rows: everything, or the picked tile — the list's own
  /// matches until the server's answer arrives, rather than an empty table.
  List<_SentPost> get _rows {
    final filter = _filter;
    if (filter == null) return _matching(_shown);

    return _matching(
      _filtered ??
          [
            for (final p in _shown)
              if (p.tile == filter) p,
          ],
    );
  }

  /// Retry from the table: one failed account is retried there and then;
  /// several open the post, where each has its own Try again.
  void _retryPost(_SentPost post) {
    final failed = [
      for (final t in post.targets)
        if (t.status == 'failed' && t.id != null) t,
    ];

    failed.length == 1 ? _retry(post, failed.single) : _openPost(post);
  }

  /// Only `queued` starts the poll. A `scheduled` target is waiting for a
  /// clock, not for the worker — polling it would spend twenty requests to
  /// learn nothing and then announce that the queue had stopped answering.
  bool get _anyQueued =>
      _shown.any((p) => p.targets.any((t) => t.status == 'queued'));

  /// The posts to draw: the list, and the just-published one until the list
  /// carries it.
  List<_SentPost> get _shown {
    final early = _provisional;
    if (early == null || _posts.any((p) => p.id == early.id)) return _posts;

    return [early, ..._posts];
  }

  /// The just-published post as the server last described it.
  _SentPost? get _landing {
    for (final post in _shown) {
      if (post.id == _justPosted) return post;
    }

    return null;
  }

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  /// A post went out (or was filed for later): follow it where it was sent.
  Future<void> _posted(_SentPost post) async {
    setState(() {
      _provisional = post;
      _justPosted = post.id;
      _outlined = post.id;
      _gaveUpWaiting = false;
    });

    await _load();
  }

  /// Open the calendar on the post's day, and scroll to it when the layout
  /// stacks. [leave] ends the finish screen as well (the phone's Done).
  void _toCalendar({bool leave = false}) {
    final when = _landing?.when;

    setState(() {
      _tab = 'calendar';
      if (when != null) _focusDay = when;
      if (leave) _justPosted = null;
    });

    WidgetsBinding.instance.addPostFrameCallback((_) {
      final calendar = _calendarKey.currentContext;
      if (mounted && calendar != null) {
        Scrollable.ensureVisible(
          calendar,
          duration: const Duration(milliseconds: 300),
        );
      }
    });
  }

  /// One post: every account, its link or its reason.
  Future<void> _openPost(_SentPost post) {
    return showSidePanel<void>(
      context,
      builder: (panel) => SidePanel(
        title: 'Post',
        subtitle: _stamp(post, _formats),
        child: _PostDetail(
          post: post,
          connections: _connections,
          // The panel closes and the composer follows the account, the same
          // moment a new post gets.
          onRetry: _canPost
              ? (target) {
                  Navigator.of(panel).pop();
                  _retry(post, target);
                }
              : null,
          // UI queue 100: what can still be done to it, each behind the same
          // marketing.edit the server checks.
          onEdit: _canPost
              ? () {
                  Navigator.of(panel).pop();
                  _editPost(post);
                }
              : null,
          onCancel: _canPost
              ? () async {
                  if (await _confirm(
                        'Cancel this post?',
                        'It will not go out, and it is removed from the list. '
                            'This cannot be undone.',
                        'Cancel post',
                      ) &&
                      panel.mounted) {
                    Navigator.of(panel).pop();
                    _act(widget.api.delete('/social/posts/${post.id}'));
                  }
                }
              : null,
          onDismiss: _canPost
              ? (target) {
                  Navigator.of(panel).pop();
                  _act(
                    widget.api.post(
                      '/social/posts/${post.id}/targets/${target.id}/dismiss',
                      const {},
                    ),
                  );
                }
              : null,
          onDeleteRemote: _canPost
              ? (target) async {
                  final network = target.providerLabel;
                  if (await _confirm(
                        'Delete it from $network?',
                        'The post is taken down from $network. This cannot '
                            'be undone.',
                        'Delete from $network',
                      ) &&
                      panel.mounted) {
                    Navigator.of(panel).pop();
                    _act(
                      widget.api.delete(
                        '/social/posts/${post.id}/targets/${target.id}/remote',
                      ),
                    );
                  }
                }
              : null,
        ),
      ),
    );
  }

  /// Asks before something that cannot be taken back, in a side panel as
  /// every other question here.
  Future<bool> _confirm(String title, String body, String yes) async =>
      await showSidePanel<bool>(
        context,
        builder: (panel) => SidePanel(
          title: title,
          actions: [
            TextButton(
              key: const Key('social-confirm-no'),
              onPressed: () => Navigator.of(panel).pop(false),
              child: const Text('Keep it'),
            ),
            FilledButton(
              key: const Key('social-confirm-yes'),
              onPressed: () => Navigator.of(panel).pop(true),
              child: Text(yes),
            ),
          ],
          child: Text(body),
        ),
      ) ==
      true;

  /// Runs one change, says the server's refusal when there is one, and reads
  /// the list again either way.
  Future<void> _act(Future<ApiResponse> call) async {
    final response = await call;
    if (!mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(response.reason)));
    }
    await _loadPosts();
  }

  /// Edit the words or move the time of a post nothing has sent yet.
  Future<void> _editPost(_SentPost post) async {
    final body = TextEditingController(text: post.body);
    final wall = post.scheduledFor ?? '';
    final when = TextEditingController(
      text: wall.length > 16 ? wall.substring(0, 16) : wall,
    );
    final saved = await showSidePanel<bool>(
      context,
      builder: (panel) => SidePanel(
        title: 'Edit post',
        subtitle: 'Before it goes out',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(panel).pop(false),
            child: const Text('Back'),
          ),
          FilledButton(
            key: const Key('social-edit-save'),
            onPressed: () => Navigator.of(panel).pop(true),
            child: const Text('Save'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              key: const Key('social-edit-body'),
              controller: body,
              minLines: 3,
              maxLines: 10,
              decoration: const InputDecoration(labelText: 'Post'),
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('social-edit-when'),
              controller: when,
              decoration: InputDecoration(
                labelText: 'Goes out',
                hintText: 'YYYY-MM-DD HH:MM',
                helperText: clockLabel(post.scheduledTimezone).isEmpty
                    ? 'On this view’s clock'
                    : 'On the ${clockLabel(post.scheduledTimezone)} clock',
              ),
            ),
          ],
        ),
      ),
    );
    if (saved != true || !mounted) return;

    await _act(
      widget.api.patch('/social/posts/${post.id}', {
        'body': body.text,
        if (when.text.trim().isNotEmpty) 'scheduled_for': when.text.trim(),
      }),
    );
  }

  bool get _canPost =>
      widget.session.me?.permissions.contains('marketing.edit') ?? false;

  /// Try again, for one account that failed: sent back to the queue, then
  /// followed like a post just published.
  Future<void> _retry(_SentPost post, _SentTarget target) async {
    final response = await widget.api.post(
      '/social/posts/${post.id}/targets/${target.id}/retry',
      const {},
    );
    if (!mounted) return;

    // A 422 says it is no longer failed, and why; the list read below shows
    // what it is now, so the sentence is enough.
    if (!response.ok) {
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(response.reason)));
    }

    // To Compose, from the dashboard or the calendar: that is where the
    // result is drawn.
    setState(() {
      _justPosted = post.id;
      _gaveUpWaiting = false;
      _tab = 'compose';
    });

    await _load();
  }

  /// Follow a post until the queue has actually dealt with it.
  ///
  /// ── The defect this exists to fix ────────────────────────────
  ///
  /// Publishing is a job per account, so a 201 means QUEUED and never means
  /// posted. The screen loaded the Sent list once, immediately, and never
  /// again — so a target that failed two seconds later sat on screen saying
  /// "waiting to send" indefinitely. On 2026-08-28 that is exactly what
  /// happened on production: TikTok had already refused the post and been
  /// written `failed`, while the composer showed it still in flight.
  ///
  /// A failure displayed as progress is worse than an error. An error sends
  /// somebody looking; a spinner tells them it is working and to wait.
  ///
  /// It gives up rather than polling forever: if the queue worker is down,
  /// every row stays `queued` and an endless timer would hammer the server to
  /// keep drawing a lie. `_gaveUpWaiting` then says so on screen.
  void _watchPending() {
    _poll?.cancel();

    if (!_anyQueued) return;

    var tries = 0;

    _poll = Timer.periodic(const Duration(seconds: 3), (timer) async {
      if (!mounted) {
        timer.cancel();
        return;
      }

      if (++tries > _pollLimit) {
        timer.cancel();
        setState(() => _gaveUpWaiting = true);
        return;
      }

      await _loadPosts();

      if (!mounted || !_anyQueued) {
        timer.cancel();
        if (mounted) setState(() => _gaveUpWaiting = false);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final shown = _shownPanes;

    if (shown.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            key: const ValueKey('tour-marketing-social-none'),
            "None of Social Media Management's tabs are available to your role.",
            style: Theme.of(context).textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    // The chosen pane if it is shown, else the first that is — resolved every
    // frame so a grant changing mid-session lands on a pane that exists.
    final current = shown.any((p) => p.$1 == _tab) ? _tab : shown.first.$1;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TabBarLike(
          key: const ValueKey('tour-marketing-social-panes'),
          level: TabLevel.secondary,
          labels: [for (final pane in shown) (pane.$2, pane.$3)],
          selected: shown.indexWhere((p) => p.$1 == current),
          onSelected: (i) => setState(() => _tab = shown[i].$1),
        ),
        const Divider(height: 1),
        Expanded(
          child: current == 'qr_codes'
              ? QrCodesTab(api: widget.api, session: widget.session)
              : current != 'accounts'
              ? _PostsPane(
                  api: widget.api,
                  session: widget.session,
                  viewName: _viewName,
                  connections: _connections,
                  posts: _shown,
                  rows: _rows,
                  search: _search,
                  onSearch: _searched,
                  q: _q,
                  tiles: _tileCounts,
                  filter: _filter,
                  onFilter: _pickFilter,
                  view: current,
                  onNew: _mayOpen('compose')
                      ? () => setState(() => _tab = 'compose')
                      : null,
                  onAccounts: _mayOpen('accounts')
                      ? () => setState(() => _tab = 'accounts')
                      : null,
                  onRetryPost: _canPost ? _retryPost : null,
                  stillWaiting: _gaveUpWaiting,
                  pickMedia: widget.pickMedia,
                  landing: _landing,
                  outlined: _outlined,
                  focusDay: _focusDay,
                  calendarKey: _calendarKey,
                  formats: _formats,
                  onSent: _posted,
                  onWriteAnother: () => setState(() => _justPosted = null),
                  onSeeCalendar: _toCalendar,
                  onDone: () => _toCalendar(leave: true),
                  onOpen: _openPost,
                  onRetry: _canPost ? _retry : null,
                  onConnect: () => _openConnect(context),
                )
              : _AccountsPane(
                  viewName: _viewName,
                  providers: _providers,
                  connections: _connections,
                  error: _loadError,
                  onConnect: () => _openConnect(context),
                  onDisconnect: _disconnect,
                  onComments: _openComments,
                ),
        ),
      ],
    );
  }

  /// Comments on the Instagram account linked to a Facebook Page.
  Future<void> _openComments(_SocialConnection page) {
    return showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: 'Instagram comments',
        subtitle: page.displayName,
        child: InstagramCommentsPanel(
          api: widget.api,
          connectionId: page.id,
          canAnswer:
              widget.session.me?.permissions.contains('marketing.edit') ??
              false,
        ),
      ),
    );
  }

  Future<void> _openConnect(BuildContext context) {
    return showSidePanel<void>(
      context,
      builder: (context) => _ConnectPanel(
        api: widget.api,
        viewName: _viewName,
        providers: _providers,
        connected: {for (final c in _connections) c.provider},
        openConsent: widget.openConsent,
        onConnected: _load,
      ),
    );
  }

  /// Confirm before forgetting an account.
  ///
  /// A panel and not an `AlertDialog`. The house rule is "no modals, ever", and
  /// confirm-before-destructive is exactly the case it means — this was a real
  /// dialog until the coherency audit found it, and "but it is only a
  /// confirmation" is the argument every one of the five in the app was
  /// shipped on.
  Future<void> _disconnect(_SocialConnection page) async {
    final done = await confirmDisconnect(
      context,
      api: widget.api,
      title: 'Disconnect ${page.displayName}?',
      subtitle: page.providerLabel,
      explanation:
          'EzBizi forgets this ${page.providerLabel} account and its tokens. '
          'To revoke EzBizi at ${page.providerLabel}’s end as well, use that '
          'network’s own settings.',
      dependentsPath: '/integrations/social/${page.id}/dependents',
      perform: (code) => widget.api.deleteWithBody(
        '/social/connections/${page.id}',
        {'code': code},
      ),
    );

    if (done == null || !mounted) return;

    await _load();
  }
}

class _PostsPane extends StatelessWidget {
  const _PostsPane({
    required this.api,
    required this.session,
    required this.viewName,
    required this.connections,
    required this.posts,
    required this.rows,
    required this.search,
    required this.onSearch,
    required this.q,
    required this.tiles,
    required this.filter,
    required this.onFilter,
    required this.view,
    required this.stillWaiting,
    required this.pickMedia,
    required this.landing,
    required this.outlined,
    required this.focusDay,
    required this.calendarKey,
    required this.formats,
    required this.onSent,
    required this.onWriteAnother,
    required this.onSeeCalendar,
    required this.onDone,
    required this.onOpen,
    required this.onConnect,
    this.onNew,
    this.onAccounts,
    this.onRetry,
    this.onRetryPost,
  });

  final ApiClient api;
  final Session session;
  final String viewName;
  final List<_SocialConnection> connections;

  /// Every post the list carries, for the calendars.
  final List<_SentPost> posts;

  /// The timeline's posts: [posts], or the picked tile's.
  final List<_SentPost> rows;

  /// The timeline's search box, and what it last searched.
  final TextEditingController search;
  final ValueChanged<String> onSearch;
  final String q;
  final Map<String, int> tiles;
  final String? filter;
  final ValueChanged<String?> onFilter;

  /// The tab this is drawn for: `dashboard`, `compose` or `calendar`.
  final String view;

  /// The dashboard's New post: the Compose tab, or null without it.
  final VoidCallback? onNew;

  /// The Accounts tab, or null when this role is not shown it.
  final VoidCallback? onAccounts;
  final bool stillWaiting;
  final Future<PickedBytes?> Function() pickMedia;
  final _SentPost? landing;

  /// Try again on a failed account; null without the right to post.
  final void Function(_SentPost, _SentTarget)? onRetry;

  /// The timeline's Retry; null without the right to post.
  final void Function(_SentPost)? onRetryPost;
  final String? outlined;
  final DateTime? focusDay;
  final GlobalKey calendarKey;
  final DisplayFormats formats;
  final Future<void> Function(_SentPost) onSent;
  final VoidCallback onWriteAnother;
  final VoidCallback onSeeCalendar;
  final VoidCallback onDone;
  final void Function(_SentPost) onOpen;
  final VoidCallback onConnect;

  static const double _stackBelow = 900;

  /// Below this a month grid does not fit, so the calendar is an agenda and
  /// publishing takes the pane (design 27, pick 4).
  static const double _phoneBelow = 600;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < _phoneBelow;
        final stack = constraints.maxWidth < _stackBelow;
        final landing = this.landing;

        // Only on Compose: the tabs are above this pane now, and a tap on
        // Dashboard must open the dashboard, not the post still landing.
        if (phone && landing != null && view == 'compose') {
          return ListView(
            key: const ValueKey('tour-marketing-compose-outcome'),
            padding: const EdgeInsets.all(16),
            children: [
              _PostOutcome(
                post: landing,
                connections: connections,
                stillWaiting: stillWaiting,
                formats: formats,
                large: true,
                onWriteAnother: onWriteAnother,
                onDone: onDone,
                onRetry: onRetry == null
                    ? null
                    : (target) => onRetry!(landing, target),
              ),
            ],
          );
        }

        final compose = _ComposeColumn(
          api: api,
          session: session,
          viewName: viewName,
          connections: connections,
          stillWaiting: stillWaiting,
          pickMedia: pickMedia,
          landing: landing,
          formats: formats,
          onSent: onSent,
          onWriteAnother: onWriteAnother,
          onSeeCalendar: onSeeCalendar,
          onRetry: onRetry,
        );

        final dashboard = _PostsDashboard(
          session: session,
          viewName: viewName,
          connections: connections,
          rows: rows,
          search: search,
          onSearch: onSearch,
          q: q,
          tiles: tiles,
          filter: filter,
          onFilter: onFilter,
          stillWaiting: stillWaiting,
          formats: formats,
          onOpen: onOpen,
          onNew: onNew,
          onConnect: onConnect,
          onAccounts: onAccounts,
          onRetry: onRetryPost,
        );

        final Widget calendarView;
        if (phone) {
          calendarView = SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: _Agenda(
              key: calendarKey,
              posts: posts,
              outlined: outlined,
              formats: formats,
              onOpen: onOpen,
            ),
          );
        } else if (stack) {
          // Not a ListView: that builds lazily, so the calendar had no
          // context yet when Done asked to scroll to it.
          calendarView = SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: _CalendarsColumn(
              key: calendarKey,
              posts: posts,
              outlined: outlined,
              focusDay: focusDay,
              formats: formats,
              onOpen: onOpen,
            ),
          );
        } else {
          calendarView = Padding(
            padding: const EdgeInsets.all(12),
            child: _CalendarsColumn(
              key: calendarKey,
              fill: true,
              posts: posts,
              outlined: outlined,
              focusDay: focusDay,
              formats: formats,
              onOpen: onOpen,
            ),
          );
        }

        // All three kept: looking at the dashboard or the calendar must not
        // throw away a post half written.
        return IndexedStack(
          index: switch (view) {
            'compose' => 1,
            'calendar' => 2,
            _ => 0,
          },
          sizing: StackFit.expand,
          children: [dashboard, compose, calendarView],
        );
      },
    );
  }
}

/// Posts at a glance (UI queue 76, Jose's pick 2): the tiles, every post on
/// one timeline with each account's answer, and the accounts beside it.
///
/// Every number is one EzBizi holds. No likes, reach or followers: the Meta
/// review told Meta that EzBizi does not read them
/// (docs/meta-app-review-submission.md), so they need a new review first.
class _PostsDashboard extends StatelessWidget {
  const _PostsDashboard({
    required this.session,
    required this.viewName,
    required this.connections,
    required this.rows,
    required this.search,
    required this.onSearch,
    required this.q,
    required this.tiles,
    required this.filter,
    required this.onFilter,
    required this.stillWaiting,
    required this.formats,
    required this.onOpen,
    required this.onConnect,
    this.onNew,
    this.onAccounts,
    this.onRetry,
  });

  /// Where the List | Board choice is remembered, per person.
  final Session session;
  final String viewName;
  final List<_SocialConnection> connections;
  final List<_SentPost> rows;
  final TextEditingController search;
  final ValueChanged<String> onSearch;
  final String q;
  final Map<String, int> tiles;
  final String? filter;
  final ValueChanged<String?> onFilter;

  /// The queue stopped answering before anything left `queued`. Said out loud
  /// rather than drawn as an indefinite wait — see `_watchPending`.
  final bool stillWaiting;
  final DisplayFormats formats;
  final void Function(_SentPost) onOpen;
  final VoidCallback? onNew;
  final VoidCallback onConnect;
  final VoidCallback? onAccounts;

  /// Null without the right to post: a failure then opens, nothing more.
  final void Function(_SentPost)? onRetry;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final timeline = _ListOrBoard(
          session: session,
          list: _timeline(context, phone: box.maxWidth < 600),
          board: () => _board(context),
        );
        final accounts = _accounts(context);

        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (stillWaiting) ...[
              const DashboardNote(
                'Something is still waiting to send and the queue has stopped '
                'answering. Nothing is lost — but nothing is going out either '
                'until somebody looks at the send queue.',
              ),
              const SizedBox(height: 12),
            ],
            _tiles(),
            const SizedBox(height: 12),
            if (box.maxWidth >= 1000)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(child: timeline),
                  const SizedBox(width: 12),
                  SizedBox(width: 340, child: accounts),
                ],
              )
            else ...[
              timeline,
              const SizedBox(height: 12),
              accounts,
            ],
          ],
        );
      },
    );
  }

  Widget _tiles() {
    final count = connections.length;
    final cannot = connections.where((c) => !c.canPublish).length;

    return FilterTiles(
      key: const ValueKey('tour-marketing-social-tiles'),
      prefix: 'social-posts',
      picked: filter,
      onPick: onFilter,
      tiles: [
        // Every post at once (UI queue 100).
        FilterTile(
          key: null,
          label: 'All',
          count: tiles.values.fold<int>(0, (a, b) => a + b),
          note: 'every post',
        ),
        FilterTile(
          key: 'scheduled',
          label: 'Scheduled',
          count: tiles['scheduled'],
          note: 'waiting for their time',
          tone: Tone.info,
        ),
        FilterTile(
          key: 'sent',
          label: 'Sent',
          count: tiles['sent'],
          note: 'went out',
          tone: Tone.ok,
        ),
        FilterTile(
          key: 'failed',
          label: 'Failed',
          count: tiles['failed'],
          note: 'an account refused it',
          tone: Tone.bad,
        ),
      ],
      facts: [
        FactTile(
          key: const Key('social-posts-fact-pages'),
          label: 'Pages',
          value: '$count',
          note: count == 0
              ? 'none connected yet'
              : cannot > 0
              ? '$cannot cannot post yet'
              : count == 1
              ? 'it can post'
              : 'all $count can post',
        ),
      ],
    );
  }

  /// What is coming, then each day's posts, newest day first.
  Widget _timeline(BuildContext context, {required bool phone}) {
    int byTime(_SentPost a, _SentPost b) =>
        (a.when ?? DateTime(0)).compareTo(b.when ?? DateTime(0));

    final ahead = [
      for (final post in rows)
        if (post.tile == 'scheduled') post,
    ]..sort(byTime);
    final byDay = <DateTime, List<_SentPost>>{};
    for (final post in rows) {
      if (post.tile == 'scheduled') continue;
      final when = post.when ?? DateTime(0);
      byDay
          .putIfAbsent(DateTime(when.year, when.month, when.day), () => [])
          .add(post);
    }
    for (final list in byDay.values) {
      list.sort((a, b) => byTime(b, a));
    }
    final days = byDay.keys.toList()..sort((a, b) => b.compareTo(a));

    String heading(DateTime day) => day.year == 0
        ? 'Earlier'
        : switch (_daysFromToday(day)) {
            0 => 'Today',
            -1 => 'Yesterday',
            _ => _shortDay(day),
          };

    final field = TextField(
      key: const Key('social-posts-search'),
      controller: search,
      onChanged: onSearch,
      // bodyMedium for the text and the hint, as the dense pickers draw:
      // 53 points tall, as they are; the theme's 16-point hint makes 56.
      style: Theme.of(context).textTheme.bodyMedium,
      decoration: InputDecoration(
        isDense: true,
        prefixIcon: const Icon(Icons.search, size: 18),
        hintText: 'What it said, who or where',
        hintStyle: Theme.of(context).textTheme.bodyMedium?.copyWith(
          color: Theme.of(context).inputDecorationTheme.hintStyle?.color,
        ),
      ),
    );
    final newPost = onNew == null
        ? null
        : FilledButton.icon(
            key: const ValueKey('tour-marketing-new-post'),
            onPressed: onNew,
            icon: const Icon(Icons.add, size: 18),
            label: const Text('New post'),
          );

    return DashboardCard(
      key: const ValueKey('tour-marketing-timeline'),
      title: 'Timeline',
      icon: Icons.timeline,
      // A phone has no room beside the title; the box sits above the rows.
      controls: phone
          ? newPost
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                SizedBox(width: 240, child: field),
                if (newPost != null) ...[const SizedBox(width: 8), newPost],
              ],
            ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (phone) ...[field, const SizedBox(height: 8)],
          if (rows.isEmpty)
            DashboardNote(
              q.isNotEmpty
                  ? 'No post matches "$q".'
                  : filter == null
                  ? 'Nothing posted or scheduled yet. What you publish is '
                        'listed here.'
                  : 'No posts here.',
            )
          else
            Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (ahead.isNotEmpty) ...[
                  _DayHeading('Coming up'),
                  for (final post in ahead) _row(post, phone: phone),
                ],
                for (final day in days) ...[
                  _DayHeading(heading(day)),
                  for (final post in byDay[day]!) _row(post, phone: phone),
                ],
              ],
            ),
        ],
      ),
    );
  }

  /// The same posts in four columns (UI queue 100). A part-sent post sits
  /// under Posted, its pill saying how much of it went out.
  Widget _board(BuildContext context) {
    String column(_SentPost p) {
      final statuses = p.targets.map((t) => t.status).toSet();
      if (statuses.contains('queued')) return 'posting';
      if (statuses.isNotEmpty && statuses.every((s) => s == 'scheduled')) {
        return 'scheduled';
      }
      if (statuses.contains('sent')) return 'posted';
      return statuses.contains('failed') ? 'failed' : 'posted';
    }

    const columns = [
      ('scheduled', 'Scheduled'),
      ('posting', 'Posting'),
      ('posted', 'Posted'),
      ('failed', 'Failed'),
    ];
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, box) {
        // ponytail: columns never narrower than a row needs; past four the
        // board scrolls sideways.
        final width = ((box.maxWidth - 36) / 4).clamp(260.0, double.infinity);
        return SingleChildScrollView(
          key: const ValueKey('social-posts-board'),
          scrollDirection: Axis.horizontal,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              for (final (key, label) in columns) ...[
                if (key != 'scheduled') const SizedBox(width: 12),
                SizedBox(
                  width: width,
                  child: DashboardCard(
                    key: ValueKey('social-board-$key'),
                    icon: switch (key) {
                      'scheduled' => Icons.schedule,
                      'posting' => Icons.hourglass_top,
                      'posted' => Icons.check_circle_outline,
                      _ => Icons.error_outline,
                    },
                    title:
                        '$label · ${rows.where((p) => column(p) == key).length}',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (final post in rows)
                          if (column(post) == key)
                            _row(post, phone: true),
                        if (!rows.any((p) => column(p) == key))
                          Text('None', style: theme.textTheme.bodySmall),
                      ],
                    ),
                  ),
                ),
              ],
            ],
          ),
        );
      },
    );
  }

  Widget _row(_SentPost post, {required bool phone}) {
    final retry = onRetry;
    final retryable =
        retry != null &&
        post.targets.any((t) => t.status == 'failed' && t.id != null);

    return _TimelineRow(
      post: post,
      formats: formats,
      phone: phone,
      onOpen: () => onOpen(post),
      onRetry: retryable ? () => retry(post) : null,
    );
  }

  /// Each page: whether it can post, and why not when it cannot.
  Widget _accounts(BuildContext context) {
    final theme = Theme.of(context);
    final count = connections.length;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return DashboardCard(
      key: const ValueKey('tour-marketing-social-accounts-card'),
      title: 'Accounts',
      icon: Icons.link_outlined,
      controls: TextButton(onPressed: onConnect, child: const Text('Connect')),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            count == 0
                ? 'No pages connected in $viewName yet'
                : count == 1
                ? '1 page connected'
                : '$count pages connected',
            style: muted,
          ),
          for (final page in connections)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SocialNetworkMark(network: page.provider, size: 20),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          page.displayName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        Text(
                          page.canPublish || page.cannotPublishBecause == null
                              ? page.providerLabel
                              : '${page.providerLabel} · '
                                    '${page.cannotPublishBecause}',
                          style: muted,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  page.canPublish
                      ? const StatusPill('Can post', tone: Tone.ok)
                      : const StatusPill('Cannot post', tone: Tone.neutral),
                ],
              ),
            ),
          if (onAccounts != null && count > 0) ...[
            const SizedBox(height: 6),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton(
                onPressed: onAccounts,
                child: const Text('Manage accounts'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// A post's pill: "Failed"; "Partly posted · 2 of 3" when some accounts took
/// it and others refused (UI queue 100); "1 failed" while the rest wait.
(String, Tone) _statusOf(_SentPost post) {
  final total = post.targets.length;
  final failed = post.targets.where((t) => t.status == 'failed').length;
  final sent = post.targets.where((t) => t.status == 'sent').length;
  if (failed > 0) {
    if (failed == total) return ('Failed', Tone.bad);
    if (sent + failed == total) {
      return ('Partly posted · $sent of $total', Tone.caution);
    }
    return ('$failed failed', Tone.bad);
  }

  return switch (post.tone) {
    Tone.neutral => ('Posting $sent of $total', Tone.neutral),
    Tone.info => ('Scheduled', Tone.info),
    _ => ('Sent', Tone.ok),
  };
}

/// List or Board, remembered per person (UI queue 100).
class _ListOrBoard extends StatefulWidget {
  const _ListOrBoard({
    required this.session,
    required this.list,
    required this.board,
  });

  static const preference = 'marketing.social.posts_view';

  final Session session;
  final Widget list;
  final Widget Function() board;

  @override
  State<_ListOrBoard> createState() => _ListOrBoardState();
}

class _ListOrBoardState extends State<_ListOrBoard> {
  late bool _board =
      widget.session.me?.preferences[_ListOrBoard.preference] == 'board';

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: AlignmentDirectional.centerEnd,
          child: SegmentedButton<bool>(
            key: const ValueKey('social-posts-view'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(
                value: false,
                icon: Icon(Icons.view_list_outlined, size: 18),
                label: Text('List'),
              ),
              ButtonSegment(
                value: true,
                icon: Icon(Icons.view_kanban_outlined, size: 18),
                label: Text('Board'),
              ),
            ],
            selected: {_board},
            onSelectionChanged: (picked) {
              setState(() => _board = picked.single);
              widget.session.setPreference(
                _ListOrBoard.preference,
                _board ? 'board' : 'list',
              );
            },
          ),
        ),
        const SizedBox(height: 8),
        _board ? widget.board() : widget.list,
      ],
    );
  }
}

class _DayHeading extends StatelessWidget {
  const _DayHeading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(top: 10, bottom: 2),
      child: Text(
        text.toUpperCase(),
        style: theme.textTheme.labelSmall?.copyWith(
          letterSpacing: 0.8,
          fontWeight: FontWeight.w700,
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }
}

/// One post on the timeline: when, its words, and every account's answer as
/// a dot — a refusal in the network's own words, two lines of it, under the
/// accounts that took it.
class _TimelineRow extends StatelessWidget {
  const _TimelineRow({
    required this.post,
    required this.formats,
    required this.phone,
    required this.onOpen,
    this.onRetry,
  });

  final _SentPost post;
  final DisplayFormats formats;

  /// The time above the words rather than in a column of its own.
  final bool phone;
  final VoidCallback onOpen;
  final VoidCallback? onRetry;

  static Tone _toneOf(_SentTarget target) => switch (target.status) {
    'failed' => Tone.bad,
    'scheduled' => Tone.info,
    'queued' => Tone.neutral,
    _ => Tone.ok,
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final when = post.when;
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final bad = ToneColours.of(context, Tone.bad).ink;
    final failed = [
      for (final t in post.targets)
        if (t.status == 'failed') t,
    ];
    String name(_SentTarget t) => t.account.isEmpty ? t.providerLabel : t.account;

    Widget dot(Tone tone) => Container(
      width: 8,
      height: 8,
      decoration: BoxDecoration(
        color: ToneColours.of(context, tone).ink,
        shape: BoxShape.circle,
      ),
    );

    // A promise is said as it was made: the wall time the server filed and
    // the clock it is on, never re-read through this device's own.
    final wall = post.scheduledFor;
    final String time;
    if (wall != null && post.tile == 'scheduled') {
      time = 'Goes out $wall${clockSuffix(post.scheduledTimezone)}';
    } else if (when == null) {
      time = '';
    } else {
      time = formats.formatTime(when.hour, when.minute);
    }
    final (status, tone) = _statusOf(post);

    return InkWell(
      key: ValueKey('social-post-${post.id}'),
      onTap: onOpen,
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8),
        decoration: BoxDecoration(
          border: Border(top: BorderSide(color: EzBiziTheme.edge(context))),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (!phone) ...[
              SizedBox(
                width: 132,
                child: Text(time, maxLines: 3, style: small),
              ),
              const SizedBox(width: 10),
            ],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (phone && time.isNotEmpty) Text(time, style: small),
                  Text(
                    post.preview,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Wrap(
                    spacing: 12,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      // The word as well as the dots: a colour alone says
                      // nothing to somebody who cannot tell red from green.
                      StatusPill(status, tone: tone),
                      for (final target in post.targets)
                        if (target.status != 'failed')
                          Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              dot(_toneOf(target)),
                              const SizedBox(width: 5),
                              Flexible(
                                child: Text(
                                  name(target),
                                  overflow: TextOverflow.ellipsis,
                                  style: small,
                                ),
                              ),
                            ],
                          ),
                    ],
                  ),
                  // How far a post going out has got (UI queue 100).
                  if (post.tone == Tone.neutral && post.targets.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 6, right: 12),
                      child: LinearProgressIndicator(
                        key: ValueKey('social-post-progress-${post.id}'),
                        value:
                            post.targets
                                .where((t) => t.status == 'sent')
                                .length /
                            post.targets.length,
                        minHeight: 4,
                        borderRadius: BorderRadius.circular(2),
                      ),
                    ),
                  // The failure sentence is the server's. A status word on
                  // its own says something is wrong and nothing about what to
                  // do; Open shows the rest of a long one.
                  for (final target in failed)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Padding(
                            padding: const EdgeInsets.only(top: 5),
                            child: dot(Tone.bad),
                          ),
                          const SizedBox(width: 5),
                          Expanded(
                            child: Text(
                              target.failure == null
                                  ? '${name(target)} · refused it'
                                  : '${name(target)} · ${target.failure}',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: bad,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            if (onRetry != null)
              OutlinedButton(
                key: ValueKey('social-retry-${post.id}'),
                onPressed: onRetry,
                child: const Text('Retry'),
              )
            else
              Icon(
                Icons.chevron_right,
                color: theme.colorScheme.onSurfaceVariant,
              ),
          ],
        ),
      ),
    );
  }
}

/// Longest post each network takes, in characters (Mia, 2026-10-02). One map
/// so a network's change is one line here.
///
/// Client-side only, and deliberately so: the server checks one 5,000 ceiling
/// (SocialPostsController::MAX_BODY) because most of these networks have no
/// publisher yet. This is the warning before Publish, in the network's own
/// number. A network missing here is counted without a limit.
const Map<String, int> _bodyLimits = {
  'x': 280,
  'bluesky': 300,
  'linkedin': 3000,
  'instagram_professional': 2200,
  'google_business_profile': 1500,
  'facebook_page': 63206,
};

/// Characters as a person counts them: an emoji is one, not two code units.
int _lengthOf(String text) => text.characters.length;

/// Write once, tick the accounts, send — and beside it, how it will look.
///
/// ── Why the accounts are ticked here and not in the bar above ───
///
/// The bar answers "what may this view post as". This answers "where is THIS
/// post going", which changes per post — the same three accounts are the right
/// answer today and the wrong one tomorrow. Putting the ticks up there would
/// make a per-post choice look like a property of the account.
class _ComposeColumn extends StatefulWidget {
  const _ComposeColumn({
    required this.api,
    required this.session,
    required this.viewName,
    required this.connections,
    required this.stillWaiting,
    required this.pickMedia,
    required this.landing,
    required this.formats,
    required this.onSent,
    required this.onWriteAnother,
    required this.onSeeCalendar,
    this.onRetry,
  });

  final ApiClient api;
  final Session session;
  final String viewName;
  final List<_SocialConnection> connections;
  final bool stillWaiting;
  final Future<PickedBytes?> Function() pickMedia;
  final void Function(_SentPost, _SentTarget)? onRetry;

  /// The post just published. While set, the composer IS the result
  /// (design 27, pick 1): its accounts resolving, then what happened.
  final _SentPost? landing;
  final DisplayFormats formats;
  final Future<void> Function(_SentPost) onSent;
  final VoidCallback onWriteAnother;
  final VoidCallback onSeeCalendar;

  @override
  State<_ComposeColumn> createState() => _ComposeColumnState();
}

class _ComposeColumnState extends State<_ComposeColumn> {
  final TextEditingController _body = TextEditingController();
  final Set<String> _picked = <String>{};

  /// Business Profile listings, posted to beside the accounts (A1 takeover,
  /// "compose once for many locations"). A listing Google will not take a
  /// post on (suspended, unverified) starts unticked and says why.
  List<Map<String, dynamic>> _places = const [];
  final Set<String> _pickedPlaces = <String>{};

  /// The listing's button: none, or BOOK / ORDER / SHOP / LEARN_MORE /
  /// SIGN_UP / CALL. Only asked while a listing is ticked.
  String? _cta;
  final TextEditingController _ctaUrl = TextEditingController();

  /// A picture or video from the library (UI queue 105), sent as its id
  /// instead of an upload. Either this or [_media], never both.
  Map<String, dynamic>? _libraryItem;

  Future<void> _fromLibrary() async {
    final item = await showMediaLibraryPanel(
      context,
      api: widget.api,
      pickMedia: widget.pickMedia,
      canEdit: widget.session.me?.can('marketing.edit') ?? false,
    );
    if (item == null || !mounted) return;
    setState(() {
      _releaseMedia();
      _media = null;
      _libraryItem = item;
    });
  }

  @override
  void initState() {
    super.initState();
    _loadPlaces();
  }

  Future<void> _loadPlaces() async {
    final response = await widget.api.get('/social/places');
    if (!mounted || !response.ok) return;
    setState(
      () => _places = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      ),
    );
  }

  /// Per ticked TikTok account, what the person chose (see TikTokChoice).
  final Map<String, TikTokChoice> _tiktok = <String, TikTokChoice>{};
  PickedBytes? _media;
  bool _sending = false;
  String? _error;

  /// A file is held over the composer (UI queue 76): the pane says where it
  /// will land. [_dropRefused] is why the last drop attached nothing.
  bool _dropping = false;
  String? _dropRefused;

  /// Object URL for the picked video, and the player over it. Both live only
  /// as long as the attachment does — see `_clearMedia`.
  String? _mediaUrl;
  VideoPlayerController? _player;

  /// Wall clock in this view's timezone, `YYYY-MM-DD HH:MM`, empty for "now".
  final TextEditingController _when = TextEditingController();
  bool _scheduling = false;

  /// The ticked accounts, one per network: the counter and the preview are
  /// about the network's rules, which two pages on it share.
  List<_SocialConnection> get _tickedNetworks {
    final seen = <String>{};

    return [
      for (final c in widget.connections)
        if (_picked.contains(c.id) && seen.add(c.provider)) c,
    ];
  }

  String get _words => _body.text.trim();

  /// The first ticked network this post is too long for, said with its
  /// number and the two ways out.
  String? get _overLimit {
    final length = _lengthOf(_words);

    for (final account in _tickedNetworks) {
      final limit = _bodyLimits[account.provider];
      if (limit != null && length > limit) {
        return '${account.providerLabel} takes $limit characters at most and '
            'this is $length. Shorten it, or untick ${account.providerLabel}.';
      }
    }

    return null;
  }

  @override
  void dispose() {
    _body.dispose();
    _when.dispose();
    _ctaUrl.dispose();
    _releaseMedia();
    super.dispose();
  }

  /// Let go of the player and the object URL behind it.
  ///
  /// A blob is held until revoked, so picking three videos while deciding
  /// would otherwise keep all three in memory for the life of the page.
  void _releaseMedia() {
    _player?.dispose();
    _player = null;

    final url = _mediaUrl;
    if (url != null) Files.releasePreviewUrl(url);
    _mediaUrl = null;
  }

  /// Why Publish cannot be pressed, or null when it can.
  ///
  /// One sentence at a time and in the order somebody hits them, rather than a
  /// list of everything wrong at once: told three things, a person fixes the
  /// first and is told the other two anyway.
  ///
  /// The permission comes first because it is the one nothing on this screen
  /// can fix — ticking accounts and attaching a picture would all still end in
  /// a 403, and offering a control that always refuses is the §4 failure.
  String? get _refusal {
    final unpermitted = widget.session.me?.refusal(
      'marketing.edit',
      'publish to a social account',
    );

    if (unpermitted != null) return unpermitted;

    if (widget.connections.isEmpty && _places.isEmpty) {
      return 'No account is connected in ${widget.viewName}, so there is '
          'nowhere to publish.';
    }
    if (_places.isEmpty && !widget.connections.any((c) => c.canPublish)) {
      return 'None of the accounts connected in ${widget.viewName} can be '
          'posted to from EzBizi yet.';
    }
    if (_picked.isEmpty && _pickedPlaces.isEmpty) {
      return 'Tick at least one account or listing to post to.';
    }
    if (_pickedPlaces.isNotEmpty &&
        _cta != null &&
        _cta != 'CALL' &&
        !_ctaUrl.text.trim().startsWith('https://')) {
      return "The listing's button needs a web address starting https://.";
    }
    if (_body.text.trim().isEmpty && _media == null && _libraryItem == null) {
      return 'Write something, or attach a picture or a video.';
    }
    final over = _overLimit;
    if (over != null) return over;
    for (final account in widget.connections) {
      final choice = _tiktok[account.id];
      if (choice == null || !_picked.contains(account.id)) continue;
      final why = choice.refusal(
        account.displayName,
        _player?.value.duration,
      );
      if (why != null) return why;
    }
    if (_sending) return 'Sending.';

    return null;
  }

  /// What the button will do, said on the button.
  ///
  /// "Publish now" on a post that will sit until Tuesday is the same class of
  /// lie as a spinner over a failed send — it is read once, believed, and not
  /// checked again.
  String get _sendLabel {
    if (_sending) return 'Sending…';

    return _scheduling && _when.text.trim().isNotEmpty ? 'Schedule' : 'Publish now';
  }

  Widget _scheduleRow(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Now / Later as two chips (Mia, 2026-10-02, layout A). Later
        // opens the time; Now forgets it, so a time typed and abandoned is
        // never sent.
        Wrap(
          key: const ValueKey('tour-marketing-compose-when'),
          spacing: 8,
          children: [
            ChoiceChip(
              label: const Text('Now'),
              selected: !_scheduling,
              onSelected: _sending
                  ? null
                  : (_) => setState(() {
                      _scheduling = false;
                      _when.clear();
                    }),
            ),
            ChoiceChip(
              label: const Text('Later'),
              selected: _scheduling,
              onSelected: _sending
                  ? null
                  : (_) => setState(() => _scheduling = true),
            ),
          ],
        ),
        if (_scheduling) ...[
          const SizedBox(height: 8),
          // CalendarDayField and not showDatePicker: those are modal dialogs,
          // and this app has none. Its calendar opens under the boxes the way a
          // menu does, and it already speaks the `YYYY-MM-DD HH:MM` the server
          // wants — so the wall time the person reads is the wall time sent.
          CalendarDayField(
            controller: _when,
            formats: widget.session.me?.display ?? DisplayFormats.fallback,
            label: 'Send at',
            withTime: true,
            enabled: !_sending,
            firstDate: DateTime.now(),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 6),
          // Same correction as the newsletter composer, and for the same
          // reason: "the time it goes out there" names the PLACE, and above
          // franchise scope the clock is the platform default fixed offset,
          // which is an hour off that place from March to November. The
          // scheduled post names the clock it actually got.
          DashboardNote(
            'This view’s clock — ${widget.viewName}. No timezone is sent: the '
            'server files the time against this view’s own clock, and the '
            'scheduled post names which one.',
          ),
        ],
      ],
    );
  }

  /// Attach what was picked, or [dropped] onto the composer.
  Future<void> _attach({PickedBytes? dropped}) async {
    final picked = dropped ?? await widget.pickMedia();
    if (picked == null || !mounted) return;

    _releaseMedia();

    setState(() {
      _media = picked;
      _dropRefused = null;
    });

    if (_looksLikeImage(picked.name)) return;

    // Video: hand the bytes to the browser as a blob and play them from there.
    // Nothing is uploaded — the file is being looked at precisely because
    // nobody is sure yet that it is the right one.
    final url = Files.previewUrl(picked.bytes, _mimeFor(picked.name));
    if (url == null || !mounted) return;

    final player = VideoPlayerController.networkUrl(Uri.parse(url));

    try {
      await player.initialize();
    } catch (_) {
      // A container the browser will not decode. The placeholder below says
      // what the file is; claiming a broken player is worse than saying less.
      player.dispose();
      if (mounted) Files.releasePreviewUrl(url);
      return;
    }

    if (!mounted) {
      player.dispose();
      Files.releasePreviewUrl(url);
      return;
    }

    setState(() {
      _mediaUrl = url;
      _player = player;
    });
  }

  /// Read what this TikTok account allows, for its panel. Asked fresh on
  /// every tick: TikTok's guidelines want the current answer, not a cached one.
  Future<void> _askTikTok(String id) async {
    final choice = TikTokChoice();
    _tiktok[id] = choice;

    final response = await widget.api.get('/social/connections/$id/tiktok-creator');
    if (!mounted || _tiktok[id] != choice) return;

    setState(() {
      if (response.ok) {
        choice.creator = TikTokCreator.fromJson(
          response.body['data'] as Map<String, dynamic>? ?? const {},
        );
      } else {
        choice.loadError = response.reason;
      }
    });
  }

  Future<void> _publish() async {
    setState(() {
      _sending = true;
      _error = null;
    });

    final when = _scheduling ? _when.text.trim() : '';

    final fields = <String, String>{
      'body': _body.text.trim(),
      if (when.isNotEmpty) 'scheduled_for': when,
      // Indexed rather than repeated: a multipart body carries flat name/value
      // pairs, so `connections[]` three times would arrive as one value.
      for (final (index, id) in _picked.indexed) 'connections[$index]': id,
      for (final id in _picked) ...?_tiktok[id]?.fields(id),
      for (final (index, id) in _pickedPlaces.indexed) 'places[$index]': id,
      if (_pickedPlaces.isNotEmpty && _cta != null) 'cta_type': _cta!,
      if (_pickedPlaces.isNotEmpty && _cta != null && _cta != 'CALL')
        'cta_url': _ctaUrl.text.trim(),
    };

    final media = _media;

    final response = media == null
        ? await widget.api.post('/social/posts', {
            'body': fields['body'],
            'connections': _picked.toList(),
            'places': _pickedPlaces.toList(),
            if (_libraryItem != null) 'library_item': _libraryItem!['id'],
            if (_pickedPlaces.isNotEmpty && _cta != null) 'cta_type': _cta,
            if (_pickedPlaces.isNotEmpty && _cta != null && _cta != 'CALL')
              'cta_url': _ctaUrl.text.trim(),
            if (when.isNotEmpty) 'scheduled_for': when,
          })
        : await widget.api.postFile(
            '/social/posts',
            'media',
            media.name,
            media.bytes,
            fields: fields,
          );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _sending = false;
        _error = response.reason;
      });
      return;
    }

    // What the POST answered, drawn at once: every account still queued.
    final data = response.body['data'] as Map<String, dynamic>? ?? const {};
    final posted = _SentPost(
      id: data['id'] as String? ?? '',
      body: _body.text.trim(),
      hasMedia: media != null,
      scheduledFor: when.isEmpty ? null : when,
      createdAt: DateTime.now(),
      targets: ((data['targets'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SentTarget.fromJson)
          .toList(),
    );

    // Cleared only on success. A refusal leaves the words and the picture
    // exactly where they were, because the fix is usually one tick or one
    // attachment away and retyping the post is not part of it.
    setState(() {
      _sending = false;
      _body.clear();
      _releaseMedia();
      _media = null;
      _picked.clear();
      _pickedPlaces.clear();
      _libraryItem = null;
      _cta = null;
      _ctaUrl.clear();
      _tiktok.clear();
      _when.clear();
      _scheduling = false;
    });

    await widget.onSent(posted);
  }

  @override
  Widget build(BuildContext context) {
    final landing = widget.landing;
    if (landing != null) {
      final outcome = DashboardCard(
        key: const ValueKey('tour-marketing-compose-outcome'),
        title: 'New post',
        icon: Icons.edit_outlined,
        child: _PostOutcome(
          post: landing,
          connections: widget.connections,
          stillWaiting: widget.stillWaiting,
          formats: widget.formats,
          large: false,
          onWriteAnother: widget.onWriteAnother,
          onSeeCalendar: widget.onSeeCalendar,
          onRetry: widget.onRetry == null
              ? null
              : (target) => widget.onRetry!(landing, target),
        ),
      );
      return SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: outcome,
      );
    }

    final refusal = _refusal;
    final theme = Theme.of(context);
    final networks = _tickedNetworks;
    final unable = [
      for (final c in widget.connections)
        if (!c.canPublish && c.cannotPublishBecause != null) c,
    ];
    final when = _when.text.trim();

    Widget label(String text) => Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Text(
        text,
        style: theme.textTheme.labelMedium?.copyWith(
          fontWeight: FontWeight.w700,
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );

    final composer = DashboardCard(
      key: const ValueKey('tour-marketing-compose-form'),
      title: 'New post',
      icon: Icons.edit_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.connections.isNotEmpty) ...[
            label('Post to'),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final account in widget.connections)
                  Refused(
                    reason: account.cannotPublishBecause,
                    child: FilterChip(
                      key: ValueKey('social-account-${account.id}'),
                      showCheckmark: false,
                      avatar: SocialNetworkMark(
                        network: account.provider,
                        size: 16,
                      ),
                      label: Text(
                        account.displayName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      selected: _picked.contains(account.id),
                      onSelected: !account.canPublish || _sending
                          ? null
                          : (on) => setState(() {
                              on
                                  ? _picked.add(account.id)
                                  : _picked.remove(account.id);
                              if (on && account.asksTikTokChoices) {
                                _askTikTok(account.id);
                              } else if (!on) {
                                _tiktok.remove(account.id);
                              }
                            }),
                    ),
                  ),
              ],
            ),
            // A dead chip says why on the screen, not only on a long press.
            for (final account in unable)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  '${account.displayName}: ${account.cannotPublishBecause}',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
            // The note goes here, by the account, BEFORE anybody presses
            // Publish. "Sent" and "sent, but you must go and finish it in
            // TikTok" are different enough that learning it afterwards is
            // learning it too late.
            for (final account in widget.connections)
              if (account.postingNote != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    '${account.displayName}: ${account.postingNote}',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
            const SizedBox(height: 14),
          ],
          if (_places.isNotEmpty) ...[
            label('Business Profile listings'),
            // Which listing each is, grouped by branch (Jose, 2026-10-19:
            // "They should have their appropiate names like they do in Leads
            // Manage Connections").
            for (final (i, branch) in {
              for (final p in _places) '${p['branch'] ?? ''}',
            }.indexed) ...[
              if (branch.isNotEmpty)
                Padding(
                  padding: EdgeInsets.only(top: i == 0 ? 0 : 8, bottom: 4),
                  child: Text(branch, style: theme.textTheme.labelMedium),
                ),
            Wrap(
              key: i == 0 ? const ValueKey('social-places') : null,
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final place in _places)
                  if ('${place['branch'] ?? ''}' == branch)
                  FilterChip(
                    key: ValueKey('social-place-${place['id']}'),
                    showCheckmark: false,
                    avatar: const SocialNetworkMark(
                      network: 'google_business_profile',
                      size: 16,
                    ),
                    label: Text(
                      place['postable'] == false
                          ? '${place['label'] ?? place['title']} · ${place['state'] ?? 'cannot post'}'
                          : '${place['label'] ?? place['title']}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    tooltip: place['address'] as String?,
                    selected: _pickedPlaces.contains(place['id']),
                    onSelected: _sending
                        ? null
                        : (on) => setState(
                            () => on
                                ? _pickedPlaces.add('${place['id']}')
                                : _pickedPlaces.remove(place['id']),
                          ),
                  ),
              ],
            ),
            ],
            if (_pickedPlaces.isNotEmpty) ...[
              const SizedBox(height: 10),
              Wrap(
                spacing: 12,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  SizedBox(
                    width: 200,
                    child: SearchPickerField<String?>(
                      key: const ValueKey('social-place-cta'),
                      value: _cta,
                      isDense: true,
                      hint: 'No button',
                      options: const [
                        SearchOption(value: null, label: 'No button'),
                        SearchOption(value: 'BOOK', label: 'Book'),
                        SearchOption(value: 'ORDER', label: 'Order'),
                        SearchOption(value: 'SHOP', label: 'Shop'),
                        SearchOption(value: 'LEARN_MORE', label: 'Learn more'),
                        SearchOption(value: 'SIGN_UP', label: 'Sign up'),
                        SearchOption(value: 'CALL', label: 'Call'),
                      ],
                      onChanged: (v) => setState(() => _cta = v),
                    ),
                  ),
                  if (_cta != null && _cta != 'CALL')
                    SizedBox(
                      width: 320,
                      child: TextField(
                        key: const ValueKey('social-place-cta-url'),
                        controller: _ctaUrl,
                        enabled: !_sending,
                        keyboardType: TextInputType.url,
                        onChanged: (_) => setState(() {}),
                        decoration: const InputDecoration(
                          isDense: true,
                          labelText: 'Where the button goes (https://...)',
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                'Listings take words and pictures, not video.',
                style: theme.textTheme.bodySmall,
              ),
            ],
            const SizedBox(height: 14),
          ],
          label('Words'),
          TextField(
            controller: _body,
            enabled: !_sending,
            minLines: 8,
            maxLines: 16,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              hintText: 'What should go out?',
              alignLabelWithHint: true,
            ),
          ),
          if (networks.isNotEmpty) ...[
            const SizedBox(height: 6),
            _counter(context, networks),
          ],
          const SizedBox(height: 14),
          label('Picture or video'),
          _attachment(context),
          for (final account in widget.connections)
            if (_picked.contains(account.id) && _tiktok[account.id] != null) ...[
              const SizedBox(height: 14),
              TikTokPostChoices(
                account: account.displayName,
                choice: _tiktok[account.id]!,
                enabled: !_sending,
                onChanged: () => setState(() {}),
              ),
            ],
          const SizedBox(height: 14),
          label('When'),
          _scheduleRow(context),
        ],
      ),
    );

    final preview = _PreviewCard(
      networks: networks,
      body: _words,
      media: _media,
      when: _scheduling && when.isNotEmpty ? 'Scheduled $when' : 'Now',
    );

    // Publish stays in sight however long the post (UI queue 76): the bar
    // under the scrolling form says where it goes and when, beside the button.
    final picked = _picked.length;
    final bar = DecoratedBox(
      key: const ValueKey('tour-marketing-compose-bar'),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border(top: BorderSide(color: EzBiziTheme.edge(context))),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_error != null) ...[
              DashboardNote(_error!),
              const SizedBox(height: 8),
            ],
            Row(
              children: [
                Expanded(
                  child: Text(
                    picked == 0
                        ? 'No account ticked yet'
                        : '${picked == 1 ? 'Going to 1 account' : 'Going to $picked accounts'}'
                              '${_scheduling && when.isNotEmpty ? ' · $when' : ' · now'}',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Refused(
                  reason: refusal,
                  child: FilledButton(
                    onPressed: refusal == null ? _publish : null,
                    child: Text(_sendLabel),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );

    final form = LayoutBuilder(
      builder: (context, box) {
        if (box.maxWidth < _PostsPane._stackBelow) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [composer, const SizedBox(height: 12), preview],
          );
        }

        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(flex: 4, child: composer),
            const SizedBox(width: 12),
            Expanded(flex: 5, child: preview),
          ],
        );
      },
    );

    final pane = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: form,
          ),
        ),
        bar,
      ],
    );

    // A picture or video dropped anywhere on the composer attaches as Attach
    // does, the way Suggestions takes files (UI queue 76).
    return DropTarget(
      enable: !_sending,
      onDragEntered: (_) => setState(() => _dropping = true),
      onDragExited: (_) => setState(() => _dropping = false),
      onDragDone: (details) => _drop(details.files),
      child: Stack(
        children: [
          Positioned.fill(child: pane),
          if (_dropping)
            Positioned.fill(
              child: IgnorePointer(
                child: Container(
                  key: const Key('social-drop-hint'),
                  margin: const EdgeInsets.all(12),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: theme.colorScheme.primaryContainer.withValues(
                      alpha: 0.85,
                    ),
                    border: Border.all(
                      color: theme.colorScheme.primary,
                      width: 2,
                    ),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Text(
                    'Drop a picture or video to attach it',
                    style: theme.textTheme.titleMedium?.copyWith(
                      color: theme.colorScheme.onPrimaryContainer,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  /// "X 212/280 · LinkedIn 212/3000" for the ticked networks, a network over
  /// its limit in red. Publish carries the sentence; this is where to look.
  Widget _counter(BuildContext context, List<_SocialConnection> networks) {
    final theme = Theme.of(context);
    final length = _lengthOf(_words);
    final bad = ToneColours.of(context, Tone.bad).ink;

    return Wrap(
      spacing: 14,
      runSpacing: 4,
      children: [
        for (final account in networks)
          Builder(
            builder: (context) {
              final limit = _bodyLimits[account.provider];
              final over = limit != null && length > limit;

              return Text(
                limit == null
                    ? '${account.providerLabel} $length'
                    : '${account.providerLabel} $length/$limit',
                key: ValueKey('social-count-${account.provider}'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: over ? bad : null,
                  fontWeight: over ? FontWeight.w700 : null,
                ),
              );
            },
          ),
      ],
    );
  }

  /// The first picture or video of a drop, attached the way Attach does: a
  /// post carries one file, so the rest are not taken.
  Future<void> _drop(List<DropItem> items) async {
    setState(() => _dropping = false);

    for (final item in items) {
      if (item is DropItemDirectory) continue;
      if (!_looksLikeImage(item.name) && !_looksLikeVideo(item.name)) continue;

      final bytes = await item.readAsBytes();
      if (!mounted) return;

      await _attach(dropped: PickedBytes(item.name, bytes));
      return;
    }

    setState(() => _dropRefused = 'Only a picture or a video can be attached.');
  }

  Widget _attachment(BuildContext context) {
    final media = _media;
    final theme = Theme.of(context);

    final fromLibrary = _libraryItem;
    if (media == null && fromLibrary != null) {
      return Row(
        key: const ValueKey('social-library-picked'),
        children: [
          Icon(
            fromLibrary['kind'] == 'video'
                ? Icons.movie_outlined
                : Icons.photo_outlined,
            size: 18,
          ),
          const SizedBox(width: 8),
          Expanded(child: Text('From the library: ${fromLibrary['name'] ?? ''}')),
          TextButton(
            onPressed: _sending
                ? null
                : () => setState(() => _libraryItem = null),
            child: const Text('Remove'),
          ),
        ],
      );
    }

    if (media == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              OutlinedButton.icon(
                onPressed: _sending ? null : _attach,
                icon: const Icon(Icons.image_outlined, size: 18),
                label: const Text('Attach a picture or video'),
              ),
              OutlinedButton.icon(
                key: const ValueKey('social-from-library'),
                onPressed: _sending ? null : _fromLibrary,
                icon: const Icon(Icons.photo_library_outlined, size: 18),
                label: const Text('From library'),
              ),
            ],
          ),
          // Not on a phone, where nothing is dragged.
          if (MediaQuery.sizeOf(context).width >= 600) ...[
            const SizedBox(height: 4),
            Text(
              'or drag one onto this page',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
          if (_dropRefused != null) ...[
            const SizedBox(height: 4),
            Text(
              _dropRefused!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: ToneColours.of(context, Tone.bad).ink,
              ),
            ),
          ],
        ],
      );
    }

    final isImage = _looksLikeImage(media.name);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        /*
         * The picture itself, not its filename.
         *
         * A label is not confirmation. This goes to a public account under the
         * tenant's own name, and the cost of the wrong file is a real audience
         * seeing it — so the check has to be the thing a person can actually
         * perform, which is looking at it.
         *
         * `Image.memory` on the bytes already in hand: nothing uploaded yet,
         * no dependency, no network. Video gets a named placeholder rather
         * than a frame — decoding one needs a player package, which is a
         * decision rather than a detail. See the note below it.
         */
        ClipRRect(
          borderRadius: BorderRadius.circular(8),
          child: SizedBox(
            height: 160,
            width: double.infinity,
            child: isImage
                ? Image.memory(
                    Uint8List.fromList(media.bytes),
                    fit: BoxFit.contain,
                    // A file that claims .png and is not decodable should say
                    // so here, while it can still be swapped — not fail at the
                    // network days later.
                    errorBuilder: (context, _, _) => _mediaFallback(
                      context,
                      Icons.broken_image_outlined,
                      'That file could not be read as an image.',
                    ),
                  )
                : _videoPreview(context, media),
          ),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: Text(
                media.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
            TextButton(
              onPressed: _sending ? null : _attach,
              child: const Text('Replace'),
            ),
            TextButton(
              onPressed: _sending
                  ? null
                  : () => setState(() {
                      _releaseMedia();
                      _media = null;
                    }),
              child: const Text('Remove'),
            ),
          ],
        ),
      ],
    );
  }

  /// The video, playing, or an honest label when it cannot be.
  ///
  /// Off the web there is no object URL and no player, and a container the
  /// browser will not decode fails the same way. Both land on the label, which
  /// still tells somebody which file they attached and how big it is — less
  /// than Jose asked for, and not a pretence that it is something else.
  Widget _videoPreview(BuildContext context, PickedBytes media) {
    final player = _player;

    if (player == null || !player.value.isInitialized) {
      return _mediaFallback(
        context,
        Icons.movie_outlined,
        '${media.name} · ${_sizeOf(media.bytes.length)}',
      );
    }

    return Stack(
      alignment: Alignment.center,
      children: [
        Center(
          child: AspectRatio(
            aspectRatio: player.value.aspectRatio,
            child: VideoPlayer(player),
          ),
        ),
        // Play and pause, and nothing else. Reviewing a clip before it goes out
        // needs to start and stop it; a scrubber, volume and fullscreen are a
        // media player, which is not what this is.
        IconButton.filled(
          onPressed: () => setState(() {
            player.value.isPlaying ? player.pause() : player.play();
          }),
          icon: Icon(
            player.value.isPlaying ? Icons.pause : Icons.play_arrow,
          ),
          tooltip: player.value.isPlaying ? 'Pause' : 'Play',
        ),
      ],
    );
  }

  static String _mimeFor(String name) {
    return switch (name.toLowerCase().split('.').last) {
      'mov' => 'video/quicktime',
      'webm' => 'video/webm',
      _ => 'video/mp4',
    };
  }

  Widget _mediaFallback(BuildContext context, IconData icon, String label) {
    final theme = Theme.of(context);

    return ColoredBox(
      color: theme.colorScheme.surfaceContainerHighest,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(icon, size: 32, color: theme.colorScheme.onSurfaceVariant),
          const SizedBox(height: 6),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Text(
              label,
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
          ),
        ],
      ),
    );
  }

  static bool _looksLikeVideo(String name) => const [
    'mp4',
    'mov',
    'webm',
    'm4v',
  ].contains(name.toLowerCase().split('.').last);

  static bool _looksLikeImage(String name) {
    final ext = name.toLowerCase().split('.').last;
    return const ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif']
        .contains(ext);
  }

  static String _sizeOf(int bytes) => bytes >= 1048576
      ? '${(bytes / 1048576).toStringAsFixed(1)} MB'
      : '${(bytes / 1024).round()} KB';
}

/// "How it will look": the post drawn as each ticked network would show it.
///
/// A likeness, said so on the card (Mia, 2026-10-02, layout A). Each network
/// crops, folds and decorates in its own way and changes it without notice;
/// this answers "is the text too long, is it the right picture, does it read
/// as us" before it goes out, which is what it is for.
class _PreviewCard extends StatelessWidget {
  const _PreviewCard({
    required this.networks,
    required this.body,
    required this.media,
    required this.when,
  });

  /// One ticked account per network.
  final List<_SocialConnection> networks;
  final String body;
  final PickedBytes? media;
  final String when;

  @override
  Widget build(BuildContext context) {
    Widget likeness(_SocialConnection network) => _PostLikeness(
      account: network,
      body: body,
      media: media,
      when: when,
    );

    return DashboardCard(
      key: const ValueKey('tour-marketing-compose-preview'),
      // Every ticked network at once (UI queue 76, Jose's pick 2), so the one
      // that cuts the words short is seen without clicking through the rest.
      title: 'How it will look',
      icon: Icons.visibility_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (networks.isEmpty)
            const DashboardNote(
              'Tick an account to see the post the way it will look there.',
            )
          else
            LayoutBuilder(
              builder: (context, box) {
                // A phone swipes between them: side by side, each would be
                // too narrow to read.
                if (box.maxWidth < 360 && networks.length > 1) {
                  return _PreviewPager(
                    children: [for (final n in networks) likeness(n)],
                  );
                }

                const gap = 10.0;
                final across = (box.maxWidth / 220).floor().clamp(
                  1,
                  networks.length.clamp(1, 3),
                );
                final width = (box.maxWidth - gap * (across - 1)) / across;

                return Wrap(
                  spacing: gap,
                  runSpacing: gap,
                  children: [
                    for (final network in networks)
                      SizedBox(width: width, child: likeness(network)),
                  ],
                );
              },
            ),
          const SizedBox(height: 10),
          const DashboardNote(
            'A close likeness drawn here; each network has the last word on '
            'how it looks.',
          ),
        ],
      ),
    );
  }
}

/// Likenesses a thumb swipes through, one to a screen, with a dot for each.
class _PreviewPager extends StatefulWidget {
  const _PreviewPager({required this.children});

  final List<Widget> children;

  @override
  State<_PreviewPager> createState() => _PreviewPagerState();
}

class _PreviewPagerState extends State<_PreviewPager> {
  final ScrollController _scroll = ScrollController();
  int _page = 0;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      final width = _scroll.position.viewportDimension;
      final page = width == 0 ? 0 : (_scroll.offset / width).round();
      if (page != _page) setState(() => _page = page);
    });
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, box) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Not a PageView: that needs a height, and a likeness is as tall as
          // its words and picture make it.
          SingleChildScrollView(
            key: const ValueKey('social-preview-pager'),
            controller: _scroll,
            scrollDirection: Axis.horizontal,
            physics: const PageScrollPhysics(),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final child in widget.children)
                  SizedBox(width: box.maxWidth, child: child),
              ],
            ),
          ),
          const SizedBox(height: 2),
          // A dot is also a way there: a mouse cannot swipe a Flutter
          // scroller on the web, and a narrow desktop window lands here too.
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (var i = 0; i < widget.children.length; i++)
                GestureDetector(
                  key: ValueKey('social-preview-dot-$i'),
                  behavior: HitTestBehavior.opaque,
                  onTap: () => _scroll.animateTo(
                    i * box.maxWidth,
                    duration: const Duration(milliseconds: 250),
                    curve: Curves.easeOut,
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(8),
                    child: Container(
                      width: 7,
                      height: 7,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: i == _page
                            ? theme.colorScheme.primary
                            : theme.colorScheme.outlineVariant,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// One network's post: who it is from, the words, the picture, the count.
class _PostLikeness extends StatelessWidget {
  const _PostLikeness({
    required this.account,
    required this.body,
    required this.media,
    required this.when,
  });

  final _SocialConnection account;
  final String body;
  final PickedBytes? media;
  final String when;

  /// Networks that lead with the picture and put the words under it, after
  /// the account's name.
  static const _pictureFirst = {
    'instagram_professional',
    'pinterest',
    'tiktok',
    'youtube',
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final limit = _bodyLimits[account.provider];
    final length = _lengthOf(body);
    final over = limit != null && length > limit;
    final pictureFirst = _pictureFirst.contains(account.provider);
    final media = this.media;

    final header = Row(
      children: [
        SocialNetworkMark(network: account.provider, size: 32),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                account.displayName,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              Text(
                '${account.providerLabel} · $when',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ],
    );

    final Widget? picture = media == null
        ? null
        : ClipRRect(
            key: const ValueKey('social-preview-media'),
            borderRadius: BorderRadius.circular(8),
            child: SizedBox(
              height: 150,
              width: double.infinity,
              child: _ComposeColumnState._looksLikeImage(media.name)
                  ? Image.memory(
                      Uint8List.fromList(media.bytes),
                      fit: BoxFit.cover,
                      errorBuilder: (context, _, _) => _thumbnailLabel(
                        theme,
                        Icons.broken_image_outlined,
                        media.name,
                      ),
                    )
                  : _thumbnailLabel(theme, Icons.movie_outlined, media.name),
            ),
          );

    final Widget words = body.isEmpty
        ? Text(
            media == null ? 'Nothing written yet.' : 'No words, just the file.',
            style: theme.textTheme.bodySmall?.copyWith(
              fontStyle: FontStyle.italic,
            ),
          )
        : pictureFirst
        ? Text.rich(
            TextSpan(
              children: [
                TextSpan(
                  text: '${account.handle ?? account.displayName} ',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
                TextSpan(text: body),
              ],
            ),
            style: theme.textTheme.bodyMedium,
          )
        : Text(body, style: theme.textTheme.bodyMedium);

    return Container(
      key: ValueKey('social-likeness-${account.provider}'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: theme.colorScheme.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          header,
          const SizedBox(height: 10),
          if (pictureFirst && picture != null) ...[
            picture,
            const SizedBox(height: 10),
          ],
          words,
          if (!pictureFirst && picture != null) ...[
            const SizedBox(height: 10),
            picture,
          ],
          const SizedBox(height: 10),
          Text(
            limit == null
                ? '$length characters'
                : '$length / $limit characters',
            key: const ValueKey('social-preview-count'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: over ? ToneColours.of(context, Tone.bad).ink : null,
              fontWeight: over ? FontWeight.w700 : null,
            ),
          ),
        ],
      ),
    );
  }

  static Widget _thumbnailLabel(ThemeData theme, IconData icon, String name) {
    return ColoredBox(
      color: theme.colorScheme.surfaceContainerHighest,
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 28, color: theme.colorScheme.onSurfaceVariant),
            const SizedBox(height: 4),
            Text(
              name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

class _CalendarsColumn extends StatefulWidget {
  const _CalendarsColumn({
    super.key,
    this.fill = false,
    this.posts = const [],
    this.outlined,
    this.focusDay,
    this.formats = DisplayFormats.fallback,
    this.onOpen,
  });

  /// When true the column fills the pane and month squares shrink to fit,
  /// so the whole month is on screen. Off in the stacked (phone) layout,
  /// which already scrolls the page as a whole.
  final bool fill;

  /// Every post, sent and scheduled, drawn on its day: green posted, blue
  /// scheduled, red a failure to look at (design 27). They used to be drawn
  /// with no posts passed in at all, so a post that went out never appeared.
  final List<_SentPost> posts;

  /// The newest post, outlined.
  final String? outlined;

  /// A day to show: the month calendar moves to it when it changes.
  final DateTime? focusDay;
  final DisplayFormats formats;
  final void Function(_SentPost)? onOpen;

  static const _days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  static const _monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
  ];

  static const _shortMonths = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];

  static DateTime _dateOnly(DateTime d) => DateTime(d.year, d.month, d.day);

  static DateTime mondayOf(DateTime d) {
    final day = _dateOnly(d);
    return day.subtract(Duration(days: day.weekday - DateTime.monday));
  }

  static String monthTitle(DateTime d) =>
      '${_monthNames[d.month - 1]} ${d.year}';

  static String weekRange(DateTime monday) {
    final sunday = monday.add(const Duration(days: 6));
    if (monday.month == sunday.month) {
      return '${monday.day}–${sunday.day} ${_shortMonths[monday.month - 1]}';
    }
    return '${monday.day} ${_shortMonths[monday.month - 1]} – '
        '${sunday.day} ${_shortMonths[sunday.month - 1]}';
  }

  @override
  State<_CalendarsColumn> createState() => _CalendarsColumnState();
}

class _CalendarsColumnState extends State<_CalendarsColumn> {
  late DateTime _month;

  @override
  void initState() {
    super.initState();
    final now = widget.focusDay ?? DateTime.now();
    _month = DateTime(now.year, now.month, 1);
  }

  @override
  void didUpdateWidget(_CalendarsColumn old) {
    super.didUpdateWidget(old);
    final focus = widget.focusDay;
    if (focus != null && focus != old.focusDay) {
      _month = DateTime(focus.year, focus.month, 1);
    }
  }

  void _shiftMonth(int months) {
    setState(() {
      _month = DateTime(_month.year, _month.month + months, 1);
    });
  }

  @override
  Widget build(BuildContext context) {
    final entries = _DayEntries(
      byDay: _postsByDay(widget.posts),
      outlined: widget.outlined,
      formats: widget.formats,
      onOpen: widget.onOpen,
    );
    final month = _MonthCard(
      month: _month,
      onPrevious: () => _shiftMonth(-1),
      onNext: () => _shiftMonth(1),
      fill: widget.fill,
      entries: entries,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _WeekCard(entries: entries),
        const SizedBox(height: 12),
        if (widget.fill) Expanded(child: month) else month,
      ],
    );
  }
}

class _WeekCard extends StatelessWidget {
  const _WeekCard({required this.entries});

  final _DayEntries entries;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final today = _CalendarsColumn._dateOnly(DateTime.now());
    final monday = _CalendarsColumn.mondayOf(today);

    return DashboardCard(
      key: const ValueKey('tour-marketing-week'),
      title: 'Weekly Calendar',
      icon: Icons.calendar_today_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const DashboardNote('Only posts for pages this view can see.'),
          const SizedBox(height: 8),
          Text(
            _CalendarsColumn.weekRange(monday),
            textAlign: TextAlign.center,
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 8),
          _WeekdayHeader(theme: theme),
          Table(
            defaultColumnWidth: const FlexColumnWidth(),
            children: [
              TableRow(
                children: [
                  for (var i = 0; i < 7; i++)
                    _DayCell(
                      height: 88,
                      theme: theme,
                      label: '${monday.add(Duration(days: i)).day}',
                      highlight: monday.add(Duration(days: i)) == today,
                      entries: entries.on(monday.add(Duration(days: i))),
                    ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _MonthCard extends StatelessWidget {
  const _MonthCard({
    required this.month,
    required this.onPrevious,
    required this.onNext,
    required this.entries,
    this.fill = false,
  });

  final DateTime month;
  final VoidCallback onPrevious;
  final VoidCallback onNext;
  final _DayEntries entries;
  final bool fill;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final today = _CalendarsColumn._dateOnly(DateTime.now());
    final first = DateTime(month.year, month.month, 1);
    final daysInMonth = DateTime(month.year, month.month + 1, 0).day;
    final leading = first.weekday - DateTime.monday;
    final cells = leading + daysInMonth;
    final rows = (cells / 7).ceil();

    return DashboardCard(
      key: const ValueKey('tour-marketing-month'),
      title: 'Monthly Calendar',
      icon: Icons.calendar_view_month_outlined,
      expand: fill,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _PeriodNav(
            label: _CalendarsColumn.monthTitle(month),
            previousTooltip: 'Previous month',
            nextTooltip: 'Next month',
            onPrevious: onPrevious,
            onNext: onNext,
          ),
          const SizedBox(height: 8),
          _WeekdayHeader(theme: theme),
          if (fill)
            Expanded(
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final rowHeight = rows == 0
                      ? constraints.maxHeight
                      : constraints.maxHeight / rows;
                  return Table(
                    key: const Key('month-grid'),
                    defaultColumnWidth: const FlexColumnWidth(),
                    children: [
                      for (var r = 0; r < rows; r++)
                        TableRow(
                          children: [
                            for (var c = 0; c < 7; c++)
                              _monthCell(
                                theme,
                                today,
                                month,
                                leading,
                                daysInMonth,
                                r * 7 + c,
                                entries,
                                height: rowHeight,
                              ),
                          ],
                        ),
                    ],
                  );
                },
              ),
            )
          else
            Table(
              defaultColumnWidth: const FlexColumnWidth(),
              children: [
                for (var r = 0; r < rows; r++)
                  TableRow(
                    children: [
                      for (var c = 0; c < 7; c++)
                        _monthCell(
                          theme,
                          today,
                          month,
                          leading,
                          daysInMonth,
                          r * 7 + c,
                          entries,
                        ),
                    ],
                  ),
              ],
            ),
        ],
      ),
    );
  }

  static Widget _monthCell(
    ThemeData theme,
    DateTime today,
    DateTime month,
    int leading,
    int daysInMonth,
    int index,
    _DayEntries entries, {
    double? height,
  }) {
    final day = index - leading + 1;
    if (day < 1 || day > daysInMonth) {
      return _DayCell(
        theme: theme,
        square: height == null,
        empty: true,
        height: height,
      );
    }
    final date = DateTime(month.year, month.month, day);
    return _DayCell(
      key: Key('month-day-$day'),
      theme: theme,
      square: height == null,
      height: height,
      label: '$day',
      highlight: date == today,
      entries: entries.on(date),
    );
  }
}

class _PeriodNav extends StatelessWidget {
  const _PeriodNav({
    required this.label,
    required this.previousTooltip,
    required this.nextTooltip,
    required this.onPrevious,
    required this.onNext,
  });

  final String label;
  final String previousTooltip;
  final String nextTooltip;
  final VoidCallback onPrevious;
  final VoidCallback onNext;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      children: [
        IconButton(
          tooltip: previousTooltip,
          visualDensity: VisualDensity.compact,
          onPressed: onPrevious,
          icon: const Icon(Icons.chevron_left),
        ),
        Expanded(
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: theme.textTheme.titleSmall,
          ),
        ),
        IconButton(
          tooltip: nextTooltip,
          visualDensity: VisualDensity.compact,
          onPressed: onNext,
          icon: const Icon(Icons.chevron_right),
        ),
      ],
    );
  }
}

class _WeekdayHeader extends StatelessWidget {
  const _WeekdayHeader({required this.theme});

  final ThemeData theme;

  @override
  Widget build(BuildContext context) {
    return Table(
      children: [
        TableRow(
          children: [
            for (final day in _CalendarsColumn._days)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Text(day, style: theme.textTheme.labelSmall),
              ),
          ],
        ),
      ],
    );
  }
}

class _DayCell extends StatelessWidget {
  const _DayCell({
    super.key,
    required this.theme,
    this.height,
    this.square = false,
    this.label,
    this.highlight = false,
    this.empty = false,
    this.entries = const [],
  });

  final double? height;
  final bool square;
  final ThemeData theme;
  final String? label;
  final bool highlight;
  final bool empty;

  /// The day's posts, drawn under the date.
  final List<Widget> entries;

  @override
  Widget build(BuildContext context) {
    final box = Container(
      decoration: BoxDecoration(
        color: empty
            ? theme.colorScheme.surface
            : highlight
                ? theme.colorScheme.primary.withValues(alpha: 0.12)
                : theme.colorScheme.surfaceContainerLowest,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(
          color: highlight ? theme.colorScheme.primary : theme.dividerColor,
        ),
      ),
      alignment: Alignment.topLeft,
      padding: const EdgeInsets.all(6),
      child: label == null
          ? null
          // Clipped, not overflowing: a small square shows the date and what
          // fits, and "+2 more" says the rest is there.
          : ClipRect(
              child: SingleChildScrollView(
                physics: const NeverScrollableScrollPhysics(),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      label!,
                      style: theme.textTheme.labelSmall?.copyWith(
                        fontWeight: highlight
                            ? FontWeight.w700
                            : FontWeight.w500,
                        color: highlight
                            ? theme.colorScheme.primary
                            : theme.colorScheme.onSurface,
                      ),
                    ),
                    ...entries,
                  ],
                ),
              ),
            ),
    );

    final padded = Padding(
      padding: const EdgeInsets.only(right: 3, bottom: 3),
      child: box,
    );

    if (height != null) {
      return SizedBox(height: height, child: padded);
    }

    if (square) {
      return AspectRatio(aspectRatio: 1, child: padded);
    }

    return padded;
  }
}

class _AccountsPane extends StatelessWidget {
  const _AccountsPane({
    required this.viewName,
    required this.providers,
    required this.connections,
    required this.onConnect,
    required this.onDisconnect,
    required this.onComments,
    this.error,
  });

  final String viewName;
  final List<_SocialNetwork> providers;
  final List<_SocialConnection> connections;
  final VoidCallback onConnect;
  final void Function(_SocialConnection) onDisconnect;
  final void Function(_SocialConnection) onComments;
  final String? error;

  @override
  Widget build(BuildContext context) {
    final connected = {for (final c in connections) c.provider: c};

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        DashboardCard(
          key: const ValueKey('tour-marketing-accounts'),
          title: 'Accounts in $viewName',
          icon: Icons.link_outlined,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const DashboardNote(
                'A connection is created at the view you are in. Organization '
                'sees every company below. Siblings do not share.',
              ),
              const SizedBox(height: 8),
              if (error != null) DashboardNote(error!),
              if (connections.isEmpty && error == null)
                DashboardNote('No pages connected in $viewName.')
              else
                for (final page in connections)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: SocialNetworkMark(network: page.provider),
                    title: Text(
                      page.displayName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    subtitle: Text(
                      page.handle != null
                          ? '${page.providerLabel} · @${page.handle}'
                          : page.providerLabel,
                    ),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        // Instagram comments are moderated through the Page
                        // the Instagram account is linked to.
                        if (page.provider == 'facebook_page')
                          TextButton(
                            onPressed: () => onComments(page),
                            child: const Text('Instagram comments'),
                          ),
                        TextButton(
                          onPressed: () => onDisconnect(page),
                          child: const Text('Disconnect'),
                        ),
                      ],
                    ),
                  ),
              const SizedBox(height: 12),
              Align(
                alignment: Alignment.centerLeft,
                child: FilledButton(
                  key: const ValueKey('tour-marketing-accounts-connect'),
                  onPressed: onConnect,
                  child: Text('Connect a page to $viewName'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Builder(
          key: const ValueKey('tour-marketing-accounts-catalogue'),
          builder: (context) {
            /*
             * The catalogue is what is NOT connected yet.
             *
             * A network with an account already listed above was saying the same
             * thing twice — once as the account itself, once as a row repeating
             * its name. The card is here to answer "what else could I connect,
             * and why can I not connect it", so a connected network has no
             * answer to give and leaves.
             *
             * This does not close the door on a second account of the same
             * network: Connect opens the panel, and the panel lists every
             * network whatever is connected here.
             */
            final unconnected = [
              for (final network in providers)
                if (connected[network.value] == null) network,
            ];

            if (unconnected.isEmpty) {
              return const DashboardCard(
                title: 'Catalogue',
                icon: Icons.public_outlined,
                child: DashboardNote(
                  'Every network EzBizi can post to is connected in this view. '
                  'Connect adds another account on any of them.',
                ),
              );
            }

            return DashboardCard(
              title: 'Catalogue',
              icon: Icons.public_outlined,
              child: Column(
                children: [
                  for (final network in unconnected)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: SocialNetworkMark(network: network.value),
                      title: Text(network.label),
                      // No `maxLines`. The reason is the server's sentence and
                      // it ends in what to DO ("…so Facebook, Instagram and
                      // Messenger cannot be connected until …"). Cut at two
                      // lines beside "Not ready" on a phone, the half that was
                      // lost was exactly the half that helps. It wraps.
                      subtitle: Text(
                        network.usable
                            ? 'Ready to connect'
                            : network.unusableBecause ?? 'Not ready',
                      ),
                      trailing: Text(
                        network.usable ? 'Ready' : 'Not ready',
                        style: Theme.of(context).textTheme.labelSmall,
                      ),
                    ),
                ],
              ),
            );
          },
        ),
      ],
    );
  }
}

class _SocialNetwork {
  const _SocialNetwork({
    required this.value,
    required this.label,
    required this.usable,
    this.unusableBecause,
    this.connectsBy = 'sign_in',
    this.startPath = '',
  });

  final String value;
  final String label;
  final bool usable;
  final String? unusableBecause;
  final String connectsBy;
  final String startPath;

  factory _SocialNetwork.fromJson(Map<String, dynamic> json) {
    return _SocialNetwork(
      value: json['value'] as String? ?? '',
      label: json['label'] as String? ?? '',
      usable: json['usable'] as bool? ?? false,
      unusableBecause: json['unusable_because'] as String?,
      connectsBy: json['connects_by'] as String? ?? 'sign_in',
      startPath: json['start_path'] as String? ?? '',
    );
  }
}

class _SocialConnection {
  const _SocialConnection({
    required this.id,
    required this.displayName,
    required this.provider,
    required this.providerLabel,
    this.handle,
    this.canPublish = false,
    this.cannotPublishBecause,
    this.postingNote,
    this.asksTikTokChoices = false,
  });

  final String id;
  final String displayName;
  final String provider;
  final String providerLabel;
  final String? handle;

  /// Connected is not the same as postable — four of the nine networks have no
  /// publisher yet. The server decides which, and says why; this screen must
  /// never work it out from [provider], or it becomes a second copy of that
  /// list which goes stale the day one is added.
  final bool canPublish;
  final String? cannotPublishBecause;

  /// What posting here actually does, when it is not what somebody would
  /// assume — TikTok going to drafts rather than to the profile. The server's
  /// sentence; null when the network holds no surprise.
  final String? postingNote;

  /// TikTok Direct Post: privacy, interactions and disclosure must be asked
  /// for this account on every post (TikTokPostChoices).
  final bool asksTikTokChoices;

  factory _SocialConnection.fromJson(Map<String, dynamic> json) {
    return _SocialConnection(
      id: json['id'] as String? ?? '',
      displayName: json['display_name'] as String? ?? '',
      provider: json['provider'] as String? ?? '',
      providerLabel: json['provider_label'] as String? ?? '',
      handle: json['handle'] as String?,
      canPublish: json['can_publish'] as bool? ?? false,
      cannotPublishBecause: json['cannot_publish_because'] as String?,
      postingNote: json['posting_note'] as String?,
      asksTikTokChoices: json['asks_tiktok_choices'] as bool? ?? false,
    );
  }
}

/// One post that was sent, and how it landed at each account.
class _SentPost {
  const _SentPost({
    required this.id,
    required this.body,
    required this.hasMedia,
    required this.targets,
    this.author,
    this.scheduledFor,
    this.scheduledTimezone,
    this.createdAt,
  });

  final String id;
  final String body;
  final bool hasMedia;
  final String? author;

  /// When it was made, on this device's clock.
  final DateTime? createdAt;

  /// Wall clock, in the view's timezone, exactly as it was promised. Null for
  /// a post that went out immediately.
  final String? scheduledFor;

  /// WHICH view timezone, as the server resolved it. Worth showing beside the
  /// wall time: above franchise scope the platform default is a fixed offset,
  /// so the promised 9am and the branch's 9am are an hour apart in summer.
  final String? scheduledTimezone;

  final List<_SentTarget> targets;

  factory _SentPost.fromJson(Map<String, dynamic> json) {
    return _SentPost(
      id: json['id'] as String? ?? '',
      body: json['body'] as String? ?? '',
      hasMedia: json['has_media'] as bool? ?? false,
      author: json['author'] as String?,
      scheduledFor: json['scheduled_for'] as String?,
      scheduledTimezone: json['scheduled_timezone'] as String?,
      createdAt: DateTime.tryParse(
        json['created_at'] as String? ?? '',
      )?.toLocal(),
      targets: ((json['targets'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SentTarget.fromJson)
          .toList(),
    );
  }

  /// Where it sits on a calendar: the promised wall time for a scheduled
  /// post, otherwise when it was made. Null when neither is known.
  DateTime? get when {
    final wall = scheduledFor;
    final promised = wall == null
        ? null
        : DateTime.tryParse(wall.replaceFirst(' ', 'T'));

    return promised ?? createdAt;
  }

  /// The one colour a calendar has room for. A failure outranks everything,
  /// so a post that reached two accounts of three still reads red.
  Tone get tone {
    if (targets.any((t) => t.status == 'failed')) return Tone.bad;
    if (targets.any((t) => t.status == 'queued')) return Tone.neutral;
    if (targets.isNotEmpty && targets.every((t) => t.status == 'scheduled')) {
      return Tone.info;
    }

    return Tone.ok;
  }

  /// The Posts tile it counts under, read as the server reads it
  /// (SocialPostsController::inStatus); null while it is still sending.
  String? get tile => switch (tone) {
    Tone.bad => 'failed',
    Tone.info => 'scheduled',
    Tone.neutral => null,
    _ => 'sent',
  };

  /// Its first line, or what it carries when it has no words.
  String get preview {
    final text = body.trim();
    if (text.isEmpty) return hasMedia ? 'A picture or video' : 'A post';

    return text.split('\n').first;
  }
}

class _SentTarget {
  const _SentTarget({
    required this.account,
    required this.providerLabel,
    required this.status,
    this.failure,
    this.url,
    this.connectionId,
    this.provider,
    this.id,
    this.canDelete = false,
  });

  /// For Try again, which names the target in its path.
  final String? id;

  /// The server's word: sent, and its network lets EzBizi take it down.
  final bool canDelete;

  final String account;
  final String providerLabel;
  final String status;

  /// Which connection, to read its posting note (TikTok's drafts).
  final String? connectionId;

  /// The network's key, for its mark.
  final String? provider;
  final String? failure;

  /// Where the post can be seen, as the network reported it; null when it
  /// gave none.
  final String? url;

  factory _SentTarget.fromJson(Map<String, dynamic> json) {
    return _SentTarget(
      account: json['account'] as String? ?? '',
      providerLabel: json['provider_label'] as String? ?? '',
      status: json['status'] as String? ?? 'queued',
      failure: json['failure'] as String?,
      url: json['url'] as String?,
      connectionId: json['connection_id'] as String?,
      provider: json['provider'] as String?,
      id: json['id'] as String?,
      canDelete: json['can_delete'] as bool? ?? false,
    );
  }
}

class _ConnectPanel extends StatefulWidget {
  const _ConnectPanel({
    required this.api,
    required this.viewName,
    required this.providers,
    required this.connected,
    required this.openConsent,
    required this.onConnected,
  });

  final ApiClient api;
  final String viewName;
  final List<_SocialNetwork> providers;

  /// Provider keys already connected in this view, so the button can say
  /// Reconnect rather than offering Connect on something already connected.
  final Set<String> connected;
  final OpenConsentWindow openConsent;
  final Future<void> Function() onConnected;

  @override
  State<_ConnectPanel> createState() => _ConnectPanelState();
}

class _ConnectPanelState extends State<_ConnectPanel> {
  String? _busy;
  String? _error;
  Timer? _poll;
  final _handle = TextEditingController();
  final _appPassword = TextEditingController();

  /// The app-password form is open on an account that is ALREADY connected.
  ///
  /// Only meaningful for a network that connects by password — see
  /// [_blueskyTile]. False is both "not connected" and "connected and settled",
  /// which the tile tells apart by asking [_ConnectPanel.connected].
  bool _replacing = false;

  @override
  void dispose() {
    _poll?.cancel();
    _handle.dispose();
    _appPassword.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Connect to ${widget.viewName}',
      subtitle:
          'This page will belong to ${widget.viewName}. Siblings will not see it. '
          'The organization view will, because it owns this company.',
      child: Column(
        children: [
          if (_error != null) ...[
            DashboardNote(_error!),
            const SizedBox(height: 8),
          ],
          for (final network in widget.providers)
            if (network.connectsBy == 'password')
              _blueskyTile(network)
            else
              ListTile(
                leading: SocialNetworkMark(network: network.value),
                title: Text(network.label),
                subtitle: Text(
                  _busy == network.value
                      ? 'Waiting for ${network.label}…'
                      : widget.connected.contains(network.value)
                          ? 'Already connected. Signing in again refreshes it.'
                          : network.usable
                              ? 'Opens the network sign-in.'
                              : network.unusableBecause ?? 'Not ready',
                ),
                trailing: FilledButton(
                  onPressed: network.usable && _busy == null
                      ? () => _connectOauth(network)
                      : null,
                  child: Text(
                    widget.connected.contains(network.value)
                        ? 'Reconnect'
                        : 'Connect',
                  ),
                ),
              ),
        ],
      ),
    );
  }

  /// Bluesky's tile — the one network here that cannot open a sign-in.
  ///
  /// ── Why it needs a connected state of its own ────────────────────
  ///
  /// Every other network in this panel is OAuth, and for those the button does
  /// the same thing whether or not an account is already connected: it opens
  /// the network's sign-in. One tile serves both states and only the wording
  /// changes — "Connect" becomes "Reconnect".
  ///
  /// Bluesky has no developer console, so connecting IS this form, and a form
  /// cannot be relabelled the same way. Left as it was, an account already
  /// connected still drew two empty boxes and a Connect button — which reads as
  /// "not connected" sitting directly beneath TikTok and Pinterest saying that
  /// they are. That is the whole of what was wrong with it.
  ///
  /// So the form becomes what Reconnect OPENS, rather than what the tile is.
  /// The sentence differs from the OAuth tiles' because the mechanism does:
  /// signing in again refreshes a token that already exists, and an app
  /// password cannot be refreshed at all — only replaced by another one, which
  /// is a thing somebody has to go and generate in Bluesky first.
  Widget _blueskyTile(_SocialNetwork network) {
    if (widget.connected.contains(network.value) && !_replacing) {
      return ListTile(
        leading: SocialNetworkMark(network: network.value),
        title: Text(network.label),
        subtitle: Text(
          _busy == network.value
              ? 'Waiting for ${network.label}…'
              : 'Already connected. An app password cannot be refreshed — '
                    'reconnecting replaces it with a new one.',
        ),
        trailing: FilledButton(
          onPressed: _busy == null
              ? () => setState(() => _replacing = true)
              : null,
          child: const Text('Reconnect'),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              SocialNetworkMark(network: network.value, size: 24),
              const SizedBox(width: 8),
              Text(network.label, style: Theme.of(context).textTheme.titleSmall),
            ],
          ),
          const SizedBox(height: 4),
          DashboardNote(
            'Bluesky has no developer console. Use an app password from Bluesky '
            'settings, not the account password.',
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _handle,
            decoration: const InputDecoration(
              labelText: 'Handle',
              hintText: 'company.bsky.social',
            ),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _appPassword,
            obscureText: true,
            decoration: const InputDecoration(labelText: 'App password'),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              FilledButton(
                onPressed: _busy == null ? () => _connectBluesky(network) : null,
                child: Text(_replacing ? 'Replace' : 'Connect'),
              ),
              // A way back out. Reconnect is one tap from a tile that was
              // saying everything is fine, and without this the only way to
              // close the form somebody opened by accident is to close the
              // whole panel.
              if (_replacing) ...[
                const SizedBox(width: 8),
                TextButton(
                  onPressed: _busy == null
                      ? () => setState(() => _replacing = false)
                      : null,
                  child: const Text('Cancel'),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _connectBluesky(_SocialNetwork network) async {
    setState(() {
      _busy = network.value;
      _error = null;
    });

    final response = await widget.api.post(network.startPath, {
      'handle': _handle.text,
      'app_password': _appPassword.text,
    });

    if (!mounted) return;

    setState(() => _busy = null);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    // The password is not kept once it has been sent, and the tile settles back
    // to saying it is connected rather than leaving a filled-in form behind
    // that looks like it still has something to do.
    _handle.clear();
    _appPassword.clear();
    setState(() => _replacing = false);

    await widget.onConnected();
  }

  Future<void> _connectOauth(_SocialNetwork network) async {
    _poll?.cancel();
    _poll = await runConsentFlow(
      api: widget.api,
      openConsent: widget.openConsent,
      startPath: network.startPath,
      label: network.label,
      refusedFallback: '${network.label} sign-in did not finish.',
      isMounted: () => mounted,
      onStart: () => setState(() {
        _busy = network.value;
        _error = null;
      }),
      onEnd: (error) => setState(() {
        _busy = null;
        _error = error;
      }),
      onConnected: widget.onConnected,
    );
  }
}

// ── Design 27: posting that feels finished ─────────────────────────
//
// Jose, 2026-09-28, via the Meta session: "there was no hint that the post had
// been successful ... i didn't see anything appear on the calendar even though
// you posted it and it succeeded". He took the recommendation: on a desktop
// the composer becomes the result and the post lands on the calendar (1); on
// a phone a posting screen, a finish screen, then an agenda (4).
// `docs/previews/social-poster-layouts.html`.

/// A post just published, landing: one line per account that resolves on its
/// own, then what happened, in a sentence.
///
/// The composer becomes this on a desktop; on a phone it is the whole pane.
/// Both read the list the calendar reads, so the finish and the calendar
/// cannot disagree about one post.
class _PostOutcome extends StatelessWidget {
  const _PostOutcome({
    required this.post,
    required this.connections,
    required this.stillWaiting,
    required this.formats,
    required this.large,
    required this.onWriteAnother,
    this.onSeeCalendar,
    this.onDone,
    this.onRetry,
  });

  final _SentPost post;
  final List<_SocialConnection> connections;
  final void Function(_SentTarget)? onRetry;

  /// The poll gave up with accounts still queued: said as still sending,
  /// never as failed.
  final bool stillWaiting;
  final DisplayFormats formats;

  /// The phone's version: a ring while posting, a big mark after.
  final bool large;
  final VoidCallback onWriteAnother;
  final VoidCallback? onSeeCalendar;
  final VoidCallback? onDone;

  @override
  Widget build(BuildContext context) {
    final total = post.targets.length;
    final waiting = post.targets.where((t) => t.status == 'queued').length;
    final sending = waiting > 0 && !stillWaiting;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // The phone's way back, and what Android's Back presses.
        if (onDone != null)
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: IconButton(
              tooltip: 'Back to your posts',
              icon: const Icon(Icons.arrow_back),
              onPressed: onDone,
            ),
          ),
        if (sending)
          _progress(context, total - waiting, total)
        else
          _verdict(context),
        const SizedBox(height: 16),
        if (!sending) ...[_Quote(post: post), const SizedBox(height: 8)],
        for (final target in post.targets)
          _TargetLine(
            target: target,
            drafts: _draftsFor(target, connections),
            onRetry: onRetry == null ? null : () => onRetry!(target),
          ),
        if (!sending) ...[
          const SizedBox(height: 16),
          Wrap(
            alignment: large ? WrapAlignment.end : WrapAlignment.start,
            spacing: 8,
            runSpacing: 8,
            children: [
              if (onSeeCalendar != null && post.when != null)
                OutlinedButton(
                  onPressed: onSeeCalendar,
                  child: const Text('See it on the calendar'),
                ),
              if (onDone != null) ...[
                OutlinedButton(
                  onPressed: onWriteAnother,
                  child: const Text('Write another'),
                ),
                FilledButton(onPressed: onDone, child: const Text('Done')),
              ] else
                FilledButton(
                  onPressed: onWriteAnother,
                  child: const Text('Write another'),
                ),
            ],
          ),
        ],
      ],
    );
  }

  /// "Posting… 1 of 3 done" with a bar, or on a phone a ring that fills as
  /// each account answers. Filled by answers, never by a clock: a bar that
  /// moved on its own would be claiming progress nobody reported.
  Widget _progress(BuildContext context, int done, int total) {
    final theme = Theme.of(context);
    final share = total == 0 ? 0.0 : done / total;

    Widget filling(Widget Function(double) draw) =>
        TweenAnimationBuilder<double>(
          tween: Tween(end: share),
          duration: const Duration(milliseconds: 400),
          curve: Curves.easeOut,
          builder: (context, value, _) => draw(value),
        );

    if (!large) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Posting… $done of $total done',
            style: theme.textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          filling((value) => LinearProgressIndicator(value: value)),
        ],
      );
    }

    return Column(
      children: [
        SizedBox.square(
          dimension: 120,
          child: Stack(
            fit: StackFit.expand,
            children: [
              filling(
                (value) => CircularProgressIndicator(
                  value: value,
                  strokeWidth: 8,
                  backgroundColor: theme.colorScheme.surfaceContainerHighest,
                ),
              ),
              Center(
                child: Text(
                  '$done / $total',
                  style: theme.textTheme.headlineSmall,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Text(
          'Posting to your accounts',
          style: theme.textTheme.titleMedium,
          textAlign: TextAlign.center,
        ),
      ],
    );
  }

  Widget _verdict(BuildContext context) {
    final theme = Theme.of(context);
    final (icon, tone, title, line) = _say();
    final colours = ToneColours.of(context, tone);
    final size = large ? 88.0 : 40.0;

    // The flourish Jose asked for, and it ends: a mark that settles once.
    final mark = TweenAnimationBuilder<double>(
      tween: Tween(begin: 0.5, end: 1),
      duration: const Duration(milliseconds: 450),
      curve: Curves.easeOutBack,
      builder: (context, scale, child) =>
          Transform.scale(scale: scale, child: child),
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: colours.fill, shape: BoxShape.circle),
        child: Icon(icon, color: colours.ink, size: size * 0.55),
      ),
    );
    final align = large ? TextAlign.center : TextAlign.start;
    final words = Column(
      crossAxisAlignment: large
          ? CrossAxisAlignment.center
          : CrossAxisAlignment.start,
      children: [
        Text(
          title,
          textAlign: align,
          style: large
              ? theme.textTheme.titleLarge
              : theme.textTheme.titleMedium,
        ),
        const SizedBox(height: 4),
        Text(line, textAlign: align, style: theme.textTheme.bodyMedium),
      ],
    );

    if (large) {
      return Column(children: [mark, const SizedBox(height: 16), words]);
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        mark,
        const SizedBox(width: 12),
        Expanded(child: words),
      ],
    );
  }

  /// What happened, as a heading and a sentence.
  (IconData, Tone, String, String) _say() {
    final targets = post.targets;
    List<_SentTarget> withStatus(String status) =>
        targets.where((t) => t.status == status).toList();
    final waiting = withStatus('queued');
    final failed = withStatus('failed');
    final sent = withStatus('sent');
    final drafts = [
      for (final t in sent)
        if (_draftsFor(t, connections) != null) t,
    ];
    final live = [
      for (final t in sent)
        if (_draftsFor(t, connections) == null) t,
    ];
    String accounts(int n) => n == 1 ? '1 account' : '$n accounts';

    if (targets.isNotEmpty &&
        withStatus('scheduled').length == targets.length) {
      final when = post.when;
      final at = when == null
          ? ''
          : ' for ${_dayWord(when)} ${formats.formatTime(when.hour, when.minute)}';

      return (
        Icons.schedule,
        Tone.info,
        'Scheduled$at on ${accounts(targets.length)}',
        'It goes out on its own, and it is on the calendar now.',
      );
    }

    if (waiting.isNotEmpty) {
      return (
        Icons.hourglass_empty,
        Tone.caution,
        'Still sending',
        '${_names(waiting)} ${_names(waiting).contains(' and ') ? 'have' : 'has'} '
            'not answered yet. Nothing has failed; look again in a minute.',
      );
    }

    if (sent.isEmpty) {
      return (
        Icons.error_outline,
        Tone.bad,
        'Not posted',
        failed.length == 1
            ? '${_names(failed)} did not take it. The reason is below.'
            : 'None of the accounts took it. The reasons are below.',
      );
    }

    if (failed.isNotEmpty) {
      return (
        Icons.error_outline,
        Tone.caution,
        'Posted to ${sent.length} of ${targets.length} accounts',
        '${_names(failed)} did not take it. The reason is below.',
      );
    }

    if (live.isEmpty) {
      return (
        Icons.edit_note,
        Tone.ok,
        'Sent to your drafts',
        'Open ${_names(drafts)} to finish and post it.',
      );
    }

    String isAre(List<_SentTarget> some) =>
        _names(some).contains(' and ') ? 'are' : 'is';

    return (
      Icons.check_circle,
      Tone.ok,
      'Posted to ${accounts(targets.length)}',
      drafts.isEmpty
          ? '${_names(live)} ${isAre(live)} live.'
          : '${_names(live)} ${isAre(live)} live. ${_names(drafts)} '
                '${isAre(drafts)} waiting in your drafts.',
    );
  }
}

/// A post opened from the calendar: what went out, then every account.
class _PostDetail extends StatelessWidget {
  const _PostDetail({
    required this.post,
    required this.connections,
    this.onRetry,
    this.onEdit,
    this.onCancel,
    this.onDismiss,
    this.onDeleteRemote,
  });

  final _SentPost post;
  final List<_SocialConnection> connections;
  final void Function(_SentTarget)? onRetry;
  final VoidCallback? onEdit;
  final VoidCallback? onCancel;
  final void Function(_SentTarget)? onDismiss;
  final void Function(_SentTarget)? onDeleteRemote;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // The server's rule: editable and cancellable only while every account
    // is still waiting for its time.
    final waiting =
        post.targets.isNotEmpty &&
        post.targets.every((t) => t.status == 'scheduled');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _Quote(post: post),
        if (post.author != null) ...[
          const SizedBox(height: 6),
          Text('By ${post.author}', style: theme.textTheme.bodySmall),
        ],
        if (waiting && (onEdit != null || onCancel != null)) ...[
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              if (onEdit != null)
                OutlinedButton.icon(
                  key: const Key('social-post-edit'),
                  onPressed: onEdit,
                  icon: const Icon(Icons.edit_outlined, size: 18),
                  label: const Text('Edit or reschedule'),
                ),
              if (onCancel != null)
                TextButton.icon(
                  key: const Key('social-post-cancel'),
                  onPressed: onCancel,
                  icon: const Icon(Icons.cancel_outlined, size: 18),
                  label: const Text('Cancel post'),
                ),
            ],
          ),
        ],
        const SizedBox(height: 12),
        for (final target in post.targets)
          _TargetLine(
            target: target,
            drafts: _draftsFor(target, connections),
            onRetry: onRetry == null ? null : () => onRetry!(target),
            onDismiss: onDismiss == null ? null : () => onDismiss!(target),
            onDeleteRemote: onDeleteRemote == null
                ? null
                : () => onDeleteRemote!(target),
          ),
      ],
    );
  }
}

/// The post, shown back as it went out.
class _Quote extends StatelessWidget {
  const _Quote({required this.post});

  final _SentPost post;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final words = post.body.trim();

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLowest,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: theme.colorScheme.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            words.isEmpty ? post.preview : words,
            maxLines: 6,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodyMedium,
          ),
          if (post.hasMedia && words.isNotEmpty) ...[
            const SizedBox(height: 6),
            Row(
              children: [
                Icon(
                  Icons.image_outlined,
                  size: 16,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                const SizedBox(width: 6),
                Text(
                  'With a picture or video',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// One account's answer: posted with its link, in drafts, failed with the
/// network's own sentence, or still going.
class _TargetLine extends StatelessWidget {
  const _TargetLine({
    required this.target,
    required this.drafts,
    this.onRetry,
    this.onDismiss,
    this.onDeleteRemote,
  });

  /// Hide one failed account's line without trying again.
  final VoidCallback? onDismiss;

  /// Take a posted one down from its network.
  final VoidCallback? onDeleteRemote;

  final _SentTarget target;

  /// The account's posting note, when what "sent" means is its drafts.
  final String? drafts;

  /// Send it again; drawn on a failed account only, and null without the
  /// right to post.
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final network = target.providerLabel.isEmpty
        ? 'the network'
        : target.providerLabel;
    final (word, tone, icon) = switch (target.status) {
      'queued' => ('Posting…', Tone.neutral, Icons.hourglass_top),
      'scheduled' => ('Scheduled', Tone.info, Icons.schedule),
      'removed' => ('Deleted from $network', Tone.neutral, Icons.delete_outline),
      // The network's sentence, whole: "failed" says something is wrong and
      // nothing about what to do.
      'failed' => (
        target.failure ?? 'Did not post.',
        Tone.bad,
        Icons.error_outline,
      ),
      _ when drafts != null => (
        'In your $network drafts. Finish it in the $network app.',
        Tone.ok,
        Icons.edit_note,
      ),
      _ => ('Posted', Tone.ok, Icons.check_circle_outline),
    };
    final colours = ToneColours.of(context, tone);
    final url = target.url;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SocialNetworkMark(network: target.provider ?? '', size: 24),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  target.account.isEmpty ? network : target.account,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleSmall,
                ),
                Text(
                  word,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: colours.ink,
                  ),
                ),
                if (url != null && target.status == 'sent')
                  TextButton.icon(
                    style: TextButton.styleFrom(
                      padding: EdgeInsets.zero,
                      visualDensity: VisualDensity.compact,
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    onPressed: () => launchUrl(
                      Uri.parse(url),
                      mode: LaunchMode.externalApplication,
                    ),
                    icon: const Icon(Icons.open_in_new, size: 16),
                    label: Text('View on $network'),
                  ),
                if (target.status == 'failed' &&
                    target.id != null &&
                    onRetry != null)
                  TextButton.icon(
                    style: TextButton.styleFrom(
                      padding: EdgeInsets.zero,
                      visualDensity: VisualDensity.compact,
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    onPressed: onRetry,
                    icon: const Icon(Icons.refresh, size: 16),
                    label: const Text('Try again'),
                  ),
                if (target.status == 'failed' &&
                    target.id != null &&
                    onDismiss != null)
                  TextButton.icon(
                    key: ValueKey('social-target-dismiss-${target.id}'),
                    style: TextButton.styleFrom(
                      padding: EdgeInsets.zero,
                      visualDensity: VisualDensity.compact,
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    onPressed: onDismiss,
                    icon: const Icon(Icons.close, size: 16),
                    label: const Text('Dismiss'),
                  ),
                if (target.status == 'sent' &&
                    target.id != null &&
                    onDeleteRemote != null)
                  target.canDelete
                      ? TextButton.icon(
                          key: ValueKey('social-target-delete-${target.id}'),
                          style: TextButton.styleFrom(
                            padding: EdgeInsets.zero,
                            visualDensity: VisualDensity.compact,
                            tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                            foregroundColor: theme.colorScheme.error,
                          ),
                          onPressed: onDeleteRemote,
                          icon: const Icon(Icons.delete_outline, size: 16),
                          label: Text('Delete from $network'),
                        )
                      : Text(
                          '$network does not let EzBizi delete posts. Delete '
                          'it in $network itself.',
                          style: theme.textTheme.bodySmall,
                        ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Icon(icon, size: 18, color: colours.ink),
        ],
      ),
    );
  }
}

/// A day's posts for a calendar square: two, then "+N more".
class _DayEntries {
  const _DayEntries({
    required this.byDay,
    required this.outlined,
    required this.formats,
    required this.onOpen,
  });

  final Map<DateTime, List<_SentPost>> byDay;
  final String? outlined;
  final DisplayFormats formats;
  final void Function(_SentPost)? onOpen;

  /// ponytail: two, which is what a month square holds. A busier day is read
  /// in the Sent list; a day view is the upgrade if that stops being enough.
  static const int _room = 2;

  List<Widget> on(DateTime day) {
    final posts = byDay[day] ?? const <_SentPost>[];

    return [
      for (final post in posts.take(_room))
        _PostChip(
          post: post,
          formats: formats,
          outlined: post.id == outlined,
          onOpen: onOpen,
        ),
      if (posts.length > _room)
        Builder(
          builder: (context) => Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(
              '+${posts.length - _room} more',
              style: Theme.of(context).textTheme.labelSmall,
            ),
          ),
        ),
    ];
  }
}

/// Posts by the day they sit on, each day in time order.
Map<DateTime, List<_SentPost>> _postsByDay(List<_SentPost> posts) {
  final byDay = <DateTime, List<_SentPost>>{};

  for (final post in posts) {
    final when = post.when;
    if (when == null) continue;
    byDay.putIfAbsent(_CalendarsColumn._dateOnly(when), () => []).add(post);
  }
  for (final day in byDay.values) {
    day.sort((a, b) => a.when!.compareTo(b.when!));
  }

  return byDay;
}

/// One post in a calendar square: its time and first words, in its colour.
class _PostChip extends StatelessWidget {
  const _PostChip({
    required this.post,
    required this.formats,
    required this.outlined,
    required this.onOpen,
  });

  final _SentPost post;
  final DisplayFormats formats;

  /// The newest post, outlined the first time it is seen.
  final bool outlined;
  final void Function(_SentPost)? onOpen;

  @override
  Widget build(BuildContext context) {
    final colours = ToneColours.of(context, post.tone);
    final when = post.when!;
    final open = onOpen;

    return Padding(
      padding: const EdgeInsets.only(top: 3),
      child: Material(
        color: colours.fill,
        clipBehavior: Clip.antiAlias,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(6),
          side: outlined
              ? BorderSide(color: colours.ink, width: 1.5)
              : BorderSide.none,
        ),
        child: InkWell(
          onTap: open == null ? null : () => open(post),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
            child: Row(
              children: [
                Icon(_toneIcon(post.tone), size: 11, color: colours.ink),
                const SizedBox(width: 3),
                Expanded(
                  child: Text(
                    '${_clockShort(when, formats)} ${post.preview}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(
                      context,
                    ).textTheme.labelSmall?.copyWith(color: colours.ink),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The phone's calendar. A month grid does not fit a phone; a list of days
/// does: Today, Tomorrow, the days ahead, then Earlier.
class _Agenda extends StatelessWidget {
  const _Agenda({
    super.key,
    required this.posts,
    required this.outlined,
    required this.formats,
    required this.onOpen,
  });

  final List<_SentPost> posts;
  final String? outlined;
  final DisplayFormats formats;
  final void Function(_SentPost) onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ahead = <int, List<_SentPost>>{};
    final earlier = <_SentPost>[];

    for (final post in posts) {
      final when = post.when;
      final days = when == null ? -1 : _daysFromToday(when);
      if (days < 0) {
        earlier.add(post);
      } else {
        ahead.putIfAbsent(days, () => []).add(post);
      }
    }

    int byTime(_SentPost a, _SentPost b) =>
        (a.when ?? DateTime(0)).compareTo(b.when ?? DateTime(0));

    final sections = <(String, List<_SentPost>, bool)>[
      for (final days in ahead.keys.toList()..sort())
        (
          switch (days) {
            0 => 'Today',
            1 => 'Tomorrow',
            _ => _shortDay(ahead[days]!.first.when!),
          },
          // Today newest first, so what was just posted is at the top; the
          // days ahead in the order they come.
          ahead[days]!..sort((a, b) => days == 0 ? byTime(b, a) : byTime(a, b)),
          false,
        ),
      if (earlier.isNotEmpty)
        ('Earlier', earlier..sort((a, b) => byTime(b, a)), true),
    ];

    return DashboardCard(
      key: const ValueKey('tour-marketing-agenda'),
      title: 'Your posts',
      icon: Icons.view_agenda_outlined,
      child: sections.isEmpty
          ? const DashboardNote(
              'Nothing posted or scheduled yet. What you publish lands here, '
              'on its day.',
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final (heading, list, dated) in sections) ...[
                  Padding(
                    padding: const EdgeInsets.only(top: 4, bottom: 6),
                    child: Text(heading, style: theme.textTheme.titleSmall),
                  ),
                  for (final post in list)
                    _AgendaRow(
                      post: post,
                      formats: formats,
                      outlined: post.id == outlined,
                      dated: dated,
                      onOpen: onOpen,
                    ),
                ],
              ],
            ),
    );
  }
}

class _AgendaRow extends StatelessWidget {
  const _AgendaRow({
    required this.post,
    required this.formats,
    required this.outlined,
    required this.dated,
    required this.onOpen,
  });

  final _SentPost post;
  final DisplayFormats formats;
  final bool outlined;

  /// Earlier posts carry their date; the rest sit under one already.
  final bool dated;
  final void Function(_SentPost) onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colours = ToneColours.of(context, post.tone);
    final when = post.when;
    final stamp = when == null
        ? ''
        : dated
        ? '${_CalendarsColumn._shortMonths[when.month - 1]} ${when.day}'
        : formats.formatTime(when.hour, when.minute);
    final networks = {
      for (final t in post.targets)
        if (t.provider != null) t.provider!,
    };

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        key: ValueKey('agenda-${post.id}'),
        color: theme.colorScheme.surface,
        clipBehavior: Clip.antiAlias,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(10),
          side: BorderSide(
            color: outlined ? colours.ink : theme.colorScheme.outlineVariant,
            width: outlined ? 2 : 1,
          ),
        ),
        child: InkWell(
          onTap: () => onOpen(post),
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(_toneIcon(post.tone), size: 20, color: colours.ink),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Wrap(
                        spacing: 6,
                        runSpacing: 2,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          if (stamp.isNotEmpty)
                            Text(stamp, style: theme.textTheme.titleSmall),
                          for (final network in networks)
                            SocialNetworkMark(network: network, size: 14),
                          Text(
                            _postWord(post),
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: colours.ink,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 2),
                      Text(
                        post.preview,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyMedium,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The account's posting note when [target] went to its drafts, not live.
String? _draftsFor(_SentTarget target, List<_SocialConnection> connections) {
  if (target.status != 'sent') return null;

  for (final connection in connections) {
    if (connection.id == target.connectionId) return connection.postingNote;
  }

  return null;
}

/// "Facebook", "Facebook and Instagram", "Facebook, Instagram and TikTok".
String _names(List<_SentTarget> targets) {
  final names = {
    for (final t in targets)
      t.providerLabel.isEmpty ? t.account : t.providerLabel,
  }.toList();
  if (names.length < 2) return names.join();

  return '${names.sublist(0, names.length - 1).join(', ')} and ${names.last}';
}

IconData _toneIcon(Tone tone) => switch (tone) {
  Tone.bad => Icons.error_outline,
  Tone.caution => Icons.warning_amber_outlined,
  Tone.neutral => Icons.hourglass_top,
  Tone.info => Icons.schedule,
  _ => Icons.check_circle_outline,
};

/// A post's state in a word or two.
String _postWord(_SentPost post) => switch (post.tone) {
  Tone.bad => 'needs a look',
  Tone.neutral => 'posting…',
  Tone.info => 'scheduled',
  _ => 'posted',
};

const _weekdayNames = [
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
  'Sunday',
];

/// Whole days from today to [day], counted on the calendar rather than in
/// hours, so the night the clocks change is still one day.
int _daysFromToday(DateTime day) {
  final now = DateTime.now();

  return DateTime.utc(
    day.year,
    day.month,
    day.day,
  ).difference(DateTime.utc(now.year, now.month, now.day)).inDays;
}

/// "Fri, Oct 2".
String _shortDay(DateTime day) =>
    '${_CalendarsColumn._days[day.weekday - 1]}, '
    '${_CalendarsColumn._shortMonths[day.month - 1]} ${day.day}';

/// "today", "tomorrow", "Tuesday" within the week, else "Fri, Oct 2".
String _dayWord(DateTime day) => switch (_daysFromToday(day)) {
  0 => 'today',
  1 => 'tomorrow',
  final n when n > 1 && n < 7 => _weekdayNames[day.weekday - 1],
  _ => _shortDay(day),
};

/// "9a", "2:30p" on a 12-hour clock, "09:00" on a 24-hour one. A calendar
/// square has room for a time or for words, and "9:00 AM" left it neither.
String _clockShort(DateTime time, DisplayFormats formats) {
  if (formats.timeFormat != '12h') {
    return formats.formatTime(time.hour, time.minute);
  }

  final hour = time.hour % 12 == 0 ? 12 : time.hour % 12;
  final minute = time.minute == 0
      ? ''
      : ':${time.minute.toString().padLeft(2, '0')}';

  return '$hour$minute${time.hour < 12 ? 'a' : 'p'}';
}

/// "Today 3:14 PM · posted", for the panel's subtitle.
String _stamp(_SentPost post, DisplayFormats formats) {
  final when = post.when;
  if (when == null) return _postWord(post);

  final day = _dayWord(when);

  return '${day[0].toUpperCase()}${day.substring(1)} '
      '${formats.formatTime(when.hour, when.minute)} · ${_postWord(post)}';
}
