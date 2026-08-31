import 'voice_device.dart';

/// VM / desktop stub. The Voice SDK is a browser script.
VoiceDevice create() => _SilentVoiceDevice();

class _SilentVoiceDevice implements VoiceDevice {
  @override
  Future<void> setup(String token) async {}

  /// False, and honestly so: off the web there is no Voice SDK to register
  /// with, so this device can never take a call.
  @override
  bool get registered => false;

  @override
  Future<void> connect({required String to, String? callerId}) async {}

  @override
  void hangUp() {}

  @override
  void acceptIncoming() {}

  @override
  void declineIncoming() {}

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void dispose() {}
}
