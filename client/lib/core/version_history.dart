/// What each client version shipped, newest first.
///
/// The version stamp at the foot of the sidebar opens this. Add a [VersionRelease]
/// in the **same commit** that bumps `client/VERSION` — that is the whole point
/// of the list. A number without a note is a date nobody can remember.
class VersionHistory {
  VersionHistory._();

  static const List<VersionRelease> releases = [
    VersionRelease(
      version: '0.0.0127',
      date: '31 Aug 2026',
      items: [
        'Social: a post can be sent later. Pick the day and time, and it goes '
            'out on your own clock - the one for the company or branch you are '
            'looking at, not the server clock.',
        'A picture or video you attach is shown before you send it, so you can '
            'check it is the right one. Video plays in the composer.',
        'A post sent to TikTok lands in the drafts of that TikTok account, '
            'the profile - open TikTok to finish and post it. TikTok has not '
            'audited the app yet. This replaces what the 0.0.0113 note said.',
      ],
    ),
    VersionRelease(
      version: '0.0.0126',
      date: '31 Aug 2026',
      items: [
        'Phone: a new Team tab shows each person, their extension, and the '
            'number they call out from. Numbers were being bought with nobody '
            'assigned to them, so every outbound call went out under the same '
            'line - four people ringing four customers all showed one number, '
            'and every callback landed on whoever picked up.',
        'Phone: the call desk now gives each column a fair share of the window '
            'rather than fixing the two busiest at their smallest useful size '
            'and giving every extra pixel to the dial pad.',
        'The sidebar scrolls. On a shorter screen the module list used to run '
            'off the bottom and draw over Settings and Development, and the '
            'modules underneath could not be reached at all - there was '
            'nothing to scroll with. Settings and Development stay pinned at '
            'the foot where they always were.',
        'The brand cyan was too pale to read. As text on white it sat at about '
            'half the contrast needed to be legible, and white text on a cyan '
            'button was the same - which mattered most where a filled colour '
            'carries words, like a task title on a Gantt bar. Buttons, links '
            'and the box outline you get when you click into a field are all a '
            'deeper shade now. Dark mode is unchanged.',
        'Five modules were drawing a generic chart icon on the dashboard '
            'because nobody had chosen one for them: Tasks, Reviews, Leads, '
            'Business profile and Monitoring. They have their own now.',
      ],
    ),
    VersionRelease(
      version: '0.0.0125',
      date: '31 Aug 2026',
      items: [
        'Phone: a ring group now shows who would actually be rung, not how many '
            'people are in it. A group of four where everyone is on Do not '
            'disturb rings nobody, and it used to look identical to a working '
            'one.',
        'Phone: a menu that no caller ever hears is now shown as skipped. A menu '
            'can be built, named and attached to a line and still be stepped '
            'past on every call, and nothing said so - the caller does not know '
            'they missed it and you would never get a complaint about it.',
        'Phone: each number lists its full routing, so you can see where a call '
            'goes rather than inferring it.',
        'Notifications: the bell now refreshes on its own and raises desktop and '
            'phone notifications, instead of only checking once when you signed '
            'in.',
        'Notifications: turning notifications off now turns all of them off. The '
            'setting was only being honoured in one of the four places that '
            'send, so an organization that switched it off kept receiving most '
            'of them.',
      ],
    ),
    VersionRelease(
      version: '0.0.0124',
      date: '31 Aug 2026',
      items: [
        'Scheduling: set how many jobs you can take, per time slot, per day, '
            'for each service area. Most businesses run the same hours '
            'everywhere, so Settings shows your pattern first and lists the '
            'areas that differ from it, rather than making you set the same '
            'thing thirty times.',
        'A service area holds its own zip codes, and a zip belongs to exactly '
            'one of them - so searching a zip has one answer rather than two. '
            'A zip another area already covers is refused, and says which area '
            'has it.',
        'Closing a day removes it rather than setting it to zero, because "we '
            'do not work Sundays" and "Sunday is full" are different things '
            'and should not look the same.',
        'Availability: search a zip code or an area and see that week. The '
            'numbers are what you are set up to take - booked jobs are not '
            'counted yet, and the screen says so rather than implying the '
            'week is empty.',
      ],
    ),
    VersionRelease(
      version: '0.0.0123',
      date: '31 Aug 2026',
      items: [
        'Web Management: "Get the plugin" now fetches the current version from '
            'wordpress.org. It used to hand you whatever copy this server was '
            'built with, which could be older than the one the page was '
            'describing.',
      ],
    ),
    VersionRelease(
      version: '0.0.0122',
      date: '31 Aug 2026',
      items: [
        'Web Management: your websites are a list down the left and the '
            'details of the one you picked fill the right, instead of cards '
            'you open one at a time.',
        'Web Management: every field shows its shortcode as a button that '
            'copies it, rather than as text you had to select by hand.',
        'Web Management: hours of operation has a day-by-day editor with a '
            'clock, and still lets you type your own wording if your hours do '
            'not fit a grid.',
      ],
    ),
    VersionRelease(
      version: '0.0.0121',
      date: '31 Aug 2026',
      items: [
        'Scheduling has a place in the sidebar. It holds Availability, Schedule '
            'and Settings, and all three are empty for now - the tab is here so '
            'that who may see it can be decided before there is anything in it '
            'to see.',
      ],
    ),
    VersionRelease(
      version: '0.0.0120',
      date: '31 Aug 2026',
      items: [
        'You can delete your own account from the account screen. It erases '
            'your name, email and personal details and signs you out on every '
            'device, and it asks for your password and the word DELETE first - '
            'being signed in is not enough for something that cannot be undone.',
        'The work stays with the organization. Jobs, customers and records you '
            'touched keep their history; what was yours is removed from them.',
        'The only administrator of an organization cannot delete their account, '
            'because that would leave the business with nobody able to run it. '
            'The screen says so, and says to make somebody else an '
            'administrator first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0119',
      date: '31 Aug 2026',
      items: [
        'Email: a mailbox connected by password now opens its other folders '
            'too. EzBizi asks your mail server what it has rather than '
            'guessing, so Sent is found whether your server calls it Sent, '
            'Sent Items or something in your own language - and a folder your '
            'server does not have is simply not offered.',
      ],
    ),
    VersionRelease(
      version: '0.0.0118',
      date: '31 Aug 2026',
      items: [
        'Email: attach files to a message you are starting. Up to five, two '
            'megabytes together — and if they come to more than that, it says '
            'so before you have waited for them to upload rather than after.',
        'Email: pick a folder and get that folder. Outlook inboxes now open '
            'Sent, Drafts, Trash, Spam and Archive; a mail server connected by '
            'password still opens only the Inbox, and says so plainly instead '
            'of handing back the Inbox under another name.',
      ],
    ),
    VersionRelease(
      version: '0.0.0117',
      date: '28 Aug 2026',
      items: [
        'Web Management: you can issue a new browser key without touching the '
            'plugin token. A page still carrying the old key keeps whatever '
            'text is already written into it rather than going blank.',
      ],
    ),
    VersionRelease(
      version: '0.0.0116',
      date: '28 Aug 2026',
      items: [
        'Phone: point a line at a menu. Menus could be built, named and given '
            'destinations, and there was no way to attach one to a number — '
            'every caller went straight past it.',
        'Phone: the Cost screen no longer spins forever on a company with no '
            'usage collected yet.',
        'Web Management: your two keys are shown side by side and named for '
            'what each is for — the secret one belongs on your server, the '
            'public one goes in your page source on purpose.',
      ],
    ),
    VersionRelease(
      version: '0.0.0115',
      date: '28 Aug 2026',
      items: [
        'Email can start a conversation, not only answer one. Write to '
            'somebody from a connected inbox.',
        'Phone: search the numbers a country actually sells and buy one — by '
            'area code, city, or a pattern the number contains.',
        'Each line carries its own emergency address, caller-name lookup and '
            'voicemail transcription. A line with nobody to ring now says so.',
        'Fleet keeps the fields A-1 Chimney was keeping elsewhere: plate '
            'state, registration and inspection dates, toll device and plan, '
            'the registered owner, and who is listed to drive.',
        'Leads and Reviews no longer offer a Connect button to somebody who '
            'is not allowed to connect anything.',
      ],
    ),
    VersionRelease(
      version: '0.0.0114',
      date: '28 Aug 2026',
      items: [
        'Web Management: keep your hours, phone numbers and addresses in '
            'EzBizi, and let the WordPress plugin render them on your site.',
        'Change them once here and every page that shows them follows.',
      ],
    ),
    VersionRelease(
      version: '0.0.0113',
      date: '28 Aug 2026',
      items: [
        'Social: write a post, choose the accounts, and send it. Publishing '
            'was a console command; it is a button now.',
        'A post sent to TikTok lands in the drafts of that TikTok account - '
            'TikTok to finish and post it. (Corrected 31 Aug 2026: this note '
            'used to say the post landed on your profile privately. It did '
            'not, and somebody checking their profile would have concluded '
            'publishing was broken.)',
        'Inspections: take photographs while you walk the job, and download the '
            'finished report as a PDF.',
        'A customer found by search says which company owns the franchise — on '
            'the duplicate check now as well as the list.',
      ],
    ),
    VersionRelease(
      version: '0.0.0112',
      date: '28 Aug 2026',
      items: [
        'Reports: how many calls, how many missed, how long, and broken down '
            'by day, hour, line, who answered, or what it was about.',
        'Save a report and it keeps the question rather than the dates, so it '
            'answers itself again next month.',
        'The phone account picker said "company" three times. It names the '
            'place now, and only shows the one you are looking at.',
      ],
    ),
    VersionRelease(
      version: '0.0.0111',
      date: '28 Aug 2026',
      items: [
        'Tasks: make boards for the organization, company, or franchise you are '
            'looking at, then group, assign, follow, and finish the work.',
        'The dense desktop table becomes touch-friendly cards and full task '
            'details on a phone. My Tasks, saved views, filters, and search keep '
            'large boards manageable.',
        'Updates, files, linked EzBizi records, notifications, automations, and '
            'safe spreadsheet import and export stay with the task.',
      ],
    ),
    VersionRelease(
      version: '0.0.0110',
      date: '27 Aug 2026',
      items: [
        'Tags: say what a call was about when you put the phone down, so calls '
            'can be counted by what they were.',
        'Settings keeps the list. A tag that has been used is retired rather '
            'than deleted, so last month still adds up.',
      ],
    ),
    VersionRelease(
      version: '0.0.0109',
      date: '27 Aug 2026',
      items: [
        'Call menus: write what a caller hears and say what each key does — '
            'ring a group, take a message, forward, or say something and stop.',
        'Pressing a key now does what the key says. It used to play the menu '
            'and then ring the line whatever was pressed.',
        'Opening hours, per line, on the clock where the phone actually is.',
        'Trust says where each carrier registration has got to, and what can '
            'be done about it — usually nothing, which it now says.',
      ],
    ),
    VersionRelease(
      version: '0.0.0108',
      date: '27 Aug 2026',
      items: [
        'Numbers: the lines a phone account already holds can be brought into '
            'EzBizi, which points them here so their calls arrive.',
        'A line says who it rings, its hours, whether it records, and what '
            'happens when it is closed.',
        'Looking at one company no longer lists the others on Phone settings.',
      ],
    ),
    VersionRelease(
      version: '0.0.0107',
      date: '27 Aug 2026',
      items: [
        'Switching a company on no longer picks up a phone account that was '
            'closed. It kept its name, so it looked like the right one and '
            'every attempt failed with a bare Server Error.',
      ],
    ),
    VersionRelease(
      version: '0.0.0106',
      date: '27 Aug 2026',
      items: [
        'Switching a company on no longer leaves a half-made phone account '
            'behind when it fails, and trying again takes over the one that '
            'exists instead of making a second.',
      ],
    ),
    VersionRelease(
      version: '0.0.0105',
      date: '27 Aug 2026',
      items: [
        'Phone service is EzBizi’s to switch on. Connecting your own '
            'Twilio has been removed — it was never a service EzBizi '
            'offered, and it was also a way past the approval.',
      ],
    ),
    VersionRelease(
      version: '0.0.0104',
      date: '27 Aug 2026',
      items: [
        'Phone service is asked for and granted. The Phone tab explains the '
            'service and takes a request naming the companies that need it, '
            'how busy the lines will be, and whether texting is needed. EzBizi '
            'answers each company, and only an approved company can be '
            'switched on.',
        'Ring groups: name who a line rings, all at once or one at a time, and '
            'where unanswered calls go. Until now a call reached voicemail '
            'however the account was set up.',
        'Roles can grant phone lines, the way they already grant inboxes.',
        'The dial pad no longer runs off the bottom of a short screen.',
      ],
    ),
    VersionRelease(
      version: '0.0.0103',
      date: '26 Aug 2026',
      items: [
        'The Windows app keeps itself up to date. It looks for a newer build '
            'when it starts and once an hour after that, and installs what it '
            'finds — after a minute of warning you can put off, so an update '
            'never arrives in the middle of something you were typing.',
        'The version number at the foot of the sidebar, and on the sign-in '
            'screen, has a button beside it: check now, or install the build '
            'that is waiting.',
        'The installer no longer fails when EzBizi is already open. It closes '
            'the app, replaces it, and starts it again.',
        'The installer looks like software now — its own icon, artwork that '
            'stays sharp on any screen, and a proper welcome page.',
        'The app icon lost the dark edges around its corners.',
      ],
    ),
    VersionRelease(
      version: '0.0.0102',
      date: '26 Aug 2026',
      items: [
        'Closing the Windows app puts it in the notification area instead of '
            'quitting it, the way Teams and Slack do. Click the icon to bring '
            'it back, or right-click it to quit properly. Signing out of '
            'Windows, and the updater, still close it for real.',
        'There is a download page at ezbizi.com/download, which always shows '
            'the version it is actually offering.',
      ],
    ),
    VersionRelease(
      version: '0.0.0101',
      date: '26 Aug 2026',
      items: [
        'The Windows app carries the EzBizi icon. It was still Flutter\'s — in '
            'the taskbar, in Alt-Tab, and on the installer itself.',
        'The installer offers to start EzBizi when you sign in to Windows. It '
            'is a checkbox, it shows up in Task Manager\'s Startup tab like '
            'anything else, and turning it off survives later updates.',
      ],
    ),
    VersionRelease(
      version: '0.0.0100',
      date: '26 Aug 2026',
      items: [
        'On a phone, the sidebar is a drawer. It was a bar across the bottom '
            'holding all fifteen destinations, which left twenty-four pixels '
            'each and broke every label mid-word. The drawer shows them at '
            'full width, grouped the way the sidebar groups them.',
        'The app no longer draws underneath the status bar or the gesture bar '
            'on Android 15.',
        'The Windows app updates itself. It checks for a newer build on '
            'launch, offers it in a strip you can dismiss, and on Update '
            'downloads the installer and restarts into it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0099',
      date: '26 Aug 2026',
      items: [
        'Nothing changed in the app. This build exists so Google Play has a '
            'bundle made after the store artwork and the new icon, and so its '
            '"upload your bundle again" notice clears. Said plainly rather '
            'than dressed up: a version whose note invents a feature is worse '
            'than one that admits it is a rebuild.',
      ],
    ),
    VersionRelease(
      version: '0.0.0098',
      date: '26 Aug 2026',
      items: [
        'EzBizi installs on Windows and on Android. One release script builds '
            'the website, the Windows installer and the Play app bundle from '
            'the same commit, so all three report the same version.',
        'A social account can be disconnected, says Connected once it is, and '
            'offers Reconnect. The sign-in window is EzBizi\'s own, and shows '
            'the network it is joining.',
        'TikTok and Pinterest can be connected for real.',
      ],
    ),
    VersionRelease(
      version: '0.0.0097',
      date: '25 Aug 2026',
      items: [
        'Date fields are month, day and year, with a calendar next to them. '
            'The organization date format still decides which box is first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0096',
      date: '25 Aug 2026',
      items: [
        'Matching the device theme uses a half-light, half-dark circle, not '
            'the automatic-brightness A.',
      ],
    ),
    VersionRelease(
      version: '0.0.0095',
      date: '25 Aug 2026',
      items: [
        'Clock In and Clock Out in the top bar are an outlined chip with an '
            'arrow, not a solid fill.',
      ],
    ),
    VersionRelease(
      version: '0.0.0094',
      date: '25 Aug 2026',
      items: [
        'Clock in: a top-bar button when the organization turns it on, and a '
            'Human Resources day list.',
      ],
    ),
    VersionRelease(
      version: '0.0.0093',
      date: '25 Aug 2026',
      items: [
        'Services: a catalogue of what the company sells, with square photos, '
            'prices, labels and groups.',
      ],
    ),
    VersionRelease(
      version: '0.0.0092',
      date: '25 Aug 2026',
      items: [
        'Email Settings scrolls when Google Workspace cards are taller than '
            'the page.',
      ],
    ),
    VersionRelease(
      version: '0.0.0091',
      date: '25 Aug 2026',
      items: [
        'Sales orders on Inventory: draft, open, fulfill from a location. '
            'The buyer is a Customer, or a note for a counter sale.',
      ],
    ),
    VersionRelease(
      version: '0.0.0090',
      date: '25 Aug 2026',
      items: [
        'In a company view the organization Twilio is visible but cannot be '
            'connected or changed. Switch to the group to edit it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0089',
      date: '25 Aug 2026',
      items: [
        'A company view cannot connect or change the organization Twilio. '
            'That is only offered when looking at the group.',
      ],
    ),
    VersionRelease(
      version: '0.0.0088',
      date: '25 Aug 2026',
      items: [
        'A company view cannot connect or change the organization Twilio. '
            'That is only offered when looking at the group.',
      ],
    ),
    VersionRelease(
      version: '0.0.0087',
      date: '25 Aug 2026',
      items: [
        'Phone Settings only offers Twilio for the company you are looking at, '
            'not sibling companies.',
      ],
    ),
    VersionRelease(
      version: '0.0.0086',
      date: '25 Aug 2026',
      items: [
        'Phone: calls, texts, numbers, Twilio at organization or company.',
      ],
    ),
    VersionRelease(
      version: '0.0.0085',
      date: '25 Aug 2026',
      items: [
        'The dashboard boxes now say stock health and orders, vehicles due for '
            'service or insurance, the customer book, jobs today, people '
            'active or not, and inspections by name.',
      ],
    ),
    VersionRelease(
      version: '0.0.0084',
      date: '24 Aug 2026',
      items: [
        'Inventory can buy, issue and transfer from the screen: a new purchase '
            'order, a new vendor, an issue, and a transfer between locations.',
      ],
    ),
    VersionRelease(
      version: '0.0.0083',
      date: '24 Aug 2026',
      items: [
        'The dashboard is one box per module you can open, with a chart, '
            'one week, month, quarter or year for the page, and no panel picker. '
            'On Jobs you pick which statuses count as earned.',
      ],
    ),
    VersionRelease(
      version: '0.0.0082',
      date: '24 Aug 2026',
      items: [
        'Looking at one company files work on that company. Sibling companies '
            'are no longer offered on New location, New item, or New vehicle.',
      ],
    ),
    VersionRelease(
      version: '0.0.0081',
      date: '24 Aug 2026',
      items: [
        'Inventory is a register of stock, items, orders and locations, '
            'not a coming-soon tab. Costs stay hidden without financials.',
      ],
    ),
    VersionRelease(
      version: '0.0.0080',
      date: '24 Aug 2026',
      items: [
        'Settings is one place: the shape of the business, how the work '
            'behaves, and who may do it. Organization is a tab inside it, not '
            'a neighbour on the sidebar.',
      ],
    ),
    VersionRelease(
      version: '0.0.0079',
      date: '24 Aug 2026',
      items: [
        'Sign out after inactivity is minutes, hours or days. Job statuses have '
            'names people can read, and renaming one updates jobs that already '
            'have it. The business address is street, city, state and ZIP, with '
            'a search that fills them in.',
      ],
    ),
    VersionRelease(
      version: '0.0.0078',
      date: '24 Aug 2026',
      items: [
        'Default timezone is hours from UTC (UTC-5, UTC, UTC+3), not a list '
            'of cities. Every Settings selector uses the same outlined menu.',
      ],
    ),
    VersionRelease(
      version: '0.0.0077',
      date: '24 Aug 2026',
      items: [
        'Settings that are a choice from a known list — timezone, language, '
            'paper size, fiscal year — are selectors, not boxes to type into.',
      ],
    ),
    VersionRelease(
      version: '0.0.0076',
      date: '24 Aug 2026',
      items: [
        'Opening Email loads the inbox as one wait, not one wait per '
            'conversation.',
      ],
    ),
    VersionRelease(
      version: '0.0.0075',
      date: '24 Aug 2026',
      items: [
        'An onboarding that was started by mistake can be cancelled. The row '
            'stays, and you can start a new one for them.',
      ],
    ),
    VersionRelease(
      version: '0.0.0074',
      date: '24 Aug 2026',
      items: [
        'One contract per role: hourly or salary is chosen on the onboarding '
            'form, and the unused pay questions stay off the page. The contract '
            'builder can say when a question is asked.',
      ],
    ),
    VersionRelease(
      version: '0.0.0073',
      date: '24 Aug 2026',
      items: [
        'Inventory and Services are sidebar tabs, marked coming soon, '
            'the same way Web Management is.',
      ],
    ),
    VersionRelease(
      version: '0.0.0071',
      date: '24 Aug 2026',
      items: [
        'Opening Development asks for your authenticator code each time, '
            'instead of a control-plane session that expires and tells you to '
            'sign in again.',
      ],
    ),
    VersionRelease(
      version: '0.0.0070',
      date: '24 Aug 2026',
      items: [
        'Social catalogue uses each network\'s real brand mark, '
            'not a drawn stand-in.',
      ],
    ),
    VersionRelease(
      version: '0.0.0069',
      date: '24 Aug 2026',
      items: [
        'Fleet vehicles can belong to the organization, not only a company. '
            'Untagged vans are General. The Company label is fully visible '
            'when adding a vehicle. Owned or rented has to be chosen before '
            'the rest of how we hold it appears.',
      ],
    ),
    VersionRelease(
      version: '0.0.0068',
      date: '24 Aug 2026',
      items: [
        'Social catalogue rows show each network\'s mark, and Facebook and '
            'Instagram are named that way — not Page or professional.',
      ],
    ),
    VersionRelease(
      version: '0.0.0067',
      date: '21 Aug 2026',
      items: [
        'Contracts can keep or drop a clause from the answers, draw headings '
            'and tables, and name home and business addresses. Checklists of '
            'tools merge as a table on the signed page.',
      ],
    ),
    VersionRelease(
      version: '0.0.0066',
      date: '21 Aug 2026',
      items: [
        'Fleet is a register of the company\'s vehicles: owned and rented '
            'periods, insurance, service, and who has the keys. Payments stay '
            'hidden without financials.',
      ],
    ),
    VersionRelease(
      version: '0.0.0065',
      date: '21 Aug 2026',
      items: [
        'Social Media Monthly Calendar uses the full pane width, '
            'the same as Weekly Calendar, and still fits without scrolling.',
      ],
    ),
    VersionRelease(
      version: '0.0.0064',
      date: '21 Aug 2026',
      items: [
        'Reloading the app keeps you where you were. Marketing stays '
            'Marketing, Settings stays Settings — the address bar holds '
            'the place, so a refresh is not a trip back to Dashboard.',
      ],
    ),
    VersionRelease(
      version: '0.0.0063',
      date: '21 Aug 2026',
      items: [
        'Connect Workspace can install EzBizi from Google in one Allow, '
            'instead of pasting scopes, once the Marketplace listing is live.',
      ],
    ),
    VersionRelease(
      version: '0.0.0062',
      date: '21 Aug 2026',
      items: [
        'Social Media Monthly Calendar squares shrink to fit the pane, '
            'so the whole month is on screen without scrolling.',
      ],
    ),
    VersionRelease(
      version: '0.0.0061',
      date: '21 Aug 2026',
      items: [
        'Social Media Posts is Weekly Calendar and Monthly Calendar. '
            'The week stays on this week. Month days are square, with '
            'arrows to move months.',
      ],
    ),
    VersionRelease(
      version: '0.0.0060',
      date: '21 Aug 2026',
      items: [
        'Email settings shows Workspace health: which Google Admin '
            'scopes are granted, and which still need adding — Trash '
            'is gmail.modify.',
      ],
    ),
    VersionRelease(
      version: '0.0.0059',
      date: '21 Aug 2026',
      items: [
        'The Social Media calendars have wider day boxes, and arrows to '
            'move to the previous or next week and month.',
      ],
    ),
    VersionRelease(
      version: '0.0.0058',
      date: '21 Aug 2026',
      items: [
        'Social Media Accounts can connect Pinterest, Facebook Pages, '
            'Instagram, Threads, LinkedIn, TikTok, YouTube and X the same '
            'way Email signs in. Bluesky uses a handle and an app password. '
            'A network that is not set up on this installation says why, '
            'instead of failing.',
      ],
    ),
    VersionRelease(
      version: '0.0.0057',
      date: '21 Aug 2026',
      items: [
        'Sender icons load the brand logo — Apple is the apple, not a '
            'letter A. Verified brands get a blue check, like Gmail.',
      ],
    ),
    VersionRelease(
      version: '0.0.0056',
      date: '21 Aug 2026',
      items: [
        'All inboxes combines every mailbox you can open into one list. '
            'Opening a mail still names that inbox, so a reply cannot go '
            'out as the wrong address.',
      ],
    ),
    VersionRelease(
      version: '0.0.0055',
      date: '21 Aug 2026',
      items: [
        'The open email shows the sender icon, same as the list. '
            'Simple mail is padded off the edge of the page. '
            'Attachments can be downloaded — streamed live, not stored.',
      ],
    ),
    VersionRelease(
      version: '0.0.0054',
      date: '21 Aug 2026',
      items: [
        'Social Media Posts shows the whole month under this week, '
            'so a scheduled post is visible on both the week strip and '
            'the month grid.',
      ],
    ),
    VersionRelease(
      version: '0.0.0053',
      date: '21 Aug 2026',
      items: [
        'Social Media Management is a Posts tab: pages on the left, '
            'compose in the middle, a week calendar on the right. '
            'Accounts is where a page will be connected. Publish stays '
            'off until this view has a page.',
      ],
    ),
    VersionRelease(
      version: '0.0.0052',
      date: '21 Aug 2026',
      items: [
        'Tick several emails at once to delete, mark read or unread. '
            'Sender icons show on the list — a letter, or the logo when '
            'the domain has one. Delete now bins the thread in Gmail, '
            'once Google Admin allows gmail.modify.',
      ],
    ),
    VersionRelease(
      version: '0.0.0051',
      date: '21 Aug 2026',
      items: [
        'Email matches Gmail\'s two views: the list fills the pane, '
            'opening a mail replaces it, and a search bar plus labels '
            'sit on the list. Back, delete and read/unread are on the '
            'reading toolbar.',
      ],
    ),
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
      items: ['Fleet Management appears in the sidebar as coming soon.'],
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
      items: ['Automations can fire when a shared inbox receives mail.'],
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
      items: ['The chip palette on contracts no longer cuts itself in half.'],
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
      items: ['A contract can be written in the app.'],
    ),
    VersionRelease(
      version: '0.0.0014',
      date: '11 Aug 2026',
      items: ['Two critical holes the rules audit found, both closed.'],
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
      items: ['Contracts, attachments, and sensitive values — holes closed.'],
    ),
    VersionRelease(
      version: '0.0.0011',
      date: '11 Aug 2026',
      items: ['Human Resources: invite a second person into an organization.'],
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
      items: ['Workflows are Automations, and creating one has a screen.'],
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
      items: ['The app no longer reloads itself for every change.'],
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
      items: ['Settings you can actually set, and companies you can edit.'],
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
