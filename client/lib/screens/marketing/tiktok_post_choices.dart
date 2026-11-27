import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../widgets/search_picker.dart';

/// What a TikTok account allows, read from TikTok just before posting.
class TikTokCreator {
  const TikTokCreator({
    required this.nickname,
    required this.privacyOptions,
    this.commentDisabled = false,
    this.duetDisabled = false,
    this.stitchDisabled = false,
    this.maxSeconds,
  });

  final String nickname;
  final List<String> privacyOptions;
  final bool commentDisabled;
  final bool duetDisabled;
  final bool stitchDisabled;
  final int? maxSeconds;

  factory TikTokCreator.fromJson(Map<String, dynamic> json) => TikTokCreator(
    nickname: json['nickname'] as String? ?? '',
    privacyOptions: [
      for (final o in (json['privacy_level_options'] as List<dynamic>? ?? const []))
        if (o is String) o,
    ],
    commentDisabled: json['comment_disabled'] as bool? ?? false,
    duetDisabled: json['duet_disabled'] as bool? ?? false,
    stitchDisabled: json['stitch_disabled'] as bool? ?? false,
    maxSeconds: (json['max_video_post_duration_sec'] as num?)?.toInt(),
  );
}

/// One TikTok account's answers for one post.
///
/// TikTok's Direct Post rules: privacy has NO default, comment / duet /
/// stitch start off, disclosure is asked every time. Nothing here is
/// pre-filled on the person's behalf.
class TikTokChoice {
  TikTokChoice({this.mode = 'direct'});

  /// `direct` posts to the profile; `inbox` sends a draft to the account's
  /// TikTok inbox for the creator to finish in the app.
  String mode;
  bool get toInbox => mode == 'inbox';

  TikTokCreator? creator;
  String? loadError;
  String? privacy;
  bool comment = false;
  bool duet = false;
  bool stitch = false;
  bool disclose = false;
  bool yourBrand = false;
  bool branded = false;

  /// Why this account stops Publish, or null.
  String? refusal(String account, Duration? length) {
    // A draft asks nothing: the creator chooses in the TikTok app.
    if (toInbox) return null;
    final c = creator;
    if (loadError != null) return '$account: $loadError';
    if (c == null) return 'Asking TikTok what $account allows.';
    final max = c.maxSeconds;
    if (max != null && length != null && length.inSeconds > max) {
      return 'TikTok lets $account post videos up to $max seconds; this one is '
          '${length.inSeconds}.';
    }
    if (privacy == null) return 'Choose who can see the post on $account.';
    if (disclose && !yourBrand && !branded) {
      return 'You need to indicate if your content promotes yourself, a third '
          'party, or both.';
    }
    if (branded && privacy == 'SELF_ONLY') {
      return 'Branded content visibility cannot be set to private.';
    }
    return null;
  }

  /// The same choices for a JSON body, as `tiktok: {<id>: {...}}`.
  Map<String, Object> json() => toInbox ? {'mode': mode} : {
    'mode': mode,
    'privacy_level': privacy ?? '',
    'allow_comment': comment,
    'allow_duet': duet,
    'allow_stitch': stitch,
    'disclose': disclose,
    'your_brand': yourBrand,
    'branded_content': branded,
  };

  /// The multipart fields for `tiktok[<id>]`.
  Map<String, String> fields(String id) => toInbox ? {'tiktok[$id][mode]': mode} : {
    'tiktok[$id][mode]': mode,
    'tiktok[$id][privacy_level]': privacy ?? '',
    'tiktok[$id][allow_comment]': comment ? '1' : '0',
    'tiktok[$id][allow_duet]': duet ? '1' : '0',
    'tiktok[$id][allow_stitch]': stitch ? '1' : '0',
    'tiktok[$id][disclose]': disclose ? '1' : '0',
    'tiktok[$id][your_brand]': yourBrand ? '1' : '0',
    'tiktok[$id][branded_content]': branded ? '1' : '0',
  };
}

const _privacyLabels = {
  'PUBLIC_TO_EVERYONE': 'Everyone',
  'MUTUAL_FOLLOW_FRIENDS': 'Friends',
  'FOLLOWER_OF_CREATOR': 'Followers',
  'SELF_ONLY': 'Only me',
};

const _musicUrl =
    'https://www.tiktok.com/legal/page/global/music-usage-confirmation/en';
const _brandedUrl = 'https://www.tiktok.com/legal/page/global/bc-policy/en';

/// The TikTok settings for one ticked account, drawn in the composer.
class TikTokPostChoices extends StatelessWidget {
  const TikTokPostChoices({
    super.key,
    required this.account,
    required this.choice,
    required this.enabled,
    required this.onChanged,
  });

