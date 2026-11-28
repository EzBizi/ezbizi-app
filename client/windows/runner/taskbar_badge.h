#ifndef RUNNER_TASKBAR_BADGE_H_
#define RUNNER_TASKBAR_BADGE_H_

#include <flutter/encodable_value.h>
#include <flutter/flutter_engine.h>
#include <flutter/method_channel.h>
#include <windows.h>

#include <memory>

// Chat's unread count on the taskbar button, as WhatsApp desktop shows it
// (Jose, 2026-11-27). `ezbizi/badge` set(int): a red overlay with the number,
// 0 clears it. The same channel the macOS dock answers.
std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterTaskbarBadgeChannel(flutter::FlutterEngine* engine, HWND window);

#endif  // RUNNER_TASKBAR_BADGE_H_
