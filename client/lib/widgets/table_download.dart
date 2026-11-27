import 'dart:async';
import 'dart:convert';

import 'package:archive/archive.dart';
import 'package:flutter/material.dart';

import '../core/files.dart';
import 'date_range_picker.dart';

/// A report table as a file (Merav, 2026-11-19: "Option to download by excel
/// or csv files"): what the screen shows, headings first. A cell is a String,
/// a num or null (empty).
class TableFile {
  /// The file's name: the report and its dates.
  static String name(String report, DateSpan span) => span.single
      ? '$report ${span.fromKey}'
      : '$report ${span.fromKey} to ${span.toKey}';

  /// A figure as a number Excel can add up (`$1,925.00`, `−$45.00`, `1,204`);
  /// anything else (`12%`, `$5.09M`) as its text; a dash as an empty cell.
  /// formatMoney always writes US separators, so stripping them is exact.
  static Object? value(String text, {required bool numeric}) {
    final t = text.trim();
    if (t.isEmpty || t == '—') return null;
    if (!numeric) return t;
    final n = num.tryParse(
      t.replaceAll('−', '-').replaceAll(RegExp(r'[$,*\s]'), ''),
    );
    if (n == null || !n.isFinite) return t;
    // 1925, not 1925.0, in a CSV.
    return n == n.truncate() ? n.toInt() : n;
  }

  /// UTF-8 with a byte-order mark, which Excel needs to read accents.
  static List<int> csv(List<List<Object?>> rows) {
    String field(Object? v) {
      var s = v?.toString() ?? '';
      // A cell Excel would run as a formula is text (CSV injection).
      if (v is! num && RegExp(r'^[=+\-@\t\r]').hasMatch(s)) s = "'$s";
      return RegExp(r'[",\r\n]').hasMatch(s)
          ? '"${s.replaceAll('"', '""')}"'
          : s;
    }

    final body = [for (final r in rows) r.map(field).join(',')].join('\r\n');
    return const Utf8Codec().encode('﻿$body\r\n');
  }

  /// The smallest workbook Excel opens, as the server's Xlsx writes it.
  /// ponytail: no styles or widths; add a package when an export needs them.
  static List<int> xlsx(String title, List<List<Object?>> rows) {
    String esc(String s) => s
        .replaceAll(RegExp(r'[\x00-\x08\x0B\x0C\x0E-\x1F]'), '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
    String column(int i) {
      var s = '';
      for (i++; i > 0; i = (i - 1) ~/ 26) {
        s = String.fromCharCode(65 + (i - 1) % 26) + s;
      }
      return s;
    }

    const xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    const main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const rel = 'http://schemas.openxmlformats.org/package/2006/relationships';
    const doc =
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    final sheet = StringBuffer('$xml<worksheet xmlns="$main"><sheetData>');
    for (final (r, row) in rows.indexed) {
      sheet.write('<row r="${r + 1}">');
      for (final (c, v) in row.indexed) {
        final ref = '${column(c)}${r + 1}';
        if (v is num) {
          sheet.write('<c r="$ref"><v>$v</v></c>');
        } else if (v != null) {
          sheet.write(
            '<c r="$ref" t="inlineStr"><is><t xml:space="preserve">'
            '${esc('$v')}</t></is></c>',
          );
        }
      }
      sheet.write('</row>');
    }
    sheet.write('</sheetData></worksheet>');
    // Excel refuses a sheet name over 31 characters or with []:*?/\ in it.
    final cleaned = title.replaceAll(RegExp(r'[\[\]:*?/\\]'), ' ').trim();
    final name = cleaned.isEmpty
        ? 'Report'
        : cleaned.substring(0, cleaned.length.clamp(0, 31));

    final files = {
      '[Content_Types].xml':
          '$xml<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          '<Default Extension="xml" ContentType="application/xml"/>'
          '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
          '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
          '</Types>',
      '_rels/.rels':
          '$xml<Relationships xmlns="$rel"><Relationship Id="rId1" '
          'Type="$doc/officeDocument" Target="xl/workbook.xml"/></Relationships>',
      'xl/workbook.xml':
          '$xml<workbook xmlns="$main" xmlns:r="$doc"><sheets>'
          '<sheet name="${esc(name)}" sheetId="1" r:id="rId1"/></sheets></workbook>',
      'xl/_rels/workbook.xml.rels':
          '$xml<Relationships xmlns="$rel"><Relationship Id="rId1" '
          'Type="$doc/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
      'xl/worksheets/sheet1.xml': sheet.toString(),
    };
    final archive = Archive();
    files.forEach(
      (path, text) => archive.addFile(ArchiveFile.string(path, text)),
    );
    return ZipEncoder().encodeBytes(archive);
  }
}

/// Download ▾ → Excel (.xlsx) or CSV of [rows], named [name].
class TableDownload extends StatelessWidget {
  const TableDownload({super.key, required this.name, required this.rows});

  final String name;

  /// Read when chosen, so the file is the table as it is then; null when it
  /// could not be read (a paged table fetches its other pages first).
  final FutureOr<List<List<Object?>>?> Function() rows;

  Future<void> _save(BuildContext context, String format) async {
    final messenger = ScaffoldMessenger.maybeOf(context);
    final data = await rows();
    if (data == null) {
      messenger?.showSnackBar(
        const SnackBar(
          content: Text('The table could not be read. Try again.'),
        ),
      );
      return;
    }
    // A report named "A/B" is not a folder.
    Files.download(
      '${name.replaceAll(RegExp(r'[\\/:*?"<>|]'), '-')}.$format',
      format == 'csv' ? TableFile.csv(data) : TableFile.xlsx(name, data),
    );
  }

  @override
  Widget build(BuildContext context) {
    return MenuAnchor(
      menuChildren: [
        MenuItemButton(
          key: const Key('table-download-xlsx'),
          onPressed: () => _save(context, 'xlsx'),
          child: const Text('Excel (.xlsx)'),
        ),
        MenuItemButton(
          key: const Key('table-download-csv'),
          onPressed: () => _save(context, 'csv'),
          child: const Text('CSV'),
        ),
      ],
      builder: (context, menu, _) => OutlinedButton.icon(
        key: const Key('table-download'),
        onPressed: () => menu.isOpen ? menu.close() : menu.open(),
        icon: const Icon(Icons.download_outlined, size: 18),
        label: const Text('Download'),
      ),
    );
  }
}
