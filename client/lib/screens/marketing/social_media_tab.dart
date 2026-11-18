import 'dart:async';
import 'dart:math' as math;

import 'package:desktop_drop/desktop_drop.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image/image.dart' as img;

import '../../widgets/list_search.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:video_player/video_player.dart';

import 'clock_label.dart';
import 'comments_inbox_tab.dart';
import 'connection_setup.dart';
import 'instagram_comments_panel.dart';
import 'qr_codes_tab.dart';
import 'tiktok_post_choices.dart';
import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/disconnect_panel.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/social_network_mark.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../email/consent_window.dart';
import 'media_library_panel.dart';
import 'compose_listings_picker.dart';

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
    this.videoPlayer = _videoPlayer,
  });

  final ApiClient api;
  final Session session;
  final OpenConsentWindow openConsent;

  /// How a file is chosen. Injectable for the same reason [openConsent] is:
  /// `Files.pickBytes` reaches the platform, so a test that could not replace
  /// it could not reach the preview at all — and the preview is the whole
  /// point of attaching a file you are about to publish under your own name.
  final Future<PickedBytes?> Function() pickMedia;

  /// The one player for an attached video, or null where none can run.
  /// Injectable so a test can stand in for the platform player.
  final VideoPlayerController? Function(Uri) videoPlayer;

  @override
  State<SocialMediaTab> createState() => _SocialMediaTabState();
}

Future<PickedBytes?> _pickMedia() => Files.pickBytes(accept: 'image/*,video/*');

/// No platform player answers under `flutter test`; null keeps the label.
VideoPlayerController? _videoPlayer(Uri uri) =>
    Files.underTest ? null : VideoPlayerController.networkUrl(uri);

class _SocialMediaTabState extends State<SocialMediaTab> {
  /// Roughly a minute at three seconds a try. Long enough for a video upload
  /// to a slow network, short enough that a dead worker is reported rather
  /// than drawn as progress forever.
  static const int _pollLimit = 20;

  /// The panes a role may be granted, each its own `marketing.<key>.view`.
  /// Since UI queue 199 they are not tabs: the calendar is the page, and the
  /// rest open beside it.

  static const _panes = [
    ('dashboard', 'Dashboard', Icons.insights_outlined),
    ('compose', 'Compose', Icons.edit_outlined),
    ('calendar', 'Calendar', Icons.calendar_month_outlined),
    ('accounts', 'Accounts', Icons.link_outlined),
    ('comments', 'Comments', Icons.forum_outlined),
    ('qr_codes', 'QR Codes', Icons.qr_code_2_outlined),
  ];

  /// Absent `/me` answers NO.
  bool _mayOpen(String pane) =>
      widget.session.me?.permissions.contains('marketing.$pane.view') ?? false;

  /// Each of the three post panes reads the posts list; Accounts and QR Codes
  /// do not, and are not made to ask for it.
  bool get _mayReadPosts =>
      _mayOpen('dashboard') || _mayOpen('compose') || _mayOpen('calendar');

  List<(String, String, IconData)> get _shownPanes => [
    for (final pane in _panes)
      if (_mayOpen(pane.$1)) pane,
  ];

  List<_SocialNetwork> _providers = const [];
  List<_SocialConnection> _connections = const [];

  /// Unreplied comments across the accounts, for the Comments tab's badge.
  int _unreplied = 0;

  List<CommentSource> get _commentSources => [
    for (final c in _connections)
      if (CommentsInbox.hasComments(c.provider))
        (id: c.id, name: c.displayName, provider: c.provider),
  ];

  Future<void> _countComments() async {
    if (!_mayOpen('comments') || _commentSources.isEmpty) return;
    final read = await CommentsInbox.load(widget.api, _commentSources);
    if (mounted) {
      setState(
        () => _unreplied = read.comments.where((c) => c.unreplied).length,
      );
    }
  }

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

