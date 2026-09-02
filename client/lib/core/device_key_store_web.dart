// Web persistence for the monitoring device key.
//
// The same localStorage-backed store the auth token uses on the web — the
// device key is written with `persist: true`, so it lands in localStorage and
// survives the tab closing. That is right here: the key identifies the install,
// not the browser session. Re-exported rather than duplicated so there is one
// implementation of the browser read/write.
export 'token_store_web.dart' show readItem, writeItem;
