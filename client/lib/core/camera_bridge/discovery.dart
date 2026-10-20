import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

/// A recorder or camera answering ONVIF WS-Discovery on this network.
class FoundDevice {
  const FoundDevice({
    required this.key,
    required this.address,
    this.label,
    this.kind = 'camera',
  });

  final String key;
  final String address;
  final String? label;

  /// `recorder` or `camera`.
  final String kind;

  Map<String, dynamic> toJson() => {
    'key': key,
    'label': label,
    'kind': kind,
    'address': address,
    'channels': const [],
  };
}

/// Parses one WS-Discovery ProbeMatches reply. Regex, not an XML package: we
/// need three fields and the namespaces prefixes vary by vendor.
FoundDevice? parseProbeMatch(String xml) {
  final addr = RegExp(
    r'<[\w-]*:?XAddrs>\s*([^<\s]+)',
  ).firstMatch(xml)?.group(1);
  if (addr == null) return null;
  final host = Uri.tryParse(addr)?.host;
  if (host == null || host.isEmpty) return null;
  final key =
      RegExp(r'<[\w-]*:?Address>\s*([^<\s]+)').firstMatch(xml)?.group(1) ??
      host;
  final scopes =
      RegExp(r'<[\w-]*:?Scopes>([^<]*)<').firstMatch(xml)?.group(1) ?? '';
  String? scope(String name) {
    final m = RegExp(
      'onvif://www.onvif.org/$name/([^\\s]+)',
    ).firstMatch(scopes);
    return m == null ? null : Uri.decodeComponent(m.group(1)!);
  }

  final hardware = scope('hardware');
  final recorder =
      scopes.contains('NVR') ||
      scopes.contains('NetworkVideoRecorder') ||
      (hardware?.toUpperCase().startsWith('NV') ?? false);
  return FoundDevice(
    key: key,
    address: host,
    label: hardware ?? scope('name'),
    kind: recorder ? 'recorder' : 'camera',
  );
}

/// ONVIF WS-Discovery: one multicast probe on UDP 3702, answers for 3 s.
Future<List<FoundDevice>> discoverOnvif({
  Duration wait = const Duration(seconds: 3),
}) async {
  final socket = await RawDatagramSocket.bind(InternetAddress.anyIPv4, 0);
  final id =
      'uuid:${Random().nextInt(1 << 32)}-${DateTime.now().microsecondsSinceEpoch}';
  final probe =
      '<?xml version="1.0" encoding="UTF-8"?>'
      '<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope" '
      'xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing" '
      'xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery" '
      'xmlns:dn="http://www.onvif.org/ver10/network/wsdl">'
      '<e:Header><w:MessageID>$id</w:MessageID>'
      '<w:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>'
      '<w:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action></e:Header>'
      '<e:Body><d:Probe><d:Types>dn:NetworkVideoTransmitter</d:Types></d:Probe></e:Body></e:Envelope>';
  final found = <String, FoundDevice>{};
  final done = Completer<void>();
  socket.listen((event) {
    if (event != RawSocketEvent.read) return;
    final d = socket.receive();
    if (d == null) return;
    final device = parseProbeMatch(utf8.decode(d.data, allowMalformed: true));
    if (device != null) found[device.address] = device;
  });
  socket.send(utf8.encode(probe), InternetAddress('239.255.255.250'), 3702);
  Timer(wait, done.complete);
  await done.future;
  socket.close();
  return found.values.toList();
}
