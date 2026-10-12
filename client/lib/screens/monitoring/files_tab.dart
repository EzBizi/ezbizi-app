import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/file_access_types.dart';
import '../../core/file_browser_service.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import 'device_display.dart';

/// Files — browse and download a monitored machine's files (the viewer half of
/// 2026-10-01-monitoring-files). UI/UX's recommended layout: a device rail, a
/// breadcrumb over one list, and a downloads tray; on a phone, one job per
/// screen (the rail, then the browser, with a back arrow — no full-screen push,
/// per the containment rule).
///
/// Every navigation is a round trip (request, then poll), so each folder open
/// and each download shows a brief loading beat — [FileBrowserService] owns the
/// asking; this owns the showing.
class FilesTab extends StatefulWidget {
  const FilesTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Below this the rail and the browser are one-at-a-time (phone); above, side
  /// by side. Same breakpoint the ownership chart uses.
  static const double splitAbove = 600;

  static const double railWidth = 280;

  /// The slug → sentence map the agent's refusals read as. `offline` is handled
  /// separately because it names the device's last-seen time.
  static const Map<String, String> errorNotes = {
    'access_denied': 'Windows refused access to this folder.',
    'not_found': 'This folder or file no longer exists.',
    'too_large': 'Over 5 MB — it can’t be fetched from here yet.',
    'unreadable': 'This could not be read (it may be locked or in use).',
  };

  static const String emptyFolderNote = 'This folder is empty.';
  static const String pickDeviceNote =
      'Pick a computer on the left to browse its files.';

  @override
  State<FilesTab> createState() => _FilesTabState();
}

class _FilesTabState extends State<FilesTab> {
  late final FileBrowserService _browser = FileBrowserService(widget.api);

  bool _loadingDevices = true;
  String? _devicesError;
  List<Map<String, dynamic>> _devices = [];

  Map<String, dynamic>? _device;

  /// The path from the roots to where we are, as (label, path) crumbs — a stack
  /// rather than a parsed path string, so a Windows path never has to be split
  /// back apart to build the breadcrumb.
  final List<({String label, String path})> _trail = [];

  bool _browsing = false;
  String? _browseError; // a slug, or the composed offline sentence
  FileListing? _listing;

  final List<_Download> _downloads = [];

  @override
  void initState() {
    super.initState();
    _loadDevices();
  }

  Future<void> _loadDevices() async {
    setState(() {
      _loadingDevices = _devices.isEmpty;
      _devicesError = null;
    });

    try {
      final response = await widget.api.get('/monitoring/devices');
      if (!mounted) return;

      if (!response.ok) {
        setState(() {
          _loadingDevices = false;
          _devicesError = 'Could not load the device list.';
        });
        return;
      }

      setState(() {
        _devices = (response.data as List<dynamic>).cast<Map<String, dynamic>>();
        _loadingDevices = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loadingDevices = false;
        _devicesError = 'Could not load the device list.';
      });
    }
  }

  void _selectDevice(Map<String, dynamic> device) {
    setState(() {
      _device = device;
      _trail
        ..clear()
        ..add((label: 'This PC', path: ''));
      _listing = null;
      _browseError = null;
    });
    _open('');
  }

  void _clearDevice() {
    setState(() {
      _device = null;
      _trail.clear();
      _listing = null;
      _browseError = null;
    });
  }

  Future<void> _open(String path) async {
    final device = _device;
    if (device == null) return;

    setState(() {
      _browsing = true;
      _browseError = null;
    });

    try {
      final listing = await _browser.list(device['id'] as String, path);
      if (!mounted) return;
      setState(() {
        _listing = listing;
        _browsing = false;
      });
    } on FileAccessException catch (e) {
      if (!mounted) return;
      setState(() {
        _browsing = false;
        _browseError = e.slug == 'offline' ? _offlineSentence(device) : e.slug;
      });
    }
  }

  void _openFolder(FileEntry entry) {
    final parent = _trail.isEmpty ? '' : _trail.last.path;
    final path = _join(parent, entry.name);
    setState(() => _trail.add((label: entry.name, path: path)));
    _open(path);
  }

