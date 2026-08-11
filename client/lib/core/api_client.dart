import 'dart:convert';
import 'package:http/http.dart' as http;

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

  static const String baseUrl = String.fromEnvironment('API_BASE', defaultValue: '');

  static String get _root => baseUrl.isEmpty ? '' : baseUrl;

  void setToken(String? token) => _token = token;
  bool get hasToken => _token != null;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        // Always sent, so a forgotten header can never produce an HTML error
        // page the client cannot parse (the bug found on the deployed API).
        if (_token != null) 'Authorization': 'Bearer $_token',
      };

  Future<ApiResponse> get(String path, [Map<String, String>? query]) async {
    final uri = Uri.parse('$_root/api/v1$path').replace(queryParameters: query);
    return _send(() => _http.get(uri, headers: _headers));
  }

  Future<ApiResponse> post(String path, Map<String, dynamic> body) async {
    final uri = Uri.parse('$_root/api/v1$path');
    return _send(() => _http.post(uri, headers: _headers, body: jsonEncode(body)));
  }

  /// PUT, for endpoints that replace a whole thing rather than amend one.
  /// The dashboard layout is the first: sending half a layout would be a
  /// different operation, not a partial version of this one.
  Future<ApiResponse> put(String path, Map<String, dynamic> body) async {
    final uri = Uri.parse('$_root/api/v1$path');
    return _send(() => _http.put(uri, headers: _headers, body: jsonEncode(body)));
  }

  Future<ApiResponse> patch(String path, Map<String, dynamic> body,
      [Map<String, String>? query]) async {
    final uri = Uri.parse('$_root/api/v1$path').replace(queryParameters: query);
    return _send(() => _http.patch(uri, headers: _headers, body: jsonEncode(body)));
  }

  Future<ApiResponse> delete(String path, [Map<String, String>? query]) async {
    final uri = Uri.parse('$_root/api/v1$path').replace(queryParameters: query);
    return _send(() => _http.delete(uri, headers: _headers));
  }

  /// DELETE with a JSON body.
  ///
  /// Exists because the query-string version was being used to send a PASSWORD
  /// — which puts it in the nginx access log, the browser's history and any
  /// Referer header the page emits. A body is not encryption, but it is the
  /// difference between a secret in transit and a secret written to disk in
  /// three places.
  Future<ApiResponse> deleteWithBody(String path, Map<String, dynamic> body) async {
    final uri = Uri.parse('$_root/api/v1$path');
    return _send(() => _http.delete(uri, headers: _headers, body: jsonEncode(body)));
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
    List<int> bytes,
  ) async {
    try {
      final request = http.MultipartRequest('POST', Uri.parse('$_root/api/v1$path'))
        ..headers['Accept'] = 'application/json'
        ..files.add(http.MultipartFile.fromBytes(field, bytes, filename: filename));

      if (_token != null) request.headers['Authorization'] = 'Bearer $_token';

      final streamed = await request.send();
      final response = await http.Response.fromStream(streamed);

      return ApiResponse(
        statusCode: response.statusCode,
        body: response.body.isEmpty
            ? <String, dynamic>{}
            : jsonDecode(response.body) as Map<String, dynamic>,
        correlationId: response.headers['x-correlation-id'],
      );
    } catch (_) {
      return const ApiResponse(
        statusCode: 0,
        body: {'message': 'Could not upload that. Check your connection.'},
      );
    }
  }

  /// A file, not JSON. Returns null on any failure — the caller shows a note.
  ///
  /// Separate from get() because a CSV body would fail jsonDecode, and because
  /// a download has to carry the bearer token: a plain link cannot send a
  /// header, which is what makes "just open the URL" not an option here.
  Future<List<int>?> getBytes(String path) async {
    try {
      final response = await _http.get(
        Uri.parse('$_root/api/v1$path'),
        headers: {if (_token != null) 'Authorization': 'Bearer $_token'},
      );

      return response.statusCode == 200 ? response.bodyBytes : null;
    } catch (_) {
      return null;
    }
  }

  Future<ApiResponse> _send(Future<http.Response> Function() request) async {
    try {
      final response = await request();

      final body = response.body.isEmpty
          ? <String, dynamic>{}
          : jsonDecode(response.body) as Map<String, dynamic>;

      return ApiResponse(
        statusCode: response.statusCode,
        body: body,
        // Ties a client-side error to the server's audit trail (§10) — without
        // it, "it broke at 3pm" is unmatchable against the logs.
        correlationId: response.headers['x-correlation-id'],
      );
    } on FormatException {
      // The server answered with something that isn't JSON. Surfacing this
      // distinctly matters: it usually means a proxy or error page intercepted
      // the request, not that the API rejected it.
      return const ApiResponse(
        statusCode: 0,
        body: {'message': 'The server returned a response the app could not read.'},
      );
    } catch (e) {
      return ApiResponse(
        statusCode: 0,
        body: {'message': 'Could not reach the server. Check your connection.'},
      );
    }
  }
}

class ApiResponse {
  const ApiResponse({
    required this.statusCode,
    required this.body,
    this.correlationId,
  });

  final int statusCode;
  final Map<String, dynamic> body;
  final String? correlationId;

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
