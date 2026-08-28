import 'dart:typed_data';
import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
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

  bool get _anyQueued =>
      _posts.any((p) => p.targets.any((t) => t.status == 'queued'));

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
                  posts: _posts,
                  stillWaiting: _gaveUpWaiting,
                  pickMedia: widget.pickMedia,
                  onSent: _load,
                  onConnect: () => _openConnect(context),
                )
              : _AccountsPane(
                  viewName: _viewName,
                  providers: _providers,
                  connections: _connections,
                  error: _loadError,
                  onConnect: () => _openConnect(context),
                  onDisconnect: _disconnect,
                ),
        ),
      ],
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
    required this.onSent,
    required this.onConnect,
  });

  final ApiClient api;
  final Session session;
  final String viewName;
  final List<_SocialConnection> connections;
  final List<_SentPost> posts;
  final bool stillWaiting;
  final Future<PickedBytes?> Function() pickMedia;
  final Future<void> Function() onSent;
  final VoidCallback onConnect;

  static const double _stackBelow = 900;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final stack = constraints.maxWidth < _stackBelow;
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
          onSent: onSent,
        );

        if (stack) {
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              pages,
              const SizedBox(height: 12),
              compose,
              const SizedBox(height: 12),
              const _CalendarsColumn(),
            ],
          );
        }

        return Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: 220, child: pages),
              const SizedBox(width: 10),
              Expanded(flex: 4, child: compose),
              const SizedBox(width: 10),
              const Expanded(
                flex: 7,
                child: _CalendarsColumn(fill: true),
              ),
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
    required this.onSent,
  });

  final ApiClient api;
  final Session session;
  final String viewName;
  final List<_SocialConnection> connections;
  final List<_SentPost> posts;
  final bool stillWaiting;
  final Future<PickedBytes?> Function() pickMedia;
  final Future<void> Function() onSent;

  @override
  State<_ComposeColumn> createState() => _ComposeColumnState();
}

class _ComposeColumnState extends State<_ComposeColumn> {
  final TextEditingController _body = TextEditingController();
  final Set<String> _picked = <String>{};
  PickedBytes? _media;
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _body.dispose();
    super.dispose();
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

  Future<void> _attach() async {
    final picked = await widget.pickMedia();
    if (picked == null || !mounted) return;

    setState(() => _media = picked);
  }

  Future<void> _publish() async {
    setState(() {
      _sending = true;
      _error = null;
    });

    final fields = <String, String>{
      'body': _body.text.trim(),
      // Indexed rather than repeated: a multipart body carries flat name/value
      // pairs, so `connections[]` three times would arrive as one value.
      for (final (index, id) in _picked.indexed) 'connections[$index]': id,
    };

    final media = _media;

    final response = media == null
        ? await widget.api.post('/social/posts', {
            'body': fields['body'],
            'connections': _picked.toList(),
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

    // Cleared only on success. A refusal leaves the words and the picture
    // exactly where they were, because the fix is usually one tick or one
    // attachment away and retyping the post is not part of it.
    setState(() {
      _sending = false;
      _body.clear();
      _media = null;
      _picked.clear();
    });

    await widget.onSent();
  }

  @override
  Widget build(BuildContext context) {
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
          Align(
            alignment: Alignment.centerLeft,
            child: Refused(
              reason: refusal,
              child: FilledButton(
                onPressed: refusal == null ? _publish : null,
                child: Text(_sending ? 'Sending…' : 'Publish now'),
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
                : _mediaFallback(
                    context,
                    Icons.movie_outlined,
                    '${media.name} · ${_sizeOf(media.bytes.length)}',
                  ),
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
              onPressed: _sending ? null : () => setState(() => _media = null),
              child: const Text('Remove'),
            ),
          ],
        ),
      ],
    );
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
                const SizedBox(height: 4),
                for (final target in post.targets)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Text(
                      // The failure sentence is the server's, shown whole. A
                      // status word on its own ("failed") tells somebody that
                      // something is wrong and nothing about what to do.
                      target.failure != null
                          ? '${target.account} · ${target.failure}'
                          : '${target.account} · ${_statusWord(target.status)}',
                      style: theme.textTheme.bodySmall,
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
    _ => 'waiting to send',
  };
}

class _CalendarsColumn extends StatefulWidget {
  const _CalendarsColumn({this.fill = false});

  /// When true the column fills the pane and month squares shrink to fit,
  /// so the whole month is on screen. Off in the stacked (phone) layout,
  /// which already scrolls the page as a whole.
  final bool fill;

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
    final now = DateTime.now();
    _month = DateTime(now.year, now.month, 1);
  }

  void _shiftMonth(int months) {
    setState(() {
      _month = DateTime(_month.year, _month.month + months, 1);
    });
  }

  @override
  Widget build(BuildContext context) {
    final month = _MonthCard(
      month: _month,
      onPrevious: () => _shiftMonth(-1),
      onNext: () => _shiftMonth(1),
      fill: widget.fill,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const _WeekCard(),
        const SizedBox(height: 12),
        if (widget.fill) Expanded(child: month) else month,
      ],
    );
  }
}

class _WeekCard extends StatelessWidget {
  const _WeekCard();

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
    this.fill = false,
  });

  final DateTime month;
  final VoidCallback onPrevious;
  final VoidCallback onNext;
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
    int index, {
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
  });

  final double? height;
  final bool square;
  final ThemeData theme;
  final String? label;
  final bool highlight;
  final bool empty;

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
          : Text(
              label!,
              style: theme.textTheme.labelSmall?.copyWith(
                fontWeight: highlight ? FontWeight.w700 : FontWeight.w500,
                color: highlight
                    ? theme.colorScheme.primary
                    : theme.colorScheme.onSurface,
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
    this.error,
  });

  final String viewName;
  final List<_SocialNetwork> providers;
  final List<_SocialConnection> connections;
  final VoidCallback onConnect;
  final void Function(_SocialConnection) onDisconnect;
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
                    trailing: TextButton(
                      onPressed: () => onDisconnect(page),
                      child: const Text('Disconnect'),
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
                      subtitle: Text(
                        network.usable
                            ? 'Ready to connect'
                            : network.unusableBecause ?? 'Not ready',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
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
  });

  final String id;
  final String body;
  final bool hasMedia;
  final String? author;
  final List<_SentTarget> targets;

  factory _SentPost.fromJson(Map<String, dynamic> json) {
    return _SentPost(
      id: json['id'] as String? ?? '',
      body: json['body'] as String? ?? '',
      hasMedia: json['has_media'] as bool? ?? false,
      author: json['author'] as String?,
      targets: ((json['targets'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_SentTarget.fromJson)
          .toList(),
    );
  }
}

class _SentTarget {
  const _SentTarget({
    required this.account,
    required this.providerLabel,
    required this.status,
    this.failure,
  });

  final String account;
  final String providerLabel;
  final String status;
  final String? failure;

  factory _SentTarget.fromJson(Map<String, dynamic> json) {
    return _SentTarget(
      account: json['account'] as String? ?? '',
      providerLabel: json['provider_label'] as String? ?? '',
      status: json['status'] as String? ?? 'queued',
      failure: json['failure'] as String?,
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
