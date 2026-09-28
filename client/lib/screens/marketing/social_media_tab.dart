import 'dart:typed_data';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:video_player/video_player.dart';

import 'clock_label.dart';
import 'instagram_comments_panel.dart';
import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/social_network_mark.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';
import '../email/consent_window.dart';

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

  int _tab = 0;
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
    super.dispose();
  }

  Future<void> _load() async {
    final providers = await widget.api.get('/social/providers');
    final connections = await widget.api.get('/social/connections');
    final posts = await widget.api.get('/social/posts');
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
      _posts = !posts.ok
          ? const []
          : ((posts.body['data'] as List<dynamic>?) ?? const [])
              .whereType<Map<String, dynamic>>()
              .map(_SentPost.fromJson)
              .toList();
    });

    _watchPending();
  }

  /// Just the Sent list. Used by the poll below, which has no business
  /// re-reading the catalogue and the connections every few seconds.
  Future<void> _loadPosts() async {
    final posts = await widget.api.get('/social/posts');
    if (!mounted || !posts.ok) return;

    setState(() {
      _posts = ((posts.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SentPost.fromJson)
          .toList();
    });
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
        ),
      ),
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

    setState(() {
      _justPosted = post.id;
      _gaveUpWaiting = false;
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
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TabBarLike(
          labels: const [
            ('Posts', Icons.edit_outlined),
            ('Accounts', Icons.link_outlined),
          ],
          selected: _tab,
          onSelected: (tab) => setState(() => _tab = tab),
        ),
        const Divider(height: 1),
        Expanded(
          child: _tab == 0
              ? _PostsPane(
                  api: widget.api,
                  session: widget.session,
                  viewName: _viewName,
                  connections: _connections,
                  posts: _shown,
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
    final go = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Disconnect ${page.displayName}?',
        subtitle: page.providerLabel,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep it'),
          ),
          const SizedBox(width: 8),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Disconnect'),
          ),
        ],
        child: Text(
          'EzBizi forgets this ${page.providerLabel} account and its tokens. '
          'Anything scheduled to it stops. To revoke EzBizi at '
          '${page.providerLabel}’s end as well, use that network’s own '
          'settings.',
        ),
      ),
    );

    if (go != true || !mounted) return;

    final response = await widget.api.delete('/social/connections/${page.id}');
    if (!mounted) return;

    if (!response.ok) {
      setState(() => _loadError = response.reason);
      return;
    }

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
    this.onRetry,
  });

  final ApiClient api;
  final Session session;
  final String viewName;
  final List<_SocialConnection> connections;
  final List<_SentPost> posts;
  final bool stillWaiting;
  final Future<PickedBytes?> Function() pickMedia;
  final _SentPost? landing;

  /// Try again on a failed account; null without the right to post.
  final void Function(_SentPost, _SentTarget)? onRetry;
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

        if (phone && landing != null) {
          return ListView(
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

        final pages = _PagesColumn(
          viewName: viewName,
          connections: connections,
          onConnect: onConnect,
        );
        final compose = _ComposeColumn(
          api: api,
          session: session,
          viewName: viewName,
          connections: connections,
          posts: posts,
          stillWaiting: stillWaiting,
          pickMedia: pickMedia,
          landing: landing,
          formats: formats,
          onSent: onSent,
          onWriteAnother: onWriteAnother,
          onSeeCalendar: onSeeCalendar,
          onRetry: onRetry,
        );
        final Widget calendar = phone
            ? _Agenda(
                key: calendarKey,
                posts: posts,
                outlined: outlined,
                formats: formats,
                onOpen: onOpen,
              )
            : _CalendarsColumn(
                key: calendarKey,
                fill: !stack,
                posts: posts,
                outlined: outlined,
                focusDay: focusDay,
                formats: formats,
                onOpen: onOpen,
              );

        if (stack) {
          // Not a ListView: that builds lazily, so the calendar at the bottom
          // had no context yet when Done asked to scroll to it.
          return SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                pages,
                const SizedBox(height: 12),
                compose,
                const SizedBox(height: 12),
                calendar,
              ],
            ),
          );
        }

        return Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: 220, child: pages),
              const SizedBox(width: 10),
              // Scrolls on its own, at least as tall as the column: twenty
              // posts in the Sent list below the form are taller than a
              // laptop screen.
              Expanded(
                flex: 4,
                child: LayoutBuilder(
                  builder: (context, column) => SingleChildScrollView(
                    child: ConstrainedBox(
                      constraints: BoxConstraints(minHeight: column.maxHeight),
                      child: compose,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(flex: 7, child: calendar),
            ],
          ),
        );
      },
    );
  }
}

