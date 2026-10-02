import 'file_access_types.dart';

// The platform split: `dart:io` on the Windows agent, a refusing stub
// everywhere else, so importing this never drags `dart:io` into a web build —
// the same conditional-import shape training's video_embed.dart uses.
import 'file_access_reader_stub.dart'
    if (dart.library.io) 'file_access_reader_io.dart' as impl;

/// The reader for this build. The service takes an optional override for tests;
/// this is what it falls back to.
const FileAccessReader defaultFileAccessReader = impl.reader;
