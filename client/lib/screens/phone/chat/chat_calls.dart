// Chat calls: voice and video, two people or a group (Twilio Video rooms).
//
// [ChatCalls] is app-wide: a call must ring whatever screen is open, so it
// polls GET /chat/calls/ringing (one cache read) every few seconds while the
// app is in front, and [ChatCallLayer], mounted above every screen beside the
// phone's IncomingCallPopup, draws the ring and the call itself.

import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:record/record.dart';

import '../../../core/api_client.dart';
import '../../../core/call_alerts.dart';
import '../../../core/session.dart';
import '../../../widgets/user_avatar.dart';
import 'chat_alerts.dart';
import 'call_view.dart';
import 'chat_store.dart';

class ChatCalls extends ChangeNotifier with WidgetsBindingObserver {
  ChatCalls({CallAlerts? alerts}) : _alertsOverride = alerts;

  static final ChatCalls instance = ChatCalls();

  /// How often a ring is looked for while the app is in front.
  static Duration every = const Duration(seconds: 4);

  final CallAlerts? _alertsOverride;
  late final CallAlerts _alerts = _alertsOverride ?? SystemCallAlerts();

  ApiClient? _api;
  Timer? _timer;
  bool _polling = false;

  /// Calls ringing for me now (newest last).
  List<Json> ringing = const [];

  /// The call I am in: the server's answer (token, room, names…) plus `title`.
  Json? active;

  /// Why the last Call or Answer did not start one, for a snack bar.
  final refusals = StreamController<String>.broadcast();

  bool get started => _api != null;

  void start(ApiClient api) {
    if (_api != null) return;
    _api = api;
    WidgetsBinding.instance.addObserver(this);
    _schedule(true);
  }

  void stop() {
    _timer?.cancel();
    _timer = null;
    if (_api != null) WidgetsBinding.instance.removeObserver(this);
    _api = null;
    ringing = const [];
    _alerts.stop();
  }

  void _schedule(bool front) {
    _timer?.cancel();
    if (!front || _api == null) return;
    _timer = Timer.periodic(every, (_) => unawaited(poll()));
    unawaited(poll());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) =>
      _schedule(state == AppLifecycleState.resumed);

  Future<void> poll() async {
    final api = _api;
    if (api == null || _polling) return;
    _polling = true;
    try {
      final r = await api.get('/chat/calls/ringing');
      if (!r.ok || r.data is! List) return;
      final next = [
        for (final c in r.data as List)
          if (c is Map && c['call_id'] != active?['call_id']) Json.from(c),
      ];
      if (jsonEncode(next) == jsonEncode(ringing)) return;
      ringing = next;
      if (ringing.isEmpty || active != null) {
        _alerts.stop();
      } else {
        final c = ringing.last;
        _alerts.incoming(
          '${c['from_name']} · ${c['video'] == true ? 'video' : 'voice'} call',
        );
      }
      notifyListeners();
    } finally {
      _polling = false;
    }
  }

  /// Start a call in a chat (or join the one already in progress there).
  Future<void> call(
    String chatId, {
    required bool video,
    required String title,
  }) async {
    if (!await devicesAllowed(video)) return;
    final r = await _api!.post('/chat/chats/$chatId/calls', {'video': video});
    _take(r, title);
  }

  Future<void> answer(Json ring) async {
    _alerts.stop();
    if (!await devicesAllowed(ring['video'] == true)) return;
    final r = await _api!.post('/chat/calls/${ring['call_id']}/join', {});
    ringing = [
      for (final c in ringing)
        if (c['call_id'] != ring['call_id']) c,
    ];
    _take(r, '${ring['chat_name'] ?? ring['from_name']}');
  }

  /// Join a group call that is in progress, from its line in the chat.
  Future<void> join(
    String callId, {
    required bool video,
    required String title,
  }) async {
    if (!await devicesAllowed(video)) return;
    final r = await _api!.post('/chat/calls/$callId/join', {});
    _take(r, title);
  }

  Future<void> decline(Json ring) async {
    ringing = [
      for (final c in ringing)
        if (c['call_id'] != ring['call_id']) c,
    ];
    if (ringing.isEmpty) _alerts.stop();
    notifyListeners();
    await _api?.post('/chat/calls/${ring['call_id']}/decline', {});
  }

