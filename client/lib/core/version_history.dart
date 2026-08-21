/// What each client version shipped, newest first.
///
/// The version stamp at the foot of the sidebar opens this. Add a [VersionRelease]
/// in the **same commit** that bumps `client/VERSION` — that is the whole point
/// of the list. A number without a note is a date nobody can remember.
class VersionHistory {
  VersionHistory._();

  static const List<VersionRelease> releases = [
    VersionRelease(
      version: '0.0.0050',
      date: '21 Aug 2026',
      items: [
        'The Email reading pane is a white page, like Gmail, not the '
            'app\'s grey chrome around the message.',
      ],
    ),
    VersionRelease(
      version: '0.0.0049',
      date: '21 Aug 2026',
      items: [
        'Gmail conversations stay one row in the inbox, with a count, '
            'instead of repeating every message in the thread.',
      ],
    ),
    VersionRelease(
      version: '0.0.0048',
      date: '21 Aug 2026',
      items: [
        'HTML mail is shown the way Gmail shows it — tables, sized '
            'pictures, the layout the sender designed — inside a sandbox. '
            'Scripts still cannot run.',
      ],
    ),
    VersionRelease(
      version: '0.0.0047',
      date: '21 Aug 2026',
      items: [
        'Pictures in Email draw at the size the message asked for, not '
            'the file\'s pixel size, so a logo no longer fills the pane.',
      ],
    ),
    VersionRelease(
      version: '0.0.0046',
      date: '21 Aug 2026',
      items: [
        'Pictures in Email are shown. They are fetched through EzBizi, '
            'not from the sender\'s address.',
      ],
    ),
    VersionRelease(
      version: '0.0.0045',
      date: '21 Aug 2026',
      items: [
        'Email Inbox shows the mail that is actually in the connected '
            'address, and a reply names the inbox it will go out as.',
      ],
    ),
    VersionRelease(
      version: '0.0.0044',
      date: '21 Aug 2026',
      items: [
        'Sidebar icons stay after a deploy. A tab that was already open '
            'picks up the current set on the next load — no need for a '
            'private window.',
      ],
    ),
    VersionRelease(
      version: '0.0.0043',
      date: '21 Aug 2026',
      items: [
        'The version number at the bottom of the sidebar opens this list — '
            'what shipped in each build, newest first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0042',
      date: '20 Aug 2026',
      items: [
        'Adding a Workspace inbox is a search, not a dropdown that overflows '
            'on a real directory.',
        'Google\'s fake aliases (user@domain.test-google-a.com) are hidden.',
      ],
    ),
    VersionRelease(
      version: '0.0.0041',
      date: '20 Aug 2026',
      items: [
        'An inbox added on one company stays on that company. The organization '
            'view still lists every company\'s. Sibling companies do not see it '
            'on Settings or Inbox.',
      ],
    ),
    VersionRelease(
      version: '0.0.0040',
      date: '20 Aug 2026',
      items: [
        'Google Workspace is connected for the company you are looking at, not '
            'the whole group.',
        'Pending Admin setup is a numbered to-do list with a link into Google '
            'Admin, and copyable client ID and scopes.',
      ],
    ),
    VersionRelease(
      version: '0.0.0039',
      date: '20 Aug 2026',
      items: [
        'Web Management, Phone and Fleet Management keep their sidebar icons '
            'after a refresh.',
      ],
    ),
    VersionRelease(
      version: '0.0.0038',
      date: '20 Aug 2026',
      items: [
        'Fleet Management appears in the sidebar as coming soon.',
      ],
    ),
    VersionRelease(
      version: '0.0.0037',
      date: '20 Aug 2026',
      items: [
        'Social Media Management under Marketing.',
        'Web Management and Phone tabs, marked coming soon.',
      ],
    ),
    VersionRelease(
      version: '0.0.0036',
      date: '20 Aug 2026',
      items: [
        'Keep me signed in is a 30-day session, not only a tick the browser '
            'remembered while the server still signed you out overnight.',
      ],
    ),
    VersionRelease(
      version: '0.0.0035',
      date: '19 Aug 2026',
      items: [
        'Administrator stays at the top of Roles. The Built in tag is gone.',
      ],
    ),
    VersionRelease(
      version: '0.0.0034',
      date: '19 Aug 2026',
      items: [
        'Role grants follow the sidebar — real tabs, not a list of modules '
            'that are not destinations.',
      ],
    ),
    VersionRelease(
      version: '0.0.0033',
      date: '19 Aug 2026',
      items: [
        'Inspection Builder can delete a draft that was never published.',
      ],
    ),
    VersionRelease(
      version: '0.0.0032',
      date: '19 Aug 2026',
      items: [
        'Customers and jobs can be opened, edited and deleted from their lists.',
      ],
    ),
    VersionRelease(
      version: '0.0.0031',
      date: '17 Aug 2026',
      items: [
        'Dashboard charts flow and zoom.',
        'Google Workspace can be connected.',
        'A stale client is told to update.',
      ],
    ),
    VersionRelease(
      version: '0.0.0030',
      date: '17 Aug 2026',
      items: [
        'One chart for the whole business, with each franchise on its own line.',
      ],
    ),
    VersionRelease(
      version: '0.0.0029',
      date: '14 Aug 2026',
      items: [
        'The organization structure view: the business as boxes you arrange '
            'yourself.',
      ],
    ),
    VersionRelease(
      version: '0.0.0028',
      date: '13 Aug 2026',
      items: [
        'Automations can fire when a shared inbox receives mail.',
      ],
    ),
    VersionRelease(
      version: '0.0.0027',
      date: '13 Aug 2026',
      items: [
        'Google sign-in for a shared inbox, and IMAP over EzBizi\'s own socket.',
      ],
    ),
    VersionRelease(
      version: '0.0.0026',
      date: '13 Aug 2026',
      items: [
        'Email settings, including an honest form when a mail protocol cannot '
            'run on this installation.',
      ],
    ),
    VersionRelease(
      version: '0.0.0025',
      date: '13 Aug 2026',
      items: [
        'HR contracts: a cover, no version line on the page, and a common core '
            'that tells the truth.',
      ],
    ),
    VersionRelease(
      version: '0.0.0024',
      date: '13 Aug 2026',
      items: [
        'The chip palette on contracts no longer cuts itself in half.',
      ],
    ),
    VersionRelease(
      version: '0.0.0023',
      date: '13 Aug 2026',
      items: [
        'The Email tab reaches a real API route, with a test that says so.',
      ],
    ),
    VersionRelease(
      version: '0.0.0022',
      date: '13 Aug 2026',
      items: [
        'An expired session signs you out instead of leaving you clicking on a '
            'shell that looks live.',
      ],
    ),
    VersionRelease(
      version: '0.0.0021',
      date: '12 Aug 2026',
      items: [
        'Email: shared inboxes, read live from the provider, never stored.',
      ],
    ),
    VersionRelease(
      version: '0.0.0020',
      date: '12 Aug 2026',
      items: [
        'Sending identity that cascades down the business.',
        'Marketing declared as a module.',
      ],
    ),
    VersionRelease(
      version: '0.0.0019',
      date: '12 Aug 2026',
      items: [
        'Bulk export is its own permission.',
        'The structure canvas pen can drag again.',
      ],
    ),
    VersionRelease(
      version: '0.0.0018',
      date: '12 Aug 2026',
      items: [
        'Fixes from a review of findings that were true — and tests that were not.',
      ],
    ),
    VersionRelease(
      version: '0.0.0017',
      date: '12 Aug 2026',
      items: [
        'The contract writing surface is a real surface, not a squeezed panel.',
      ],
    ),
    VersionRelease(
      version: '0.0.0016',
      date: '11 Aug 2026',
      items: [
        'Contracts take half of the HR tab. Round-two review findings closed.',
      ],
    ),
    VersionRelease(
      version: '0.0.0015',
      date: '11 Aug 2026',
      items: [
        'A contract can be written in the app.',
      ],
    ),
    VersionRelease(
      version: '0.0.0014',
      date: '11 Aug 2026',
      items: [
        'Two critical holes the rules audit found, both closed.',
      ],
    ),
    VersionRelease(
      version: '0.0.0013',
      date: '11 Aug 2026',
      items: [
        'HR onboarding, with the three ways it could have been abused closed.',
      ],
    ),
    VersionRelease(
      version: '0.0.0012',
      date: '11 Aug 2026',
      items: [
        'Contracts, attachments, and sensitive values — holes closed.',
      ],
    ),
    VersionRelease(
      version: '0.0.0011',
      date: '11 Aug 2026',
      items: [
        'Human Resources: invite a second person into an organization.',
      ],
    ),
    VersionRelease(
      version: '0.0.0010',
      date: '10 Aug 2026',
      items: [
        'Roles and tab permissions.',
        'The test sandbox cannot reach production.',
      ],
    ),
    VersionRelease(
      version: '0.0.0009',
      date: '10 Aug 2026',
      items: [
        'Automations publish what a rule may set.',
        'STATUS numbers are generated instead of remembered.',
      ],
    ),
    VersionRelease(
      version: '0.0.0008',
      date: '10 Aug 2026',
      items: [
        'Workflows are Automations, and creating one has a screen.',
      ],
    ),
    VersionRelease(
      version: '0.0.0007',
      date: '10 Aug 2026',
      items: [
        'Settings that actually save, and honest copy for the ones that do not.',
      ],
    ),
    VersionRelease(
      version: '0.0.0006',
      date: '10 Aug 2026',
      items: [
        'The app no longer reloads itself for every change.',
      ],
    ),
    VersionRelease(
      version: '0.0.0005',
      date: '7 Aug 2026',
      items: [
        'Signup, with the four things that would have made it useless or dangerous closed.',
      ],
    ),
    VersionRelease(
      version: '0.0.0004',
      date: '7 Aug 2026',
      items: [
        'The tenant boundary is closed, and the box no longer fails quietly.',
      ],
    ),
    VersionRelease(
      version: '0.0.0003',
      date: '7 Aug 2026',
      items: [
        'Audit findings cut, and one finding the audit itself had refuted dropped.',
      ],
    ),
    VersionRelease(
      version: '0.0.0002',
      date: '7 Aug 2026',
      items: [
        'Settings you can actually set, and companies you can edit.',
      ],
    ),
    VersionRelease(
      version: '0.0.0001',
      date: '7 Aug 2026',
      items: [
        'The build is stamped with a version and a commit id, so a stale cache '
            'is visible rather than guessed at.',
      ],
    ),
  ];
}

class VersionRelease {
  const VersionRelease({
    required this.version,
    required this.date,
    required this.items,
  });

  final String version;
  final String date;
  final List<String> items;
}
