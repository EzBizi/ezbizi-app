import 'package:flutter/material.dart';

import '../../core/api_client.dart';

import 'newsletter_blocks.dart';
import 'newsletter_studio.dart';

/// A branded starter campaign (suggestion 01a1ef54, 2026-11-18): versioned,
/// owned by one brand, and made only of native blocks so every word, picture,
/// link and colour stays editable in the composer.
///
/// Using one clones its blocks into a draft; the draft never points back, so
/// editing it cannot change the starter, another draft or the company brand.
/// Audience and schedule are never part of a starter.
class NewsletterStarter {
  const NewsletterStarter({
    required this.id,
    required this.version,
    required this.brand,
    required this.category,
    required this.title,
    required this.subject,
    required this.preheader,
    required this.useCase,
    required this.check,
    this.checkResolved,
    required this.build,
  });

  /// [check] once the booking link resolved to the branch's own site.
  final String? checkResolved;

  final String id;

  /// Bumped whenever the blocks change; a draft keeps what it was cloned from.
  final int version;

  /// The brand whose assets these are (see [NewsletterStarters.forCompany]).
  final String brand;
  final String category;
  final String title;
  final String subject;
  final String preheader;
  final String useCase;

  /// What an editor should confirm before sending.
  final String check;
  final List<Map<String, dynamic>> Function() build;

  String get ref => '$id@$version';

  /// A fresh, deep copy of the blocks: nothing shared with the starter.
  List<Map<String, dynamic>> blocks() => build();
}

/// The A1 Chimney starter pack. Assets are A1 Chimney's own first-party
/// addresses; the booking target is the confirmed national contact page,
/// which the editor replaces with a location's link where there is one.
class NewsletterStarters {
  NewsletterStarters._();

  static const _ink = '#171A1C';
  static const _gray = '#4C4941';
  static const _amber = '#FFB82F';
  static const contact = 'https://www.a-1chimney.com/contact-us/';
  static const logo = 'https://www.a-1chimney.com/wp-content/uploads/2022/05/A1Chimney-Logo-300x47.png';

  static Map<String, dynamic> _logo() => {
    'type': 'image',
    'src': logo,
    'alt': 'A1 Chimney',
    'width': 200,
    'align': 'left',
    'href': 'https://www.a-1chimney.com/',
  };

  static Map<String, dynamic> _eyebrow(String text) => {
    'type': 'text',
    'text': text.toUpperCase(),
    'size': 12,
    'color': _gray,
    'align': 'left',
    'spacing': 6,
  };

  static Map<String, dynamic> _heading(String text, {int size = 34}) => {
    'type': 'heading',
    'text': text,
    'size': size,
    'color': _ink,
    'align': 'left',
  };

  static Map<String, dynamic> _text(String text) => {
    'type': 'text',
    'text': text,
    'size': 16,
    'color': _gray,
    'align': 'left',
  };

  static Map<String, dynamic> _sub(String text) => {
    'type': 'heading',
    'text': text,
    'size': 20,
    'color': _ink,
    'align': 'left',
    'spacing': 8,
  };

  static Map<String, dynamic> _button(String label, String url) => {
    'type': 'button',
    'text': label,
    'url': url,
    'background': _amber,
    'color': _ink,
    'radius': 4,
    'align': 'left',
  };

  static Map<String, dynamic> _image(String src, String alt) => {
    'type': 'image',
    'src': src,
    'alt': alt,
    'align': 'center',
  };

  static Map<String, dynamic> _divider() => {'type': 'divider'};

