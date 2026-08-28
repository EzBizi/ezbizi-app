import 'files.dart';

/// Stubs for every non-web target. See files.dart.
void download(String filename, List<int> content) {}

Future<PickedFile?> pickText() async => null;

void clearQuery() {}

Future<PickedBytes?> pickImage() async => null;

Future<PickedBytes?> pickBytes({String accept = ''}) async => null;

String? previewUrl(List<int> bytes, String mime) => null;

void releasePreviewUrl(String url) {}
