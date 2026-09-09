#include "monitoring_screenshot.h"

#include <objbase.h>
#include <string>
#include <windows.h>
#include <wincodec.h>
#include <wrl/client.h>

using Microsoft::WRL::ComPtr;

namespace {

// RAII wrapper for the shared screen DC from GetDC(NULL), released with
// ReleaseDC (NOT DeleteDC — it is owned by the system, not created by us).
class ScreenDC {
 public:
  ScreenDC() : hdc_(::GetDC(nullptr)) {}
  ~ScreenDC() {
    if (hdc_) {
      ::ReleaseDC(nullptr, hdc_);
    }
  }
  ScreenDC(const ScreenDC&) = delete;
  ScreenDC& operator=(const ScreenDC&) = delete;

  HDC get() const { return hdc_; }
  explicit operator bool() const { return hdc_ != nullptr; }

 private:
  HDC hdc_;
};

// RAII wrapper for a memory DC created with CreateCompatibleDC, released
// with DeleteDC.
class MemoryDC {
 public:
  explicit MemoryDC(HDC source) : hdc_(::CreateCompatibleDC(source)) {}
  ~MemoryDC() {
    if (hdc_) {
      ::DeleteDC(hdc_);
    }
  }
  MemoryDC(const MemoryDC&) = delete;
  MemoryDC& operator=(const MemoryDC&) = delete;

  HDC get() const { return hdc_; }
  explicit operator bool() const { return hdc_ != nullptr; }

 private:
  HDC hdc_;
};

// RAII wrapper for a GDI bitmap, released with DeleteObject. Callers must
// make sure the bitmap is not still selected into a DC when this destructs
// (DeleteObject silently fails, i.e. leaks, on a selected bitmap).
class GdiBitmap {
 public:
  explicit GdiBitmap(HBITMAP bitmap) : bitmap_(bitmap) {}
  ~GdiBitmap() {
    if (bitmap_) {
      ::DeleteObject(bitmap_);
    }
  }
  GdiBitmap(const GdiBitmap&) = delete;
  GdiBitmap& operator=(const GdiBitmap&) = delete;

  HBITMAP get() const { return bitmap_; }
  explicit operator bool() const { return bitmap_ != nullptr; }

 private:
  HBITMAP bitmap_;
};

// RAII guard around CoInitializeEx/CoUninitialize. WIC requires COM. If some
// other component on this thread already initialized COM with a different
// concurrency model (RPC_E_CHANGED_MODE), COM is still usable — we just must
// not call CoUninitialize, since we don't own that initialization.
class ComGuard {
 public:
  ComGuard() {
    HRESULT hr = ::CoInitializeEx(nullptr, COINIT_APARTMENTTHREADED);
    if (hr == RPC_E_CHANGED_MODE) {
      owns_ = false;
      usable_ = true;
    } else {
      owns_ = SUCCEEDED(hr);
      usable_ = SUCCEEDED(hr);
    }
  }
  ~ComGuard() {
    if (owns_) {
      ::CoUninitialize();
    }
  }
  ComGuard(const ComGuard&) = delete;
  ComGuard& operator=(const ComGuard&) = delete;

  bool usable() const { return usable_; }

 private:
  bool owns_ = false;
  bool usable_ = false;
};

// Whether the interactive desktop is one we are allowed to read. This is
// FALSE while the workstation is locked, while the screensaver is secure, and
// for the moment a UAC prompt owns the secure desktop — in every one of those
// cases the blit below would "succeed" and hand back a black frame, which is
// worse than failing, because a black screenshot looks like evidence.
bool InputDesktopReadable() {
  HDESK desk = ::OpenInputDesktop(0, FALSE, DESKTOP_READOBJECTS);
  if (desk == nullptr) {
    return false;
  }
  ::CloseDesktop(desk);
  return true;
}

// The fixed failure vocabulary. Stable slugs: they are stored on the device
// row and rendered to a human, so they are renamed only alongside the reader.
const char kCaptureReasonCom[] = "com_unavailable";
const char kCaptureReasonLocked[] = "screen_locked";
const char kCaptureReasonNoScreen[] = "no_screen";
const char kCaptureReasonGdi[] = "gdi_failed";
const char kCaptureReasonBlit[] = "blit_failed";
const char kCaptureReasonPixels[] = "pixels_unreadable";
const char kCaptureReasonEncoder[] = "jpeg_encoder_missing";
const char kCaptureReasonEncode[] = "encode_failed";

// Records the reason (when the caller asked for one) and returns the empty
// vector that means "no screenshot". One helper so a new bail-out cannot
// forget to say why — the old code's failure mode was silence, not wrong text.
std::vector<uint8_t> Fail(std::string* reason, const char* slug) {
  if (reason != nullptr) {
    reason->assign(slug);
  }
  return std::vector<uint8_t>();
}

}  // namespace