  static final List<NewsletterStarter> all = [
    NewsletterStarter(
      id: 'fireplace-ready',
      version: 1,
      brand: 'A1 Chimney',
      category: 'Before fireplace season',
      title: 'Seasonal booking',
      subject: 'Get your fireplace ready for the season',
      preheader: 'Start with a chimney inspection or sweep before your first fire.',
      useCase: 'One appointment request before the first cold nights.',
      check: 'Change the seasonal wording, pick a local service photo if you like, and confirm the location’s booking link.',
      checkResolved: 'Change the seasonal wording and pick a local service photo if you like.',
      build: () => [
        _logo(),
        _divider(),
        _eyebrow('Before fireplace season'),
        _heading('Get your fireplace ready.'),
        _image(
          'https://www.a-1chimney.com/wp-content/uploads/2024/02/chimney-inspection-level-1.jpg',
          'A1 Chimney service photo for a chimney inspection',
        ),
        _text(
          'Cozy evenings start with a fireplace you feel good about using. If it has been a while since your last chimney visit, we can help you decide what it needs.',
        ),
        _sub('Start with an inspection'),
        _text('Tell us what you’ve noticed, from an unusual odor to visible wear. We’ll help you choose the right next step.'),
        _sub('Ask about a sweep'),
        _text('If you use a wood-burning fireplace, ask whether it is time for a professional cleaning.'),
        _button('Request a visit', contact),
      ],
    ),
    NewsletterStarter(
      id: 'chimney-check-in',
      version: 1,
      brand: 'A1 Chimney',
      category: 'Homeowner newsletter',
      title: 'The Chimney Check-In',
      subject: 'The Chimney Check-In | Three things to notice this month',
      preheader: 'A quick look at leaks, worn mortar, and changes around the fireplace.',
      useCase: 'A monthly note: a lead story and practical follow-ups.',
      check: 'Swap the three stories each month and update the subject and preview text. Add a local photo or a company update if it fits.',
      build: () => [
        _logo(),
        _divider(),
        _eyebrow('Homeowner newsletter'),
        _heading('The Chimney Check-In', size: 36),
        _text(
          'A short note from A1 Chimney with things to notice around your fireplace and questions to bring to your next visit.',
        ),
        _divider(),
        _sub('After a rainy day'),
        _text('If you notice staining, damp patches, or a musty smell near the fireplace, make a note of when it started.'),
        {
          'type': 'columns',
          'columns': [
            {
              'blocks': [
                _sub('Look from the ground'),
                _text('Take a photo if you can see missing mortar, cracked brick, or a cap that looks out of place.'),
              ],
            },
            {
              'blocks': [
                _sub('Tell us what changed'),
                _text('If something looks or smells different than it did last season, include that detail when you reach out.'),
              ],
            },
          ],
        },
        _button('Ask A1 Chimney', contact),
      ],
    ),
    NewsletterStarter(
      id: 'chimney-liner-spotlight',
      version: 1,
      brand: 'A1 Chimney',
      category: 'Service spotlight',
      title: 'Service spotlight',
      subject: 'What does a chimney liner actually do?',
      preheader: 'A look inside the flue and why a properly fitted liner matters.',
      useCase: 'One service explained, with a quote or appointment request.',
      check: 'Availability and technical recommendations come from the local team. Replace the title, image, explanation and link to reuse it for caps, masonry or other services.',
      build: () => [
        _logo(),
        _divider(),
        _eyebrow('Service spotlight'),
        _heading('A closer look at stainless steel chimney liners.'),
        _image(
          'https://www.a-1chimney.com/wp-content/uploads/2026/08/a1-chimney-liner-sharp-1500x900-v3-20260824.jpg',
          'Stainless steel chimney liner pictured by A1 Chimney',
        ),
        _text(
          'A chimney liner helps carry combustion gases through the flue and out of the home. If an inspection finds damage or a fit issue, your technician can explain whether a new liner makes sense.',
        ),
        _sub('Why fit matters'),
        _text('The right liner depends on your appliance and the condition and dimensions of the flue.'),
        _sub('When to ask'),
        _text('If you’re replacing an appliance or your inspection found liner damage, ask which options fit your setup.'),
        _button('Ask about your chimney liner', contact),
        _text(
          '<a href="https://www.a-1chimney.com/our-services/chimney-liner-installation/">How chimney liner installation works</a>',
        )..['rich'] = true,
      ],
    ),
  ];

  static String _key(String s) => s.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]'), '');

  /// The starters whose assets belong to [company] (the view's company).
  /// Another brand gets its own once its assets and links are supplied.
  static List<NewsletterStarter> forCompany(String company) {
    final k = _key(company);
    return [
      for (final s in all)
        if (k.contains(_key(s.brand))) s,
    ];
  }
}

/// A starter made ready for the view it is used in (follow-up 2026-11-18):
/// its pictures copied from the company's own website into the company's
/// newsletter library (so every client can draw them and the email links to
/// EzBizi's copy), and its booking button sent to the branch's market site.
class StarterResolution {
  StarterResolution({this.pictures = const {}, this.bookingUrl});

