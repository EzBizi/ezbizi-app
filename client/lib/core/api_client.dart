import 'dart:convert';
import 'package:flutter/foundation.dart' show compute;
import 'package:http/http.dart' as http;

import 'issue_reporter.dart';
import 'build_info.dart';
import 'client_platform.dart';

/// Everything that talks to the EzBizi API goes through here (plan §11.4).
///
/// The base URL is a BUILD FLAG, never a hardcoded constant. A1 Tools had
/// `https://tools.a-1chimney.com` compiled into the binary, which is why moving
/// that app to a new domain is now a migration problem rather than a config
/// change — every installed client has the old host baked in. Same mistake, one
/// line, avoided permanently:
///
///     flutter build web --dart-define=API_BASE=https://app.ezbizi.com
///
/// The default is same-origin, so a web build served by the app needs no flag
/// at all and cannot drift from whatever host it was loaded from.
class ApiClient {
  ApiClient({http.Client? client}) : _http = client ?? http.Client();

  final http.Client _http;
  String? _token;
  int _tokenGeneration = 0;
  String? _view;

  static const String baseUrl = String.fromEnvironment(
    'API_BASE',
    defaultValue: '',
  );

  static String get _root => baseUrl.isEmpty ? '' : baseUrl;

  void setToken(String? token) {
    _token = token;
    _tokenGeneration++;
    _view = null;
  }

  /// The view actually displayed by this client, independent of other devices.
  void setView(String? view) => _view = view;
  bool get hasToken => _token != null;

  /// Called when the server refuses a request from a token we were holding.
  ///
  /// ── Why this exists at all ───────────────────────────────────
  ///
  /// The owner left a tab open overnight and came back to it:
  ///
  ///   *"today when i tried clicking on a tab it said not authenticated … if
  ///    that is the case it should log me out to the login screen instead of
  ///    letting me still click through the app."*
  ///
  /// `Session.refresh()` already handled a 401 from `/me` — but that runs when
  /// the app STARTS. Every other request just returned an error to whichever
  /// screen asked, so the shell stayed up, the sidebar kept working, and each
  /// tab reported its own failure in its own words. The session was over and
  /// the app was the last to know.
  ///
  /// A token that has expired does not un-expire, so the first refusal is
  /// enough to act on. It is noticed HERE because this is the one place every
  /// request passes through; a check in each screen is a check somebody will
  /// leave out of the next screen.
  void Function()? onUnauthorized;

  /// Called when the server says this build of the client is too old to talk to
  /// it — HTTP 426, carrying the sentence it wants shown.
  ///
  /// ── Why it is a callback here rather than a check in each screen ──
  ///
  /// Same reason as [onUnauthorized], one line above: this is the single place
  /// every request passes through, so it is the single place the answer can be
  /// noticed. A check written into each screen is a check somebody leaves out of
  /// the next screen, and the failure mode is specific — one tab that keeps
  /// working normally while the rest of the app says it is out of date.
  ///
  /// It carries an [UpgradeRequired] where [onUnauthorized] carries nothing,
  /// because the two events differ in what they leave to say. A dead session
  /// says everything by ending: the sign-in screen is the message. An outdated
  /// client has to relay the SERVER's words — which version, where the newer
  /// build lives — and none of that can be reconstructed on this side.
  ///
  /// ── Why this one is STATIC and [onUnauthorized] is not ───────────
  ///
  /// The two facts have different owners. A dead token belongs to one
  /// credential: the tenant session can expire while the staff session is
  /// perfectly good, and telling the wrong one would sign somebody out of an
  /// application that was working. Being out of date belongs to the BINARY. It
  /// is one fact about the executable every client in this process lives inside,
  /// and there is no arrangement of sessions under which half of them are too
  /// old.
  ///
  /// Registering per instance was the bug, not the style. Screens build their
  /// own clients for good reasons — the Development section needs a separate
  /// holder for the staff token, staff enrolment needs one for a fifteen-minute
  /// single-ability token — and every one of those was invisible to the gate. A
  /// 426 on the Development tab's client was dropped on the floor here and
  /// surfaced instead as "your control-plane session has expired", which is a
  /// confident, wrong, unactionable explanation of a build the server has
  /// refused. Opt-in registration means the next screen that builds a client
  /// silently reopens the same hole, and nothing fails when it does.
  ///
  /// So it is registered once, by `installVersionGate`, and it covers clients
  /// nobody remembered to think about — which is every client that matters here,
  /// because the ones people remember were never the problem.
  static void Function(UpgradeRequired notice)? onUpgradeRequiredAnywhere;

