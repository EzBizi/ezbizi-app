import 'package:flutter/services.dart';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/file_access_types.dart';
import '../../core/file_browser_service.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/status_pill.dart';
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

  /// Explorer's panes once a computer is chosen (UI queue 79): quick access
  /// on the left, and the preview beside the list from this width up; below
  /// it the preview stands in for the list, with the way back.
  static const double quickWidth = 200;
  static const double previewWidth = 300;
  static const double previewBesideAbove = 1000;

  /// The agent's own ceiling on a download, and so on a preview.
  static const int previewLimit = 5 * 1024 * 1024;

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

  /// The drives, kept from the roots' listing for Quick access.
  List<FileEntry> _roots = const [];

  /// The file chosen for the preview, and what each picture fetched: null
  /// while on its way.
  FileEntry? _selected;
  final Map<String, List<int>?> _previews = {};
  final Map<String, String> _previewErrors = {};

  /// The details list's order: `name`, `modified` or `size`.
  String _sortBy = 'name';
  bool _ascending = true;

  /// Files is behind the person's own authenticator (Jose, 2026-10-12; UI
  /// queue 79): set when the server answers `locked`, cleared by a code.
  bool _locked = false;
  bool _unlocking = false;
  String? _unlockError;
  final TextEditingController _code = TextEditingController();

  /// Explorer's Quick access from the roots' listing; empty from an agent too
  /// old to send it.
  List<QuickLink> _quickLinks = const [];

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

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
      _roots = const [];
      _quickLinks = const [];
      _selected = null;
      _previews.clear();
      _previewErrors.clear();
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
      _selected = null;
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
        if (path.isEmpty) {
          _roots = listing.entries;
          _quickLinks = listing.quickLinks;
        }
      });
    } on FileAccessException catch (e) {
      if (!mounted) return;
      setState(() {
        _browsing = false;
        if (e.slug == 'locked') {
          _locked = true;
          return;
        }
        _browseError = e.slug == 'offline' ? _offlineSentence(device) : e.slug;
      });
    }
  }

  void _openFolder(FileEntry entry) {
    final parent = _trail.isEmpty ? '' : _trail.last.path;
    final path = _join(parent, entry.name);
    setState(() {
      _trail.add((label: entry.name, path: path));
      _selected = null;
    });
    _open(path);
  }

  void _navigateTo(int crumbIndex) {
    final crumb = _trail[crumbIndex];
    setState(() {
      _trail.removeRange(crumbIndex + 1, _trail.length);
      _selected = null;
    });
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
        if (e.slug == 'locked') _locked = true;
        item.error = e.slug == 'locked'
            ? 'Files locked'
            : e.slug == 'offline'
            ? 'Device offline'
            : (FilesTab.errorNotes[e.slug] ?? 'Could not download.');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth;
        final device = _device;

        // Nothing chosen yet: the computers, on any screen.
        if (device == null) {
          return width >= FilesTab.splitAbove
              ? Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(width: FilesTab.railWidth, child: _rail(context)),
                    const VerticalDivider(width: 1),
                    Expanded(child: _browserPane(context, narrow: false)),
                  ],
                )
              : _rail(context);
        }

        // A computer chosen (UI queue 79, Jose's "as u recommend"): the rail
        // folds into a picker over Explorer's panes - quick access, the
        // details list, and the preview beside it when there is room.
        final phone = width < FilesTab.splitAbove;
        final previewBeside = width >= FilesTab.previewBesideAbove;

        // Locked: the code is asked for where the files were, never in a
        // dialog, and nothing behind it shows.
        if (_locked) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _pickerBar(context, device, phone: phone),
              const Divider(height: 1),
              Expanded(child: _lockCard(context)),
            ],
          );
        }

        final selected = _selected;
        final Widget middle = !previewBeside && selected != null
            ? _preview(context, selected, framed: false)
            : _browserPane(context, narrow: phone);

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _pickerBar(context, device, phone: phone),
            const Divider(height: 1),
            Expanded(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (!phone) ...[
                    SizedBox(
                      width: FilesTab.quickWidth,
                      child: _quickAccess(context),
                    ),
                    const VerticalDivider(width: 1),
                  ],
                  Expanded(child: middle),
                  if (previewBeside) ...[
                    const VerticalDivider(width: 1),
                    SizedBox(
                      width: FilesTab.previewWidth,
                      child: selected == null
                          ? _previewEmpty(context)
                          : _preview(context, selected, framed: true),
                    ),
                  ],
                ],
              ),
            ),
            if (_downloads.isNotEmpty) _downloadsTray(context),
          ],
        );
      },
    );
  }

  /// The chosen computer, and the way to another: a menu of them on a wide
  /// screen, the way back to the list on a phone.
  Widget _pickerBar(
    BuildContext context,
    Map<String, dynamic> device, {
    required bool phone,
  }) {
    final theme = Theme.of(context);
    final user = device['user'] as Map<String, dynamic>? ?? const {};
    final name = user['name'] as String? ?? 'Unknown';
    final machine =
        device['label'] as String? ?? device['platform'] as String? ?? '';
    final label = machine.isEmpty ? name : '$name · $machine';
    final dot = Icon(
      isDeviceOnline(device) ? Icons.circle : Icons.circle_outlined,
      size: 10,
      color: isDeviceOnline(device)
          ? EzBiziTheme.clockIn(context)
          : theme.colorScheme.outline,
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 8, 8, 8),
      child: Row(
        children: [
          if (phone) ...[
            IconButton(
              key: const Key('files-back-to-rail'),
              icon: const Icon(Icons.arrow_back),
              tooltip: 'Back to computers',
              onPressed: _clearDevice,
            ),
            dot,
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                label,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.titleSmall,
              ),
            ),
          ] else
            MenuAnchor(
              builder: (context, menu, _) => OutlinedButton.icon(
                key: const Key('files-device-picker'),
                onPressed: () => menu.isOpen ? menu.close() : menu.open(),
                icon: dot,
                label: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(label),
                    const SizedBox(width: 4),
                    const Icon(Icons.arrow_drop_down, size: 18),
                  ],
                ),
              ),
              menuChildren: [
                for (final other in _devices)
                  MenuItemButton(
                    key: ValueKey('files-pick-${other['id']}'),
                    onPressed: () => _selectDevice(other),
                    child: Text(_deviceLabel(other)),
                  ),
              ],
            ),
          if (!phone) const Spacer(),
          if (!_locked && _browser.unlockedUntil != null) ...[
            Tooltip(
              message: 'Locks after 15 minutes without use.',
              child: StatusPill(
                phone
                    ? 'Unlocked'
                    : 'Unlocked until ${_clock(_browser.unlockedUntil!)}',
                key: const Key('files-unlocked-pill'),
                tone: Tone.ok,
              ),
            ),
            TextButton(
              key: const Key('files-lock-now'),
              onPressed: _lockNow,
              child: const Text('Lock now'),
            ),
          ],
          // Accountability, worded as UI/UX asked: every open is recorded.
          Tooltip(
            message: 'Every file you open here is recorded.',
            child: Icon(
              Icons.policy_outlined,
              size: 16,
              color: theme.colorScheme.outline,
            ),
          ),
        ],
      ),
    );
  }

  String _deviceLabel(Map<String, dynamic> device) {
    final user = device['user'] as Map<String, dynamic>? ?? const {};
    final name = user['name'] as String? ?? 'Unknown';
    final machine =
        device['label'] as String? ?? device['platform'] as String? ?? '';
    return machine.isEmpty ? name : '$name · $machine';
  }

  /// Explorer's left column: This PC, and the drives under it once the roots
  /// have been read.
  Widget _quickAccess(BuildContext context) {
    final theme = Theme.of(context);
    final here = _trail.isEmpty ? '' : _trail.last.path;

    Widget heading(String text) => Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
      child: Text(
        text.toUpperCase(),
        style: theme.textTheme.labelSmall?.copyWith(
          letterSpacing: 0.6,
          fontWeight: FontWeight.w700,
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );

    Widget item(String key, IconData icon, String label, bool on, VoidCallback go) =>
        ListTile(
          key: ValueKey('files-quick-$key'),
          dense: true,
          selected: on,
          visualDensity: VisualDensity.compact,
          leading: Icon(icon, size: 18),
          title: Text(label, overflow: TextOverflow.ellipsis),
          onTap: go,
        );

    return ListView(
      children: [
        if (_quickLinks.isNotEmpty) ...[
          heading('Quick access'),
          for (final link in _quickLinks)
            item(
              link.label,
              _quickIcon(link.label),
              link.label,
              here == link.path,
              () => _openQuick(link),
            ),
        ],
        heading('This PC'),
        item('this-pc', Icons.computer_outlined, 'This PC', here.isEmpty, () {
          _navigateTo(0);
        }),
        for (final drive in _roots)
          item(
            drive.name,
            Icons.storage_outlined,
            drive.name,
            here == drive.name,
            () => _openRoot(drive.name),
          ),
      ],
    );
  }

  /// Straight to Desktop, Downloads… as Explorer's Quick access does: the
  /// crumbs read This PC › Downloads, not the whole path.
  void _openQuick(QuickLink link) {
    setState(() {
      _trail
        ..removeRange(1, _trail.length)
        ..add((label: link.label, path: link.path));
      _selected = null;
    });
    _open(link.path);
  }

  static IconData _quickIcon(String label) => switch (label.toLowerCase()) {
    'desktop' => Icons.desktop_windows_outlined,
    'documents' => Icons.description_outlined,
    'downloads' => Icons.download_outlined,
    'pictures' => Icons.image_outlined,
    'videos' => Icons.movie_outlined,
    'music' => Icons.music_note_outlined,
    _ => Icons.folder_outlined,
  };

  /// Asks the server, then walks back into the folder that asked for it.
  Future<void> _unlock() async {
    final code = _code.text.trim();
    if (!RegExp(r'^\d{6}$').hasMatch(code)) {
      setState(
        () => _unlockError = 'Enter the 6 digits from your authenticator app.',
      );
      return;
    }

    setState(() {
      _unlocking = true;
      _unlockError = null;
    });
    final why = await _browser.unlock(code);
    if (!mounted) return;

    setState(() {
      _unlocking = false;
      _unlockError = why;
      if (why == null) {
        _locked = false;
        _code.clear();
      }
    });
    if (why == null) _open(_trail.isEmpty ? '' : _trail.last.path);
  }

  /// Locks on the server, and forgets every picture fetched while open.
  Future<void> _lockNow() async {
    await _browser.lock();
    if (!mounted) return;
    setState(() {
      _locked = true;
      _selected = null;
      _previews.clear();
      _previewErrors.clear();
    });
  }

  String _clock(DateTime at) {
    final local = at.toLocal();
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    return formats.formatTime(local.hour, local.minute);
  }

  /// The code, asked for in place of the files (UI queue 79).
  Widget _lockCard(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 400),
          child: Column(
            key: const Key('files-lock-card'),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Align(
                alignment: Alignment.centerLeft,
                child: Icon(
                  Icons.lock_outline,
                  size: 32,
                  color: theme.colorScheme.primary,
                ),
              ),
              const SizedBox(height: 12),
              Text('Files is locked', style: theme.textTheme.titleMedium),
              const SizedBox(height: 6),
              Text(
                'Enter the 6-digit code from your authenticator app, the same '
                'one you sign in with. Files stays open while you use it, and '
                'locks after 15 minutes without use.',
                style: muted,
              ),
              const SizedBox(height: 16),
              TextField(
                key: const Key('files-unlock-code'),
                controller: _code,
                enabled: !_unlocking,
                autofocus: true,
                keyboardType: TextInputType.number,
                textAlign: TextAlign.center,
                maxLength: 6,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                style: theme.textTheme.headlineSmall?.copyWith(
                  letterSpacing: 8,
                ),
                decoration: const InputDecoration(
                  counterText: '',
                  hintText: '······',
                  border: OutlineInputBorder(),
                ),
                onSubmitted: (_) => _unlock(),
              ),
              if (_unlockError != null) ...[
                const SizedBox(height: 8),
                Text(
                  _unlockError!,
                  key: const Key('files-unlock-error'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              ],
              const SizedBox(height: 12),
              FilledButton(
                key: const Key('files-unlock-submit'),
                onPressed: _unlocking ? null : _unlock,
                child: Text(_unlocking ? 'Checking…' : 'Unlock'),
              ),
              const SizedBox(height: 10),
              Text(
                'Each file you open or download is logged under your name.',
                style: muted,
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Straight to a drive from Quick access, as though from This PC.
  void _openRoot(String path) {
    setState(() {
      _trail
        ..removeRange(1, _trail.length)
        ..add((label: path, path: path));
      _selected = null;
    });
    _open(path);
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
      ],
    );
  }

  Widget _breadcrumbBar(
    BuildContext context,
    Map<String, dynamic> device, {
    required bool narrow,
  }) {
    final count = _listing?.entries.length;

    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 4, 12, 4),
      child: Row(
        children: [
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
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
          if (count != null && !_browsing && _browseError == null)
            Text(
              count == 1 ? '1 item' : '$count items',
              style: Theme.of(context).textTheme.bodySmall,
            ),
        ],
      ),
    );
  }

  Widget _list(BuildContext context) {
    if (_browsing) {
      return const Center(child: CircularProgressIndicator());
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
    if (entries.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(FilesTab.emptyFolderNote,
              style: Theme.of(context).textTheme.bodyMedium),
        ),
      );
    }

    // Folders first, then files - the order a file browser is read in - each
    // by the column chosen.
    int by(FileEntry a, FileEntry b) => switch (_sortBy) {
      'modified' => (a.modifiedAt ?? '').compareTo(b.modifiedAt ?? ''),
      'size' => (a.sizeBytes ?? -1).compareTo(b.sizeBytes ?? -1),
      _ => a.name.toLowerCase().compareTo(b.name.toLowerCase()),
    };
    final sorted = [...entries]..sort((a, b) {
        if (a.isDir != b.isDir) return a.isDir ? -1 : 1;
        final order = by(a, b);
        return _ascending ? order : -order;
      });

    return LayoutBuilder(
      builder: (context, box) {
        final columns = box.maxWidth >= 480;

        // A phone has no Quick access column: at This PC its links come
        // first, above the drives (UI queue 79, phone 4).
        if (!columns && _trail.length == 1 && _quickLinks.isNotEmpty) {
          final theme = Theme.of(context);
          Widget heading(String text) => Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
            child: Text(
              text.toUpperCase(),
              style: theme.textTheme.labelSmall?.copyWith(
                letterSpacing: 0.6,
                fontWeight: FontWeight.w700,
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          );

          return ListView(
            key: const Key('files-listing'),
            children: [
              heading('Quick access'),
              for (final link in _quickLinks)
                ListTile(
                  key: ValueKey('files-quick-${link.label}'),
                  leading: Icon(
                    _quickIcon(link.label),
                    color: theme.colorScheme.primary,
                  ),
                  title: Text(link.label),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _openQuick(link),
                ),
              heading('This PC'),
              for (final entry in sorted) ...[
                _entryRow(context, entry, columns: false),
                const Divider(height: 1),
              ],
            ],
          );
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (columns) ...[_listHeader(context), const Divider(height: 1)],
            Expanded(
              child: ListView.separated(
                key: const Key('files-listing'),
                itemCount: sorted.length,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (context, i) =>
                    _entryRow(context, sorted[i], columns: columns),
              ),
            ),
          ],
        );
      },
    );
  }

  /// Name · Modified · Size, each a way to sort.
  Widget _listHeader(BuildContext context) {
    final theme = Theme.of(context);

    Widget heading(String key, String label, {bool end = false}) => InkWell(
      key: ValueKey('files-sort-$key'),
      onTap: () => setState(() {
        _ascending = _sortBy == key ? !_ascending : true;
        _sortBy = key;
      }),
      child: Row(
        mainAxisAlignment: end ? MainAxisAlignment.end : MainAxisAlignment.start,
        children: [
          Text(
            label.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              letterSpacing: 0.6,
              fontWeight: FontWeight.w700,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          if (_sortBy == key)
            Icon(
              _ascending ? Icons.arrow_upward : Icons.arrow_downward,
              size: 12,
            ),
        ],
      ),
    );

    return SizedBox(
      height: 32,
      child: _rowCells(
        heading('name', 'Name'),
        heading('modified', 'Modified'),
        heading('size', 'Size', end: true),
      ),
    );
  }

  Widget _rowCells(Widget name, Widget modified, Widget size) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 12),
    child: Row(
      children: [
        Expanded(child: Align(alignment: Alignment.centerLeft, child: name)),
        const SizedBox(width: 12),
        SizedBox(
          width: 150,
          child: Align(alignment: Alignment.centerLeft, child: modified),
        ),
        SizedBox(
          width: 80,
          child: Align(alignment: Alignment.centerRight, child: size),
        ),
      ],
    ),
  );

  /// One entry: a folder opens, a file is chosen for the preview.
  Widget _entryRow(
    BuildContext context,
    FileEntry entry, {
    required bool columns,
  }) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final on = !entry.isDir && _selected?.name == entry.name;
    final name = Row(
      children: [
        Icon(_iconFor(entry), size: 18, color: theme.colorScheme.primary),
        const SizedBox(width: 10),
        Flexible(
          child: Text(
            entry.name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodyMedium,
          ),
        ),
      ],
    );

    return Material(
      color: on ? theme.colorScheme.primaryContainer : Colors.transparent,
      child: InkWell(
        key: ValueKey('files-entry-${entry.name}'),
        onTap: () => entry.isDir ? _openFolder(entry) : _select(entry),
        child: SizedBox(
          height: columns ? 40 : 52,
          child: columns
              ? _rowCells(
                  name,
                  Text(_when(entry.modifiedAt) ?? '', style: small),
                  Text(
                    entry.sizeBytes == null ? '—' : _humanSize(entry.sizeBytes!),
                    style: small,
                  ),
                )
              // A phone: the name, and its size and date under it.
              : Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  child: Row(
                    children: [
                      Icon(_iconFor(entry), color: theme.colorScheme.primary),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              entry.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            if (!entry.isDir)
                              Text(_fileSubtitle(entry), style: small),
                          ],
                        ),
                      ),
                      if (entry.isDir) const Icon(Icons.chevron_right),
                    ],
                  ),
                ),
        ),
      ),
    );
  }

  /// A file chosen: a picture is fetched to be looked at (one request, and
  /// a download in the log); anything else shows what it is.
  void _select(FileEntry entry) {
    setState(() => _selected = entry);
    if (_isPicture(entry) && (entry.sizeBytes ?? 0) <= FilesTab.previewLimit) {
      _loadPreview(entry);
    }
  }

  Future<void> _loadPreview(FileEntry entry) async {
    final device = _device;
    final path = _pathOf(entry);
    if (device == null || _previews.containsKey(path)) return;

    setState(() => _previews[path] = null);
    try {
      final file = await _browser.download(device['id'] as String, path);
      if (!mounted) return;
      setState(() => _previews[path] = file.bytes);
    } on FileAccessException catch (e) {
      if (!mounted) return;
      setState(() {
        _previews.remove(path);
        if (e.slug == 'locked') {
          _locked = true;
          return;
        }
        _previewErrors[path] = e.slug == 'offline'
            ? _offlineSentence(device)
            : (FilesTab.errorNotes[e.slug] ?? 'It could not be shown.');
      });
    }
  }

  Widget _previewEmpty(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        'Choose a file to see it here.',
        textAlign: TextAlign.center,
        style: Theme.of(context).textTheme.bodySmall,
      ),
    ),
  );

  /// What a chosen file is: the picture itself when it is one, then its
  /// facts and Download. [framed] is the pane beside the list; otherwise it
  /// stands in for the list, with the way back.
  Widget _preview(BuildContext context, FileEntry entry, {required bool framed}) {
    final theme = Theme.of(context);
    final path = _pathOf(entry);
    final picture = _isPicture(entry);
    final tooBig = (entry.sizeBytes ?? 0) > FilesTab.previewLimit;
    final bytes = _previews[path];
    final error = _previewErrors[path];
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    final Widget shown;
    if (picture && bytes != null) {
      shown = ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: Image.memory(
          Uint8List.fromList(bytes),
          key: ValueKey('files-preview-image-${entry.name}'),
          fit: BoxFit.contain,
          errorBuilder: (context, _, _) =>
              Text('That picture could not be read.', style: muted),
        ),
      );
    } else if (picture && error != null) {
      shown = Text(error, style: muted);
    } else if (picture && !tooBig) {
      // Static on purpose: a spinner here would keep every test's settle
      // waiting for an animation, and the fetch is one short round trip.
      shown = Text('Fetching the picture…', style: muted);
    } else {
      shown = Icon(_iconFor(entry), size: 56, color: theme.colorScheme.primary);
    }

    final facts = <(String, String)>[
      ('Type', _typeOf(entry)),
      if (entry.sizeBytes != null) ('Size', _humanSize(entry.sizeBytes!)),
      if (_when(entry.modifiedAt) case final at?) ('Modified', at),
      ('Where', _trail.isEmpty ? '' : _trail.last.path),
    ];

    final body = ListView(
      key: ValueKey('files-preview-${entry.name}'),
      padding: const EdgeInsets.all(12),
      children: [
        if (!framed)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const Key('files-preview-back'),
              onPressed: () => setState(() => _selected = null),
              icon: const Icon(Icons.arrow_back, size: 18),
              label: Text(_trail.isEmpty ? 'Back' : 'Back to ${_trail.last.label}'),
            ),
          ),
        ConstrainedBox(
          constraints: BoxConstraints(maxHeight: framed ? 220 : 360),
          child: Center(child: shown),
        ),
        const SizedBox(height: 10),
        Text(
          entry.name,
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 6),
        for (final (label, value) in facts)
          Padding(
            padding: const EdgeInsets.only(bottom: 2),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(width: 72, child: Text(label, style: muted)),
                Expanded(
                  child: Text(value, style: theme.textTheme.bodySmall),
                ),
              ],
            ),
          ),
        const SizedBox(height: 10),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton.icon(
            key: ValueKey('files-download-${entry.name}'),
            onPressed: tooBig ? null : () => _download(entry),
            icon: const Icon(Icons.download_outlined, size: 18),
            label: const Text('Download'),
          ),
        ),
        if (tooBig)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(FilesTab.errorNotes['too_large']!, style: muted),
          ),
        if (picture && !tooBig)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              'Opening a preview counts as a download in the log.',
              style: muted,
            ),
          ),
      ],
    );

    return framed
        ? ColoredBox(color: theme.colorScheme.surfaceContainerLow, child: body)
        : body;
  }

  String _pathOf(FileEntry entry) =>
      _join(_trail.isEmpty ? '' : _trail.last.path, entry.name);

  static const _pictures = {'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'};

  static String _extension(String name) {
    final dot = name.lastIndexOf('.');
    return dot < 0 ? '' : name.substring(dot + 1).toLowerCase();
  }

  static bool _isPicture(FileEntry entry) =>
      !entry.isDir && _pictures.contains(_extension(entry.name));

  /// A drive at the roots reads as one ("C:\").
  static bool _isDrive(FileEntry entry) =>
      entry.isDir && RegExp(r'^[A-Za-z]:\\?$').hasMatch(entry.name);

  static IconData _iconFor(FileEntry entry) {
    if (_isDrive(entry)) return Icons.storage_outlined;
    if (entry.isDir) return Icons.folder_outlined;

    return switch (_extension(entry.name)) {
      'jpg' || 'jpeg' || 'png' || 'gif' || 'webp' || 'bmp' || 'heic' =>
        Icons.image_outlined,
      'pdf' => Icons.picture_as_pdf_outlined,
      'xls' || 'xlsx' || 'csv' => Icons.table_chart_outlined,
      'mp4' || 'mov' || 'avi' || 'mkv' => Icons.movie_outlined,
      'mp3' || 'wav' || 'm4a' => Icons.audio_file_outlined,
      'zip' || 'rar' || '7z' => Icons.folder_zip_outlined,
      _ => Icons.description_outlined,
    };
  }

  static String _typeOf(FileEntry entry) {
    if (_isDrive(entry)) return 'Drive';
    if (entry.isDir) return 'Folder';

    return switch (_extension(entry.name)) {
      'jpg' || 'jpeg' => 'JPEG picture',
      'png' => 'PNG picture',
      'gif' => 'GIF picture',
      'webp' => 'WebP picture',
      'bmp' => 'Bitmap picture',
      'pdf' => 'PDF document',
      'xls' || 'xlsx' => 'Excel workbook',
      'csv' => 'CSV file',
      'doc' || 'docx' => 'Word document',
      'txt' => 'Text file',
      'zip' => 'ZIP archive',
      '' => 'File',
      final ext => '${ext.toUpperCase()} file',
    };
  }

  /// The agent's stamp in this person's format; the raw text when it is not
  /// a date this can read.
  String? _when(String? stamp) {
    if (stamp == null || stamp.isEmpty) return null;
    final at = DateTime.tryParse(stamp)?.toLocal();
    if (at == null) return stamp;
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    // A date with no time of day says only the day.
    if (!stamp.contains('T') && !stamp.contains(' ')) {
      return formats.formatDate(at);
    }
    return '${formats.formatDate(at)} ${formats.formatTime(at.hour, at.minute)}';
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
    if (_when(entry.modifiedAt) case final at?) parts.add(at);
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

enum _DownloadStatus { downloading, ready, error }

class _Download {
  _Download(this.name);

  final String name;
  _DownloadStatus status = _DownloadStatus.downloading;
  String? error;
}