  /// A calendar day tapped, at 9:00: Compose opens set to it.
  DateTime? _composeAt;

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
    // A post sent, failed, approved or written elsewhere (the minute sweep,
    // a colleague, the weekly listing posts): the Posts list and its tiles
    // re-read; Compose's draft and an open post are their own.
    _live = LiveChanges.of(widget.api).subscribe(
      {'social_posts'},
      (_) => _loadPosts(),
      active: LiveChanges.visible(this),
    );
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    _poll?.cancel();
    _searchDebounce?.cancel();
    _search.dispose();
    _tick.dispose();
    _newPostFocus.dispose();
    _composerFocus.dispose();
    super.dispose();
  }

  Map<String, String>? get _postsQuery => _q.isEmpty ? null : {'q': _q};

  void _searched(String text) {
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      setState(() {
        _q = text.trim();
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
    });

    _watchPending();
    unawaited(_countComments());
  }

  /// Just the Sent list. Used by the poll below, which has no business
  /// re-reading the catalogue and the connections every few seconds.
  Future<void> _loadPosts() async {
    if (!_mayReadPosts) return;

    final posts = await widget.api.get('/social/posts', _postsQuery);
    if (!mounted || !posts.ok) return;

    setState(() {
      _posts = _readPosts(posts);
    });
  }

  static List<_SentPost> _readPosts(ApiResponse response) =>
      ((response.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SentPost.fromJson)
          .toList();

  /// Every failed account of one post, tried again as one action (review
  /// 2026-11-09 B5): the ones that went out are left alone.
  Future<void> _retryAll(_SentPost post) async {
    final failed = [
      for (final t in post.targets)
        if (t.status == 'failed' && t.id != null) t,
    ];
    final refused = <String>[];
    for (final target in failed) {
      final response = await widget.api.post(
        '/social/posts/${post.id}/targets/${target.id}/retry',
        const {},
      );
      if (!response.ok) refused.add(response.reason);
    }
    if (!mounted) return;
    if (refused.isNotEmpty) {
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(refused.first)));
    }
    setState(() {
      _justPosted = post.id;
      _gaveUpWaiting = false;
      _composing = true;
      _composerBuilt = true;
    });
    await _load();
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
      _composing = false;
      if (when != null) _focusDay = when;
      if (leave) _justPosted = null;
    });
  }

  Future<void> _openPost(_SentPost post) {
    return showSidePanel<void>(
      context,
      builder: (panel) => SidePanel(
        title: 'Post',
        subtitle: _stamp(post, _formats),
        child: _postDetail(post, () => Navigator.of(panel).pop()),
      ),
    );
  }

  /// One post's proof and what can still be done to it; [close] puts away
  /// whatever it is drawn in (the planner's inspector or a phone's panel).
  Widget _postDetail(_SentPost post, VoidCallback close) => _PostDetail(
    key: ValueKey('post-detail-${post.id}'),

    post: post,
    connections: _connections,
    // The words and the picture, edited in place before it goes out
    // (Jose, 2026-11-16).
    api: _canPost ? widget.api : null,
    pickMedia: widget.pickMedia,
    canEditLibrary: _canPost,
    onSaved: _canPost ? _loadPosts : null,
    // The panel closes and the composer follows the account, the same
    // moment a new post gets.
    onRetry: _canPost
        ? (target) {
            close();
            _retry(post, target);
          }
        : null,
    // UI queue 100: what can still be done to it, each behind the same
    // marketing.edit the server checks.
    onEdit: _canPost
        ? () {
            close();
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
                mounted) {
              close();
              _act(widget.api.delete('/social/posts/${post.id}'));
            }
          }
        : null,
    onDismiss: _canPost
        ? (target) {
            close();
            _act(
              widget.api.post(
                '/social/posts/${post.id}/targets/${target.id}/dismiss',
                const {},
              ),
            );
          }
        : null,
    onRetryAll: _canPost
        ? () {
            close();
            _retryAll(post);
          }
        : null,
    onDecide: _canPost
        ? (target, approve) {
            close();
            // Literal paths: ClientCallsRealRoutesTest reads them.
            _act(
              widget.api.post(
                approve
                    ? '/business-profile/post-queue/${target.id}/approve'
                    : '/business-profile/post-queue/${target.id}/disapprove',
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
                mounted) {
              close();
              _act(
                widget.api.delete(
                  '/social/posts/${post.id}/targets/${target.id}/remote',
                ),
              );
            }
          }
        : null,
  );

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
    final shownWhen = wall.length > 16 ? wall.substring(0, 16) : wall;
    final when = TextEditingController(text: shownWhen);
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
            // The composer's own picker, not a typed date (review
            // 2026-11-09 A21).
            CalendarDayField(
              key: const Key('social-edit-when'),
              controller: when,
              formats: _formats,
              label: 'Goes out',
              withTime: true,
              firstDate: DateTime.now(),
              helper: clockLabel(post.scheduledTimezone).isEmpty
                  ? 'On this view’s clock'
                  : 'On the ${clockLabel(post.scheduledTimezone)} clock',
            ),
          ],
        ),
      ),
    );
    if (saved != true || !mounted) return;

    await _act(
      widget.api.patch('/social/posts/${post.id}', {
        'body': body.text,
        // Only a moved time is sent: re-sending an unchanged one would be
        // re-read as new (bug review 2026-11-09 #9).
        if (when.text.trim().isNotEmpty && when.text.trim() != shownWhen)
          'scheduled_for': when.text.trim(),
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
      _composing = true;
      _composerBuilt = true;
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

  /// Bumped on every setState, so a panel open over the tab (its own route)
  /// redraws with what the tab now knows: Connections after a connect, say.
  final _tick = ValueNotifier(0);

  @override
  void setState(VoidCallback fn) {
    super.setState(fn);
    _tick.value++;
  }

  /// The composer, open over the planner. Kept built once opened, so closing
  /// it keeps the draft; Discard is the composer's own clear.
  bool _composing = false;
  bool _composerBuilt = false;
  final _newPostFocus = FocusNode(debugLabel: 'New post');
  final _composerFocus = FocusNode(debugLabel: 'Composer');

  void _openComposer({DateTime? at}) {
    setState(() {
      if (at != null) _composeAt = DateTime(at.year, at.month, at.day, 9);
      _composing = true;
      _composerBuilt = true;
    });
    // Esc closes it, which needs the keyboard inside it.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted && _composing) _composerFocus.requestFocus();
    });
  }

  void _closeComposer() {
    setState(() {
      _composing = false;
      // A post that has landed is done with: the next New post is a blank one.
      _justPosted = null;
    });
    _newPostFocus.requestFocus();
  }

  Future<void> _openConnections() => showSidePanel<void>(
    context,
    builder: (panel) => ValueListenableBuilder<int>(
      valueListenable: _tick,
      builder: (context, _, _) => SidePanel(
        key: const Key('social-connections-panel'),
        title: 'Connections',
        subtitle: _viewName,
        width: 720,
        scrollable: false,
        child: _AccountsPane(
          api: widget.api,
          canChange: widget.session.me?.can('marketing.edit') ?? false,
          viewName: _viewName,
          providers: _providers,
          connections: _connections,
          error: _loadError,
          onConnect: () => _openConnect(context),
          onDisconnect: _disconnect,
          onComments: _openComments,
        ),
      ),
    ),
  );

  Future<void> _openInbox() => showSidePanel<void>(
    context,
    builder: (panel) => SidePanel(
      key: const Key('social-comments-panel'),
      title: 'Comments',
      width: 720,
      scrollable: false,
      child: CommentsInbox(
        api: widget.api,
        sources: _commentSources,
        canAnswer: _canPost,
        onUnreplied: (n) => setState(() => _unreplied = n),
      ),
    ),
  );

  Future<void> _openQr() => showSidePanel<void>(
    context,
    builder: (panel) => SidePanel(
      key: const Key('social-qr-panel'),
      title: 'QR codes',
      width: 900,
      scrollable: false,
      child: QrCodesTab(api: widget.api, session: widget.session),
    ),
  );

  Widget _composer(bool phone) {
    final landing = _landing;
    if (phone && landing != null) {
      return ListView(
        key: const ValueKey('tour-marketing-compose-outcome'),
        padding: const EdgeInsets.all(16),
        children: [
          _PostOutcome(
            post: landing,
            connections: _connections,
            stillWaiting: _gaveUpWaiting,
            formats: _formats,
            large: true,
            onWriteAnother: () => setState(() => _justPosted = null),
            onDone: () => _toCalendar(leave: true),
            onRetry: _canPost ? (target) => _retry(landing, target) : null,
          ),
        ],
      );
    }
    return _ComposeColumn(
      api: widget.api,
      session: widget.session,
      viewName: _viewName,
      connections: _connections,
      stillWaiting: _gaveUpWaiting,
      pickMedia: widget.pickMedia,
      videoPlayer: widget.videoPlayer,
      landing: landing,
      formats: _formats,
      onSent: _posted,
      onWriteAnother: () => setState(() => _justPosted = null),
      onSeeCalendar: _toCalendar,
      onRetry: _canPost ? _retry : null,
      composeAt: _composeAt,
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_shownPanes.isEmpty) {
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

    // Offered with the Compose grant alone: the composer itself says why a
    // role without marketing.edit cannot publish (UI rule: say, don't hide).
    final canNew = _mayOpen('compose');
    final broken = _connections.where((c) => c.broken).length;

    // UI queue 199: one row of tools beside the work, not six tabs. A phone
    // gets the same tools as icons, so they stay one row.
    Widget tools(bool phone) {
      Widget tool(
        Key key,
        String label,
        IconData icon,
        VoidCallback onPressed, {
        String? badge,
        bool dot = false,
      }) {
        final mark = Badge(
          isLabelVisible: badge != null || dot,
          smallSize: 8,
          label: badge == null ? null : Text(badge),
          child: Icon(icon, size: phone ? 22 : 18),
        );
        return phone
            ? IconButton(
                key: key,
                tooltip: label,
                onPressed: onPressed,
                icon: mark,
              )
            : OutlinedButton.icon(
                key: key,
                onPressed: onPressed,
                icon: mark,
                label: Text(label),
              );
      }

      return Wrap(
        key: const ValueKey('tour-marketing-social-panes'),
        alignment: WrapAlignment.end,
        crossAxisAlignment: WrapCrossAlignment.center,
        spacing: phone ? 2 : 8,
        runSpacing: 8,
        children: [
          if (_mayOpen('comments'))
            tool(
              const Key('social-comments'),
              'Comments',
              Icons.forum_outlined,
              _openInbox,
              badge: _unreplied > 0 ? '$_unreplied' : null,
            ),
          if (_mayOpen('accounts'))
            tool(
              const Key('social-connections'),
              broken > 0
                  ? 'Connections · $broken need attention'
                  : 'Connections',
              Icons.link_outlined,
              _openConnections,
              dot: broken > 0,
            ),
          if (_mayOpen('qr_codes'))
            tool(
              const Key('social-qr'),
              'QR codes',
              Icons.qr_code_2_outlined,
              _openQr,
            ),
          if (canNew)
            FilledButton.icon(
              key: const ValueKey('tour-marketing-new-post'),
              focusNode: _newPostFocus,
              onPressed: () => _openComposer(),
              icon: const Icon(Icons.add, size: 18),
              label: const Text('New post'),
            ),
        ],
      );
    }

    // Without the posts, the first tool this role has is the page.
    final Widget body = _mayReadPosts
        ? _Planner(
            api: widget.api,
            posts: _shown,
            formats: _formats,
            search: _search,
            onSearch: _searched,
            matching: _matching,
            detailFor: _postDetail,
            openPost: _openPost,
            onNewOn: canNew ? (day) => _openComposer(at: day) : null,
            focusDay: _focusDay,
            outlined: _outlined,
            stillWaiting: _gaveUpWaiting,
          )
        : _mayOpen('accounts')
        ? _AccountsPane(
            api: widget.api,
            canChange: widget.session.me?.can('marketing.edit') ?? false,
            viewName: _viewName,
            providers: _providers,
            connections: _connections,
            error: _loadError,
            onConnect: () => _openConnect(context),
            onDisconnect: _disconnect,
            onComments: _openComments,
          )
        : _mayOpen('comments')
        ? CommentsInbox(
            api: widget.api,
            sources: _commentSources,
            canAnswer: _canPost,
            onUnreplied: (n) => setState(() => _unreplied = n),
          )
        : QrCodesTab(api: widget.api, session: widget.session);

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < _Planner.phoneBelow;
        final width = phone ? box.maxWidth : math.min(980.0, box.maxWidth - 32);
        return Stack(
          children: [
            Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 10),
                  child: Align(
                    alignment: Alignment.centerRight,
                    child: tools(phone),
                  ),
                ),
                Expanded(
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(
                      phone ? 0 : 16,
                      0,
                      phone ? 0 : 16,
                      phone ? 0 : 16,
                    ),
                    child: body,
                  ),
                ),
              ],
            ),
            if (_composing)
              Positioned.fill(
                child: GestureDetector(
                  key: const Key('social-composer-scrim'),
                  onTap: _closeComposer,
                  child: ColoredBox(
                    color: Theme.of(
                      context,
                    ).colorScheme.scrim.withValues(alpha: 0.25),
                  ),
                ),
              ),
            if (_composerBuilt)
              // Keyed: the scrim before it comes and goes, and an unkeyed
              // sibling would be matched to its slot and the draft dropped.
              Positioned(
                key: const ValueKey('social-composer-slot'),
                top: 0,
                right: 0,
                bottom: 0,
                width: width,
                child: Offstage(
                  offstage: !_composing,
                  child: CallbackShortcuts(
                    bindings: {
                      const SingleActivator(LogicalKeyboardKey.escape):
                          _closeComposer,
                    },
                    child: Focus(
                      focusNode: _composerFocus,
                      child: Material(
                        key: const Key('social-composer'),
                        elevation: 12,
                        // Not a docked SidePanel: that caps its body at 720,
                        // and the preview belongs beside the words.
                        child: SafeArea(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              ListTile(
                                contentPadding: const EdgeInsets.fromLTRB(
                                  20,
                                  8,
                                  8,
                                  4,
                                ),
                                title: Text(
                                  'New post',
                                  style: Theme.of(context).textTheme.titleLarge,
                                ),
                                subtitle: const Text(
                                  'Write once, preview each destination',
                                ),
                                trailing: IconButton(
                                  key: const Key('social-composer-close'),
                                  tooltip: 'Close',
                                  onPressed: _closeComposer,
                                  icon: const Icon(Icons.close),
                                ),
                              ),
                              const Divider(height: 1),
                              Expanded(child: _composer(phone)),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
          ],
        );
      },
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

/// Longest post each network takes, in characters (Mia, 2026-10-02). One map
/// so a network's change is one line here.
///
/// Client-side only, and deliberately so: the server checks one 5,000 ceiling
/// (SocialPostsController::MAX_BODY) because most of these networks have no
/// publisher yet. This is the warning before Publish, in the network's own
/// number. A network missing here is counted without a limit.
/// The same numbers as SocialProvider::maxBodyLength() on the server, which
/// refuses (never cuts) a post over them.
const Map<String, int> _bodyLimits = {
  'x': 280,
  'bluesky': 300,
  'threads': 500,
  'pinterest': 800,
  'linkedin': 3000,
  'instagram_professional': 2200,
  'tiktok': 2200,
  'google_business_profile': 1500,
  'facebook_page': 63206,
};

/// "Healthy · checked 2h ago", or what broke.
String _healthLine(_SocialConnection page) {
  final at = page.lastCheckedAt;
  String ago() {
    if (at == null) return '';
    final d = DateTime.now().difference(at.toLocal());
    final text = d.inMinutes < 1
        ? 'just now'
        : d.inHours < 1
        ? '${d.inMinutes}m ago'
        : d.inDays < 1
        ? '${d.inHours}h ago'
        : '${d.inDays}d ago';
    return ' · checked $text';
  }

  if (!page.broken) return 'Healthy${ago()}';
  final reason = page.statusReason?.trim() ?? '';
  return 'Needs reconnect${reason.isEmpty ? '' : ': $reason'}${ago()}';
}

/// "3 accounts and 39 listings": a listing is not an account.
String _destinations(int accounts, int listings) {
  String n(int count, String one) => count == 1 ? '1 $one' : '$count ${one}s';

  return [
    if (accounts > 0) n(accounts, 'account'),
    if (listings > 0) n(listings, 'listing'),
  ].join(' and ');
}

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
    required this.videoPlayer,
    required this.landing,
    required this.formats,
    required this.onSent,
    required this.onWriteAnother,
    required this.onSeeCalendar,
    this.onRetry,
    this.composeAt,
  });

  /// Set from a calendar day: Later, at this wall time.
  final DateTime? composeAt;

  final ApiClient api;
  final Session session;
  final String viewName;
  final List<_SocialConnection> connections;
  final bool stillWaiting;
  final Future<PickedBytes?> Function() pickMedia;
  final VideoPlayerController? Function(Uri) videoPlayer;
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

  /// Whether the listings show one by one, remembered per person.
  static const _listingsExpanded = 'marketing.social.listings_expanded';

  /// What was pressed here, which wins over the saved value until the next
  /// visit: a refused save must not fold the list shut under the person.
  bool? _expandedNow;
  bool get _expanded =>
      _expandedNow ?? widget.session.me?.preferences[_listingsExpanded] == true;

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
    if (item['kind'] != 'video') return;

    // The stored file, through the signed-in API: a <video> on the web
    // cannot send the token, so it plays from the bytes like a fresh pick.
    final bytes = await widget.api.getBytes(
      '/social/library/${item['id']}/file',
    );
    if (!mounted || _libraryItem != item) return;
    if (bytes == null) {
      setState(() => _videoFailed = true);
      return;
    }
    await _loadVideo(bytes, '${item['name'] ?? ''}');
  }

  @override
  void initState() {
    super.initState();
    _loadPlaces();
    _takeComposeAt();
  }

  @override
  void didUpdateWidget(_ComposeColumn old) {
    super.didUpdateWidget(old);
    if (widget.composeAt != old.composeAt) _takeComposeAt();
  }

  void _takeComposeAt() {
    final at = widget.composeAt;
    if (at == null) return;
    String two(int n) => n.toString().padLeft(2, '0');
    _scheduling = true;
    _when.text =
        '${at.year}-${two(at.month)}-${two(at.day)} '
        '${two(at.hour)}:${two(at.minute)}';
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

  /// The attached video could not be played; the label stays, with a note.
  bool _videoFailed = false;

  /// Bumped on every release, so a load that finishes late is ignored.
  int _videoGen = 0;

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
      final limit = account.bodyLimit;
      if (limit != null && length > limit) {
        return '${account.providerLabel} takes $limit characters at most and '
            'this is $length. Shorten it, or untick ${account.providerLabel}.';
      }
    }

    // A ticked Business Profile listing is a Google post too.
    final google = _bodyLimits['google_business_profile']!;
    if (_pickedPlaces.isNotEmpty && length > google) {
      return 'Google Business Profile takes $google characters at most and '
          'this is $length. Shorten it, or untick the listings.';
    }

    return null;
  }

  @override
  void dispose() {
    _body.dispose();
    _when.dispose();
    _ctaUrl.dispose();
    // Nothing draws this column any more, so the player can go now.
    _releaseMedia(now: true);
    super.dispose();
  }

  /// Let go of the player and the object URL behind it.
  ///
  /// A blob is held until revoked, so picking three videos while deciding
  /// would otherwise keep all three in memory for the life of the page.
  ///
  /// Disposed only after the frame that stops drawing it (as the help video,
  /// bc7dad52f): disposing while VideoPlayer is still on screen threw
  /// "No active player with ID n" on Android.
  void _releaseMedia({bool now = false}) {
    final player = _player;
    final url = _mediaUrl;
    _player = null;
    _mediaUrl = null;
    _videoFailed = false;
    _videoGen++;

    void release() {
      player?.dispose();
      if (url != null) Files.releasePreviewUrl(url);
    }

    if (now || (player == null && url == null)) {
      release();
      return;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) => release());
    WidgetsBinding.instance.scheduleFrame();
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
      final why = choice.refusal(account.displayName, _player?.value.duration);
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

    return _scheduling && _when.text.trim().isNotEmpty
        ? 'Schedule'
        : 'Publish now';
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

    await _loadVideo(picked.bytes, picked.name);
  }

  /// One player over [bytes], shared by the attachment box and every
  /// preview card. A blob URL on the web, a temporary file elsewhere.
  /// Nothing is uploaded — the file is being looked at precisely because
  /// nobody is sure yet that it is the right one.
  Future<void> _loadVideo(List<int> bytes, String name) async {
    final gen = _videoGen;
    final url = Files.previewUrl(bytes, _mimeFor(name));
    final player = url == null ? null : widget.videoPlayer(Uri.parse(url));

    if (player == null) {
      if (url != null) Files.releasePreviewUrl(url);
      if (mounted && gen == _videoGen) setState(() => _videoFailed = true);
      return;
    }

    // Held from here, so Remove or Replace mid-load still disposes it.
    _player = player;
    _mediaUrl = url;

    try {
      await player.initialize();
      await player.setVolume(0);
    } catch (_) {
      // A container this platform will not decode: keep the label, say so.
      if (mounted && gen == _videoGen) setState(() => _videoFailed = true);
      return;
    }

    if (mounted && gen == _videoGen) setState(() {});
  }

  /// Read what this TikTok account allows, for its panel. Asked fresh on
  /// every tick: TikTok's guidelines want the current answer, not a cached one.
  Future<void> _askTikTok(String id, String mode) async {
    final choice = TikTokChoice(mode: mode);
    _tiktok[id] = choice;

    final response = await widget.api.get(
      '/social/connections/$id/tiktok-creator',
    );
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
            // TikTok's choices, nested: a JSON body is not parsed from
            // `tiktok[id][field]` names the way a multipart one is.
            if (_picked.any((id) => _tiktok[id] != null))
              'tiktok': {
                for (final id in _picked)
                  if (_tiktok[id] != null) id: _tiktok[id]!.json(),
              },
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
                                _askTikTok(account.id, account.tiktokMode);
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
            // What a ticked network will not take, said before Publish rather
            // than as a failed target after it (review 2026-11-09 A7).
            for (final account in _tickedNetworks)
              if (account.refuses[_kind] case final String why)
                Padding(
                  key: ValueKey('social-caution-${account.id}'),
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    '${account.displayName}: $why It will be skipped.',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.error,
                    ),
                  ),
                ),
            // The note goes here, by the account, BEFORE anybody presses
            // Publish. "Sent" and "sent, but you must go and finish it in
            // TikTok" are different enough that learning it afterwards is
            // learning it too late.
            // A TikTok account's block says where its post goes instead.
            for (final account in widget.connections)
              if (account.postingNote != null && !account.asksTikTokChoices)
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
            // One switch, a count and Expand (Jose, 2026-10-26); expanded,
            // grouped by branch and named by city (Jose, 2026-10-19).
            ComposeListingsPicker(
              places: _places,
              picked: _pickedPlaces,
              enabled: !_sending,
              onChanged: () => setState(() {}),
              expanded: _expanded,
              onExpandedChanged: (open) {
                setState(() => _expandedNow = open);
                widget.session.setPreference(_listingsExpanded, open);
              },
            ),
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
            if (_picked.contains(account.id) &&
                _tiktok[account.id] != null) ...[
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

    final fromLibrary = _libraryItem;
    final preview = _PreviewCard(
      networks: [
        ...networks,
        // A ticked listing is a Google post even with no Google account
        // ticked: its likeness, its limit, no video.
        if (_pickedPlaces.isNotEmpty &&
            !networks.any((n) => n.provider == 'google_business_profile'))
          _SocialConnection(
            id: 'listings',
            displayName: _pickedPlaces.length == 1
                ? '1 listing'
                : '${_pickedPlaces.length} listings',
            provider: 'google_business_profile',
            providerLabel: 'Google Business Profile',
            canPublish: true,
          ),
      ],
      body: _words,
      media: _media,
      libraryVideo: _media == null && fromLibrary?['kind'] == 'video'
          ? '${fromLibrary!['name'] ?? ''}'
          : null,
      video: _player,
      videoFailed: _videoFailed,
      when: _scheduling && when.isNotEmpty ? 'Scheduled $when' : 'Now',
    );

    // Publish stays in sight however long the post (UI queue 76): the bar
    // under the scrolling form says where it goes and when, beside the button.
    final picked = _picked.length;
    final listings = _pickedPlaces.length;
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
                    picked == 0 && listings == 0
                        ? 'No account ticked yet'
                        : 'Going to ${_destinations(picked, listings)}'
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
        if (box.maxWidth < 760) {
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
              final limit = account.bodyLimit;
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
      final row = Row(
        key: const ValueKey('social-library-picked'),
        children: [
          Icon(
            fromLibrary['kind'] == 'video'
                ? Icons.movie_outlined
                : Icons.photo_outlined,
            size: 18,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text('From the library: ${fromLibrary['name'] ?? ''}'),
          ),
          TextButton(
            onPressed: _sending
                ? null
                : () => setState(() {
                    _releaseMedia();
                    _libraryItem = null;
                  }),
            child: const Text('Remove'),
          ),
        ],
      );
      if (fromLibrary['kind'] != 'video') return row;

      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: SizedBox(
              height: 160,
              child: _AttachedVideo(
                player: _player,
                failed: _videoFailed,
                placeholder: _mediaFallback(
                  context,
                  Icons.movie_outlined,
                  '${fromLibrary['name'] ?? ''}',
                ),
              ),
            ),
          ),
          const SizedBox(height: 8),
          row,
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
    return _AttachedVideo(
      player: _player,
      failed: _videoFailed,
      placeholder: _mediaFallback(
        context,
        Icons.movie_outlined,
        '${media.name} · ${_sizeOf(media.bytes.length)}',
      ),
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

  /// What is attached, as the server names the kinds.
  String get _kind {
    final media = _media;
    if (media != null) return _looksLikeVideo(media.name) ? 'video' : 'image';
    final item = _libraryItem;
    if (item != null) return item['kind'] == 'video' ? 'video' : 'image';
    return 'text';
  }

  static bool _looksLikeVideo(String name) => const [
    'mp4',
    'mov',
    'webm',
    'm4v',
  ].contains(name.toLowerCase().split('.').last);

  static bool _looksLikeImage(String name) {
    final ext = name.toLowerCase().split('.').last;
    return const [
      'jpg',
      'jpeg',
      'png',
      'webp',
      'gif',
      'heic',
      'heif',
    ].contains(ext);
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
    this.libraryVideo,
    this.video,
    this.videoFailed = false,
  });

  /// One ticked account per network.
  final List<_SocialConnection> networks;
  final String body;
  final PickedBytes? media;
  final String when;

  /// The name of a library video, when that is what is attached.
  final String? libraryVideo;

  /// The composer's one player, shared by every card — never one each.
  final VideoPlayerController? video;
  final bool videoFailed;

  @override
  Widget build(BuildContext context) {
    Widget likeness(_SocialConnection network) => _PostLikeness(
      account: network,
      body: body,
      media: media,
      when: when,
      libraryVideo: libraryVideo,
      video: video,
      videoFailed: videoFailed,
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
    this.libraryVideo,
    this.video,
    this.videoFailed = false,
  });

  final _SocialConnection account;
  final String body;
  final PickedBytes? media;
  final String when;
  final String? libraryVideo;
  final VideoPlayerController? video;
  final bool videoFailed;

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
    final limit = account.bodyLimit;
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

    final videoName = media?.name ?? libraryVideo;
    Widget playing(String name) => _AttachedVideo(
      player: video,
      failed: videoFailed,
      placeholder: _thumbnailLabel(theme, Icons.movie_outlined, name),
    );

    final Widget? picture = media == null
        ? (videoName == null
              ? null
              : ClipRRect(
                  key: const ValueKey('social-preview-media'),
                  borderRadius: BorderRadius.circular(8),
                  child: SizedBox(height: 150, child: playing(videoName)),
                ))
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
                  : playing(media.name),
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

/// The attached video in its frame: first frame, muted, play in the middle,
/// a thin progress bar and the length. One [player] serves every frame that
/// shows it; this widget never creates or disposes one.
///
/// Until it can play, [placeholder]; when it cannot, the placeholder with
/// a quiet note rather than an alarm.
class _AttachedVideo extends StatelessWidget {
  const _AttachedVideo({
    required this.player,
    required this.failed,
    required this.placeholder,
  });

  final VideoPlayerController? player;
  final bool failed;
  final Widget placeholder;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final player = this.player;

    Widget waiting() => Stack(
      fit: StackFit.expand,
      children: [
        placeholder,
        if (failed)
          Positioned(
            left: 8,
            right: 8,
            bottom: 6,
            child: Text(
              'Preview not available',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
      ],
    );

    if (player == null) return waiting();

    return ValueListenableBuilder<VideoPlayerValue>(
      valueListenable: player,
      builder: (context, value, _) {
        if (!value.isInitialized || failed) return waiting();

        return ColoredBox(
          color: theme.colorScheme.surfaceContainerHighest,
          child: Stack(
            fit: StackFit.expand,
            children: [
              Center(
                child: AspectRatio(
                  aspectRatio: value.aspectRatio,
                  child: VideoPlayer(player),
                ),
              ),
              // Tap anywhere to play or pause. A scrubber, volume and
              // fullscreen would be a media player, which this is not.
              GestureDetector(
                key: const ValueKey('social-video-toggle'),
                behavior: HitTestBehavior.opaque,
                onTap: () => value.isPlaying ? player.pause() : player.play(),
                child: Center(
                  child: value.isPlaying
                      ? const SizedBox.shrink()
                      : Container(
                          padding: const EdgeInsets.all(8),
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: theme.colorScheme.primary,
                          ),
                          child: Icon(
                            Icons.play_arrow,
                            size: 28,
                            color: theme.colorScheme.onPrimary,
                          ),
                        ),
                ),
              ),
              Positioned(
                right: 6,
                bottom: 8,
                child: IgnorePointer(
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 5,
                      vertical: 1,
                    ),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.inverseSurface,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      formatClock(value.duration),
                      key: const ValueKey('social-video-length'),
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: theme.colorScheme.onInverseSurface,
                      ),
                    ),
                  ),
                ),
              ),
              Positioned(
                left: 0,
                right: 0,
                bottom: 0,
                height: 3,
                child: VideoProgressIndicator(
                  player,
                  allowScrubbing: false,
                  padding: EdgeInsets.zero,
                  colors: VideoProgressColors(
                    playedColor: theme.colorScheme.primary,
                    bufferedColor: theme.colorScheme.outlineVariant,
                    backgroundColor: theme.colorScheme.surfaceContainerHigh,
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _AccountsPane extends StatelessWidget {
  const _AccountsPane({
    required this.api,
    required this.canChange,
    required this.viewName,
    required this.providers,
    required this.connections,
    required this.onConnect,
    required this.onDisconnect,
    required this.onComments,
    this.error,
  });

  final ApiClient api;

  /// May change Connect's choices (a Pinterest board): marketing.edit.
  final bool canChange;
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
    final phone = MediaQuery.sizeOf(context).width < 600;
    String subtitleOf(_SocialConnection page) => page.handle != null
        ? '${page.providerLabel} · @${page.handle}'
        : page.providerLabel;
    Widget healthOf(_SocialConnection page) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          _healthLine(page),
          key: ValueKey('social-health-${page.id}'),
          style: TextStyle(
            color: page.broken
                ? Theme.of(context).colorScheme.error
                : Theme.of(context).colorScheme.onSurfaceVariant,
          ),
        ),
        // Connect's check and choices (ConnectSetup).
        ConnectionSetup(
          api: api,
          connectionId: page.id,
          check: page.connectCheck,
          choices: page.choices,
          canChange: canChange,
        ),
      ],
    );
    List<Widget> actionsOf(BuildContext context, _SocialConnection page) => [
      if (page.broken)
        TextButton(
          key: ValueKey('social-reconnect-${page.id}'),
          onPressed: onConnect,
          child: const Text('Reconnect'),
        ),
      // Instagram comments are moderated through the Page
      // the Instagram account is linked to.
      if (page.provider == 'facebook_page')
        TextButton(
          onPressed: () => onComments(page),
          child: const Text('Instagram comments'),
        ),
      // Muted, not the accent: a destructive link styled as
      // the main action (deep dive 2026-11-02 #11).
      TextButton(
        style: TextButton.styleFrom(
          foregroundColor: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
        onPressed: () => onDisconnect(page),
        child: const Text('Disconnect'),
      ),
    ];

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
                    leading: SocialAccountAvatar(
                      network: page.provider,
                      avatarUrl: page.avatarUrl,
                    ),
                    title: Text(
                      page.displayName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    subtitle: phone
                        ? Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(subtitleOf(page)),
                              healthOf(page),
                              Wrap(children: actionsOf(context, page)),
                            ],
                          )
                        : Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [Text(subtitleOf(page)), healthOf(page)],
                          ),
                    // On a phone the links go under the name: beside it they
                    // squeezed "Facebook" to one letter a line (phone audit
                    // 2026-11-04).
                    trailing: phone
                        ? null
                        : Row(
                            mainAxisSize: MainAxisSize.min,
                            children: actionsOf(context, page),
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
    this.avatarUrl,
    this.canPublish = false,
    this.cannotPublishBecause,
    this.postingNote,
    this.asksTikTokChoices = false,
    this.tiktokMode = 'direct',
    this.status = 'connected',
    this.statusReason,
    this.lastCheckedAt,
    this.connectCheck,
    this.choices = const [],
    int? bodyLimit,
    this.refuses = const {},
  }) : _bodyLimit = bodyLimit;

  final String id;
  final String displayName;
  final String provider;
  final String providerLabel;
  final String? handle;

  /// The account's own picture, when the provider sends one (TikTok).
  final String? avatarUrl;

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

  /// Where a TikTok post starts: `direct` or `inbox`, the server's default.
  final String tiktokMode;

  /// The health check's verdict: connected, broken or revoked, with why and
  /// when it last looked (review 2026-11-09 A24).
  final String status;
  final String? statusReason;
  final DateTime? lastCheckedAt;

  /// Connect's pass/fail check and its choices (ConnectionSetup).
  final Map<String, dynamic>? connectCheck;
  final List<dynamic> choices;

  bool get broken => status != 'connected';

  /// The network's character limit, as the server refuses at it; the local
  /// map only for a payload from before the server sent one.
  final int? _bodyLimit;
  int? get bodyLimit => _bodyLimit ?? _bodyLimits[provider];

  /// What this network will not take, by kind (text, image, video): the
  /// server's sentence, or null when it takes it.
  final Map<String, String?> refuses;

  factory _SocialConnection.fromJson(Map<String, dynamic> json) {
    return _SocialConnection(
      id: json['id'] as String? ?? '',
      displayName: json['display_name'] as String? ?? '',
      provider: json['provider'] as String? ?? '',
      providerLabel: json['provider_label'] as String? ?? '',
      handle: json['handle'] as String?,
      avatarUrl: json['avatar_url'] as String?,
      canPublish: json['can_publish'] as bool? ?? false,
      cannotPublishBecause: json['cannot_publish_because'] as String?,
      postingNote: json['posting_note'] as String?,
      asksTikTokChoices: json['asks_tiktok_choices'] as bool? ?? false,
      tiktokMode: json['tiktok_mode'] as String? ?? 'direct',
      status: json['status'] as String? ?? 'connected',
      statusReason: json['status_reason'] as String?,
      lastCheckedAt: DateTime.tryParse('${json['last_checked_at'] ?? ''}'),
      connectCheck: json['connect_check'] as Map<String, dynamic>?,
      choices: json['choices'] as List<dynamic>? ?? const [],
      bodyLimit: (json['body_limit'] as num?)?.toInt(),
      refuses: {
        for (final e
            in ((json['refuses'] as Map<String, dynamic>?) ?? const {}).entries)
          e.key: e.value as String?,
      },
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
    if (wentNowhere) return Tone.neutral;
    // Waiting for approval has not gone out: it counts with Scheduled, not Sent.
    if (targets.isNotEmpty &&
        targets.every(
          (t) => t.status == 'scheduled' || t.status == 'awaiting_approval',
        )) {
      return Tone.info;
    }

    return Tone.ok;
  }

  /// Nothing went out and nothing is on its way: every account was turned
  /// down or taken back down. Not Sent (bug review 2026-11-09 #7).
  bool get wentNowhere =>
      targets.isNotEmpty &&
      targets.every((t) => t.status == 'disapproved' || t.status == 'removed');

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
    this.statusLabel,
  });

  /// The server's word for a sent target that is not on the profile:
  /// "Sent to TikTok inbox". Null otherwise.
  final String? statusLabel;

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
      statusLabel: json['status_label'] as String?,
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
              Text(
                network.label,
                style: Theme.of(context).textTheme.titleSmall,
              ),
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
                onPressed: _busy == null
                    ? () => _connectBluesky(network)
                    : null,
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
    // A Business Profile target is a listing, not an account (review
    // 2026-11-09 A16): "1 account and 39 listings".
    bool listing(_SentTarget t) => t.provider == 'google_business_profile';
    String accounts(List<_SentTarget> ts) => _destinations(
      ts.where((t) => !listing(t)).length,
      ts.where(listing).length,
    );

    if (targets.isNotEmpty &&
        withStatus('scheduled').length == targets.length) {
      final when = post.when;
      final at = when == null
          ? ''
          : ' for ${_dayWord(when)} ${formats.formatTime(when.hour, when.minute)}';

      return (
        Icons.schedule,
        Tone.info,
        'Scheduled$at on ${accounts(targets)}',
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

    // Published, then deleted from EzBizi: it did go out.
    final removed = withStatus('removed');
    if (sent.isEmpty && removed.isNotEmpty && failed.isEmpty) {
      return (
        Icons.delete_outline,
        Tone.neutral,
        'Posted · deleted from ${_names(removed)}',
        'It was published, then deleted from EzBizi.',
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
      'Posted to ${accounts(targets)}',
      drafts.isEmpty
          ? '${_names(live)} ${isAre(live)} live.'
          : '${_names(live)} ${isAre(live)} live. ${_names(drafts)} '
                '${isAre(drafts)} waiting in your drafts.',
    );
  }
}

/// A post opened from the calendar: what went out, then every account.
/// Google's photo rules for a post, the same as
/// GoogleBusinessProfilePublisher::refusesImage on the server: JPG or PNG,
/// 10 KB to 5 MB, at least 250 x 250. Null when the picture passes.
String? gbpRefusesImage(String name, List<int> bytes) {
  final dot = name.lastIndexOf('.');
  final ext = dot < 0 ? '' : name.substring(dot + 1).toLowerCase();
  if (!const ['jpg', 'jpeg', 'png'].contains(ext)) {
    return 'Google Business Profile takes a JPG or PNG picture.';
  }
  if (bytes.length < 10 * 1024 || bytes.length > 5 * 1024 * 1024) {
    return 'Google Business Profile takes a picture from 10 KB to 5 MB; '
        'this one is ${(bytes.length / 1024).round()} KB.';
  }
  final data = Uint8List.fromList(bytes);
  final info = img.findDecoderForData(data)?.startDecode(data);
  if (info == null || info.width < 250 || info.height < 250) {
    return 'Google Business Profile takes a picture at least 250 x 250 pixels.';
  }
  return null;
}

class _PostDetail extends StatefulWidget {
  const _PostDetail({
    super.key,
    required this.post,
    required this.connections,
    this.api,
    this.pickMedia,
    this.canEditLibrary = false,
    this.onSaved,
    this.onRetry,
    this.onEdit,
    this.onCancel,
    this.onDismiss,
    this.onDeleteRemote,
    this.onDecide,
    this.onRetryAll,
  });

  /// Try every failed account again at once; null without the right to post.
  final VoidCallback? onRetryAll;

  final _SentPost post;
  final List<_SocialConnection> connections;

  /// The words and picture are edited here, in place, while nothing has gone
  /// out (Jose, 2026-11-16). Null [api] or [onSaved] draws them read-only.
  final ApiClient? api;
  final Future<PickedBytes?> Function()? pickMedia;
  final bool canEditLibrary;
  final VoidCallback? onSaved;

  /// Approve (true) or disapprove (false) one listing's draft; null without
  /// the right to post.
  final void Function(_SentTarget, bool approve)? onDecide;
  final void Function(_SentTarget)? onRetry;
  final VoidCallback? onEdit;
  final VoidCallback? onCancel;
  final void Function(_SentTarget)? onDismiss;
  final void Function(_SentTarget)? onDeleteRemote;

  @override
  State<_PostDetail> createState() => _PostDetailState();
}

class _PostDetailState extends State<_PostDetail> {
  late final TextEditingController _text = TextEditingController(
    text: widget.post.body,
  );

  /// The picture as stored, read for the preview; null while unread or none.
  List<int>? _stored;

  /// A new picture, picked here: an upload or a library item.
  PickedBytes? _picked;
  String? _libraryId;
  bool _removed = false;
  bool _saving = false;
  String? _error;

  _SentPost get post => widget.post;

  /// The server's rule (assertStillScheduled with awaiting): nothing has gone
  /// out, and at least one account is still waiting for its time or a yes.
  bool get _unsent =>
      post.targets.isNotEmpty &&
      post.targets.every(
        (t) => const [
          'scheduled',
          'awaiting_approval',
          'failed',
        ].contains(t.status),
      ) &&
      post.targets.any(
        (t) => t.status == 'scheduled' || t.status == 'awaiting_approval',
      );

  bool get _editable => _unsent && widget.api != null && widget.onSaved != null;

  bool get _toGoogle =>
      post.targets.any((t) => t.provider == 'google_business_profile');

  /// The tightest limit among the accounts it goes to; null when none has one.
  int? get _limit {
    final limits = [
      for (final t in post.targets)
        if (_bodyLimits[t.provider] != null) _bodyLimits[t.provider]!,
    ];
    if (limits.isEmpty) return null;
    return limits.reduce((a, b) => a < b ? a : b);
  }

  bool get _dirty => _text.text != post.body || _picked != null || _removed;

  @override
  void initState() {
    super.initState();
    _text.addListener(() => setState(() {}));
    final api = widget.api;
    if (post.hasMedia && api != null) {
      api.getBytes('/social/posts/${post.id}/media').then((bytes) {
        if (mounted) setState(() => _stored = bytes);
      });
    }
  }

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  void _take(PickedBytes picked, {String? libraryId}) {
    final refused = _toGoogle
        ? gbpRefusesImage(picked.name, picked.bytes)
        : null;
    setState(() {
      _error = refused;
      if (refused != null) return;
      _picked = picked;
      _libraryId = libraryId;
      _removed = false;
    });
  }

  Future<void> _upload() async {
    final picked = await widget.pickMedia?.call();
    if (picked != null && mounted) _take(picked);
  }

  Future<void> _fromLibrary() async {
    final api = widget.api!;
    final item = await showMediaLibraryPanel(
      context,
      api: api,
      pickMedia: widget.pickMedia ?? () async => null,
      canEdit: widget.canEditLibrary,
    );
    if (item == null || !mounted) return;
    final bytes = await api.getBytes('/social/library/${item['id']}/file');
    if (bytes == null || !mounted) return;
    _take(
      PickedBytes('${item['name'] ?? ''}', bytes),
      libraryId: '${item['id']}',
    );
  }

  /// Words through PATCH, the picture through its own call; both audited by
  /// the server as social.post_edited. True when everything saved.
  Future<bool> _save() async {
    final api = widget.api!;
    final limit = _limit;
    if (limit != null && _lengthOf(_text.text) > limit) {
      setState(() => _error = 'At most $limit characters for these accounts.');
      return false;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    final calls = <Future<ApiResponse> Function()>[
      if (_text.text != post.body)
        () => api.patch('/social/posts/${post.id}', {'body': _text.text}),
      if (_picked != null && _libraryId != null)
        () => api.post('/social/posts/${post.id}/media', {
          'library_item': _libraryId,
        }),
      if (_picked != null && _libraryId == null)
        () => api.postFile(
          '/social/posts/${post.id}/media',
          'media',
          _picked!.name,
          _picked!.bytes,
        ),
      if (_removed)
        () => api.post('/social/posts/${post.id}/media', {'remove': true}),
    ];
    for (final call in calls) {
      final response = await call();
      if (!mounted) return false;
      if (!response.ok) {
        setState(() {
          _saving = false;
          _error = response.reason;
        });
        return false;
      }
    }
    setState(() => _saving = false);
    widget.onSaved!();
    return true;
  }

  Future<void> _approve(_SentTarget target) async {
    // Approve sends what is stored, so an edit is saved first.
    if (_editable && _dirty && !await _save()) return;
    widget.onDecide!(target, true);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // The server's rule: rescheduling and cancelling only while every
    // account is still waiting for its time.
    final waiting =
        post.targets.isNotEmpty &&
        post.targets.every((t) => t.status == 'scheduled');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_editable) _editor(theme) else _Quote(post: post),
        if (post.author != null) ...[
          const SizedBox(height: 6),
          Text('By ${post.author}', style: theme.textTheme.bodySmall),
        ],
        if (waiting && (widget.onEdit != null || widget.onCancel != null)) ...[
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              if (widget.onEdit != null)
                OutlinedButton.icon(
                  key: const Key('social-post-edit'),
                  onPressed: widget.onEdit,
                  icon: const Icon(Icons.edit_outlined, size: 18),
                  label: const Text('Edit or reschedule'),
                ),
              if (widget.onCancel != null)
                TextButton.icon(
                  key: const Key('social-post-cancel'),
                  onPressed: widget.onCancel,
                  icon: const Icon(Icons.cancel_outlined, size: 18),
                  label: const Text('Cancel post'),
                ),
            ],
          ),
        ],
        if (widget.onRetryAll != null &&
            post.targets.where((t) => t.status == 'failed').length > 1) ...[
          const SizedBox(height: 10),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              key: const Key('social-post-retry-all'),
              onPressed: widget.onRetryAll,
              icon: const Icon(Icons.refresh, size: 18),
              label: Text(
                'Retry all failed '
                '(${post.targets.where((t) => t.status == 'failed').length})',
              ),
            ),
          ),
        ],
        if (widget.onDecide != null)
          for (final target in post.targets)
            if (target.status == 'awaiting_approval') ...[
              const SizedBox(height: 10),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(target.account, style: theme.textTheme.bodyMedium),
                  FilledButton(
                    key: Key('social-approve-${target.id}'),
                    onPressed: _saving ? null : () => _approve(target),
                    child: const Text('Approve'),
                  ),
                  TextButton(
                    key: Key('social-disapprove-${target.id}'),
                    onPressed: _saving
                        ? null
                        : () => widget.onDecide!(target, false),
                    child: const Text('Disapprove'),
                  ),
                ],
              ),
            ],
        const SizedBox(height: 12),
        for (final target in post.targets)
          _TargetLine(
            target: target,
            drafts: _draftsFor(target, widget.connections),
            onRetry: widget.onRetry == null
                ? null
                : () => widget.onRetry!(target),
            onDismiss: widget.onDismiss == null
                ? null
                : () => widget.onDismiss!(target),
            onDeleteRemote: widget.onDeleteRemote == null
                ? null
                : () => widget.onDeleteRemote!(target),
          ),
      ],
    );
  }

  /// The whole text in a field, its count against the tightest network, and
  /// the picture with Upload / From library / Remove.
  Widget _editor(ThemeData theme) {
    final limit = _limit;
    final count = _lengthOf(_text.text);
    final over = limit != null && count > limit;
    final shown = _picked?.bytes ?? (_removed ? null : _stored);
    final hasPicture = _picked != null || (post.hasMedia && !_removed);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('social-post-text'),
          controller: _text,
          minLines: 4,
          maxLines: null,
          keyboardType: TextInputType.multiline,
          decoration: const InputDecoration(labelText: 'Post'),
        ),
        const SizedBox(height: 4),
        Align(
          alignment: Alignment.centerRight,
          child: Text(
            limit == null ? '$count' : '$count / $limit',
            key: const Key('social-post-count'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: over ? theme.colorScheme.error : null,
            ),
          ),
        ),
        const SizedBox(height: 8),
        if (hasPicture)
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: Container(
              key: const Key('social-post-image'),
              height: 160,
              color: theme.colorScheme.surfaceContainerHighest,
              alignment: Alignment.center,
              child: shown == null
                  ? Icon(
                      Icons.image_outlined,
                      color: theme.colorScheme.onSurfaceVariant,
                    )
                  : Image.memory(
                      Uint8List.fromList(shown),
                      fit: BoxFit.contain,
                      errorBuilder: (_, _, _) => Icon(
                        Icons.broken_image_outlined,
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
            ),
          ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            if (widget.pickMedia != null)
              OutlinedButton.icon(
                key: const Key('social-post-upload'),
                onPressed: _saving ? null : _upload,
                icon: const Icon(Icons.upload_outlined, size: 18),
                label: Text(hasPicture ? 'Replace picture' : 'Add picture'),
              ),
            OutlinedButton.icon(
              key: const Key('social-post-library'),
              onPressed: _saving ? null : _fromLibrary,
              icon: const Icon(Icons.photo_library_outlined, size: 18),
              label: const Text('From library'),
            ),
            if (hasPicture)
              TextButton.icon(
                key: const Key('social-post-remove-image'),
                onPressed: _saving
                    ? null
                    : () => setState(() {
                        _picked = null;
                        _libraryId = null;
                        _removed = post.hasMedia;
                        _error = null;
                      }),
                icon: const Icon(Icons.delete_outline, size: 18),
                label: const Text('Remove picture'),
              ),
          ],
        ),
        if (_toGoogle)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              'Google takes a JPG or PNG, 10 KB to 5 MB, at least 250 x 250.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              _error!,
              key: const Key('social-post-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton.tonal(
            key: const Key('social-post-save'),
            onPressed: _saving || !_dirty || over ? null : _save,
            child: Text(_saving ? 'Saving…' : 'Save'),
          ),
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
                Flexible(
                  child: Text(
                    'With a picture or video',
                    style: theme.textTheme.bodySmall,
                  ),
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
      'awaiting_approval' => (
        'Waiting for approval',
        Tone.info,
        Icons.hourglass_empty,
      ),
      'disapproved' => ('Not approved. Not posted.', Tone.neutral, Icons.block),
      'removed' => (
        'Deleted from $network',
        Tone.neutral,
        Icons.delete_outline,
      ),
      // The network's sentence, whole: "failed" says something is wrong and
      // nothing about what to do.
      'failed' => (
        target.failure ?? 'Did not post.',
        Tone.bad,
        Icons.error_outline,
      ),
      _ when target.statusLabel != null => (
        '${target.statusLabel}. Finish it in the $network app.',
        Tone.ok,
        Icons.edit_note,
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

/// The account's posting note when [target] went to its drafts, not live.
String? _draftsFor(_SentTarget target, List<_SocialConnection> connections) {
  if (target.status != 'sent') return null;
  if (target.statusLabel case final String label) return label;
  // A TikTok post chose its own way in; no label means it went to the profile.
  if (target.provider == 'tiktok') return null;

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

/// A post's state in a word or two.
String _postWord(_SentPost post) => switch (post.tone) {
  Tone.neutral when post.wentNowhere => 'not posted',
  Tone.bad => 'needs a look',
  Tone.neutral => 'posting…',
  Tone.info => 'scheduled',
  _ => 'posted',
};

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
    '${shortWeekdays[day.weekday - 1]}, '
    '${shortMonths[day.month - 1]} ${day.day}';

/// "today", "tomorrow", "Tuesday" within the week, else "Fri, Oct 2".
String _dayWord(DateTime day) => switch (_daysFromToday(day)) {
  0 => 'today',
  1 => 'tomorrow',
  final n when n > 1 && n < 7 => longWeekdays[day.weekday - 1],
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

/// A failure for the post list: the network's sentence when it reads as one,
/// otherwise one plain line, because the raw text lives in the post's pane
/// (deep dive 2026-11-02 #3: "unaudited_client_can_only_post_to_private_accounts").
@visibleForTesting
String plainFailure(String failure) {
  final raw =
      failure.contains('http') ||
      RegExp(r'\b[a-z]+(_[a-z]+){2,}\b').hasMatch(failure) ||
      failure.length > 90;
  return raw ? "Didn't post. Open it for the details." : failure;
}

// ── The planner (UI queue 199; Rodrigo 01a1ef71 + 01a1ef57, approved) ──────
//
// Social opens on a calendar. A post sits on the day of its saved send time,
// or, once it went out (or was tried), the moment it was written; a draft or
// an approval with no time sits on no day at all but under Needs attention,
// never on the day it was created. The legend, the grid and the list all read
// one filtered list, so a count and what it counts cannot disagree.

enum _PlanView { month, week, agenda }

/// The day and time a post is drawn at, or null for Needs attention.
DateTime? _placedAt(_SentPost post) {
  final wall = post.scheduledFor;
  if (wall != null) return DateTime.tryParse(wall.replaceFirst(' ', 'T'));
  const went = {'sent', 'failed', 'queued', 'removed', 'disapproved'};
  return post.targets.any((t) => went.contains(t.status))
      ? post.createdAt
      : null;
}

/// A post's state in the planner's words, from its saved targets: the
/// app's own pill words, plus Planned / Needs approval for a post held for
/// approval (with or without a time) and Draft for one going nowhere yet.
(String, Tone) _planState(_SentPost post) {
  if (post.targets.isEmpty) return ('Draft', Tone.neutral);
  // Taken down after it went out is still a post that went out.
  const went = {'sent', 'removed'};
  if (post.targets.every((t) => went.contains(t.status))) {
    return ('Sent', Tone.ok);
  }
  if (post.targets.any((t) => t.status == 'awaiting_approval') &&
      !post.targets.any((t) => t.status == 'failed')) {
    return post.scheduledFor == null
        ? ('Needs approval', Tone.caution)
        : ('Planned', Tone.caution);
  }
  return _statusOf(post);
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
    Tone.neutral when post.wentNowhere => ('Not posted', Tone.neutral),
    Tone.neutral => ('Posting $sent of $total', Tone.neutral),
    Tone.info => ('Scheduled', Tone.info),
    _ => ('Sent', Tone.ok),
  };
}

/// The networks a post goes to, each once, in the order first named.
List<String> _networksOf(_SentPost post) => [
  ...{
    for (final t in post.targets)
      if (t.provider != null) t.provider!,
  },
];

DateTime _day(DateTime t) => DateTime(t.year, t.month, t.day);

/// [n] calendar days on. Not `add(Duration)`: on the night the clocks go
/// back a day is 25 hours, and every square after it landed a day early.
DateTime _plus(DateTime t, int n) => DateTime(t.year, t.month, t.day + n);
DateTime _mondayOf(DateTime t) => _plus(t, 1 - t.weekday);

const _monthNames = [
  'January', 'February', 'March', 'April', 'May', 'June', 'July', //
  'August', 'September', 'October', 'November', 'December',
];
const _weekdayShort = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

String _dayHeading(DateTime d) =>
    '${_weekdayShort[d.weekday - 1]}, ${_monthNames[d.month - 1].substring(0, 3)} ${d.day}';

class _Planner extends StatefulWidget {
  const _Planner({
    required this.api,
    required this.posts,
    required this.formats,
    required this.search,
    required this.onSearch,
    required this.matching,
    required this.detailFor,
    required this.openPost,
    this.onNewOn,
    this.focusDay,
    this.outlined,
    this.stillWaiting = false,
  });

  final ApiClient api;

  /// Something is queued and the queue stopped answering: said, not drawn as
  /// an endless wait (see `_watchPending`).
  final bool stillWaiting;

  /// The tab's own newest posts (with one just sent), merged with the range
  /// the planner reads for the dates on screen.
  final List<_SentPost> posts;
  final DisplayFormats formats;
  final TextEditingController search;
  final ValueChanged<String> onSearch;
  final List<_SentPost> Function(List<_SentPost>) matching;

  /// The selected post's proof and actions; [close] clears the selection.
  final Widget Function(_SentPost post, VoidCallback close) detailFor;

  /// A phone has no room beside the list: the post opens over it.
  final void Function(_SentPost) openPost;

  /// A day's Add post: the composer with that date and no time.
  final void Function(DateTime)? onNewOn;
  final DateTime? focusDay;
  final String? outlined;

  /// Below this the inspector lies over the grid instead of beside it.
  static const double dockAt = 1100;

  /// Below this a phone's week strip and day list replace the grid.
  static const double phoneBelow = 600;

  @override
  State<_Planner> createState() => _PlannerState();
}

class _PlannerState extends State<_Planner> {
  _PlanView _view = _PlanView.month;
  late DateTime _anchor = _day(widget.focusDay ?? DateTime.now());
  late DateTime _phoneDay = _anchor;
  List<_SentPost> _range = const [];
  String? _selected;
  String? _network;
  String? _state;
  LiveSubscription? _live;

  @override
  void initState() {
    super.initState();
    _load();
    _live = LiveChanges.of(widget.api).subscribe(
      {'social_posts'},
      (_) => _load(),
      active: LiveChanges.visible(this),
    );
  }

  @override
  void didUpdateWidget(_Planner old) {
    super.didUpdateWidget(old);
    final focus = widget.focusDay;
    if (focus != null && focus != old.focusDay) {
      // "See it on the calendar": the month, on that day.
      setState(() {
        _anchor = _phoneDay = _day(focus);
        _view = _PlanView.month;
      });
      _load();
    }
  }

  @override
  void dispose() {
    _live?.cancel();
    super.dispose();
  }

  /// The first and last day on screen.
  (DateTime, DateTime) get _span {
    if (_view == _PlanView.week) {
      final monday = _mondayOf(_anchor);
      return (monday, _plus(monday, 6));
    }
    final first = DateTime(_anchor.year, _anchor.month, 1);
    final last = DateTime(_anchor.year, _anchor.month + 1, 0);
    if (_view == _PlanView.agenda) return (first, last);
    return (_mondayOf(first), _plus(_mondayOf(last), 6));
  }

  Future<void> _load() async {
    var (from, to) = _span;
    // The phone's week can straddle two months.
    final monday = _mondayOf(_phoneDay);
    if (monday.isBefore(from)) from = monday;
    final sunday = _plus(monday, 6);
    if (sunday.isAfter(to)) to = sunday;
    final asked = (from, to);
    final response = await widget.api.get('/social/posts', {
      'from': formatCalendarDay(from),
      'to': formatCalendarDay(to),
    });
    if (!mounted || !response.ok) return;
    if (asked != (from, to)) return;
    setState(() => _range = _SocialMediaTabState._readPosts(response));
  }

  void _shift(int step) {
    setState(() {
      _anchor = switch (_view) {
        _PlanView.week => _plus(_anchor, 7 * step),
        _ => DateTime(_anchor.year, _anchor.month + step, 1),
      };
    });
    _load();
  }

  void _shiftWeek(int step) {
    setState(() {
      _phoneDay = _plus(_phoneDay, 7 * step);
      _anchor = _phoneDay;
    });
    _load();
  }

  void _today() {
    setState(() => _anchor = _phoneDay = _day(DateTime.now()));
    _load();
  }

  /// Every post known, once, narrowed by search, network and state.
  List<_SentPost> get _all {
    final seen = <String>{};
    return [
      for (final p in widget.matching([...widget.posts, ..._range]))
        if (seen.add(p.id) &&
            (_network == null || _networksOf(p).contains(_network)) &&
            (_state == null || _planState(p).$1 == _state))
          p,
    ];
  }

  Map<DateTime, List<_SentPost>> _byDay(List<_SentPost> posts) {
    final byDay = <DateTime, List<_SentPost>>{};
    for (final p in posts) {
      final at = _placedAt(p);
      if (at != null) byDay.putIfAbsent(_day(at), () => []).add(p);
    }
    for (final list in byDay.values) {
      list.sort((a, b) => _placedAt(a)!.compareTo(_placedAt(b)!));
    }
    return byDay;
  }

  _SentPost? _find(String? id) {
    if (id == null) return null;
    for (final p in [...widget.posts, ..._range]) {
      if (p.id == id) return p;
    }
    return null;
  }

  void _select(_SentPost post) => setState(() => _selected = post.id);

  void _close() => setState(() => _selected = null);

  /// A busy day's every post, from its + more.
  Future<void> _showDay(DateTime day, List<_SentPost> posts) =>
      showSidePanel<void>(
        context,
        builder: (panel) => SidePanel(
          key: const Key('plan-day-panel'),
          title: _dayHeading(day),
          subtitle: '${posts.length} posts',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final p in posts)
                _PlanRow(
                  post: p,
                  formats: widget.formats,
                  onTap: () {
                    Navigator.of(panel).pop();
                    _select(p);
                  },
                ),
            ],
          ),
        ),
      );

  /// Drafts and approvals with no send time.
  Future<void> _showAttention(List<_SentPost> posts) => showSidePanel<void>(
    context,
    builder: (panel) => SidePanel(
      key: const Key('plan-attention-panel'),
      title: 'Needs attention',
      subtitle: 'No send time yet',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final p in posts)
            _PlanRow(
              post: p,
              formats: widget.formats,
              onTap: () {
                Navigator.of(panel).pop();
                MediaQuery.sizeOf(context).width < _Planner.phoneBelow
                    ? widget.openPost(p)
                    : _select(p);
              },
            ),
        ],
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final all = _all;
    final byDay = _byDay(all);
    final attention = [
      for (final p in all)
        if (_placedAt(p) == null) p,
    ];
    final selected = _find(_selected);

    return LayoutBuilder(
      builder: (context, box) {
        if (box.maxWidth < _Planner.phoneBelow) {
          return _phone(context, byDay, attention);
        }
        final (from, to) = _span;
        final shown = [
          for (final e in byDay.entries)
            if (!e.key.isBefore(from) && !e.key.isAfter(to)) ...e.value,
        ];
        final calendar = _calendarCard(context, byDay, shown, attention);
        if (selected == null) return calendar;
        final inspector = SizedBox(
          key: const Key('plan-inspector'),
          width: 380,
          child: SidePanel(
            docked: true,
            title: 'Post',
            subtitle: _planState(selected).$1,
            onClose: _close,
            width: 380,
            child: widget.detailFor(selected, _close),
          ),
        );
        if (box.maxWidth >= _Planner.dockAt) {
          return Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: calendar),
              const SizedBox(width: 12),
              inspector,
            ],
          );
        }
        return Stack(
          children: [
            Positioned.fill(child: calendar),
            Positioned(
              top: 0,
              right: 0,
              bottom: 0,
              child: Material(elevation: 8, child: inspector),
            ),
          ],
        );
      },
    );
  }

  Widget _calendarCard(
    BuildContext context,
    Map<DateTime, List<_SentPost>> byDay,
    List<_SentPost> shown,
    List<_SentPost> attention,
  ) {
    final theme = Theme.of(context);
    final counts = <String, (int, Tone)>{};
    for (final p in shown) {
      final (label, tone) = _planState(p);
      counts[label] = ((counts[label]?.$1 ?? 0) + 1, tone);
    }
    final networks = {
      for (final p in [...widget.posts, ..._range]) ..._networksOf(p),
    }.toList()..sort();
    final states = {
      for (final p in [...widget.posts, ..._range]) _planState(p).$1,
    }.toList()..sort();
    final (from, _) = _span;
    final title = _view == _PlanView.week
        ? 'Week of ${_dayHeading(from)}'
        : '${_monthNames[_anchor.month - 1]} ${_anchor.year}';

    final controls = Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        ListSearch(
          key: const Key('plan-search'),
          controller: widget.search,
          hint: 'Search posts',
          onChanged: widget.onSearch,
          width: 200,
        ),
        _PlanDropdown(
          key: const Key('plan-network'),
          value: _network,
          all: 'All channels',
          options: {for (final n in networks) n: _networkName(n)},
          onChanged: (v) => setState(() => _network = v),
        ),
        _PlanDropdown(
          key: const Key('plan-state'),
          value: _state,
          all: 'Any status',
          options: {for (final s in states) s: s},
          onChanged: (v) => setState(() => _state = v),
        ),
        SegmentedButton<_PlanView>(
          key: const Key('plan-view'),
          showSelectedIcon: false,
          segments: const [
            ButtonSegment(value: _PlanView.month, label: Text('Month')),
            ButtonSegment(value: _PlanView.week, label: Text('Week')),
            ButtonSegment(value: _PlanView.agenda, label: Text('Agenda')),
          ],
          selected: {_view},
          onSelectionChanged: (v) {
            setState(() => _view = v.single);
            _load();
          },
        ),
      ],
    );

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Wrap(
              spacing: 12,
              runSpacing: 8,
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    IconButton(
                      key: const Key('plan-previous'),
                      tooltip: 'Previous',
                      onPressed: () => _shift(-1),
                      icon: const Icon(Icons.chevron_left),
                    ),
                    IconButton(
                      key: const Key('plan-next'),
                      tooltip: 'Next',
                      onPressed: () => _shift(1),
                      icon: const Icon(Icons.chevron_right),
                    ),
                    OutlinedButton(
                      key: const Key('plan-today'),
                      onPressed: _today,
                      child: const Text('Today'),
                    ),
                    const SizedBox(width: 12),
                    Text(
                      title,
                      key: const Key('plan-title'),
                      style: theme.textTheme.titleLarge,
                    ),
                  ],
                ),
                controls,
              ],
            ),
            if (widget.search.text.trim().isNotEmpty &&
                byDay.isEmpty &&
                attention.isEmpty) ...[
              const SizedBox(height: 8),
              Text(
                'No post matches "${widget.search.text.trim()}".',
                key: const Key('plan-no-match'),
                style: theme.textTheme.bodyMedium,
              ),
            ],
            if (widget.stillWaiting) ...[
              const SizedBox(height: 8),
              const DashboardNote(
                'Something is still waiting to send and the queue has stopped '
                'answering. Nothing is lost — but nothing is going out either '
                'until somebody looks at the send queue.',
              ),
            ],
            const SizedBox(height: 8),
            Wrap(
              key: const Key('plan-legend'),
              spacing: 14,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                if (attention.isNotEmpty)
                  ActionChip(
                    key: const Key('plan-attention'),
                    avatar: const Icon(Icons.flag_outlined, size: 16),
                    label: Text('Needs attention ${attention.length}'),
                    onPressed: () => _showAttention(attention),
                  ),
                for (final e in counts.entries)
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        Icons.circle,
                        size: 9,
                        color: ToneColours.of(context, e.value.$2).ink,
                      ),
                      const SizedBox(width: 5),
                      Text(
                        '${e.key} ${e.value.$1}',
                        style: theme.textTheme.labelMedium,
                      ),
                    ],
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Expanded(
              child: switch (_view) {
                _PlanView.month => _MonthGrid(
                  key: const ValueKey('tour-marketing-month'),
                  month: DateTime(_anchor.year, _anchor.month, 1),
                  byDay: byDay,
                  formats: widget.formats,
                  selected: _selected,
                  outlined: widget.outlined,
                  onOpen: _select,
                  onMore: _showDay,
                  onNewOn: widget.onNewOn,
                ),
                _PlanView.week => _WeekColumns(
                  key: const ValueKey('tour-marketing-week'),
                  monday: _mondayOf(_anchor),
                  byDay: byDay,
                  formats: widget.formats,
                  selected: _selected,
                  onOpen: _select,
                  onNewOn: widget.onNewOn,
                ),
                // From the month's first day on: what is coming, however far.
                _PlanView.agenda => _PlanList(
                  key: const ValueKey('tour-marketing-agenda'),
                  byDay: {
                    for (final e in byDay.entries)
                      if (!e.key.isBefore(
                        DateTime(_anchor.year, _anchor.month, 1),
                      ))
                        e.key: e.value,
                  },
                  formats: widget.formats,
                  selected: _selected,
                  onOpen: _select,
                ),
              },
            ),
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Row(
                children: [
                  Icon(Icons.schedule, size: 14, color: theme.hintColor),
                  const SizedBox(width: 4),
                  Text(
                    'Times on each post’s own clock',
                    style: theme.textTheme.bodySmall,
                  ),
                  const Spacer(),
                  Text(
                    '${shown.length} posts',
                    key: const Key('plan-total'),
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Phone: the week as a strip, the picked day's posts below it.
  Widget _phone(
    BuildContext context,
    Map<DateTime, List<_SentPost>> byDay,
    List<_SentPost> attention,
  ) {
    final theme = Theme.of(context);
    final monday = _mondayOf(_phoneDay);
    final sunday = _plus(monday, 6);
    final today = _day(DateTime.now());
    final posts = byDay[_phoneDay] ?? const <_SentPost>[];

    return ListView(
      key: const ValueKey('tour-marketing-agenda'),
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 88),
      children: [
        ListSearch(
          key: const Key('plan-search'),
          controller: widget.search,
          hint: 'Search posts',
          onChanged: widget.onSearch,
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            IconButton(
              key: const Key('plan-previous'),
              tooltip: 'Previous week',
              onPressed: () => _shiftWeek(-1),
              icon: const Icon(Icons.chevron_left),
            ),
            Expanded(
              child: Text(
                '${_monthNames[monday.month - 1].substring(0, 3)} ${monday.day} – '
                '${_monthNames[sunday.month - 1].substring(0, 3)} ${sunday.day}',
                key: const Key('plan-title'),
                textAlign: TextAlign.center,
                style: theme.textTheme.titleMedium,
              ),
            ),
            IconButton(
              key: const Key('plan-next'),
              tooltip: 'Next week',
              onPressed: () => _shiftWeek(1),
              icon: const Icon(Icons.chevron_right),
            ),
            TextButton(
              key: const Key('plan-today'),
              onPressed: _today,
              child: const Text('Today'),
            ),
          ],
        ),
        Row(
          children: [
            for (var i = 0; i < 7; i++)
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 2),
                  child: _PhoneDay(
                    day: _plus(monday, i),
                    picked: _plus(monday, i) == _phoneDay,
                    today: _plus(monday, i) == today,
                    count: byDay[_plus(monday, i)]?.length ?? 0,
                    onTap: () => setState(() => _phoneDay = _plus(monday, i)),
                  ),
                ),
              ),
          ],
        ),
        if (attention.isNotEmpty) ...[
          const SizedBox(height: 10),
          Material(
            color: ToneColours.of(context, Tone.caution).fill,
            borderRadius: BorderRadius.circular(10),
            child: ListTile(
              key: const Key('plan-attention'),
              dense: true,
              leading: Icon(
                Icons.flag_outlined,
                color: ToneColours.of(context, Tone.caution).ink,
              ),
              title: Text(
                '${attention.length} need attention · no send time yet',
                style: TextStyle(
                  color: ToneColours.of(context, Tone.caution).ink,
                ),
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => _showAttention(attention),
            ),
          ),
        ],
        const SizedBox(height: 12),
        Text(
          _dayHeading(_phoneDay).toUpperCase(),
          style: theme.textTheme.labelMedium,
        ),
        const SizedBox(height: 6),
        if (posts.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 16),
            child: Text(
              'Nothing on this day.',
              key: const Key('plan-day-empty'),
              style: theme.textTheme.bodyMedium,
            ),
          ),
        for (final p in posts)
          _PlanRow(
            post: p,
            formats: widget.formats,
            selected: p.id == widget.outlined,
            onTap: () => widget.openPost(p),
          ),
      ],
    );
  }
}