class _PagesColumn extends StatelessWidget {
  const _PagesColumn({
    required this.viewName,
    required this.connections,
    required this.onConnect,
  });

  final String viewName;
  final List<_SocialConnection> connections;
  final VoidCallback onConnect;

  @override
  Widget build(BuildContext context) {
    return DashboardCard(
      title: 'Pages',
      icon: Icons.web_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (connections.isEmpty)
            DashboardNote(
              'Only pages this view may use. None are connected in $viewName yet.',
            )
          else
            for (final page in connections)
              ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                leading: SocialNetworkMark(network: page.provider, size: 24),
                // A network that gives us no display name leaves its own opaque
                // id here. One line with an ellipsis, never three wrapped lines
                // of base64 pushing the Connect button off the card.
                title: Text(
                  page.displayName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                subtitle: Text(page.providerLabel),
              ),
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton(
              onPressed: onConnect,
              child: const Text('Connect'),
            ),
          ),
        ],
      ),
    );
  }
}

/// Write once, tick the accounts, send.
///
/// ── Why the accounts are ticked here and not in the Pages column ─
///
/// Pages answers "what may this view post as". This answers "where is THIS post
/// going", which changes per post — the same three accounts are the right
/// answer today and the wrong one tomorrow. Putting the ticks in the other
/// column would make a per-post choice look like a property of the account.
///
/// Scheduling is not here yet: the button would be a control that does not
/// work, so it is not drawn. The calendar beside this says what it is waiting
/// for.
class _ComposeColumn extends StatefulWidget {
  const _ComposeColumn({
    required this.api,
    required this.session,
    required this.viewName,
    required this.connections,
    required this.posts,
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
  final List<_SentPost> posts;
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
  PickedBytes? _media;
  bool _sending = false;
  String? _error;

  /// Object URL for the picked video, and the player over it. Both live only
  /// as long as the attachment does — see `_clearMedia`.
  String? _mediaUrl;
  VideoPlayerController? _player;

  /// Wall clock in this view's timezone, `YYYY-MM-DD HH:MM`, empty for "now".
  final TextEditingController _when = TextEditingController();
  bool _scheduling = false;

  @override
  void dispose() {
    _body.dispose();
    _when.dispose();
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

    if (widget.connections.isEmpty) {
      return 'No account is connected in ${widget.viewName}, so there is '
          'nowhere to publish.';
    }
    if (!widget.connections.any((c) => c.canPublish)) {
      return 'None of the accounts connected in ${widget.viewName} can be '
          'posted to from EzBizi yet.';
    }
    if (_picked.isEmpty) return 'Tick at least one account to post to.';
    if (_body.text.trim().isEmpty && _media == null) {
      return 'Write something, or attach a picture or a video.';
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
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: _sending
                ? null
                : () => setState(() {
                    _scheduling = !_scheduling;
                    if (!_scheduling) _when.clear();
                  }),
            icon: Icon(
              _scheduling ? Icons.schedule : Icons.schedule_outlined,
              size: 18,
            ),
            label: Text(_scheduling ? 'Send it now instead' : 'Send it later'),
          ),
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

  Future<void> _attach() async {
    final picked = await widget.pickMedia();
    if (picked == null || !mounted) return;

    _releaseMedia();

    setState(() => _media = picked);

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
    };

    final media = _media;

    final response = media == null
        ? await widget.api.post('/social/posts', {
            'body': fields['body'],
            'connections': _picked.toList(),
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
      _when.clear();
      _scheduling = false;
    });

    await widget.onSent(posted);
  }

  @override
  Widget build(BuildContext context) {
    final landing = widget.landing;
    if (landing != null) {
      return DashboardCard(
        title: 'Compose',
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
    }

    final refusal = _refusal;

    return DashboardCard(
      title: 'Compose',
      icon: Icons.edit_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const DashboardNote('One post, every ticked account.'),
          const SizedBox(height: 12),
          TextField(
            controller: _body,
            enabled: !_sending,
            minLines: 5,
            maxLines: 10,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              hintText: 'What should go out?',
              alignLabelWithHint: true,
            ),
          ),
          const SizedBox(height: 12),
          _attachment(context),
          const SizedBox(height: 12),
          if (widget.connections.isNotEmpty) ...[
            for (final account in widget.connections)
              Refused(
                reason: account.cannotPublishBecause,
                child: CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: _picked.contains(account.id),
                  onChanged: !account.canPublish || _sending
                      ? null
                      : (on) => setState(() {
                          on == true
                              ? _picked.add(account.id)
                              : _picked.remove(account.id);
                        }),
                  secondary: SocialNetworkMark(
                    network: account.provider,
                    size: 22,
                  ),
                  title: Text(
                    account.displayName,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  // The note goes here, under the account, BEFORE anybody
                  // presses Publish. "Sent" and "sent, but you must go and
                  // finish it in TikTok" are different enough that learning it
                  // afterwards is learning it too late.
                  subtitle: Text(
                    account.postingNote == null
                        ? account.providerLabel
                        : '${account.providerLabel} · ${account.postingNote}',
                  ),
                ),
              ),
            const SizedBox(height: 8),
          ],
          _scheduleRow(context),
          const SizedBox(height: 10),
          Align(
            alignment: Alignment.centerLeft,
            child: Refused(
              reason: refusal,
              child: FilledButton(
                onPressed: refusal == null ? _publish : null,
                child: Text(_sendLabel),
              ),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 10),
            DashboardNote(_error!),
          ],
          if (widget.posts.isNotEmpty) ...[
            const SizedBox(height: 16),
            const Divider(height: 1),
            const SizedBox(height: 12),
            _RecentPosts(
              posts: widget.posts,
              stillWaiting: widget.stillWaiting,
            ),
          ],
        ],
      ),
    );
  }