  /// The page said the call is over (or failed). Tell the server and close.
  Future<void> ended(Map<String, dynamic> event) async {
    final call = active;
    if (call == null) return;
    active = null;
    notifyListeners();
    await _api?.post('/chat/calls/${call['call_id']}/leave', {
      'alone': event['alone'] == true,
    });
  }

  void _take(ApiResponse r, String title) {
    if (!r.ok || r.data is! Map) {
      refusals.add(r.reason);
      return;
    }
    _alerts.stop();
    active = {...Json.from(r.data as Map), 'title': title};
    notifyListeners();
  }

  /// The page's address: the token rides in the fragment, never sent anywhere.
  static String pageUrl(Json call) {
    final fragment = Uri(
      queryParameters: {
        'token': '${call['token']}',
        'room': '${call['room']}',
        'video': call['video'] == true ? '1' : '0',
        'group': call['group'] == true ? '1' : '0',
        'ring': '${call['ring_seconds'] ?? 45}',
        'title': '${call['title'] ?? ''}',
        'names': jsonEncode(call['names'] ?? const {}),
      },
    ).query;
    return '${ApiClient.baseUrl}/api/v1/chat/call#$fragment';
  }

  /// Replaced in tests, where no platform answers the permission channel.
  @visibleForTesting
  static Future<bool> Function(bool video) devicesAllowed = _mayUseDevices;

  /// Android asks for the app's own camera and microphone grant before the
  /// web view may use them; elsewhere the page's own request is the prompt.
  static Future<bool> _mayUseDevices(bool video) async {
    if (kIsWeb || defaultTargetPlatform != TargetPlatform.android) return true;
    try {
      final granted = await const MethodChannel('ezbizi/permissions')
          .invokeMethod<bool>(
            'request',
            video ? ['camera', 'microphone'] : ['microphone'],
          );
      if (granted == true) return true;
    } on MissingPluginException {
      // An older Android build without the channel: the microphone at least.
      if (!video) return AudioRecorder().hasPermission();
    } catch (_) {}
    instance.refusals.add(
      'EzBizi needs the microphone${video ? ' and camera' : ''} for calls. '
      'Allow it in the phone’s settings for EzBizi.',
    );
    return false;
  }
}

/// Tests stand in for the web view, which no test platform can host.
@visibleForTesting
Widget Function({
  Key? key,
  required String url,
  required void Function(Map<String, dynamic> event) onEvent,
})?
debugCallView;

/// The ring and the call, above every screen.
class ChatCallLayer extends StatefulWidget {
  const ChatCallLayer({
    super.key,
    required this.api,
    required this.session,
    this.calls,
    this.alerts,
  });

  final ApiClient api;
  final Session session;
  final ChatCalls? calls;

  /// A test's own; the app's is [ChatAlerts.instance].
  final ChatAlerts? alerts;

  @override
  State<ChatCallLayer> createState() => _ChatCallLayerState();
}

class _ChatCallLayerState extends State<ChatCallLayer> {
  late final ChatCalls _calls = widget.calls ?? ChatCalls.instance;
  final _viewKey = GlobalKey();
  StreamSubscription<String>? _refusals;
  String? _note;

  @override
  void initState() {
    super.initState();
    _calls.addListener(_changed);
    widget.session.addListener(_signedInChanged);
    _refusals = _calls.refusals.stream.listen((text) {
      if (mounted) setState(() => _note = text);
      Timer(const Duration(seconds: 6), () {
        if (mounted && _note == text) setState(() => _note = null);
      });
    });
    _signedInChanged();
  }

  @override
  void dispose() {
    // The layer lives as long as the app; a test tearing the tree down must
    // not leave the ring poll running.
    _calls.stop();
    (widget.alerts ?? ChatAlerts.instance).stop();
    _calls.removeListener(_changed);
    widget.session.removeListener(_signedInChanged);
    unawaited(_refusals?.cancel());
    super.dispose();
  }

  void _changed() {
    if (mounted) setState(() {});
  }

  /// Ring only for somebody signed in who can use Chat.
  void _signedInChanged() {
    final may =
        widget.session.me?.permissions.contains('phone.chat.view') ?? false;
    if (may && !_calls.started) _calls.start(widget.api);
    if (!may && _calls.started) _calls.stop();
    // And the OS notifications for new messages, alongside the ring.
    final alerts = widget.alerts ?? ChatAlerts.instance;
    if (may && !alerts.started) alerts.start(widget.api);
    if (!may && alerts.started) alerts.stop();
  }

