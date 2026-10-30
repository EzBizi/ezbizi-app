import 'dart:typed_data';

import 'package:ezbizi_client/core/files_io.dart' as io;
import 'package:flutter_test/flutter_test.dart';

/// On a phone a download is the share sheet, not a desktop "Save as" that
/// silently returns nothing (Jose, 2026-10-30: the contract icon did nothing).
void main() {
  test('a PDF on a phone goes to the share sheet with its name', () async {
    final shared = <(String, int)>[];
    final wasPhone = io.isPhone;
    final wasShare = io.sharePdf;
    io.isPhone = () => true;
    io.sharePdf = (Uint8List bytes, String name) async =>
        shared.add((name, bytes.length));
    addTearDown(() {
      io.isPhone = wasPhone;
      io.sharePdf = wasShare;
    });

    io.download('eric-coo.pdf', [37, 80, 68, 70]);
    await Future<void>.delayed(Duration.zero);

    expect(shared, [('eric-coo.pdf', 4)]);
  });
}