  /// The two names, spelled the way the server reads them.
  ///
  /// `X-EzBizi-` and not a bare `X-Client-`, and this is not a style choice.
  /// These shipped once as `X-Client-Platform` / `X-Client-Version` while
  /// EnforceMinimumClientVersion read the prefixed spelling, so the server saw
  /// no platform header from any real client, fell open on every request, and
  /// the entire feature was inert — staff could raise a minimum, watch the
  /// screen report it as enforced, and refuse nobody. Both suites stayed green
  /// because each side asserted against its own spelling.
  ///
  /// They are written out as literals rather than assembled from a prefix so
  /// that a search for the exact string finds every place it exists, and they
  /// are asserted as literals in client/test/client_version_gate_test and in
  /// tests/Feature/Api/MinimumClientVersionTest — the latter reads THIS FILE, so
  /// changing one end alone turns the deploy gate red instead of quietly
  /// switching the feature off. Prefixed to match the only other custom headers
  /// this product sends (the outbound webhook signature pair), and because an
  /// unprefixed `X-Client-Version` is a name a proxy or a CDN could plausibly
  /// already be using for something else.
  static const String platformHeader = 'X-EzBizi-Client-Platform';
  static const String versionHeader = 'X-EzBizi-Client-Version';

  /// Which build is asking. Sent with every single request, on every verb.
  ///
  /// ── What the server does with it, and why that shapes this ───────
  ///
  /// The server keeps a minimum acceptable version PER PLATFORM and refuses
  /// anything below it with a 426. Windows can ship a fix in minutes; an iOS
  /// build sits in App Store review for days — so one global number would mean
  /// that raising it for Windows locks out every iOS customer who cannot yet get
  /// a build that satisfies it. Hence two headers rather than one: the version
  /// is meaningless without knowing which release train it belongs to.
  ///
  /// ── This is a courtesy check, NOT a security boundary ────────────
  ///
  /// Both values come from the client, so both can be lied about. A modified
  /// client can claim to be current and the server will believe it. That is a
  /// deliberate conclusion rather than an oversight, and the reasoning is that
  /// the liar's only prize is skipping its own upgrade prompt — it gains no
  /// access, no data and no permission it did not already have. NOTHING may ever
  /// be authorised on the strength of these headers. The moment something is,
  /// this stops being a nudge to update and becomes a lock whose key is a string
  /// the attacker types.
  ///
  /// Empty values are omitted rather than sent blank. Platform detection can
  /// come back empty (see client_platform_io.dart) and an absent header lets the
  /// server fail open, which is the whole design; a present-but-empty one invites
  /// whatever a parser does with "".
  ///
  /// A hand-built bundle sends `dev`, which is [BuildInfo]'s default when nobody
  /// passed APP_VERSION. That is sent on purpose rather than suppressed: it is
  /// the truth about the build, the server cannot read it as a version and
  /// therefore lets it through, and it is distinguishable in a log from a header
  /// a proxy stripped. Suppressing it would make a developer's own build
  /// indistinguishable from a broken one.
  Map<String, String> get _identity => {
    'X-EzBizi-View': ?_view,
    if (ClientPlatform.name.isNotEmpty) platformHeader: ClientPlatform.name,
    if (BuildInfo.version.isNotEmpty) versionHeader: BuildInfo.version,
  };

