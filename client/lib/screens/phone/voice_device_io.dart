import 'voice_device.dart';

/// VM / desktop stub. The Voice SDK is a browser script.
VoiceDevice create() => _SilentVoiceDevice();

class _SilentVoiceDevice implements VoiceDevice {
  @override
  Future<void> setup(String token) async {}

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
