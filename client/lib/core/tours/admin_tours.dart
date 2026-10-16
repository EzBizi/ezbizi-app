import '../tab_tours.dart';

/// Settings, Suggestions, Development and Your account (Jose, 2026-10-16:
/// "you didn't do the walkthrough and video for Settings - Suggestions -
/// Development - Your Account"). Each Settings tab has its own, keyed as
/// `tourKeyFor` reads them.
const Map<String, Tour> adminTours = {
  'settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-settings-tabs',
        title: 'Settings',
        body:
            'Organization, Settings, Integrations, Roles & permissions and '
            'API activity, as your role allows.',
      ),
    ],
  ),
  'settings/Organization': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-settings-tabs',
        title: 'Organization',
        body: 'Your companies and their branches, in one place.',
      ),
      TourStep(
        anchor: 'tour-org-views',
        title: 'List or Structure',
        body:
            'List shows every company and branch. Structure shows which sits '
            'under which.',
      ),
      TourStep(
        anchor: 'tour-org-add',
        title: 'Add company',
        body: 'Add a company. Open one to add its branches.',
      ),
    ],
  ),
  'settings/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'settings-hub-index',
        title: 'Every setting',
        body:
            "All settings is the whole business's. Under it, each module's "
            'own settings, the same as in that module.',
      ),
      TourStep(
        anchor: 'settings-hub-jump',
        title: 'Jump to',
        body:
            "All settings is the whole business's. The chips open each "
            "module's own settings.",
      ),
    ],
  ),
  'settings/Integrations': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-integrations-list',
        title: 'Integrations',
        body:
            'Connect the services EzBizi works with. Open one to connect, '
            'rename or disconnect an account.',
      ),
    ],
  ),
  'settings/Roles & permissions': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-roles-new',
        title: 'Roles',
        body:
            'A role decides which tabs its people see and what they may do. '
            'Make a new one here.',
      ),
      TourStep(
        anchor: 'role-invitable',
        title: 'Who can invite',
        body: 'Which roles can be given to somebody you invite.',
      ),
    ],
  ),
  'settings/API activity': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'api-activity-all',
        title: 'API activity',
        body:
            'Every change made with an API key. Click one to see what it changed.',
      ),
      TourStep(
        anchor: 'api-activity-person',
        title: 'Narrow it',
        body: 'Show one person, one key or only changes.',
      ),
    ],
  ),
  'suggestions': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-suggestions-new',
        title: 'Write a suggestion',
        body: 'Tell the people who build EzBizi what it should do differently.',
      ),
      TourStep(
        anchor: 'suggestion-tab',
        title: 'Which tab',
        body: 'Say which tab it is about, so it reaches the right people.',
      ),
      TourStep(
        anchor: 'tour-suggestions-write',
        title: 'Your suggestion',
        body:
            'In your own words. Only you and EzBizi staff can read it, not your '
            'administrator.',
      ),
      TourStep(
        anchor: 'tour-suggestions-send',
        title: 'Send',
        body: 'It is sent to EzBizi, and the answer comes back here.',
      ),
      TourStep(
        anchor: 'suggestions-rail',
        title: 'Your suggestions',
        body: 'Everything you sent, and what EzBizi said.',
      ),
    ],
  ),
  'development': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-development-tabs',
        title: 'Development',
        body:
            'For EzBizi staff: organizations, modules, app versions, client '
            'access, health and suggestions.',
      ),
    ],
  ),
  'account': Tour(
    version: 1,
    steps: [
      // Desktop.
      TourStep(
        anchor: 'tour-account-identity',
        title: 'Who you are',
        body: 'Your name, photo, phone and address.',
      ),
      TourStep(
        anchor: 'tour-account-facts',
        title: 'Role and access',
        body: 'Your role and email, set by your business.',
      ),
      TourStep(
        anchor: 'tour-account-appearance',
        title: 'Appearance',
        body: 'Light or dark, and how the sidebar looks.',
      ),
      TourStep(
        anchor: 'tour-account-signing-in',
        title: 'Signing in',
        body: 'Your password, two-factor and codes by text.',
      ),
      TourStep(
        anchor: 'tour-account-schedule',
        title: 'Work schedule',
        body: 'The hours you work.',
      ),
      TourStep(
        anchor: 'tour-account-sessions',
        title: 'Where you are signed in',
        body: 'Each device signed in as you, and Sign out everywhere else.',
      ),
      TourStep(
        anchor: 'tour-account-api-keys',
        title: 'API keys',
        body:
            'Keys that let another app work as you. Revoke one you no '
            'longer use.',
      ),
      // Phone: the same parts, as groups of rows.
      TourStep(
        anchor: 'tour-account-group-You',
        title: 'You',
        body: 'Your name, phone and address. Tap a row to change it.',
      ),
      TourStep(
        anchor: 'tour-account-group-Signing in',
        title: 'Signing in',
        body: 'Your password, two-factor and codes by text.',
      ),
      TourStep(
        anchor: 'tour-account-group-Appearance',
        title: 'Appearance',
        body: 'Light or dark, and how the sidebar looks.',
      ),
      TourStep(
        anchor: 'tour-account-group-Signed in',
        title: 'Signed in',
        body: 'Where you are signed in, and your API keys.',
      ),
    ],
  ),
};