  Widget _attachment(BuildContext context) {
    final media = _media;

    if (media == null) {
      return Align(
        alignment: Alignment.centerLeft,
        child: OutlinedButton.icon(
          onPressed: _sending ? null : _attach,
          icon: const Icon(Icons.image_outlined, size: 18),
          label: const Text('Attach a picture or video'),
        ),
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

  static bool _looksLikeImage(String name) {
    final ext = name.toLowerCase().split('.').last;
    return const ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif']
        .contains(ext);
  }

  static String _sizeOf(int bytes) => bytes >= 1048576
      ? '${(bytes / 1048576).toStringAsFixed(1)} MB'
      : '${(bytes / 1024).round()} KB';
}

/// What went out, and what each account said back.
///
/// Under the composer rather than on a screen of its own, because the question
/// this answers is "did the thing I just pressed work" and the answer is worth
/// nothing three clicks away. A real queue with paging and filters is a later
/// piece; this is the receipt.
class _RecentPosts extends StatelessWidget {
  const _RecentPosts({required this.posts, required this.stillWaiting});

  final List<_SentPost> posts;

  /// The queue stopped answering before anything left `queued`. Said out loud
  /// rather than drawn as an indefinite wait — see `_watchPending`.
  final bool stillWaiting;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Sent', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        if (stillWaiting) ...[
          const DashboardNote(
            'Something is still waiting to send and the queue has stopped '
            'answering. Nothing is lost — but nothing is going out either '
            'until somebody looks at the send queue.',
          ),
          const SizedBox(height: 8),
        ],
        for (final post in posts)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  post.body.isEmpty
                      ? (post.hasMedia ? 'A picture or video' : 'A post')
                      : post.body,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium,
                ),
                if (post.scheduledFor != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    'Goes out ${post.scheduledFor}'
                    '${clockSuffix(post.scheduledTimezone)}',
                    style: theme.textTheme.bodySmall,
                  ),
                ],
                const SizedBox(height: 4),
                for (final target in post.targets)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Wrap(
                      crossAxisAlignment: WrapCrossAlignment.center,
                      spacing: 8,
                      children: [
                        Text(
                          // The failure sentence is the server's, shown whole.
                          // A status word on its own ("failed") tells somebody
                          // that something is wrong and nothing about what to do.
                          target.failure != null
                              ? '${target.account} · ${target.failure}'
                              : '${target.account} · ${_statusWord(target.status)}',
                          style: theme.textTheme.bodySmall,
                        ),
                        if (target.url != null)
                          InkWell(
                            onTap: () => launchUrl(
                              Uri.parse(target.url!),
                              mode: LaunchMode.externalApplication,
                            ),
                            child: Text(
                              'View post',
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: theme.colorScheme.primary,
                                decoration: TextDecoration.underline,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }

  static String _statusWord(String status) => switch (status) {
    'sent' => 'sent',
    'failed' => 'did not send',
    // Distinct from "waiting to send", which means the queue has it now. A
    // post due next Tuesday is not waiting on anything that could go wrong.
    'scheduled' => 'scheduled',
    _ => 'waiting to send',
  };
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
                  onPressed: onConnect,
                  child: Text('Connect a page to $viewName'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Builder(
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
  });

  /// For Try again, which names the target in its path.
  final String? id;

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
    final window = widget.openConsent();
    if (window == null) {
      setState(() {
        _error =
            'EzBizi could not open the ${network.label} sign-in window. On the web that '
            'is usually the browser blocking new windows for this site — allow '
            'them and press the button again.';
      });
      return;
    }

    setState(() {
      _busy = network.value;
      _error = null;
    });

    final response = await widget.api.post(network.startPath, {});

    if (!mounted) {
      window.close();
      return;
    }

    if (!response.ok) {
      window.close();
      setState(() {
        _busy = null;
        _error = response.reason;
      });
      return;
    }

    final data = response.data;
    final url = data is Map<String, dynamic> ? data['authorization_url'] : null;
    final flow = data is Map<String, dynamic> ? data['flow'] : null;

    if (url is! String || flow is! String) {
      window.close();
      setState(() {
        _busy = null;
        _error = '${network.label} did not hand back a sign-in address.';
      });
      return;
    }

    window.goTo(url);
    _watch(window, network, flow);
  }

  void _watch(ConsentWindow window, _SocialNetwork network, String flow) {
    var asks = 150;
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 2), (timer) async {
      asks -= 1;
      if (asks <= 0 || !mounted) {
        timer.cancel();
        if (mounted) setState(() => _busy = null);
        return;
      }

      final statusPath = network.startPath.replaceFirst('/start', '/status');
      final status = await widget.api.get(statusPath, {'flow': flow});
      if (!mounted) {
        timer.cancel();
        return;
      }

      final data = status.data;
      final state = data is Map<String, dynamic> ? data['state'] : null;
      final message = data is Map<String, dynamic> ? data['message'] : null;

      if (state == 'connected') {
        timer.cancel();
        window.close();
        setState(() => _busy = null);
        await widget.onConnected();
        return;
      }

      if (state == 'refused') {
        timer.cancel();
        window.close();
        setState(() {
          _busy = null;
          _error = message is String
              ? message
              : '${network.label} sign-in did not finish.';
        });
      }
    });
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
  });

  final _SentPost post;
  final List<_SocialConnection> connections;
  final void Function(_SentTarget)? onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _Quote(post: post),
        if (post.author != null) ...[
          const SizedBox(height: 6),
          Text('By ${post.author}', style: theme.textTheme.bodySmall),
        ],
        const SizedBox(height: 12),
        for (final target in post.targets)
          _TargetLine(
            target: target,
            drafts: _draftsFor(target, connections),
            onRetry: onRetry == null ? null : () => onRetry!(target),
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
  const _TargetLine({required this.target, required this.drafts, this.onRetry});

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