  Map<String, String> get _headers => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    // Always sent, so a forgotten header can never produce an HTML error
    // page the client cannot parse (the bug found on the deployed API).
    if (_token != null) 'Authorization': 'Bearer $_token',
    ..._identity,
  };

  /// A query value is a string, or a list of them for a key the server reads
  /// as an array (`tag[]` repeated once per value).
  Future<ApiResponse> get(
    String path, [
    Map<String, Object>? query,
    Map<String, String>? headers,
  ]) async {
    final uri = Uri.parse('$_root/api/v1$path').replace(queryParameters: query);
    return _send('GET', path, () => _http.get(uri, headers: {..._headers, ...?headers}));
  }

  Future<ApiResponse> post(String path, Map<String, dynamic> body) async {
    final uri = Uri.parse('$_root/api/v1$path');
    return _send(
      'POST', path,
      () => _http.post(uri, headers: _headers, body: jsonEncode(body)),
    );
  }

  /// PUT, for endpoints that replace a whole thing rather than amend one.
  /// The dashboard layout is the first: sending half a layout would be a
  /// different operation, not a partial version of this one.
  Future<ApiResponse> put(String path, Map<String, dynamic> body) async {
    final uri = Uri.parse('$_root/api/v1$path');
    return _send(
      'PUT', path,
      () => _http.put(uri, headers: _headers, body: jsonEncode(body)),
    );
  }

  Future<ApiResponse> patch(
    String path,
    Map<String, dynamic> body, [
    Map<String, String>? query,
  ]) async {
    final uri = Uri.parse('$_root/api/v1$path').replace(queryParameters: query);
    return _send(
      'PATCH', path,
      () => _http.patch(uri, headers: _headers, body: jsonEncode(body)),
    );
  }

  Future<ApiResponse> delete(String path, [Map<String, String>? query]) async {
    final uri = Uri.parse('$_root/api/v1$path').replace(queryParameters: query);
    return _send('DELETE', path, () => _http.delete(uri, headers: _headers));
  }

  /// DELETE with a JSON body.
  ///
  /// Exists because the query-string version was being used to send a PASSWORD
  /// — which puts it in the nginx access log, the browser's history and any
  /// Referer header the page emits. A body is not encryption, but it is the
  /// difference between a secret in transit and a secret written to disk in
  /// three places.
  Future<ApiResponse> deleteWithBody(
    String path,
    Map<String, dynamic> body,
  ) async {
    final uri = Uri.parse('$_root/api/v1$path');
    return _send(
      'DELETE', path,
      () => _http.delete(uri, headers: _headers, body: jsonEncode(body)),
    );
  }

  /// Upload a file.
  ///
  /// Multipart rather than a base64 field: a photo grows by a third in base64,
  /// and the server's `image` and `dimensions` validation rules only work on a
  /// real uploaded file.
  Future<ApiResponse> postFile(
    String path,
    String field,
    String filename,
    List<int> bytes, {
    Map<String, String>? fields,
  }) => _postMultipart(
    path,
    [http.MultipartFile.fromBytes(field, bytes, filename: filename)],
    fields,
    'Could not upload that. Check your connection.',
  );

  /// SEVERAL files under one field name, plus ordinary fields.
  ///
  /// [postFile] sends exactly one, which is right for the upload endpoints that
  /// take one. Composing an email takes up to five and they have to arrive in
  /// the SAME request as the message — sending them one at a time would mean
  /// deciding what to do when the third upload fails after two have already
  /// gone, and there is no good answer to that for a message that has not been
  /// sent yet.
  ///
  /// [field] is repeated per file (`attachments[]`), which is what a PHP
  /// backend reads as an array. Everything else — the identity headers, `_http`
  /// rather than `request.send()`, the 426 notice — is the same request as
  /// [postFile]'s, sent by [_postMultipart]; see the comments there.
  ///
  /// Additive: no existing caller changes. This lives in core rather than in
  /// the Email module because nothing about it is email-specific.
  Future<ApiResponse> postFiles(
    String path,
    String field,
    List<({String name, List<int> bytes})> files, {
    Map<String, String>? fields,
  }) => _postMultipart(
    path,
    [
      for (final file in files)
        http.MultipartFile.fromBytes('$field[]', file.bytes, filename: file.name),
    ],
    fields,
    'Could not send that. Check your connection.',
  );

  /// The multipart POST behind [postFile] and [postFiles]; [failure] is the
  /// sentence when it cannot be sent at all.
  Future<ApiResponse> _postMultipart(
    String path,
    List<http.MultipartFile> files,
    Map<String, String>? fields,
    String failure,
  ) async {
    final tokenGeneration = _tokenGeneration;
    final authenticated = _token != null;
    try {
      final request = http.MultipartRequest('POST', Uri.parse('$_root/api/v1$path'))
        ..headers['Accept'] = 'application/json'
        // This path assembles its headers by hand rather than using `_headers`,
        // so the identity pair has to be added here explicitly or an upload
        // becomes the one verb the server cannot recognise as coming from a
        // stale build. `_identity` and not `_headers` because Content-Type does
        // not belong to this request — a multipart body carries a generated
        // boundary and `MultipartRequest.finalize` writes that header itself.
        ..headers.addAll(_identity)
        ..files.addAll(files);

      if (fields != null) request.fields.addAll(fields);

      if (_token != null) request.headers['Authorization'] = 'Bearer $_token';

      // `_http.send`, NOT `request.send()`.
      //
      // MultipartRequest.send() quietly builds a brand new Client of its own and
      // throws it away afterwards, so this one verb ignored the client this
      // ApiClient was handed. That made uploads the single request in the app
      // that could not be tested — the mock never saw them, and any assertion
      // about what an upload sends or how it reacts was really asserting about a
      // real socket that is not there. Routing it through `_http` puts it on the
      // same footing as every other verb, which is the only way the identity
      // headers above and the 426 check below can be shown to work.
      final streamed = await _http.send(request);
      final response = await http.Response.fromStream(streamed);

      _noticeResponse(response, tokenGeneration, authenticated);

      return ApiResponse(
        statusCode: response.statusCode,
        body: response.body.isEmpty
            ? <String, dynamic>{}
            : jsonDecode(response.body) as Map<String, dynamic>,
        correlationId: response.headers['x-correlation-id'],
        headers: Map<String, String>.unmodifiable(response.headers),
      );
    } catch (_) {
      return ApiResponse(statusCode: 0, body: {'message': failure});
    }
  }

  /// A file, not JSON. Returns null on any failure — the caller shows a note.
  ///
  /// Separate from get() because a CSV body would fail jsonDecode, and because
  /// a download has to carry the bearer token: a plain link cannot send a
  /// header, which is what makes "just open the URL" not an option here.
  Future<List<int>?> getBytes(String path, [Map<String, String>? query]) async {
    final response = await getBytesResponse(path, query);
    return response.bytes;
  }

  /// A binary response that preserves refusal status and words for callers
  /// whose privacy boundary depends on distinguishing access loss from an
  /// ordinary network failure.
  Future<ApiBytesResponse> getBytesResponse(
    String path, [
    Map<String, String>? query,
  ]) => _bytes(
    (headers) => _http.get(
      Uri.parse('$_root/api/v1$path').replace(queryParameters: query),
      headers: headers,
    ),
  );

  /// The same for a POST: an onboarding's draft PDF is drawn from answers
  /// nobody has saved yet, so they travel in the body.
  Future<ApiBytesResponse> postBytesResponse(
    String path,
    Map<String, dynamic> body, [
    Map<String, String>? query,
  ]) => _bytes(
    (headers) => _http.post(
      Uri.parse('$_root/api/v1$path').replace(queryParameters: query),
      headers: {...headers, 'Content-Type': 'application/json'},
      body: jsonEncode(body),
    ),
  );

  Future<ApiBytesResponse> _bytes(
    Future<http.Response> Function(Map<String, String> headers) request,
  ) async {
    final tokenGeneration = _tokenGeneration;
    final authenticated = _token != null;
    try {
      final response = await request({
        if (_token != null) 'Authorization': 'Bearer $_token',
        // A download is a request like any other and can be refused like any
        // other. Without these two headers this one verb would be invisible to
        // the gate, and the symptom would be a customer whose whole app says
        // it is out of date except exports, which fail with nothing on screen.
        ..._identity,
      });

      _noticeResponse(response, tokenGeneration, authenticated);

      if (response.statusCode == 200) {
        return ApiBytesResponse(
          statusCode: response.statusCode,
          bytes: response.bodyBytes,
          correlationId: response.headers['x-correlation-id'],
        );
      }

      var message = 'Something went wrong (HTTP ${response.statusCode}).';
      try {
        final decoded = jsonDecode(response.body);
        if (decoded is Map) {
          message = ApiResponse(
            statusCode: response.statusCode,
            body: Map<String, dynamic>.from(decoded),
          ).reason;
        }
      } catch (_) {}

      return ApiBytesResponse(
        statusCode: response.statusCode,
        message: message,
        correlationId: response.headers['x-correlation-id'],
      );
    } catch (_) {
      return const ApiBytesResponse(
        statusCode: 0,
        message: 'Could not download that. Check your connection.',
      );
    }
  }

  Future<ApiResponse> _send(
    String method,
    String path,
    Future<http.Response> Function() request,
  ) async {
    final tokenGeneration = _tokenGeneration;
    final authenticated = _token != null;
    final started = DateTime.now();
    try {
      final response = await request();

      // Server errors are the app's problem to report too (2026-10-15).
      if (response.statusCode >= 500) {
        IssueReporter.instance.apiFailure(
          method, _pathShape(path), response.statusCode, DateTime.now().difference(started));
      }

      /*
       * NO CONDITION AT ALL, unlike the 401 below, and the difference is the
       * point.
       *
       * 401 is ambiguous — the same status covers "the token we were holding has
       * died" and "the password you just typed is wrong" — so it needs
       * `_token != null` to tell them apart. 426 is not ambiguous. There is one
       * reason the server sends it and it does not depend on who is asking or
       * whether anybody is signed in at all. An app too old to sign in is
       * exactly as stuck as an app too old to load a job, and the person staring
       * at a sign-in form that refuses them for no visible reason is the worse
       * off of the two.
       *
       * ABOVE the JSON decode, deliberately. A body that will not parse throws
       * to the FormatException handler below and the status code is lost with
       * it — so a 426 that arrived through a proxy which rewrote the body into
       * an HTML page would vanish, and the app would report "the server returned
       * a response the app could not read" to somebody whose real problem is
       * that their app is a year old. The status line is the part of a 426 that
       * matters; the sentence is a courtesy on top of it.
       */
      _noticeResponse(response, tokenGeneration, authenticated);

      final body = response.body.isEmpty
          ? <String, dynamic>{}
          : await decodeJsonBody(response.body);

      /*
       * ONLY WHEN WE WERE HOLDING A TOKEN, and that is the whole condition.
       *
       * Signing in with the wrong password also answers 401, and it arrives
       * before any token is set. Without this check, a mistyped password would
       * fire the session-ended path — throwing away nothing, but announcing
       * "your session expired" to somebody who never had one.
       *
       * So `_token != null` is the discriminator: it separates "the credential
       * we were using has stopped working" from "the credential you just typed
       * is wrong", which are the same status code and completely different
       * events.
       */
      return ApiResponse(
        statusCode: response.statusCode,
        body: body,
        // Ties a client-side error to the server's audit trail (§10) — without
        // it, "it broke at 3pm" is unmatchable against the logs.
        correlationId: response.headers['x-correlation-id'],
        headers: Map<String, String>.unmodifiable(response.headers),
      );
    } on FormatException {
      // The server answered with something that isn't JSON. Surfacing this
      // distinctly matters: it usually means a proxy or error page intercepted
      // the request, not that the API rejected it.
      return const ApiResponse(
        statusCode: 0,
        body: {
          'message': 'The server returned a response the app could not read.',
        },
      );
    } catch (e) {
      IssueReporter.instance.apiFailure(
          method, _pathShape(path), 0, DateTime.now().difference(started));
      return ApiResponse(
        statusCode: 0,
        body: {'message': 'Could not reach the server. Check your connection.'},
      );
    }
  }

  /// `/customers/0194…-…/jobs` → `/customers/{id}/jobs`, so one failing
  /// endpoint is one problem rather than one per record.
  static String _pathShape(String path) => path
      .split('?')
      .first
      .replaceAll(RegExp(r'[0-9a-fA-F]{8}-[0-9a-fA-F-]{27}'), '{id}')
      .replaceAll(RegExp(r'/\d+(?=/|$)'), '/{n}');

  /// Status handling precedes decoding and is identical for every transport.
  /// A late refusal for an old token must never end a newly adopted session.
  void _noticeResponse(
    http.Response response,
    int generation,
    bool authenticated,
  ) {
    _noticeUpgradeRequired(response.statusCode, response.body);
    if (response.statusCode == 401 &&
        authenticated &&
        generation == _tokenGeneration) {
      onUnauthorized?.call();
    }
  }

  /// Raise the update screen if this answer was a 426, and not otherwise.
  ///
  /// Takes the RAW body rather than a decoded map, for two reasons. It can then
  /// be called before any parsing has happened, so a 426 whose body will not
  /// parse still counts (see the comment at the call site in [_send]). And the
  /// three request paths that assemble their answers differently — [_send],
  /// [postFile] and [getBytes] — can all reach it with what they already hold,
  /// rather than one of them growing its own copy of this and drifting.
  ///
  /// Parsing is total: every shape of body, including an empty one and one that
  /// is not JSON at all, produces a notice. The screen has its own words for
  /// when the server offered none, so a missing sentence costs a little
  /// plainness and never costs somebody the explanation of why their app has
  /// stopped working.
  void _noticeUpgradeRequired(int statusCode, String body) {
    /*
     * 426 UPGRADE REQUIRED, and only 426.
     *
     * Not 401, which means the credentials are wrong and which this client
     * already answers by ending the session — an outdated app that got signed
     * out would be told the wrong thing twice. Not 403, which means this account
     * may not do this. Not 400, which means the request was malformed and
     * invites somebody to try again with different input. 426 is the one status
     * that means precisely "your side is too old", nothing else in this client
     * treats it as anything, and every existing screen already falls through to
     * showing its message — so an old client pointed at a new server degrades to
     * a plain sentence even before the screen below exists.
     */
    if (statusCode != 426) return;

    final notice = onUpgradeRequiredAnywhere;
    if (notice == null) return;

    notice(UpgradeRequired.fromBody(body));
  }
}