  void _navigateTo(int crumbIndex) {
    final crumb = _trail[crumbIndex];
    setState(() => _trail.removeRange(crumbIndex + 1, _trail.length));
    _open(crumb.path);
  }

  Future<void> _download(FileEntry entry) async {
    final device = _device;
    if (device == null) return;

    final parent = _trail.isEmpty ? '' : _trail.last.path;
    final path = _join(parent, entry.name);

    final item = _Download(entry.name);
    setState(() => _downloads.insert(0, item));

    try {
      final file = await _browser.download(device['id'] as String, path);
      if (!mounted) return;
      // Hand the bytes to the browser's own save (web). On the desktop build
      // Files.download is a no-op today — a manager fetching files is on the
      // web app — so the tray still marks it ready rather than claiming a save
      // that did not happen.
      Files.download(file.name, file.bytes);
      setState(() => item.status = _DownloadStatus.ready);
    } on FileAccessException catch (e) {
      if (!mounted) return;
      setState(() {
        item.status = _DownloadStatus.error;
        item.error = switch (e.slug) {
          'offline' => 'Device offline',
          'locked' => 'Enter your authenticator code first',
          _ => FilesTab.errorNotes[e.slug] ?? 'Could not download.',
        };
        // The 15 minutes lapsed mid-browse: bring the code prompt back.
        if (e.slug == 'locked') _browseError = 'locked';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= FilesTab.splitAbove;

        if (wide) {
          return Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: FilesTab.railWidth, child: _rail(context)),
              const VerticalDivider(width: 1),
              Expanded(child: _browserPane(context, narrow: false)),
            ],
          );
        }

        // Phone: one job per screen, swapped within the tab (no Navigator).
        return _device == null
            ? _rail(context)
            : _browserPane(context, narrow: true);
      },
    );
  }

  Widget _rail(BuildContext context) {
    return AsyncBody(
      loading: _loadingDevices,
      error: _devicesError,
      onRetry: _loadDevices,
      child: ListView(
        key: const Key('files-device-rail'),
        children: [
          for (final device in _devices) _deviceTile(context, device),
        ],
      ),
    );
  }

  Widget _deviceTile(BuildContext context, Map<String, dynamic> device) {
    final user = device['user'] as Map<String, dynamic>? ?? const {};
    final name = user['name'] as String? ?? 'Unknown';
    final machine = device['label'] as String? ?? device['platform'] as String? ?? '';
    final online = isDeviceOnline(device);
    final selected = _device != null && _device!['id'] == device['id'];

    return ListTile(
      key: ValueKey('files-device-${device['id']}'),
      selected: selected,
      leading: Icon(
        online ? Icons.circle : Icons.circle_outlined,
        size: 12,
        color: online
            ? EzBiziTheme.clockIn(context)
            : Theme.of(context).colorScheme.outline,
      ),
      title: Text(name),
      subtitle: machine.isEmpty ? null : Text(machine),
      onTap: () => _selectDevice(device),
    );
  }

  Widget _browserPane(BuildContext context, {required bool narrow}) {
    final device = _device;
    if (device == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(FilesTab.pickDeviceNote,
              style: Theme.of(context).textTheme.bodyMedium),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _breadcrumbBar(context, device, narrow: narrow),
        const Divider(height: 1),
        Expanded(child: _list(context)),
        if (_downloads.isNotEmpty) _downloadsTray(context),
      ],
    );
  }

  Widget _breadcrumbBar(
    BuildContext context,
    Map<String, dynamic> device, {
    required bool narrow,
  }) {
    final user = device['user'] as Map<String, dynamic>? ?? const {};
    final who = user['name'] as String? ?? 'this computer';

    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 8, 8, 8),
      child: Row(
        children: [
          if (narrow)
            IconButton(
              key: const Key('files-back-to-rail'),
              icon: const Icon(Icons.arrow_back),
              tooltip: 'Back to computers',
              onPressed: _clearDevice,
            ),
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  Padding(
                    padding: const EdgeInsets.only(right: 4),
                    child: Text("$who · ",
                        style: Theme.of(context).textTheme.labelMedium),
                  ),
                  for (var i = 0; i < _trail.length; i++) ...[
                    if (i > 0) const Icon(Icons.chevron_right, size: 16),
                    TextButton(
                      onPressed: i == _trail.length - 1
                          ? null
                          : () => _navigateTo(i),
                      child: Text(_trail[i].label),
                    ),
                  ],
                ],
              ),
            ),
          ),
          // Accountability, worded as UI/UX asked: every open is recorded.
          Tooltip(
            message: 'Every file you open here is recorded.',
            child: Icon(Icons.policy_outlined,
                size: 16, color: Theme.of(context).colorScheme.outline),
          ),
        ],
      ),
    );
  }

  Widget _list(BuildContext context) {
    if (_browsing) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_browseError == 'locked') {
      return _UnlockPrompt(
        browser: _browser,
        onUnlocked: () => _open(_trail.isEmpty ? '' : _trail.last.path),
      );
    }

    if (_browseError != null) {
      final note = FilesTab.errorNotes[_browseError] ?? _browseError!;
      return Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(note, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: () =>
                    _open(_trail.isEmpty ? '' : _trail.last.path),
                child: const Text('Try again'),
              ),
            ],
          ),
        ),
      );
    }

    final entries = _listing?.entries ?? const [];
    // Explorer's Quick access, above the drives on This PC. Only agents new
    // enough to send them have any.
    final quickLinks = _listing?.quickLinks ?? const <QuickLink>[];
    if (entries.isEmpty && quickLinks.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(FilesTab.emptyFolderNote,
              style: Theme.of(context).textTheme.bodyMedium),
        ),
      );
    }

    // Folders first, then files, each alphabetical — the order a file browser is
    // read in.
    final sorted = [...entries]..sort((a, b) {
        if (a.isDir != b.isDir) return a.isDir ? -1 : 1;
        return a.name.toLowerCase().compareTo(b.name.toLowerCase());
      });

    return ListView.separated(
      key: const Key('files-listing'),
      itemCount: quickLinks.length + sorted.length,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, i) => i < quickLinks.length
          ? _quickLinkTile(quickLinks[i])
          : _entryTile(context, sorted[i - quickLinks.length]),
    );
  }

  Widget _quickLinkTile(QuickLink link) {
    return ListTile(
      key: ValueKey('files-quick-${link.label}'),
      leading: Icon(_quickIcon(link.label)),
      title: Text(link.label),
      trailing: const Icon(Icons.chevron_right),
      onTap: () {
        setState(() => _trail.add((label: link.label, path: link.path)));
        _open(link.path);
      },
    );
  }

  static IconData _quickIcon(String label) => switch (label) {
        'Desktop' => Icons.desktop_windows_outlined,
        'Documents' => Icons.description_outlined,
        'Downloads' => Icons.download_outlined,
        'Pictures' => Icons.image_outlined,
        'Videos' => Icons.movie_outlined,
        'Music' => Icons.music_note_outlined,
        _ => Icons.folder_special_outlined,
      };

  Widget _entryTile(BuildContext context, FileEntry entry) {
    return ListTile(
      key: ValueKey('files-entry-${entry.name}'),
      leading: Icon(entry.isDir ? Icons.folder_outlined : Icons.description_outlined),
      title: Text(entry.name),
      subtitle: entry.isDir ? null : Text(_fileSubtitle(entry)),
      trailing: entry.isDir
          ? const Icon(Icons.chevron_right)
          : IconButton(
              icon: const Icon(Icons.download_outlined),
              tooltip: 'Download',
              onPressed: () => _download(entry),
            ),
      onTap: () => entry.isDir ? _openFolder(entry) : _download(entry),
    );
  }

  Widget _downloadsTray(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: theme.colorScheme.surfaceContainerHighest,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Column(
          key: const Key('files-downloads-tray'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Text('Downloads', style: theme.textTheme.labelLarge),
                const Spacer(),
                TextButton(
                  onPressed: () => setState(() => _downloads.clear()),
                  child: const Text('Clear'),
                ),
              ],
            ),
            for (final d in _downloads.take(5)) _downloadRow(context, d),
          ],
        ),
      ),
    );
  }

  Widget _downloadRow(BuildContext context, _Download d) {
    final theme = Theme.of(context);
    final (icon, color) = switch (d.status) {
      _DownloadStatus.downloading => (Icons.hourglass_empty, theme.colorScheme.outline),
      _DownloadStatus.ready => (Icons.check_circle_outline, EzBiziTheme.clockIn(context)),
      _DownloadStatus.error => (Icons.error_outline, theme.colorScheme.error),
    };

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 8),
          Expanded(child: Text(d.name, overflow: TextOverflow.ellipsis)),
          if (d.status == _DownloadStatus.error && d.error != null)
            Text(d.error!, style: theme.textTheme.bodySmall?.copyWith(color: color)),
        ],
      ),
    );
  }

  String _fileSubtitle(FileEntry entry) {
    final parts = <String>[];
    if (entry.sizeBytes != null) parts.add(_humanSize(entry.sizeBytes!));
    if (entry.modifiedAt != null) parts.add(entry.modifiedAt!);
    return parts.join(' · ');
  }

  String _offlineSentence(Map<String, dynamic> device) {
    final seen = DateTime.tryParse(device['last_seen_at'] as String? ?? '');
    if (seen == null) {
      return 'This computer looks offline — it has not reported in.';
    }
    final mins = DateTime.now().toUtc().difference(seen.toUtc()).inMinutes;
    return 'This computer looks offline (last seen $mins min ago).';
  }

  /// Join a Windows path and a child name, tolerant of a base that already ends
  /// in a separator (a drive root like `C:\`) and of an empty base (the roots).
  static String _join(String base, String name) {
    if (base.isEmpty) return name;
    if (base.endsWith('\\') || base.endsWith('/')) return '$base$name';
    return '$base\\$name';
  }

  static String _humanSize(int bytes) {
    if (bytes < 1024) return '$bytes B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(0)} KB';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
}