  @override
  Widget build(BuildContext context) {
    final active = _calls.active;
    final ring = _calls.ringing.isEmpty ? null : _calls.ringing.last;
    return Stack(
      children: [
        if (active != null) Positioned.fill(child: _inCall(context, active)),
        if (ring != null && active == null)
          Positioned(
            top: 12,
            left: 12,
            right: 12,
            child: SafeArea(child: Center(child: _ringCard(context, ring))),
          ),
        if (_note != null)
          Positioned(
            bottom: 24,
            left: 24,
            right: 24,
            child: SafeArea(
              child: Center(
                child: Material(
                  color: Theme.of(context).colorScheme.inverseSurface,
                  borderRadius: BorderRadius.circular(10),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Text(
                      _note!,
                      key: const ValueKey('chat-call-note'),
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.onInverseSurface,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }

  Widget _ringCard(BuildContext context, Json ring) {
    final theme = Theme.of(context);
    final video = ring['video'] == true;
    final group = ring['group'] == true;
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 440),
      child: Material(
        key: const ValueKey('chat-call-ring'),
        elevation: 8,
        borderRadius: BorderRadius.circular(16),
        color: theme.colorScheme.surfaceContainerHigh,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              group
                  ? const CircleAvatar(radius: 24, child: Icon(Icons.groups))
                  : UserAvatar(
                      api: widget.api,
                      userId: '${ring['from_id']}',
                      name: '${ring['from_name']}',
                      radius: 24,
                    ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      group ? '${ring['chat_name']}' : '${ring['from_name']}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleMedium,
                    ),
                    Text(
                      '${group ? '${ring['from_name']} · ' : ''}'
                      'Incoming ${group ? 'group ' : ''}${video ? 'video' : 'voice'} call',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
              IconButton.filled(
                key: const ValueKey('chat-call-decline'),
                tooltip: 'Decline',
                style: IconButton.styleFrom(
                  backgroundColor: theme.colorScheme.error,
                  foregroundColor: theme.colorScheme.onError,
                ),
                icon: const Icon(Icons.call_end),
                onPressed: () => unawaited(_calls.decline(ring)),
              ),
              const SizedBox(width: 8),
              IconButton.filled(
                key: const ValueKey('chat-call-answer'),
                tooltip: 'Answer',
                icon: Icon(video ? Icons.videocam : Icons.call),
                onPressed: () => unawaited(_calls.answer(ring)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// The call fills the window: on a phone that is the phone, on a desktop it
  /// is the app window, as a WhatsApp call window is.
  Widget _inCall(BuildContext context, Json call) => Material(
    key: const ValueKey('chat-call-active'),
    color: Theme.of(context).colorScheme.scrim,
    child: SafeArea(
      child: Stack(
        children: [
          Positioned.fill(
            child: (debugCallView ?? chatCallView)(
              key: _viewKey,
              url: ChatCalls.pageUrl(call),
              onEvent: (event) {
                final what = event['event'];
                if (what == 'ended') unawaited(_calls.ended(event));
                if (what == 'failed') {
                  _calls.refusals.add(
                    event['reason'] == 'permission'
                        ? 'EzBizi needs the microphone'
                              '${call['video'] == true ? ' and camera' : ''} for calls.'
                        : 'The call could not connect.',
                  );
                  // My own failure is not the room emptying: a group call
                  // goes on for the others (a 1:1 call ends either way).
                  unawaited(_calls.ended(const {'alone': false}));
                }
              },
            ),
          ),
          // The page draws its own End button; this one always works, even
          // while the page is still loading or failed to.
          Positioned(
            top: 8,
            left: 8,
            child: IconButton.filledTonal(
              key: const ValueKey('chat-call-close'),
              tooltip: 'Leave call',
              icon: const Icon(Icons.arrow_back),
              onPressed: () {
                final call = _calls.active;
                // The page hangs up and reports whether anyone is left; if it
                // never loaded, nothing reports, so close after a moment.
                hangUpChatCall(_viewKey);
                Timer(const Duration(seconds: 2), () {
                  if (identical(_calls.active, call)) {
                    unawaited(_calls.ended(const {'alone': false}));
                  }
                });
              },
            ),
          ),
        ],
      ),
    ),
  );
}
