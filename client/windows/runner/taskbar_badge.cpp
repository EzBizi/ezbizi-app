#include "taskbar_badge.h"

#include <flutter/standard_method_codec.h>
#include <shobjidl.h>

#include <string>
#include <variant>
#include <vector>

namespace {

// A 32x32 red disc with the count in white ("99+" past 99). Drawn with GDI on
// a 32-bit DIB; GDI leaves alpha at 0, so every painted pixel is made opaque.
HICON MakeBadge(int count) {
  const int size = 32;
  BITMAPINFO info = {};
  info.bmiHeader.biSize = sizeof(BITMAPINFOHEADER);
  info.bmiHeader.biWidth = size;
  info.bmiHeader.biHeight = -size;
  info.bmiHeader.biPlanes = 1;
  info.bmiHeader.biBitCount = 32;
  info.bmiHeader.biCompression = BI_RGB;

  HDC screen = ::GetDC(nullptr);
  void* bits = nullptr;
  HBITMAP color =
      ::CreateDIBSection(screen, &info, DIB_RGB_COLORS, &bits, nullptr, 0);
  HDC dc = ::CreateCompatibleDC(screen);
  ::ReleaseDC(nullptr, screen);
  if (color == nullptr || dc == nullptr) {
    if (color) ::DeleteObject(color);
    if (dc) ::DeleteDC(dc);
    return nullptr;
  }
  ::ZeroMemory(bits, size * size * 4);

  HGDIOBJ old_bitmap = ::SelectObject(dc, color);
  HBRUSH red = ::CreateSolidBrush(RGB(0xE5, 0x39, 0x35));
  HGDIOBJ old_brush = ::SelectObject(dc, red);
  HGDIOBJ old_pen = ::SelectObject(dc, ::GetStockObject(NULL_PEN));
  ::Ellipse(dc, 0, 0, size + 1, size + 1);

  const std::wstring text = count > 99 ? L"99+" : std::to_wstring(count);
  HFONT font = ::CreateFontW(text.size() > 2 ? -13 : -20, 0, 0, 0, FW_BOLD,
                             FALSE, FALSE, FALSE, DEFAULT_CHARSET,
                             OUT_DEFAULT_PRECIS, CLIP_DEFAULT_PRECIS,
                             NONANTIALIASED_QUALITY, DEFAULT_PITCH,
                             L"Segoe UI");
  HGDIOBJ old_font = ::SelectObject(dc, font);
  ::SetBkMode(dc, TRANSPARENT);
  ::SetTextColor(dc, RGB(0xFF, 0xFF, 0xFF));
  RECT box = {0, 0, size, size};
  ::DrawTextW(dc, text.c_str(), -1, &box,
              DT_CENTER | DT_VCENTER | DT_SINGLELINE);
  ::GdiFlush();

  auto* pixels = static_cast<DWORD*>(bits);
  for (int i = 0; i < size * size; ++i) {
    if (pixels[i] & 0x00FFFFFF) pixels[i] |= 0xFF000000;
  }

  ::SelectObject(dc, old_font);
  ::SelectObject(dc, old_pen);
  ::SelectObject(dc, old_brush);
  ::SelectObject(dc, old_bitmap);
  ::DeleteObject(font);
  ::DeleteObject(red);
  ::DeleteDC(dc);

  // Alpha decides; the mask is all zeros.
  std::vector<BYTE> zeros(size * size / 8, 0);
  HBITMAP mask = ::CreateBitmap(size, size, 1, 1, zeros.data());
  ICONINFO icon_info = {TRUE, 0, 0, mask, color};
  HICON icon = ::CreateIconIndirect(&icon_info);
  ::DeleteObject(mask);
  ::DeleteObject(color);
  return icon;
}

void SetBadge(HWND window, int count) {
  ITaskbarList3* taskbar = nullptr;
  if (FAILED(::CoCreateInstance(CLSID_TaskbarList, nullptr,
                                CLSCTX_INPROC_SERVER,
                                IID_PPV_ARGS(&taskbar)))) {
    return;
  }
  if (SUCCEEDED(taskbar->HrInit())) {
    HICON icon = count > 0 ? MakeBadge(count) : nullptr;
    const std::wstring label =
        count > 0 ? std::to_wstring(count) + L" unread" : L"";
    taskbar->SetOverlayIcon(window, icon, label.c_str());
    if (icon) ::DestroyIcon(icon);
  }
  taskbar->Release();
}

}  // namespace

std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterTaskbarBadgeChannel(flutter::FlutterEngine* engine, HWND window) {
  auto channel =
      std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
          engine->messenger(), "ezbizi/badge",
          &flutter::StandardMethodCodec::GetInstance());
  channel->SetMethodCallHandler(
      [window](const flutter::MethodCall<flutter::EncodableValue>& call,
               std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>>
                   result) {
        if (call.method_name() != "set") {
          result->NotImplemented();
          return;
        }
        int count = 0;
        if (const auto* value = std::get_if<int32_t>(call.arguments())) {
          count = *value;
        } else if (const auto* big = std::get_if<int64_t>(call.arguments())) {
          count = static_cast<int>(*big);
        }
        SetBadge(window, count < 0 ? 0 : count);
        result->Success();
      });
  return channel;
}