/// What the server said when it refused an app for being too old.
///
/// Both fields come from the server rather than from constants here, and that is
/// the whole reason this class exists instead of the screen writing its own
/// sentence. The server is the only side that knows which version it now
/// requires, when that changed, and — under whichever distribution channel this
/// platform uses, App Store or Play Store or MSIX or a self-hosted installer —
/// where a newer build actually lives. A sentence compiled into the client is
/// frozen at the moment the client was built, which is precisely the moment that
/// has been established as too long ago.
class UpgradeRequired {
  const UpgradeRequired({this.message = '', this.upgradeUrl, this.note});

  /// The plain sentence to show. Empty when the server sent none, which the
  /// screen answers with words of its own rather than a blank space.
  final String message;

  /// Where a newer build lives, when one is configured. Null is ordinary and
  /// expected: web has no installer to point at, and a platform whose channel
  /// has not been set up yet has nowhere to send anybody.
  final String? upgradeUrl;

  /// One short sentence a staff member attached to this minimum — "this update
  /// fixes invoice totals", "we stop accepting the old app on Friday".
  ///
  /// Optional and usually absent. It exists because the alternative is a person
  /// being told to install something with no reason given, and a reason is the
  /// difference between an instruction and an interruption. Shown UNDER the
  /// remedy rather than in place of it: the remedy is what to do and this is
  /// why, and swapping one for the other would leave somebody informed and
  /// stuck.
  final String? note;

