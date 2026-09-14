/// The web half of mobile_view_window.dart.
///
/// A browser tab cannot resize the window it is in — `window.resizeTo` has
/// been refused for anything the script did not open itself for twenty years,
/// and quite right. Mobile view on the web is the narrow column and nothing
/// else, which is the whole of what it was before this file existed.
Future<void> shrink(double width) async {}

Future<void> restore() async {}
