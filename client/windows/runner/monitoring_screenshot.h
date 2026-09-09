#ifndef RUNNER_MONITORING_SCREENSHOT_H_
#define RUNNER_MONITORING_SCREENSHOT_H_

#include <cstdint>
#include <string>
#include <vector>

// Captures the PRIMARY monitor and returns it JPEG-encoded (quality ~0.6).
//
// Never throws and never crashes: every GDI object and COM interface used
// internally is released on every path, success or failure. Returns an
// empty vector if capture or encoding fails for any reason (no primary
// display, GDI call failure, WIC/COM unavailable, etc.).
//
// ── Why failure carries a reason ───────────────────────────────────
//
// This used to return a bare empty vector and the channel handler turned
// that into `Success(null)`, so ~20 distinct failures — a locked
// workstation, a missing WIC JPEG encoder, a failed blit — all reached Dart
// as the exact same "no bytes" and were logged only under kDebugMode, i.e.
// nowhere in a shipped build. A device that never delivered a screenshot was
// therefore indistinguishable from one that had simply not tried yet, and no
// amount of querying the server could tell them apart, because nothing had
// ever recorded which happened.
//
// [reason] (optional, may be null) receives a short stable slug naming the
// failing step on an empty return — see kCaptureReason* in the .cpp. It is
// deliberately a fixed vocabulary, not a formatted message: it is reported
// to the server on the next heartbeat and shown to a human, so it must not
// carry any of the screen's content, a path, or a user name.
std::vector<uint8_t> CapturePrimaryMonitorJpeg(std::string* reason = nullptr);

#endif  // RUNNER_MONITORING_SCREENSHOT_H_