std::vector<uint8_t> CapturePrimaryMonitorJpeg(std::string* reason) {
  // Cheapest check first, and the one that is a normal fact of a working day
  // rather than a fault: somebody locked their machine or walked away.
  if (!InputDesktopReadable()) {
    return Fail(reason, kCaptureReasonLocked);
  }

  // WIC needs COM on this thread.
  ComGuard com_guard;
  if (!com_guard.usable()) {
    return Fail(reason, kCaptureReasonCom);
  }

  const int width = ::GetSystemMetrics(SM_CXSCREEN);
  const int height = ::GetSystemMetrics(SM_CYSCREEN);
  if (width <= 0 || height <= 0) {
    return Fail(reason, kCaptureReasonNoScreen);
  }

  // --- Grab the primary monitor into a 32bpp top-down DIB buffer. ---

  ScreenDC screen_dc;
  if (!screen_dc) {
    return Fail(reason, kCaptureReasonGdi);
  }

  MemoryDC mem_dc(screen_dc.get());
  if (!mem_dc) {
    return Fail(reason, kCaptureReasonGdi);
  }

  GdiBitmap bitmap(
      ::CreateCompatibleBitmap(screen_dc.get(), width, height));
  if (!bitmap) {
    return Fail(reason, kCaptureReasonGdi);
  }

  HGDIOBJ previous_bitmap = ::SelectObject(mem_dc.get(), bitmap.get());
  if (previous_bitmap == nullptr) {
    return Fail(reason, kCaptureReasonGdi);
  }

  BOOL blit_ok = ::BitBlt(mem_dc.get(), 0, 0, width, height, screen_dc.get(),
                          0, 0, SRCCOPY);
  // Deselect our bitmap so it is safe to DeleteObject regardless of the
  // BitBlt outcome, and so the DC is left in its original state.
  ::SelectObject(mem_dc.get(), previous_bitmap);
  if (!blit_ok) {
    return Fail(reason, kCaptureReasonBlit);
  }

  BITMAPINFO bitmap_info = {};
  bitmap_info.bmiHeader.biSize = sizeof(BITMAPINFOHEADER);
  bitmap_info.bmiHeader.biWidth = width;
  bitmap_info.bmiHeader.biHeight = -height;  // Top-down.
  bitmap_info.bmiHeader.biPlanes = 1;
  bitmap_info.bmiHeader.biBitCount = 32;
  bitmap_info.bmiHeader.biCompression = BI_RGB;

  const size_t stride = static_cast<size_t>(width) * 4;
  std::vector<uint8_t> pixels(stride * static_cast<size_t>(height));

  int lines_copied = ::GetDIBits(mem_dc.get(), bitmap.get(), 0, height,
                                 pixels.data(), &bitmap_info, DIB_RGB_COLORS);
  if (lines_copied != height) {
    return Fail(reason, kCaptureReasonPixels);
  }

  // --- Encode the BGRA buffer to JPEG via WIC. ---

  ComPtr<IWICImagingFactory> wic_factory;
  HRESULT hr = ::CoCreateInstance(CLSID_WICImagingFactory, nullptr,
                                  CLSCTX_INPROC_SERVER,
                                  IID_PPV_ARGS(&wic_factory));
  if (FAILED(hr) || !wic_factory) {
    return Fail(reason, kCaptureReasonEncoder);
  }

  // GDI leaves the 4th byte undefined (not a real alpha channel), so treat
  // the buffer as opaque BGR to avoid the encoder reading garbage alpha.
  ComPtr<IWICBitmap> wic_bitmap;
  hr = wic_factory->CreateBitmapFromMemory(
      static_cast<UINT>(width), static_cast<UINT>(height),
      GUID_WICPixelFormat32bppBGR, static_cast<UINT>(stride),
      static_cast<UINT>(pixels.size()), pixels.data(), &wic_bitmap);
  if (FAILED(hr) || !wic_bitmap) {
    return Fail(reason, kCaptureReasonEncode);
  }

  ComPtr<IStream> stream;
  {
    IStream* raw_stream = nullptr;
    hr = ::CreateStreamOnHGlobal(nullptr, TRUE, &raw_stream);
    if (FAILED(hr) || !raw_stream) {
      return Fail(reason, kCaptureReasonEncode);
    }
    stream.Attach(raw_stream);
  }

  ComPtr<IWICBitmapEncoder> encoder;
  hr = wic_factory->CreateEncoder(GUID_ContainerFormatJpeg, nullptr,
                                  &encoder);
  if (FAILED(hr) || !encoder) {
    return Fail(reason, kCaptureReasonEncoder);
  }

  hr = encoder->Initialize(stream.Get(), WICBitmapEncoderNoCache);
  if (FAILED(hr)) {
    return Fail(reason, kCaptureReasonEncode);
  }

  ComPtr<IWICBitmapFrameEncode> frame;
  ComPtr<IPropertyBag2> property_bag;
  hr = encoder->CreateNewFrame(&frame, &property_bag);
  if (FAILED(hr) || !frame) {
    return Fail(reason, kCaptureReasonEncode);
  }

  if (property_bag) {
    PROPBAG2 option = {};
    option.pstrName = const_cast<LPOLESTR>(L"ImageQuality");
    VARIANT value;
    ::VariantInit(&value);
    value.vt = VT_R4;
    value.fltVal = 0.6f;
    property_bag->Write(1, &option, &value);
  }

  hr = frame->Initialize(property_bag.Get());
  if (FAILED(hr)) {
    return Fail(reason, kCaptureReasonEncode);
  }

  hr = frame->SetSize(static_cast<UINT>(width), static_cast<UINT>(height));
  if (FAILED(hr)) {
    return Fail(reason, kCaptureReasonEncode);
  }

  // Let the encoder pick the pixel format it wants; WriteSource converts
  // our BGR buffer to it internally.
  WICPixelFormatGUID pixel_format = GUID_WICPixelFormatDontCare;
  hr = frame->SetPixelFormat(&pixel_format);
  if (FAILED(hr)) {
    return Fail(reason, kCaptureReasonEncode);
  }

  hr = frame->WriteSource(wic_bitmap.Get(), nullptr);
  if (FAILED(hr)) {
    return Fail(reason, kCaptureReasonEncode);
  }

  hr = frame->Commit();
  if (FAILED(hr)) {
    return Fail(reason, kCaptureReasonEncode);
  }

  hr = encoder->Commit();
  if (FAILED(hr)) {
    return Fail(reason, kCaptureReasonEncode);
  }

  // --- Copy the encoded JPEG out of the memory-backed IStream. ---

  STATSTG stats = {};
  hr = stream->Stat(&stats, STATFLAG_NONAME);
  if (FAILED(hr) || stats.cbSize.QuadPart == 0 ||
      stats.cbSize.QuadPart > static_cast<ULONGLONG>(UINT32_MAX)) {
    return Fail(reason, kCaptureReasonEncode);
  }

  LARGE_INTEGER origin = {};
  hr = stream->Seek(origin, STREAM_SEEK_SET, nullptr);
  if (FAILED(hr)) {
    return Fail(reason, kCaptureReasonEncode);
  }

  std::vector<uint8_t> jpeg_bytes(
      static_cast<size_t>(stats.cbSize.QuadPart));
  ULONG bytes_read = 0;
  hr = stream->Read(jpeg_bytes.data(), static_cast<ULONG>(jpeg_bytes.size()),
                    &bytes_read);
  if (FAILED(hr) || bytes_read != jpeg_bytes.size()) {
    return Fail(reason, kCaptureReasonEncode);
  }

  return jpeg_bytes;
}