String _networkName(String provider) => switch (provider) {
  'facebook_page' => 'Facebook',
  'instagram_professional' => 'Instagram',
  'google_business_profile' => 'Google Business',
  'linkedin' => 'LinkedIn',
  'tiktok' => 'TikTok',
  'x' => 'X',
  'youtube' => 'YouTube',
  'pinterest' => 'Pinterest',
  'threads' => 'Threads',
  'bluesky' => 'Bluesky',
  _ => provider,
};

class _PlanDropdown extends StatelessWidget {
  const _PlanDropdown({
    super.key,
    required this.value,
    required this.all,
    required this.options,
    required this.onChanged,
  });

  final String? value;
  final String all;
  final Map<String, String> options;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context) {
    // The house picker, not a popup dropdown (Jose, 2026-09-17).
    return SizedBox(
      width: 170,
      child: SearchPickerField<String?>(
        value: options.containsKey(value) ? value : null,
        isDense: true,
        hint: all,
        options: [
          SearchOption(value: null, label: all),
          for (final e in options.entries)
            SearchOption(value: e.key, label: e.value),
        ],
        onChanged: onChanged,
      ),
    );
  }
}

/// One post on the calendar: its networks, time or state, and first words.
class _PlanCard extends StatelessWidget {
  const _PlanCard({
    required this.post,
    required this.formats,
    required this.selected,
    required this.onTap,
    this.outlined = false,
  });