  /// Read a 426 body without ever throwing.
  ///
  /// Tolerant on purpose. This runs at the exact moment the client and the
  /// server are known to disagree about how current the client is — so it is the
  /// worst possible moment to be strict about the shape of a payload. An
  /// exception here would replace "your app is too old" with a crash, which is
  /// the confusing failure this whole feature was built to end.
  ///
  /// ── The keys, and the one that was wrong ─────────────────────────
  ///
  /// `message`, `download_url` and `note`, at the top level. That is what
  /// PlatformMinimumClientVersion::refusal() actually emits — the same flat
  /// shape Laravel uses for every other refusal in this API and the one
  /// [ApiResponse.message] reads.
  ///
  /// This read `upgrade_url` once, a key the server has never sent under any
  /// configuration. The result was not a crash or a blank screen, which is why
  /// it survived: every installed-app customer was told "there is no download
  /// address set for this kind of device yet" while the address staff had
  /// configured sat unread two fields away in the same payload. A dead end
  /// asserting a false fact about the configuration, which is worse than a dead
  /// end that merely says nothing. `upgrade_url` is still accepted below at no
  /// cost, so a server that ever does send it is not refused a hearing.
  ///
  /// A `data` envelope is accepted as well, because several endpoints here wrap
  /// their payloads that way and a body that is nearly right should still
  /// produce a usable screen.
  ///
  /// `detail` is deliberately NOT read. It is the server's own prose remedy —
  /// "Install the newer version from … and sign in again" — and the screen
  /// already writes that sentence per platform, with the address in a box that
  /// can be copied. Relaying both would print the same instruction twice in
  /// slightly different words. The two parts of `detail` that the screen could
  /// not otherwise know are the address and the staff note, and both arrive
  /// here as fields of their own.
  factory UpgradeRequired.fromBody(String body) {
    try {
      final decoded = jsonDecode(body);
      if (decoded is! Map) return const UpgradeRequired();

      final data = decoded['data'];
      final envelope = data is Map ? data : const {};

      String? text(Object? value) {
        final string = value is String ? value.trim() : '';

        return string.isEmpty ? null : string;
      }

      // The same key looked for at the top level and then inside the envelope.
      String? field(String key) => text(decoded[key]) ?? text(envelope[key]);

      return UpgradeRequired(
        message: field('message') ?? '',
        upgradeUrl: field('download_url') ?? field('upgrade_url'),
        note: field('note'),
      );
    } catch (_) {
      // An empty body, an HTML error page from a proxy, anything at all. The
      // status code already carried the only fact that cannot be done without.
      return const UpgradeRequired();
    }
  }
}

