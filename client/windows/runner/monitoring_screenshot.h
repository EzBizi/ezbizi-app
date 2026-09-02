#ifndef RUNNER_MONITORING_SCREENSHOT_H_
#define RUNNER_MONITORING_SCREENSHOT_H_

#include <cstdint>
#include <vector>

// Captures the PRIMARY monitor and returns it JPEG-encoded (quality ~0.6).
//
// Never throws and never crashes: every GDI object and COM interface used
// internally is released on every path, success or failure. Returns an
// empty vector if capture or encoding fails for any reason (no primary
// display, GDI call failure, WIC/COM unavailable, etc.) — the caller must
// treat an empty result as "no screenshot", not as an error to propagate.
std::vector<uint8_t> CapturePrimaryMonitorJpeg();

#endif  // RUNNER_MONITORING_SCREENSHOT_H_