  final _SentPost post;
  final DisplayFormats formats;
  final bool selected;
  final bool outlined;
  final VoidCallback onTap;

  static const double height = 36;

  @override
  Widget build(BuildContext context) {
    final (label, tone) = _planState(post);
    final colours = ToneColours.of(context, tone);
    final at = _placedAt(post);
    final style = Theme.of(context).textTheme.labelSmall;
    final when = at == null
        ? label
        : label == 'Scheduled' || label == 'Sent'
        ? _clockShort(at, formats)
        : '$label ${_clockShort(at, formats)}';

    return Semantics(
      button: true,
      label: '$label: ${post.preview}',
      child: Material(
        color: colours.fill,
        clipBehavior: Clip.antiAlias,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(6),
          side: selected || outlined
              ? BorderSide(color: colours.ink, width: 1.5)
              : BorderSide.none,
        ),
        child: InkWell(
          key: ValueKey('plan-post-${post.id}'),
          onTap: onTap,
          child: Container(
            height: height,
            decoration: BoxDecoration(
              border: Border(left: BorderSide(color: colours.ink, width: 3)),
            ),
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Row(
                  children: [
                    for (final n in _networksOf(post).take(3))
                      Padding(
                        padding: const EdgeInsets.only(right: 2),
                        child: SocialNetworkMark(network: n, size: 11),
                      ),
                    const SizedBox(width: 2),
                    Expanded(
                      child: Text(
                        when,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: style?.copyWith(
                          color: colours.ink,
                          fontWeight: FontWeight.w700,
                          fontSize: 10,
                        ),
                      ),
                    ),
                  ],
                ),
                Text(
                  post.preview,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: style,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// "Goes out 2099-03-04 09:00 (UTC−5, no daylight saving)" for a post still
/// waiting for its time: the wall time and WHICH clock, since a branch's own
/// zone and the platform default differ by an hour half the year.
String? _goesOut(_SentPost post) {
  final wall = post.scheduledFor;
  if (wall == null) return null;
  if (!post.targets.any(
    (t) => t.status == 'scheduled' || t.status == 'awaiting_approval',
  )) {
    return null;
  }
  final clock = clockLabel(post.scheduledTimezone);
  final shown = wall.length > 16 ? wall.substring(0, 16) : wall;
  return 'Goes out $shown${clock.isEmpty ? '' : ' ($clock)'}';
}

/// A post as a list row: used by + more, Needs attention, Agenda and phones.
class _PlanRow extends StatelessWidget {
  const _PlanRow({
    required this.post,
    required this.formats,
    required this.onTap,
    this.selected = false,
  });

  final _SentPost post;
  final DisplayFormats formats;
  final VoidCallback onTap;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final (label, tone) = _planState(post);
    final at = _placedAt(post);
    final networks = _networksOf(post);
    // A campaign to many listings is one line, not a wall of names (review
    // 2026-11-09 A17): "Google · 39 listings ✓37 ✗2".
    final listings = [
      for (final t in post.targets)
        if (t.provider == 'google_business_profile') t,
    ];
    final sent = listings.where((t) => t.status == 'sent').length;
    final failed = listings.where((t) => t.status == 'failed').length;

    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Material(
        color: theme.colorScheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(10),
          side: BorderSide(
            color: selected
                ? theme.colorScheme.primary
                : EzBiziTheme.edge(context),
            width: selected ? 2 : 1,
          ),
        ),
        child: InkWell(
          key: ValueKey('plan-row-${post.id}'),
          borderRadius: BorderRadius.circular(10),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 56),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              child: Row(
                children: [
                  Icon(
                    post.hasMedia ? Icons.image_outlined : Icons.notes_outlined,
                    color: theme.hintColor,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          post.preview,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleSmall,
                        ),
                        Row(
                          children: [
                            for (final n in networks.take(4))
                              Padding(
                                padding: const EdgeInsets.only(right: 3),
                                child: SocialNetworkMark(network: n, size: 13),
                              ),
                            if (networks.isNotEmpty) const SizedBox(width: 3),
                            Flexible(
                              child: Text(
                                at == null
                                    ? 'No send time'
                                    : _goesOut(post) ??
                                          _clockShort(at, formats),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.bodySmall,
                              ),
                            ),
                          ],
                        ),
                        if (listings.length > 3)
                          Text(
                            'Google · ${listings.length} listings'
                            '${sent > 0 ? ' ✓$sent' : ''}'
                            '${failed > 0 ? ' ✗$failed' : ''}',
                            key: ValueKey('social-post-listings-${post.id}'),
                            style: theme.textTheme.bodySmall,
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  StatusPill(label, tone: tone, dense: true),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The month, Monday first, each day holding as many cards as fit.
class _MonthGrid extends StatelessWidget {
  const _MonthGrid({
    super.key,
    required this.month,
    required this.byDay,
    required this.formats,
    required this.selected,
    required this.onOpen,
    required this.onMore,
    this.outlined,
    this.onNewOn,
  });

  final DateTime month;
  final Map<DateTime, List<_SentPost>> byDay;
  final DisplayFormats formats;
  final String? selected;
  final String? outlined;
  final void Function(_SentPost) onOpen;
  final void Function(DateTime, List<_SentPost>) onMore;
  final void Function(DateTime)? onNewOn;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final edge = EzBiziTheme.edge(context);
    final first = _mondayOf(month);
    final last = DateTime(month.year, month.month + 1, 0);
    final weeks = (last.difference(first).inDays ~/ 7) + 1;
    final today = _day(DateTime.now());

    Widget cell(DateTime day) {
      final posts = byDay[day] ?? const <_SentPost>[];
      final inMonth = day.month == month.month;
      final isToday = day == today;
      final add = onNewOn;
      return Container(
        key: inMonth ? Key('month-day-${day.day}') : null,
        decoration: BoxDecoration(
          color: inMonth
              ? theme.colorScheme.surface
              : theme.colorScheme.surfaceContainerLow,
          border: Border(
            right: BorderSide(color: edge),
            bottom: BorderSide(color: edge),
          ),
        ),
        padding: const EdgeInsets.all(4),
        child: LayoutBuilder(
          builder: (context, box) {
            const card = _PlanCard.height + 3;
            final room = ((box.maxHeight - 22) / card).floor().clamp(0, 99);
            final fits = posts.length <= room;
            // Room for the "+ n more" line under the cards that do fit.
            final drawn = fits
                ? posts
                : posts.take(
                    ((box.maxHeight - 36) / card).floor().clamp(0, 99),
                  );
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(
                  height: 20,
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 5),
                        decoration: isToday
                            ? BoxDecoration(
                                color: theme.colorScheme.primary,
                                borderRadius: BorderRadius.circular(6),
                              )
                            : null,
                        child: Text(
                          '${day.day}',
                          style: theme.textTheme.labelMedium?.copyWith(
                            color: isToday
                                ? theme.colorScheme.onPrimary
                                : inMonth
                                ? null
                                : theme.hintColor,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                      const Spacer(),
                      if (add != null && !day.isBefore(today))
                        SizedBox(
                          width: 20,
                          height: 20,
                          child: IconButton(
                            key: ValueKey('plan-add-${day.month}-${day.day}'),
                            tooltip: 'Add post on ${_dayHeading(day)}',
                            padding: EdgeInsets.zero,
                            iconSize: 14,
                            onPressed: () => add(day),
                            icon: const Icon(Icons.add),
                          ),
                        ),
                    ],
                  ),
                ),
                for (final p in drawn)
                  Padding(
                    padding: const EdgeInsets.only(top: 3),
                    child: _PlanCard(
                      post: p,
                      formats: formats,
                      selected: p.id == selected,
                      outlined: p.id == outlined,
                      onTap: () => onOpen(p),
                    ),
                  ),
                if (!fits)
                  InkWell(
                    key: ValueKey('plan-more-${day.month}-${day.day}'),
                    onTap: () => onMore(day, posts),
                    child: Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(
                        '+ ${posts.length - drawn.length} more',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.primary,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      );
    }

    return Container(
      key: const Key('month-grid'),
      decoration: BoxDecoration(
        border: Border(
          top: BorderSide(color: edge),
          left: BorderSide(color: edge),
        ),
      ),
      child: Column(
        children: [
          Row(
            children: [
              for (final d in _weekdayShort)
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    decoration: BoxDecoration(
                      border: Border(
                        right: BorderSide(color: edge),
                        bottom: BorderSide(color: edge),
                      ),
                    ),
                    child: Text(
                      d.toUpperCase(),
                      textAlign: TextAlign.center,
                      style: theme.textTheme.labelSmall,
                    ),
                  ),
                ),
            ],
          ),
          for (var w = 0; w < weeks; w++)
            Expanded(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (var d = 0; d < 7; d++)
                    Expanded(child: cell(_plus(first, w * 7 + d))),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// A week as seven columns, every post in each.
class _WeekColumns extends StatelessWidget {
  const _WeekColumns({
    super.key,
    required this.monday,
    required this.byDay,
    required this.formats,
    required this.selected,
    required this.onOpen,
    this.onNewOn,
  });

  final DateTime monday;
  final Map<DateTime, List<_SentPost>> byDay;
  final DisplayFormats formats;
  final String? selected;
  final void Function(_SentPost) onOpen;
  final void Function(DateTime)? onNewOn;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final today = _day(DateTime.now());
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < 7; i++)
          Expanded(
            child: Container(
              margin: const EdgeInsets.symmetric(horizontal: 2),
              decoration: BoxDecoration(
                border: Border.all(color: EzBiziTheme.edge(context)),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Padding(
                    padding: const EdgeInsets.all(6),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            _dayHeading(_plus(monday, i)),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.labelMedium?.copyWith(
                              fontWeight: FontWeight.w700,
                              color: _plus(monday, i) == today
                                  ? theme.colorScheme.primary
                                  : null,
                            ),
                          ),
                        ),
                        if (onNewOn != null &&
                            !_plus(monday, i).isBefore(today))
                          SizedBox(
                            width: 22,
                            height: 22,
                            child: IconButton(
                              tooltip: 'Add post',
                              padding: EdgeInsets.zero,
                              iconSize: 15,
                              onPressed: () => onNewOn!(_plus(monday, i)),
                              icon: const Icon(Icons.add),
                            ),
                          ),
                      ],
                    ),
                  ),
                  Expanded(
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(4, 0, 4, 4),
                      children: [
                        for (final p
                            in byDay[_plus(monday, i)] ?? const <_SentPost>[])
                          Padding(
                            padding: const EdgeInsets.only(bottom: 4),
                            child: _PlanCard(
                              post: p,
                              formats: formats,
                              selected: p.id == selected,
                              onTap: () => onOpen(p),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

/// Agenda: the month's posts, day by day.
class _PlanList extends StatelessWidget {
  const _PlanList({
    super.key,
    required this.byDay,
    required this.formats,
    required this.selected,
    required this.onOpen,
  });

  final Map<DateTime, List<_SentPost>> byDay;
  final DisplayFormats formats;
  final String? selected;
  final void Function(_SentPost) onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final days = byDay.keys.toList()..sort();
    if (days.isEmpty) {
      return Center(
        child: Text(
          'Nothing this month.',
          key: const Key('plan-agenda-empty'),
          style: theme.textTheme.bodyMedium,
        ),
      );
    }
    return ListView(
      children: [
        for (final d in days) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(2, 10, 2, 6),
            child: Text(
              _dayHeading(d).toUpperCase(),
              style: theme.textTheme.labelMedium,
            ),
          ),
          for (final p in byDay[d]!)
            _PlanRow(
              post: p,
              formats: formats,
              selected: p.id == selected,
              onTap: () => onOpen(p),
            ),
        ],
      ],
    );
  }
}

/// One day in the phone's week strip: letter, date, and a dot per post.
class _PhoneDay extends StatelessWidget {
  const _PhoneDay({
    required this.day,
    required this.picked,
    required this.today,
    required this.count,
    required this.onTap,
  });

  final DateTime day;
  final bool picked;
  final bool today;
  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final ink = picked ? scheme.onPrimary : scheme.onSurface;
    return Material(
      color: picked ? scheme.primary : scheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(
          color: today && !picked ? scheme.primary : EzBiziTheme.edge(context),
        ),
      ),
      child: InkWell(
        key: ValueKey('plan-phone-day-${day.month}-${day.day}'),
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: SizedBox(
          height: 52,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                _weekdayShort[day.weekday - 1].substring(0, 1),
                style: TextStyle(
                  color: ink,
                  fontWeight: FontWeight.w700,
                  fontSize: 11,
                ),
              ),
              Text('${day.day}', style: TextStyle(color: ink, fontSize: 12)),
              Text(
                count == 0 ? '' : '•' * count.clamp(1, 3),
                style: TextStyle(
                  color: picked ? scheme.onPrimary : scheme.primary,
                  fontSize: 9,
                  height: 1,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
