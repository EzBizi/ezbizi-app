import 'package:ezbizi_client/core/files_io.dart';
import 'package:flutter_test/flutter_test.dart';

/// The `accept` string is the only real logic in the native picker: everything
/// else is one call into file_selector. All three shapes below are passed by
/// live callers, so a wrong translation is a dialog that hides the file the
/// person came to pick.
void main() {
  test('server attachment extensions become a filter', () {
    final groups = acceptGroups('.pdf,.jpg,.png');

    expect(groups, hasLength(1));
    expect(groups.single.extensions, ['pdf', 'jpg', 'png']);
  });

  test('image and video wildcards become real extensions', () {
    // A native dialog filters on extension; it has never heard of image/*.
    final groups = acceptGroups('image/*,video/*');

    expect(groups.single.extensions, contains('jpg'));
    expect(groups.single.extensions, contains('mp4'));
    expect(groups.single.extensions, isNot(contains('image/*')));
  });

  test('every group carries a UTI, which iOS requires', () {
    // client_issue 01a1a934: iOS threw on each pick of an extensions-only group.
    expect(acceptGroups('.pdf').single.uniformTypeIdentifiers, isNotEmpty);
  });

  test('a spreadsheet import keeps both extensions', () {
    expect(acceptGroups('.csv,.xlsx').single.extensions, ['csv', 'xlsx']);
  });

  test('an unreadable accept offers every file rather than none', () {
    // The string comes from the server. A filter we cannot parse must not
    // become a dialog that shows nothing.
    expect(acceptGroups(''), isEmpty);
    expect(acceptGroups('   '), isEmpty);
  });

  test('a concrete mime type falls back to its subtype', () {
    expect(acceptGroups('application/pdf').single.extensions, ['pdf']);
  });

  test('duplicates collapse so the dialog is not given the same filter twice', () {
    final extensions = acceptGroups('.jpg,image/*').single.extensions!;

    expect(extensions.where((e) => e == 'jpg'), hasLength(1));
  });
}
