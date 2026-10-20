/// No camera bridge off Windows (and never on web).
Future<void> startCameraBridge() async {}

/// Whether this PC runs a camera bridge. Always false here.
bool get cameraBridgeSupported => false;