/// Files is locked behind a current authenticator code (Jose, 2026-10-12).
/// Shown in place of the listing — not a dialog — and reopens where the
/// manager was once the code verifies. ponytail: placeholder look; UI/UX owns
/// the final layout of this screen.
class _UnlockPrompt extends StatefulWidget {
  const _UnlockPrompt({required this.browser, required this.onUnlocked});

  final FileBrowserService browser;
  final VoidCallback onUnlocked;

  static const String note =
      'Enter a current code from your authenticator to open files. '
      'It stays open for 15 minutes after you stop browsing.';

  @override
  State<_UnlockPrompt> createState() => _UnlockPromptState();
}

class _UnlockPromptState extends State<_UnlockPrompt> {
  final _code = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy || _code.text.trim().length != 6) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final error = await widget.browser.unlock(_code.text);
    if (!mounted) return;
    if (error == null) {
      widget.onUnlocked();
      return;
    }
    setState(() {
      _busy = false;
      _error = error;
      _code.clear();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 360),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.lock_outline, size: 32),
              const SizedBox(height: 12),
              const Text(_UnlockPrompt.note, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              TextField(
                key: const Key('files-unlock-code'),
                controller: _code,
                autofocus: true,
                enabled: !_busy,
                keyboardType: TextInputType.number,
                maxLength: 6,
                textAlign: TextAlign.center,
                decoration: InputDecoration(
                  labelText: 'Authenticator code',
                  counterText: '',
                  errorText: _error,
                ),
                onSubmitted: (_) => _submit(),
              ),
              const SizedBox(height: 12),
              FilledButton(
                key: const Key('files-unlock-submit'),
                onPressed: _busy ? null : _submit,
                child: const Text('Unlock'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

enum _DownloadStatus { downloading, ready, error }

class _Download {
  _Download(this.name);

  final String name;
  _DownloadStatus status = _DownloadStatus.downloading;
  String? error;
}