  final String account;
  final TikTokChoice choice;
  final bool enabled;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final creator = choice.creator;

    void set(VoidCallback change) {
      change();
      onChanged();
    }

    // Profile or inbox, chosen per post, drawn above everything else.
    final where = SegmentedButton<String>(
      key: ValueKey('tiktok-mode-$account'),
      segments: const [
        ButtonSegment(value: 'direct', label: Text('Post to profile')),
        ButtonSegment(
          value: 'inbox',
          label: Text('Send to TikTok inbox as a draft'),
        ),
      ],
      selected: {choice.mode},
      showSelectedIcon: false,
      onSelectionChanged:
          enabled ? (v) => set(() => choice.mode = v.first) : null,
    );

    if (choice.toInbox) {
      return Column(
        key: ValueKey('tiktok-choices-$account'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('TikTok: $account', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          where,
          const SizedBox(height: 8),
          Text(
            "TikTok sends it to the account's inbox; finish posting in the "
            'TikTok app.',
            key: ValueKey('tiktok-inbox-note-$account'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      );
    }

    if (creator == null) {
      return Column(
        key: ValueKey('tiktok-choices-$account'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          where,
          const SizedBox(height: 8),
          Text(
            choice.loadError ?? 'Asking TikTok what $account allows…',
            style: theme.textTheme.bodySmall,
          ),
        ],
      );
    }

    Widget tick(String label, bool value, bool off, void Function(bool) to) =>
        CheckboxListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          title: Text(label),
          value: value && !off,
          onChanged: !enabled || off ? null : (v) => set(() => to(v ?? false)),
        );

    final small = theme.textTheme.bodySmall;

    return Column(
      key: ValueKey('tiktok-choices-$account'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Posting to TikTok as ${creator.nickname}',
          style: theme.textTheme.titleSmall,
        ),
        const SizedBox(height: 8),
        where,
        const SizedBox(height: 8),
        SearchPickerField<String>(
          key: ValueKey('tiktok-privacy-$account'),
          value: choice.privacy,
          label: 'Who can see this video',
          options: [
            for (final o in creator.privacyOptions)
              SearchOption<String>(
                value: o,
                label: _privacyLabels[o] ?? o,
                // Branded content cannot be private (TikTok's rule).
                enabled: !(choice.branded && o == 'SELF_ONLY'),
              ),
          ],
          onChanged: enabled ? (v) => set(() => choice.privacy = v) : null,
        ),
        const SizedBox(height: 8),
        Text('Allow users to', style: small),
        tick('Comment', choice.comment, creator.commentDisabled,
            (v) => choice.comment = v),
        tick('Duet', choice.duet, creator.duetDisabled, (v) => choice.duet = v),
        tick('Stitch', choice.stitch, creator.stitchDisabled,
            (v) => choice.stitch = v),
        SwitchListTile(
          key: ValueKey('tiktok-disclose-$account'),
          dense: true,
          contentPadding: EdgeInsets.zero,
          title: const Text('Disclose video content'),
          subtitle: const Text(
            'Turn on to disclose that this video promotes goods or services '
            'in exchange for something of value.',
          ),
          value: choice.disclose,
          onChanged: enabled
              ? (v) => set(() {
                  choice.disclose = v;
                  if (!v) choice.yourBrand = choice.branded = false;
                })
              : null,
        ),
        if (choice.disclose) ...[
          tick('Your brand — you are promoting yourself or your own business',
              choice.yourBrand, false, (v) => choice.yourBrand = v),
          tick('Branded content — you are promoting another brand or a third party',
              choice.branded, false, (v) => choice.branded = v),
          if (choice.branded)
            Text("Your video will be labeled as 'Paid partnership'.", style: small)
          else if (choice.yourBrand)
            Text("Your video will be labeled as 'Promotional content'.",
                style: small),
        ],
        const SizedBox(height: 8),
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text('By posting, you agree to TikTok\'s ', style: small),
            if (choice.branded) ...[
              _link('Branded Content Policy', _brandedUrl, small),
              Text(' and ', style: small),
            ],
            _link('Music Usage Confirmation', _musicUrl, small),
            Text('.', style: small),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'After you publish, TikTok can take a few minutes to process the '
          'video before it appears on the profile.',
          style: small,
        ),
      ],
    );
  }

  Widget _link(String label, String url, TextStyle? style) => InkWell(
    onTap: () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
    child: Text(
      label,
      style: style?.copyWith(decoration: TextDecoration.underline),
    ),
  );
}