class ApiResponse {
  const ApiResponse({
    required this.statusCode,
    required this.body,
    this.correlationId,
    this.headers = const <String, String>{},
  });

  final int statusCode;
  final Map<String, dynamic> body;
  final String? correlationId;
  final Map<String, String> headers;

  bool get ok => statusCode >= 200 && statusCode < 300;
  bool get unauthorized => statusCode == 401;

  /// The subscription has lapsed — read-only (§13). Distinct from 403, because
  /// the fix is billing rather than permissions, and telling someone "access
  /// denied" when they simply need to pay is a support call.
  bool get paymentRequired => statusCode == 402;

  bool get forbidden => statusCode == 403;

  String get message =>
      body['message'] as String? ?? 'Something went wrong (HTTP $statusCode).';

  /// Why the server said no, in the most specific words it offered.
  ///
  /// A 422 carries two messages: a generic one at the top ("The given data was
  /// invalid.") and the real sentence under `errors`, keyed by field — "That
  /// address already has an account in this organization." Showing the top-level
  /// one throws away the only part somebody who is stuck can act on.
  ///
  /// Falls back to [message] when there is no field-level answer, which is every
  /// 403, 404 and 500: those explain themselves at the top level, and the rules
  /// this API enforces are written to be shown verbatim.
  String get reason {
    final errors = body['errors'];

    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;

      if (first is List && first.isNotEmpty) return first.first.toString();
      if (first is String) return first;
    }

    return message;
  }

  dynamic get data => body['data'];
}

class ApiBytesResponse {
  const ApiBytesResponse({
    required this.statusCode,
    this.bytes,
    this.message,
    this.correlationId,
  });

  final int statusCode;
  final List<int>? bytes;
  final String? message;
  final String? correlationId;

  bool get ok => statusCode >= 200 && statusCode < 300;
}

/// Decodes a reply; a large one (a 20 MB task board) on another isolate, so
/// the window keeps painting its spinner (freeze, Jose 2026-11-16).
Future<Map<String, dynamic>> decodeJsonBody(String text) async =>
    text.length < 256 * 1024
    ? jsonDecode(text) as Map<String, dynamic>
    : await compute(_decodeMap, text);

Map<String, dynamic> _decodeMap(String text) =>
    jsonDecode(text) as Map<String, dynamic>;
