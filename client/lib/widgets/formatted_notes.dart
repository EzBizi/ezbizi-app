import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Formatted notes (Eric's suggestion 01a21365, Jose 11-25): bold, italic and
/// underline in any notes field.
///
/// Stored as the plain string with three tags only - `<b>`, `<i>`, `<u>` - so
/// the API column is unchanged and an old plain note reads exactly as before.
/// Anything that leaves the app (Copy, texts, emails, exports) goes through
/// [NotesMarkup.plain]; the server has the same in App\Support\NotesMarkup.
// ponytail: a note that literally contains "<b>" etc. reads as a tag; no
// escaping until someone actually types one.
class NotesMarkup {
  static const bold = 1, italic = 2, underline = 4;
  static const _tags = {'b': bold, 'i': italic, 'u': underline};
  static final _tag = RegExp(r'<(/?)([biu])>');

  /// The text without tags, and one style mask per character.
  static (String, List<int>) parse(String? markup) {
    final text = StringBuffer();
    final masks = <int>[];
    final open = {bold: 0, italic: 0, underline: 0};
    var at = 0;
    void add(String s) {
      final m = open.entries.fold(0, (a, e) => e.value > 0 ? a | e.key : a);
      text.write(s);
      masks.addAll(List.filled(s.length, m));
    }

    for (final t in _tag.allMatches(markup ?? '')) {
      add(markup!.substring(at, t.start));
      final bit = _tags[t[2]]!;
      open[bit] = t[1] == '/' ? (open[bit]! - 1).clamp(0, 99) : open[bit]! + 1;
      at = t.end;
    }
    add((markup ?? '').substring(at));
    return (text.toString(), masks);
  }

  static String serialize(String text, List<int> masks) {
    final out = StringBuffer();
    var i = 0;
    while (i < text.length) {
      final m = masks[i];
      var j = i;
      while (j < text.length && masks[j] == m) {
        j++;
      }
      final seg = text.substring(i, j);
      out.write(
        '${m & bold > 0 ? '<b>' : ''}${m & italic > 0 ? '<i>' : ''}'
        '${m & underline > 0 ? '<u>' : ''}$seg'
        '${m & underline > 0 ? '</u>' : ''}${m & italic > 0 ? '</i>' : ''}'
        '${m & bold > 0 ? '</b>' : ''}',
      );
      i = j;
    }
    return out.toString();
  }

  static String plain(String? markup) => parse(markup).$1;

  static TextStyle styleFor(int m) => TextStyle(
    fontWeight: m & bold > 0 ? FontWeight.bold : null,
    fontStyle: m & italic > 0 ? FontStyle.italic : null,
    decoration: m & underline > 0 ? TextDecoration.underline : null,
  );

  static List<TextSpan> spans(String text, List<int> masks) {
    final out = <TextSpan>[];
    var i = 0;
    while (i < text.length) {
      var j = i;
      while (j < text.length && masks[j] == masks[i]) {
        j++;
      }
      out.add(
        TextSpan(text: text.substring(i, j), style: styleFor(masks[i])),
      );
      i = j;
    }
    return out;
  }
}

/// A [TextEditingController] that keeps a style mask per character.
class FormattedNotesController extends TextEditingController {
  FormattedNotesController({String? markup}) : this._(NotesMarkup.parse(markup));
  FormattedNotesController._((String, List<int>) p)
    : _masks = p.$2,
      super(text: p.$1);

  List<int> _masks;

  /// Style for the next typed characters when nothing is selected.
  int typing = 0;

  String get markup => NotesMarkup.serialize(text, _masks);

  @override
  set value(TextEditingValue v) {
    final old = text, now = v.text;
    if (old != now) {
      var pre = 0;
      while (pre < old.length && pre < now.length && old[pre] == now[pre]) {
        pre++;
      }
      var suf = 0;
      while (suf < old.length - pre &&
          suf < now.length - pre &&
          old[old.length - 1 - suf] == now[now.length - 1 - suf]) {
        suf++;
      }
      final inserted = now.length - pre - suf;
      _masks = [
        ..._masks.take(pre),
        ...List.filled(inserted, typing),
        ..._masks.skip(old.length - suf),
      ];
    } else if (v.selection != selection && v.selection.isCollapsed) {
      final at = v.selection.baseOffset;
      typing = at > 0 && at <= _masks.length ? _masks[at - 1] : 0;
    }
    super.value = v;
  }

  bool has(int bit) {
    final s = selection;
    if (!s.isValid || s.isCollapsed) return typing & bit > 0;
    return _masks.sublist(s.start, s.end).every((m) => m & bit > 0);
  }

  void toggle(int bit) {
    final s = selection;
    final on = !has(bit);
    if (!s.isValid || s.isCollapsed) {
      typing = on ? typing | bit : typing & ~bit;
    } else {
      for (var i = s.start; i < s.end; i++) {
        _masks[i] = on ? _masks[i] | bit : _masks[i] & ~bit;
      }
    }
    notifyListeners();
  }

  @override
  TextSpan buildTextSpan({
    required BuildContext context,
    TextStyle? style,
    required bool withComposing,
  }) => TextSpan(style: style, children: NotesMarkup.spans(text, _masks));
}

class _Toggle extends Intent {
  const _Toggle(this.bit);
  final int bit;
}

/// The editor: B / I / U toolbar plus Ctrl/Cmd+B/I/U. On a phone the toolbar
/// sits under the field and the field scrolls clear of the keyboard by the
/// toolbar's height, so the toolbar rides just above the keyboard.
class FormattedNotesField extends StatelessWidget {
  const FormattedNotesField({
    super.key,
    required this.controller,
    this.label = 'Notes',
    this.minLines = 3,
    this.fieldKey,
    this.maxLength,
    this.enabled = true,
  });

  final FormattedNotesController controller;
  final String label;
  final int minLines;
  final Key? fieldKey;
  final int? maxLength;
  final bool enabled;

  static const _toolbarHeight = 40.0;

  @override
  Widget build(BuildContext context) {
    final phone = MediaQuery.sizeOf(context).width < 600;
    final toolbar = ListenableBuilder(
      listenable: controller,
      builder: (context, _) => SizedBox(
        height: _toolbarHeight,
        child: Row(
          children: [
            for (final (bit, icon, tip) in const [
              (NotesMarkup.bold, Icons.format_bold, 'Bold'),
              (NotesMarkup.italic, Icons.format_italic, 'Italic'),
              (NotesMarkup.underline, Icons.format_underlined, 'Underline'),
            ])
              IconButton(
                key: ValueKey('notes-format-$bit'),
                tooltip: tip,
                isSelected: controller.has(bit),
                icon: Icon(icon),
                onPressed: enabled ? () => controller.toggle(bit) : null,
              ),
          ],
        ),
      ),
    );
    final field = Shortcuts(
      shortcuts: {
        for (final (key, bit) in [
          (LogicalKeyboardKey.keyB, NotesMarkup.bold),
          (LogicalKeyboardKey.keyI, NotesMarkup.italic),
          (LogicalKeyboardKey.keyU, NotesMarkup.underline),
        ]) ...{
          SingleActivator(key, control: true): _Toggle(bit),
          SingleActivator(key, meta: true): _Toggle(bit),
        },
      },
      child: Actions(
        actions: {
          _Toggle: CallbackAction<_Toggle>(
            onInvoke: (i) => controller.toggle(i.bit),
          ),
        },
        child: TextField(
          key: fieldKey,
          controller: controller,
          enabled: enabled,
          minLines: minLines,
          maxLines: null,
          maxLength: maxLength,
          scrollPadding: const EdgeInsets.all(20).copyWith(
            bottom: 20 + (phone ? _toolbarHeight : 0),
          ),
          decoration: InputDecoration(labelText: label),
        ),
      ),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: phone ? [field, toolbar] : [toolbar, field],
    );
  }
}

/// Read-only notes, formatted. Plain old notes render exactly as before.
class FormattedNotesText extends StatelessWidget {
  const FormattedNotesText(this.markup, {super.key, this.style});
  final String? markup;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    final (text, masks) = NotesMarkup.parse(markup);
    return SelectableText.rich(
      TextSpan(style: style, children: NotesMarkup.spans(text, masks)),
    );
  }
}
