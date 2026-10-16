cmake_minimum_required(VERSION 3.14)
project(runner LANGUAGES CXX)

# Define the application target. To change its name, change BINARY_NAME in the
# top-level CMakeLists.txt, not the value here, or `flutter run` will no longer
# work.
#
# Any new source files that you add to the application should be added here.
add_executable(${BINARY_NAME} WIN32
  "flutter_window.cpp"
  "main.cpp"
  "monitoring_specs.cpp"
  "monitoring_screenshot.cpp"
  "monitoring_input.cpp"
  "utils.cpp"
  "watchdog.cpp"
  "win32_window.cpp"
  "${FLUTTER_MANAGED_DIR}/generated_plugin_registrant.cc"
  "Runner.rc"
  "runner.exe.manifest"
)

# Apply the standard set of build settings. This can be removed for applications
# that need different build settings.
apply_standard_settings(${BINARY_NAME})

# Add preprocessor definitions for the build version.
target_compile_definitions(${BINARY_NAME} PRIVATE "FLUTTER_VERSION=\"${FLUTTER_VERSION}\"")
target_compile_definitions(${BINARY_NAME} PRIVATE "FLUTTER_VERSION_MAJOR=${FLUTTER_VERSION_MAJOR}")
target_compile_definitions(${BINARY_NAME} PRIVATE "FLUTTER_VERSION_MINOR=${FLUTTER_VERSION_MINOR}")
target_compile_definitions(${BINARY_NAME} PRIVATE "FLUTTER_VERSION_PATCH=${FLUTTER_VERSION_PATCH}")
target_compile_definitions(${BINARY_NAME} PRIVATE "FLUTTER_VERSION_BUILD=${FLUTTER_VERSION_BUILD}")

# Disable Windows macros that collide with C++ standard library functions.
target_compile_definitions(${BINARY_NAME} PRIVATE "NOMINMAX")

# Add dependency libraries and include directories. Add any application-specific
# dependencies here.
target_link_libraries(${BINARY_NAME} PRIVATE flutter flutter_wrapper_app)
target_link_libraries(${BINARY_NAME} PRIVATE "dwmapi.lib")
# Shell_NotifyIcon, for the notification-area icon the close-to-tray behaviour
# depends on. See win32_window.cpp.
target_link_libraries(${BINARY_NAME} PRIVATE "shell32.lib")
# WLAN API, for the Wi-Fi SSID/signal reads in monitoring_specs.cpp.
target_link_libraries(${BINARY_NAME} PRIVATE "wlanapi.lib")

# Local address, link type and per-adapter traffic counters for PC Stats
# (GetAdaptersAddresses / GetIfEntry2), and inet_ntop from ws2_32.
target_link_libraries(${BINARY_NAME} PRIVATE "iphlpapi.lib")
target_link_libraries(${BINARY_NAME} PRIVATE "ws2_32.lib")

# Browser tab titles come from the accessibility tree; there is no Win32
# call for a tab, because a tab is not a window.
target_link_libraries(${BINARY_NAME} PRIVATE "uiautomationcore.lib")

# GPU load comes from performance counters (pdh) and the security posture from
# Windows' own Security Center (wscapi) — neither has a plain Win32 call.
target_link_libraries(${BINARY_NAME} PRIVATE "pdh.lib")
target_link_libraries(${BINARY_NAME} PRIVATE "wscapi.lib")
# Screen capture (BitBlt/GetDIBits), JPEG encoding (WIC), and the COM APIs
# WIC needs, for the primary-monitor screenshot channel. See
# monitoring_screenshot.cpp.
target_link_libraries(${BINARY_NAME} PRIVATE "gdi32.lib")
target_link_libraries(${BINARY_NAME} PRIVATE "windowscodecs.lib")
target_link_libraries(${BINARY_NAME} PRIVATE "ole32.lib")
# SendInput, for remote-control mouse/keyboard injection. See
# monitoring_input.cpp.
target_link_libraries(${BINARY_NAME} PRIVATE "user32.lib")
target_include_directories(${BINARY_NAME} PRIVATE "${CMAKE_SOURCE_DIR}")

# Run the Flutter tool portions of the build. This must not be removed.
add_dependencies(${BINARY_NAME} flutter_assemble)
