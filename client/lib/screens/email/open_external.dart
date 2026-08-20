import 'open_external_stub.dart'
    if (dart.library.js_interop) 'open_external_web.dart' as impl;

/// Open a URL the person can act on — Google Admin, not an EzBizi screen.
void openExternalUrl(String url) => impl.openExternalUrl(url);
