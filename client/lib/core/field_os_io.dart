import 'dart:io' show Platform;

String? osVersion() {
  final name = Platform.isIOS
      ? 'iOS'
      : Platform.isAndroid
      ? 'Android'
      : null;
  return name == null ? null : '$name ${Platform.operatingSystemVersion}';
}