  /// Remote address → library address, for each picture that was copied.
  final Map<String, String> pictures;

  /// `<branch site>/booking/`, or null: the template's national page stays.
  final String? bookingUrl;

  bool get regional => bookingUrl != null;

  static Future<StarterResolution> load(ApiClient api, List<NewsletterStarter> starters) async {
    final link = await api.get('/newsletter/booking-link');
    final data = link.ok ? link.body['data'] : null;
    final booking = data is Map ? data['url'] as String? : null;

    final remote = <String>{};
    void collect(List<Map<String, dynamic>> blocks) {
      for (final b in blocks) {
        final src = '${b['src'] ?? ''}';
        if (b['type'] == 'image' && src.startsWith('https://')) remote.add(src);
        for (final c in (b['columns'] as List? ?? const [])) {
          collect([for (final x in ((c as Map)['blocks'] as List? ?? const [])) Map<String, dynamic>.from(x as Map)]);
        }
      }
    }

    for (final s in starters) {
      collect(s.blocks());
    }
    final pictures = <String, String>{};
    for (final url in remote) {
      final response = await api.post('/newsletter/images/copy', {'url': url});
      final copied = response.ok ? response.body['data'] : null;
      if (copied is Map && copied['url'] is String) pictures[url] = copied['url'] as String;
    }
    return StarterResolution(pictures: pictures, bookingUrl: booking);
  }

  /// The starter's blocks with library pictures and the branch booking link.
  List<Map<String, dynamic>> blocks(NewsletterStarter s) {
    void fix(List<Map<String, dynamic>> blocks) {
      for (final b in blocks) {
        final src = pictures[b['src']];
        if (b['type'] == 'image' && src != null) b['src'] = src;
        if (b['type'] == 'button' && b['url'] == NewsletterStarters.contact && bookingUrl != null) {
          b['url'] = bookingUrl;
        }
        for (final c in (b['columns'] as List? ?? const [])) {
          fix([for (final x in ((c as Map)['blocks'] as List? ?? const [])) x as Map<String, dynamic>]);
        }
      }
    }

    final out = s.blocks();
    fix(out);
    return out;
  }

  String check(NewsletterStarter s) => regional ? (s.checkResolved ?? s.check) : s.check;
}

/// Start from template: a thumbnail per starter, then a Desktop / Phone proof
/// of the exact version and one Use template action.
class NewsletterTemplateGallery extends StatefulWidget {
  const NewsletterTemplateGallery({
    super.key,
    required this.company,
    required this.onUse,
    this.api,
    this.useLabel = 'Use template',
    this.note,
  });

  /// The company in view: which brand's starters are offered.
  final String company;
  /// Gets the resolved blocks (library pictures, branch booking link).
  final Future<void> Function(NewsletterStarter starter, List<Map<String, dynamic>> blocks)? onUse;

  /// Copies the pictures and reads the booking link; null shows starters as they are.
  final ApiClient? api;
  final String useLabel;

  /// Said above the Use button (e.g. that it replaces the open draft).
  final String? note;

  @override
  State<NewsletterTemplateGallery> createState() => _NewsletterTemplateGalleryState();
}

class _NewsletterTemplateGalleryState extends State<NewsletterTemplateGallery> {
  NewsletterStarter? _open;
  bool _phone = false;
  bool _using = false;
  StarterResolution _resolved = StarterResolution();

