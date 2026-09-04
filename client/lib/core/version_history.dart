/// What each client version shipped, newest first.
///
/// The version stamp at the foot of the sidebar opens this. Add a [VersionRelease]
/// in the **same commit** that bumps `client/VERSION` — that is the whole point
/// of the list. A number without a note is a date nobody can remember.
class VersionHistory {
  VersionHistory._();

  static const List<VersionRelease> releases = [
    VersionRelease(
      version: '0.0.0151',
      date: '4 Sep 2026',
      items: [
        '"Text me my codes" is on the account screen at a desk, not only on a '
            'phone. It was added to the phone layout alone, so the setting '
            'existed on a handset and did not exist on a computer - which is '
            'where most people would have gone looking for it. It now sits '
            'under Two-factor in both.',
        'Email: an inbox can now mark mail as read in the mail service itself, so '
            'a message you open here stops being bold in Gmail too. Off unless you '
            'turn it on, per inbox, on Email > Settings - because on an inbox your '
            'team shares it would hide mail from a colleague who has not read it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0150',
      date: '4 Sep 2026',
      items: [
        'A module no longer prints its own name twice. The page header added '
            'in 0.0.0137 says which module you are in, and fifteen screens '
            'were already saying it themselves - four the module name, eleven '
            'their own tab label - so Customers sat under Customers and '
            'Records under a highlighted Records tab. The sentence under each '
            'heading stays: it carries the count.',
        'The Mac build is now signed and packaged the way the Mac App Store '
            'requires, and every release delivers it to App Store Connect the '
            'same way the iPhone build reaches TestFlight. Nothing inside the '
            'app changed in this version.',
      ],
    ),
    VersionRelease(
      version: '0.0.0149',
      date: '4 Sep 2026',
      items: [
        'Marketing: a company can connect its own Mailchimp account and send '
            'a newsletter to its own list, under its own name. EzBizi does not '
            'send the mailing - Mailchimp does, on the company\'s reputation - '
            'so one business can never affect another\'s deliverability.',
        'An administrator can let a locked-out colleague back in. Somebody '
            'who loses the phone holding their authenticator, or forgets their '
            'password, no longer needs a developer: from their person record '
            'you can clear the second factor or issue a reset link, and the '
            'one thing you cannot do is either of those to yourself.',
        'Leads matches every other screen. It was the one tab strip still '
            'drawn Material\'s way - underlined, no icons, stretched full '
            'width - so beside Services or Communication it read as a '
            'different application.',
        'Communication - Settings has a consent page you can photograph. A US '
            'carrier will not carry a verification text unless the sender can '
            'show where the person agreed to receive it, and Twilio names the '
            'eight things that screenshot must show. This page is all eight.',
        'The data inventory says when a count is only your own. Suggestions '
            'are private to their author, so the organization-wide count '
            'cannot include everybody\'s - and a compliance export that '
            'quietly held a number back is a defect in its own right.',
        'Your account now has a "Text me my codes" setting, next to '
            'Two-factor. Give it your mobile number, tick the box, and EzBizi '
            'texts you a code to confirm the number is yours. You can turn it '
            'off at any time without a password, or by replying STOP to any '
            'message. It stays switched off, and says so, until EzBizi has its '
            'own number to send from - which is being registered with the '
            'carriers now.',
        'The number EzBizi texts you on is deliberately not one of your '
            'business lines. A business phone number is registered with the '
            'carriers for that business and its customers, and sending EzBizi '
            'sign-in codes from it is the kind of mismatch that gets the whole '
            'number blocked.',
      ],
    ),
    VersionRelease(
      version: '0.0.0148',
      date: '3 Sep 2026',
      items: [
        'Leads now has the same tabs as every other module. It was the one '
            'screen still using a full-width underlined strip, so it looked '
            'like a different application from the page next to it.',
        'Human Resources: an administrator can reset a colleague\'s '
            'authenticator when the phone holding it is lost. It is on each '
            'person\'s menu in Records. Their old authenticator stops working, '
            'they are signed out on every device, and they set up a new one '
            'themselves the next time they sign in - nobody, the administrator '
            'included, is ever shown it. Until now the only way back in was a '
            'developer working on the server.',
        'Human Resources: an administrator can hand somebody a password reset '
            'link. It is shown on screen to pass on directly rather than '
            'emailed, because a person locked out of their mailbox is exactly '
            'who needs one. Single use, and it expires in an hour. The '
            'administrator never chooses or sees the password.',
        'Both need their own permission - "security.reset" under Human '
            'Resources - rather than coming with editing people. Taking the '
            'second factor off a colleague is a bigger thing than changing '
            'their role, and it should be given on purpose. Administrators '
            'have it already.',
        'Phone numbers already in the system are now stored the way new ones '
            'are, so they read the same everywhere. Nineteen were written '
            'another way; nine were real numbers and are corrected. The other '
            'ten were never valid numbers to begin with and are left as they '
            'were rather than guessed at.',
      ],
    ),
    VersionRelease(
      version: '0.0.0147',
      date: '3 Sep 2026',
      items: [
        'Leads is its own module in the sidebar rather than a tab inside '
            'Marketing, and Web Management moves in the other direction to sit '
            'beside the other places a business says who it is. Where work '
            'comes from was the wrong thing to keep buried.',
        'Leads has a tab each for Google and Thumbtack. Google Ads enquiries '
            'arrive, are stored and are worked here as before. Thumbtack can '
            'be connected but does not deliver enquiries yet - what it does '
            'today is keep your associate phone numbers in step, which is what '
            'makes your caller ID right on their side.',
        'Leads, Reviews and Business profile: rows belonging to the whole '
            'organization were invisible to everyone below organization level. '
            'A connection set up for the organization rather than one company '
            'produced leads and reviews that nobody in a company or branch '
            'could see in the list, while opening one directly still worked.',
        'Reviews and Business profile: a sync that fails now says so instead '
            'of reporting no reviews. "Nothing came back" and "we could not '
            'ask" looked identical, and the reason was being sent somewhere '
            'the screen never showed it.',
        'Business profile: a business with more than a hundred locations now '
            'sees reviews for all of them. Only the first hundred were ever '
            'fetched, and the rest were silently absent.',
        'Business profile: a revoked Google permission says it was revoked, '
            'instead of telling you to wait for a quota approval that was '
            'never coming.',
        'Phone numbers read the same way everywhere - +1 (305) 724-2199 - on '
            'every screen and in every module, instead of the stored form. '
            'Typing ten digits into any phone field formats it as you go.',
      ],
    ),
    VersionRelease(
      version: '0.0.0146',
      date: '3 Sep 2026',
      items: [
        'The desktop app can take and make calls. It has been saying the phone '
            'desk was not connected since the phone arrived on Windows, and '
            'the fault was one line inside the app: the page that carries the '
            'call audio was listening for the app on the wrong channel, so the '
            'sign-in it was sent never reached it and it never started. The '
            'page loaded, reported itself loaded, and then sat there. Nothing '
            'was ever wrong with the network, the connection or Twilio - the '
            'message blaming your network was the app guessing, because it had '
            'been given no reason to report.',
        'Communication - Phone - Dial: the columns can be resized. Drag the '
            'line between any two of them. Recent calls used to take every '
            'pixel the keypad did not want, which on a wide screen was most of '
            'the window for a list of phone numbers; it now opens narrow, and '
            'where you drag it stays for as long as the app is open.',
      ],
    ),
    VersionRelease(
      version: '0.0.0145',
      date: '3 Sep 2026',
      items: [
        'Communication - Phone - Dial: the column beside the keypad is your '
            'recent calls now, not the list of colleagues. The number somebody '
            'reaches for while standing at a keypad is nearly always one they '
            'have just been speaking to - a customer who rang off, a missed '
            'call to return - and that was two tabs away in Records. Tapping a '
            'call puts the number in the field rather than dialling it, so you '
            'can read it back before it rings.',
        'Communication - Phone - Team is its own tab. It has not gone; it is '
            'where you go when the person is who you want, and moving between '
            'it and the keypad no longer takes the phone off the hook.',
        'Communication - Phone - Dial: the customer panel stays out of the way '
            'until there is somebody to show. It used to hold half the screen '
            'saying nothing. There is a pin if you would rather it stayed.',
        'Communication - Phone - Dial: the Calling as list has a search box. A '
            'dispatcher covering a business with thirty numbers was scrolling '
            'to find one, and a scrolling list is how you dial out under the '
            'wrong number.',
        'Communication - Phone - Voicemail is a tab, split into boxes. A flat '
            'list answers "did anybody ring" and not "did anybody ring me", '
            'which on a shared account is the question - so it is split by '
            'whose box it is and opens on yours. Each message shows its '
            'transcript under it rather than behind a button; the recording is '
            'still there to play, and still the record when the transcript is '
            'blank or wrong.',
        'The phone desk stops claiming to be disconnected once it has '
            'connected. It read the connection once, at startup, and never '
            'looked again - so a desk that came up a second late kept the '
            'warning until the screen was reloaded.',
      ],
    ),
    VersionRelease(
      version: '0.0.0144',
      date: '3 Sep 2026',
      items: [
        'Tasks has been removed. It is being rebuilt from scratch, and rather '
            'than leave a half-working version in the sidebar it is gone until '
            'the new one is ready. Boards, tasks and their updates are deleted '
            'with it; nothing is kept behind the scenes.',
        'Leads: Local Services enquiries load instead of failing. EzBizi was '
            'asking Google for something that is not a real part of its API, '
            'so Google answered with a plain "not found" page and the screen '
            'turned that into an error with no explanation. An account with no '
            'enquiries now says so, which is the honest answer rather than a '
            'symptom.',
        'Leads: being asked to slow down for a minute no longer reads as '
            'having no access at all. Those are different problems with '
            'different answers, and reporting the first as the second sent '
            'people to re-apply for access they already had.',
        'Whether you are taking calls now sits at the top of every screen, '
            'next to Clock In, as a handset with a green or red dot. It used '
            'to live on the dial pad, so the only way to stop your phone '
            'ringing was to go to the phone. Setting it covers every line you '
            'are on at once, and it will not interrupt a call you are already '
            'on.',
        'Communication: the Team tab is now called Users - it is where '
            'extensions and outbound lines are assigned to people.',
        'The desktop app can take calls again. It has been saying the phone '
            'desk was not connected on every launch, and the reason was inside '
            'the app rather than on your network: the part of the window that '
            'the microphone permission is handed to was not on screen yet when '
            'the phone asked for it, so the request went unanswered and Twilio '
            'refused the connection. The browser was never affected.',
      ],
    ),
    VersionRelease(
      version: '0.0.0143',
      date: '3 Sep 2026',
      items: [
        'Roles no longer ask you to tick phone lines one at a time. A role '
            'with Communication gets the company lines, and the list of '
            'seven tickboxes - which had stopped changing anything - is gone. '
            'A company with a hundred numbers would have had a hundred boxes '
            'per role.',
        'The desktop app says what actually went wrong when the phone will not '
            'connect, instead of blaming your network every time. Twilio was '
            'always telling us the reason and the app was dropping it, so an '
            'expired sign-in and a missing microphone both read as a firewall '
            'problem.',
        'An update no longer stops to ask whether to install for everyone or '
            'just you. It installs, for you, and comes back - which is what an '
            'automatic update was supposed to be.',
      ],
    ),
    VersionRelease(
      version: '0.0.0142',
      date: '3 Sep 2026',
      items: [
        'You can send a suggestion. Anything you think EzBizi should do '
            'differently goes in from inside the app, and your own tab keeps '
            'the ones you have sent with what came of each - waiting, being '
            'read, taken up, or turned down with the reason.',
        'A suggestion can be corrected or withdrawn while nobody has read it '
            'yet. Once somebody has started reading it, it stays as you sent '
            'it, because the person deciding should be answering what you '
            'actually wrote.',
        'Your suggestions are yours. Other people in your company cannot read '
            'them, and the count on the data inventory is your own rather than '
            'everybody\'s.',
      ],
    ),
    VersionRelease(
      version: '0.0.0141',
      date: '2 Sep 2026',
      items: [
        'Communication: a role that can open Communication now gets the '
            'company\'s phone lines. Each line used to be switched on for each '
            'role one at a time, so a new dispatcher saw an empty Dial screen '
            'and no reason for it - and a business with a hundred numbers '
            'would have had to tick a hundred boxes per role. Who can see '
            'which lines is still bounded by the company and branch a person '
            'is assigned to.',
        'Communication: an empty Dial screen says which of the two things is '
            'wrong. It used to say "Connect Twilio in Settings" whether the '
            'company had no phone system or you simply had no line - telling '
            'people to fix something they could not reach and that was not '
            'broken.',
        'Communication: you start on your own line. The Calling as picker '
            'opened on whichever line happened to be first, so calls went out '
            'under a shared number unless you noticed and changed it.',
        'The desktop app no longer says the phone desk is disconnected when '
            'it is not. It checked whether the desk had come up before the '
            'desk had finished answering, so the warning appeared on almost '
            'every launch and stayed until the page was reloaded.',
      ],
    ),
    VersionRelease(
      version: '0.0.0140',
      date: '2 Sep 2026',
      items: [
        'Communication - Settings - Numbers: the pane drew its own title one '
            'letter per line down the page, on top of the rest of the screen. '
            'It only did it for a business with more than one phone account, '
            'because the account picker is the only thing drawn in that spot '
            'and it was taking the whole width of the header. Every other '
            'settings pane is now protected from the same thing.',
        'Training: a test is marked, and says why. A learner sees a '
            'score, which answers were wrong, and how many attempts are '
            'left; a test whose guide has not been finished says that '
            'rather than refusing without a reason.',
      ],
    ),
    VersionRelease(
      version: '0.0.0139',
      date: '2 Sep 2026',
      items: [
        'Monitoring now shows one entry per person, led by the machine running '
            'the desktop app, and names devices plainly ("Browser", "Desktop '
            'app") instead of "web web" / "windows windows". You pick whose '
            'screen to view from a searchable list of people, not a drop-down, '
            'and only machines running the desktop app appear there.',
        'Monitoring - Desktop Monitoring: a "Go live" button shows a machine\'s '
            'screen updating every second or two, live, instead of only the '
            'latest five-minute screenshot. The live view is never recorded, '
            'and if the machine stops sending it says so rather than leaving a '
            'stale picture up.',
        'Monitoring - Desktop Monitoring: you can take control of a monitored '
            'machine - your mouse and keyboard, including Ctrl and Alt '
            'shortcuts, drive the remote screen while you watch it live. It '
            'needs a separate control permission (viewing does not grant it). '
            'An organisation can turn on a notice that shows on a machine while '
            'it is being controlled; it is off, and silent, by default.',
        'Monitoring - Camera Monitoring: HLS cameras now play inside the page '
            'on the web, not only as an open-in-your-player link. RTSP still '
            'opens in your own player.',
        'Monitoring: devices not seen for 30 days are cleaned up on their own, '
            'so old browser sessions and retired machines stop piling up.',
        'Automations: a rule with a condition on it now runs. Conditions were '
            'looking for the value one level deeper than the trigger actually '
            'sends it, so on twenty-three of the triggers no condition ever '
            'matched - the rule simply never fired, and nothing said why.',
        'Business profile, Leads and Reviews each say which Google account '
            'they are connected to, and let you disconnect it. The tab showed '
            'that something was connected without naming it, and offered no '
            'way to undo it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0138',
      date: '2 Sep 2026',
      items: [
        'Phone is now called Communication, and its first tab is Phone, '
            'holding Dial, Messages and Voicemail. Nothing moved out of reach '
            '- Messages and Voicemail are the Records views you already had, '
            'now reachable in one tap.',
        'Communication: you can choose which of the company lines you are '
            'calling from, on the Dial screen. It used to be fixed to whichever '
            'line was assigned to you, so covering a second business meant '
            'ringing customers back as the wrong company. Your own line is '
            'marked so switching away is deliberate.',
        'On iPhone and iPad, taking a call in the app now asks for the '
            'microphone the first time, and says what it is for. Apple requires '
            'an app to explain itself before it may listen, and EzBizi did not '
            'carry that sentence - so the App Store refused the build outright, '
            'before anybody could install it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0137',
      date: '2 Sep 2026',
      items: [
        'Monitoring - PC Specs now leads with a person\'s active device, adds '
            'a switcher when more than one is active, and folds older sessions '
            'away behind a toggle. Before, somebody who had signed in from '
            'several browsers filled the list with stale rows that all looked '
            'the same.',
        'Monitoring - a desktop machine is now recognised as the same device '
            'each time the app starts, instead of registering as a brand-new '
            'device on every launch and piling up duplicates.',
        'Monitoring - Desktop Monitoring: with the right permission, a manager '
            'can see periodic screenshots of a monitored Windows machine\'s '
            'screen. Images are kept for 30 days and then deleted on their own. '
            'Seeing them is a separate permission from seeing specs, so an '
            'organisation can grant one without the other.',
        'Monitoring - Camera Monitoring: connect an IP camera by its stream '
            'address - HLS, MJPEG, snapshot or RTSP - and watch it inside '
            'EzBizi. MJPEG and snapshot cameras play in the page; HLS and RTSP '
            'open in your own video player for now.',
        'Scheduling: you can add a service area. Settings listed your areas '
            'and gave you no way to make one, so a business that had never '
            'added any saw two empty boxes and nothing to click - and since '
            'the hours grid and the zip codes both live inside an area, none '
            'of it could be reached at all.',
        'Scheduling: a service area takes its zip codes, and can be renamed '
            'or deleted. A zip already covered by another area is refused and '
            'says which area has it.',
        'On a phone, a panel no longer opens underneath the clock and the '
            'battery. Anything that slides in from the right - the version '
            'list, a customer, a record - was drawing from the very top of the '
            'screen, which put its close button under the status bar where it '
            'cannot be tapped. There is no other way out of a panel on a '
            'phone, so the app had to be force-quit to escape one. All '
            'thirty-four of them are fixed together.',
      ],
    ),
    VersionRelease(
      version: '0.0.0136',
      date: '2 Sep 2026',
      items: [
        'Adding a photo or a file works in the desktop app. The button was '
            'there and pressing it did nothing at all - there was no file '
            'dialog outside a browser, so nothing opened and nothing said so.',
        'Email: signing in to a mail account from the desktop app opens your '
            'own browser to do it, rather than having nowhere to go.',
      ],
    ),
    VersionRelease(
      version: '0.0.0135',
      date: '2 Sep 2026',
      items: [
        'The desktop app keeps you signed in. Ticking "keep me signed in" had '
            'never done anything outside a browser - the desktop app held your '
            'session in memory only, so closing it, or updating it, signed you '
            'out. The session is now kept in the Windows credential store, '
            'encrypted to your Windows account, and only when you tick the '
            'box. Leave it unticked and it still ends when you close the app.',
        'Android now needs Android 8.0 or newer. Taking calls on the phone '
            'uses Twilio, which does not support anything older, so this is '
            'the floor for having calling at all rather than a preference.',
      ],
    ),
    VersionRelease(
      version: '0.0.0134',
      date: '2 Sep 2026',
      items: [
        'Phone: calls work in the app on every platform that can carry audio, '
            'not only in a browser tab.',
      ],
    ),
    VersionRelease(
      version: '0.0.0133',
      date: '1 Sep 2026',
      items: [
        'Phone: you can take and make calls inside the app on Windows, '
            'Android, iPhone and Mac, not only in a browser. On a phone an '
            'incoming call rings like a phone call rather than a notification. '
            'Incoming calls on phones need push credentials set up in Twilio '
            'first; until then calls go out but do not come in.',
        'Monitoring: a computer running the desktop app now reports what it '
            'is - memory, free disk, Windows version, and which wifi network '
            'it is on with how strong the signal is. Until now Monitoring '
            'could say a machine was active and nothing about it, so "it is '
            'slow" had no numbers next to it.',
        'That only happens on the desktop app, and only on Windows so far. '
            'Anywhere else the machine keeps reporting that it is active, '
            'exactly as before, and simply sends no specs.',
      ],
    ),
    VersionRelease(
      version: '0.0.0132',
      date: '1 Sep 2026',
      items: [
        'The desktop app updates itself without showing you an installer. It '
            'used to put a setup window on screen while replacing itself, '
            'which read as installing software rather than the app restarting.',
        'The desktop app comes back on its own after updating. It was closing '
            'itself so its files could be replaced and then staying closed - '
            'the step that reopens it was being skipped on exactly the silent '
            'installs the updater uses. If an update ever fails you will still '
            'be told; only the success case is quiet.',
        'The desktop app checks for an update when it starts and installs it '
            'there and then, instead of waiting a minute first. That wait is '
            'still there for an update found while you are working, where '
            'closing the app under you is not the same thing at all.',
      ],
    ),
    VersionRelease(
      version: '0.0.0131',
      date: '1 Sep 2026',
      items: [
        'This is the release that actually delivered everything listed under '
            '0.0.0130. That version reached the web and then failed before any '
            'app was published, so no desktop or phone ever ran it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0130',
      date: '1 Sep 2026',
      items: [
        'Phone: you can call from the desktop app, Android and iOS now. Those '
            'builds cannot carry the audio themselves - the part that does is '
            'a browser component - so EzBizi rings your own phone and joins '
            'you to the person you are calling. They still see the company '
            'number, never your mobile. Add your number to your profile first.',
        'Every module now says its own name at the top of the page. Only one '
            'of the sixteen did - the rest left you to work out where you were '
            'from the sidebar, and on a phone the sidebar is behind a button, '
            'so nothing on screen said it at all.',
        'Headings across the app are one set of sizes now. Three of the six '
            'heading styles had never been given a size or a weight, so a '
            'hundred and nine places asking for a heading were quietly drawing '
            'ordinary text. That is why titles looked unrelated from one '
            'screen to the next.',
        'The tab you are on no longer covers its own icon with a grey '
            'circle. The circle followed the selected tab rather than the '
            'mouse, so it looked like a hover effect stuck on the wrong tab. '
            'It was the tick that gets drawn on top of the icon, which the '
            'coloured background had already made unnecessary.',
        'Social: Facebook, Threads and Instagram can post. Until now a post '
            'could be written and scheduled for those three and nothing left '
            'EzBizi. Instagram needs the picture handed over in a separate '
            'step, which is why it took longer than the other two.',
        'Social: when connecting a Google account fails, the message says '
            'which of four things went wrong rather than giving one wording '
            'for all of them - the API not switched on, no quota granted '
            'yet, a developer token not approved for live accounts, or '
            'EzBizi asking for a version of the API Google has retired. The '
            'last one is why Google Ads had been failing.',
      ],
    ),
    VersionRelease(
      version: '0.0.0129',
      date: '1 Sep 2026',
      items: [
        'Automations: a rule can now send a text. Pick which of the company '
            'lines it goes out from, who it goes to, and what it says - and it '
            'works on any trigger, including ones with nothing to do with the '
            'phone, such as an email arriving.',
        'Phone: calling out works. Numbers typed the ordinary way - 305 724 '
            '2199 - were losing their country code and being dialled as '
            '+3057242199, which is not a phone number, so no outgoing call '
            'has ever connected. The same applied to text messages.',
        'Phone: the Call tab is now called Dial, and you ring a colleague from '
            'their own row in the Team list rather than from an Internal '
            'button on the keypad that did nothing until you had picked them '
            'somewhere else.',
        'Phone: a call that fails now says why, on the Records list, instead '
            'of only saying it failed. Twilio always told us the reason and '
            'the app was discarding it, which is why outbound calling took '
            'four rounds to diagnose.',
        'Phone: the call desk gives each column a fair share of the window. '
            'The two panes holding the most - the team list and the customer '
            'thread - were fixed at their smallest, and every extra pixel went '
            'to the dial pad, which is a fixed size anyway. On a wide screen '
            'the middle column was more than half the window around a keypad. '
            'A note in 0.0.0126 said this had been done; it had not been, and '
            'this is it.',
        'The sidebar has a box to find a module by name. Sixteen of them, and '
            'the way to reach one was to read all sixteen - on a short screen, '
            'after scrolling. The letters that matched are marked, so it is '
            'clear why a module is still in the list. Collapsed there is no '
            'box: at that width there are no names to search.',
        'Your account is laid out again. The things an administrator assigns '
            'you - your email, your role, and the places you can act on - sat '
            'inside the form directly above its Save button, which suggested '
            'Save applied to them. It never did. They are beside the form now, '
            'or their own row on a phone.',
        'Your account on a phone is a list of rows rather than one long form, '
            'and each row shows what it currently says - so checking your own '
            'number no longer means opening anything.',
        'Two places with the same name now say which company each belongs to. '
            'A list that reads Connecticut, Georgia, Connecticut looks like '
            'the same place twice; they are two different ones, and it is the '
            'answer to where you are allowed to work.',
      ],
    ),
    VersionRelease(
      version: '0.0.0128',
      date: '1 Sep 2026',
      items: [
        'Phone: calling a customer no longer drops you into your own '
            'voicemail. The first call went through; a second one to the same '
            'person was treated as a call arriving at your line rather than '
            'leaving it, so it was answered by your greeting instead of rung '
            'out to them.',
        'Phone: a call placed from a number the company does not own now says '
            'so, instead of connecting to silence. The call appeared to be in '
            'progress with nobody on either end - the number it claimed to be '
            'calling from was rejected, and nothing said that out loud.',
      ],
    ),
    VersionRelease(
      version: '0.0.0127',
      date: '31 Aug 2026',
      items: [
        'Phone: the desktop app now says that calls are made in the browser, '
            'instead of reporting that the network is blocking the connection. '
            'Calls have never been possible outside a browser - the part that '
            'places them only exists there - so the old message sent people to '
            'check a firewall for something that could not work.',
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
        'The sidebar scrolls. On a shorter screen the module list used to run '
            'off the bottom and draw over Settings and Development, and the '
            'modules underneath could not be reached at all - there was '
            'nothing to scroll with. Settings and Development stay pinned at '
            'the foot where they always were.',
        'The brand cyan was too pale to read. As text on white it sat at about '
            'half the contrast needed to be legible, and white text on a cyan '
            'button was the same - which mattered most where a filled colour '
            'carries words, like a title on a coloured bar. Buttons, links '
            'and the box outline you get when you click into a field are all a '
            'deeper shade now. Dark mode is unchanged.',
        'Four modules were drawing a generic chart icon on the dashboard '
            'because nobody had chosen one for them: Reviews, Leads, '
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
