import 'package:flutter/material.dart';

import '../core/theme.dart';

/// What a lead says about where it came from, read in ONE place (approved
/// design 159, options 1 + 4). The server's field names are not final: a
/// rename is a change to [LeadSourceFields.from] and nothing else.
class LeadSourceFields {
  const LeadSourceFields({
    this.sourceKey,
    this.avatarUrl,
    this.jobType,
    this.city,
    this.adQuestion = false,
    this.adName,
    this.campaignName,
    this.headline,
    this.pageName,
    this.handle,
    this.phone,
    this.email,
    this.address,
  });

  /// [lead] is a list item; [detail] the lead's own load, read first.
  factory LeadSourceFields.from(
    Map<String, dynamic> lead, [
    Map<String, dynamic>? detail,
  ]) {
    String? pick(String key) => _text(detail?[key]) ?? _text(lead[key]);
    final referral = detail?['referral'] ?? lead['referral'];
    String? ref(String key) => referral is Map ? _text(referral[key]) : null;

    return LeadSourceFields(
      sourceKey: chipKeyFor(pick('source')),
      avatarUrl: pick('avatar_url'),
      jobType: pick('job_type'),
      city: pick('city'),
      adQuestion:
          (detail?['last_contact_ad_question'] ??
              lead['last_contact_ad_question']) ==
          true,
      adName: ref('ad_name'),
      campaignName: ref('campaign_name'),
      headline: ref('headline'),
      pageName: ref('page_name'),
      handle: pick('handle'),
      phone: pick('phone'),
      email: pick('email'),
      address: pick('address'),
    );
  }

  final String? sourceKey;
  final String? avatarUrl;
  final String? jobType;
  final String? city;
  final bool adQuestion;
  final String? adName;
  final String? campaignName;
  final String? headline;
  final String? pageName;
  final String? handle;
  final String? phone;
  final String? email;
  final String? address;

  /// "Chimney repair · Springfield", or null when neither is known.
  String? get jobLine => _join([jobType, city]);

  /// "via ad: X · campaign Y", only when a referral exists.
  String? get referralLine => _join([
    if (adName != null) 'via ad: $adName',
    if (campaignName != null) 'campaign $campaignName',
  ]);

  bool get hasDetails => [
    phone,
    email,
    address,
    jobType,
    adName,
    campaignName,
    headline,
    pageName,
    handle,
  ].any((v) => v != null);

  static String? _text(Object? value) {
    final s = value is String ? value.trim() : null;

    return s == null || s.isEmpty ? null : s;
  }

  static String? _join(List<String?> parts) {
    final kept = parts.whereType<String>().toList();

    return kept.isEmpty ? null : kept.join(' · ');
  }
}

/// The server's source key to a chip's key, or null for one with no colour.
String? chipKeyFor(String? source) =>
    SourceColours.keys.contains(source) ? source : null;

/// The source's name, which every chip shows.
String sourceChipLabel(String? key) => switch (key) {
  'whatsapp' => 'WhatsApp',
  'facebook' => 'Facebook',
  'instagram' => 'Instagram',
  'google_ads' => 'Google Ads',
  'thumbtack' => 'Thumbtack',
  'website' => 'Website',
  _ => 'Other',
};

/// A source's chip: its name, in its colour; an unknown key is neutral.
class LeadSourceChip extends StatelessWidget {
  const LeadSourceChip(this.sourceKey, {super.key, this.label});

  final String? sourceKey;

  /// In place of the key's own name, e.g. a finer word the row already has.
  final String? label;

  @override
  Widget build(BuildContext context) {
    final colours = SourceColours.of(context, sourceKey);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label ?? sourceChipLabel(sourceKey),
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          fontWeight: FontWeight.w800,
          color: colours.ink,
        ),
      ),
    );
  }
}

/// "Dana Reyes" → "DR"; a name with no letters (a phone number) → "#".
String leadInitials(String name) {
  final words = name
      .trim()
      .split(RegExp(r'\s+'))
      .where((w) => w.isNotEmpty && RegExp(r'[A-Za-z]').hasMatch(w[0]))
      .toList();
  if (words.isEmpty) return '#';

  return words.take(2).map((w) => w[0].toUpperCase()).join();
}

/// The profile picture, or the initials when there is none or it fails.
class LeadAvatar extends StatelessWidget {
  const LeadAvatar({super.key, required this.name, this.url, this.radius = 15});

  final String name;
  final String? url;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final colours = PersonColours.of(context, name);

    return CircleAvatar(
      radius: radius,
      backgroundColor: colours.fill,
      foregroundColor: colours.ink,
      foregroundImage: url == null ? null : NetworkImage(url!),
      onForegroundImageError: url == null ? null : (_, _) {},
      child: Text(
        leadInitials(name),
        style: TextStyle(fontSize: radius * 0.75, fontWeight: FontWeight.w800),
      ),
    );
  }
}