  @override
  void initState() {
    super.initState();
    final api = widget.api;
    final starters = NewsletterStarters.forCompany(widget.company);
    if (api != null && starters.isNotEmpty) {
      StarterResolution.load(api, starters).then((r) {
        if (mounted) setState(() => _resolved = r);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final starters = NewsletterStarters.forCompany(widget.company);
    if (starters.isEmpty) {
      return Padding(
        key: const ValueKey('newsletter-templates-none'),
        padding: const EdgeInsets.all(Studio.grid),
        child: Text(
          'No starters for ${widget.company} yet. Starters are made for a brand '
          'once its logo, pictures and booking links are supplied.',
          style: theme.textTheme.bodySmall,
        ),
      );
    }
    final open = _open;
    if (open == null) {
      return LayoutBuilder(
        builder: (context, c) {
          final per = c.maxWidth >= 640 ? 3 : c.maxWidth >= 380 ? 2 : 1;
          final w = (c.maxWidth - Studio.grid * 2 * (per - 1)) / per;
          return Wrap(
            key: const ValueKey('newsletter-templates'),
            spacing: Studio.grid * 2,
            runSpacing: Studio.grid * 2,
            children: [for (final s in starters) SizedBox(width: w, child: _card(context, s))],
          );
        },
      );
    }
    return _proof(context, open);
  }

  Widget _thumb(NewsletterStarter s, {double height = 220}) => ClipRRect(
    borderRadius: BorderRadius.circular(6),
    child: SizedBox(
      height: height,
      child: OverflowBox(
        alignment: Alignment.topCenter,
        maxHeight: double.infinity,
        child: FittedBox(
          fit: BoxFit.fitWidth,
          alignment: Alignment.topCenter,
          child: SizedBox(
            width: NewsletterEmail.desktopWidth + 36,
            child: IgnorePointer(
              child: NewsletterEmail(blocks: _resolved.blocks(s), company: widget.company),
            ),
          ),
        ),
      ),
    ),
  );

  Widget _card(BuildContext context, NewsletterStarter s) {
    final theme = Theme.of(context);
    return Semantics(
      button: true,
      label: 'Template ${s.title}',
      child: Material(
        color: theme.colorScheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: Studio.line(context)),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          key: ValueKey('newsletter-template-${s.id}'),
          onTap: () => setState(() => _open = s),
          child: Padding(
            padding: const EdgeInsets.all(Studio.grid * 1.5),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _thumb(s, height: 180),
                const SizedBox(height: Studio.grid),
                Text(s.category.toUpperCase(),
                    style: theme.textTheme.labelSmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                Text(s.title, style: theme.textTheme.titleSmall),
                const SizedBox(height: 2),
                Text(s.subject, maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.bodySmall),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _proof(BuildContext context, NewsletterStarter s) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    return Column(
      key: ValueKey('newsletter-template-proof-${s.id}'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            IconButton(
              key: const ValueKey('newsletter-template-back'),
              tooltip: 'All templates',
              onPressed: () => setState(() => _open = null),
              icon: const Icon(Icons.arrow_back),
            ),
            Expanded(child: Text(s.title, style: theme.textTheme.titleMedium)),
            StudioDeviceToggle(
              keyPrefix: 'newsletter-template',
              value: _phone ? 'phone' : 'desktop',
              onChanged: (v) => setState(() => _phone = v == 'phone'),
            ),
          ],
        ),
        const SizedBox(height: Studio.grid),
        Text('Subject: ${s.subject}', style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
        Text('Preview text: ${s.preheader}', style: muted),
        const SizedBox(height: 4),
        Text('${s.useCase} Version ${s.version}.', style: muted),
        const SizedBox(height: Studio.grid),
        Container(
          color: Studio.canvas(context),
          child: NewsletterEmail(
            key: const ValueKey('newsletter-template-email'),
            blocks: _resolved.blocks(s),
            company: widget.company,
            phone: _phone,
            deviceWidth: 375,
          ),
        ),
        const SizedBox(height: Studio.grid),
        Text('Before sending: ${_resolved.check(s)}', key: const ValueKey('newsletter-template-check'), style: muted),
        if (_resolved.regional)
          Text('Booking button: ${_resolved.bookingUrl}', key: const ValueKey('newsletter-template-booking'), style: muted),
        Text('Audience and send time stay unset; the footer is added when it goes out.', style: muted),
        if (widget.note != null) ...[
          const SizedBox(height: 4),
          Text(widget.note!, key: const ValueKey('newsletter-template-note'), style: theme.textTheme.bodySmall),
        ],
        const SizedBox(height: Studio.grid * 1.5),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton.icon(
            key: const ValueKey('newsletter-template-use'),
            onPressed: widget.onUse == null || _using
                ? null
                : () async {
                    setState(() => _using = true);
                    try {
                      await widget.onUse!(s, _resolved.blocks(s));
                    } finally {
                      if (mounted) setState(() => _using = false);
                    }
                  },
            icon: _using
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.check, size: 18),
            label: Text(widget.useLabel),
          ),
        ),
      ],
    );
  }
}
