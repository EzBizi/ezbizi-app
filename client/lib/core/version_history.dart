/// What each client version shipped, newest first.
///
/// The version stamp at the foot of the sidebar opens this. Add a [VersionRelease]
/// in the **same commit** that bumps `client/VERSION` — that is the whole point
/// of the list. A number without a note is a date nobody can remember.
///
/// **The deploy desk writes this file. Send your note with your release request
/// instead of adding it here.** The top entry is often already pinned to a
/// build that does not carry your commit: on 2026-09-30 three notes were
/// written into entries that had been cut, and each described a feature the
/// release did not ship until the desk moved it to the next version.
class VersionHistory {
  VersionHistory._();

  static const List<VersionRelease> releases = [
    VersionRelease(
      version: '0.0.0428',
      date: '14 Oct 2026',
      items: [
        'Reports: Scoring opens on yesterday, has Today / Yesterday / 1 week / 1 month buttons, and shows each person\'s latest calls plus their best and worst, with scores and a search.',
        'Sales: a new Sales tab tracks jobs that are done but pending approval, with stage, urgency, next follow-up and notes for each; Administrators see it, and other roles get it in Roles.',
        'Leads: reconnecting Facebook or Instagram brings back that account\'s earlier enquiries.',
        'WhatsApp: the staff bot\'s menus and answers start sentences with a capital and use topic emojis instead of dots.',
        'Leads: website forms nobody has called back turn red like unanswered texts; new status Follow Up.',
        'Field: job addresses are now located two weeks ahead, so maps and routes have exact pins sooner.',
        'Integrations: every account type has its card, including Facebook Ads, so each can be connected from there.',
        'Facebook: connecting your Facebook also brings your ad accounts, so ad spend shows in Reports.',
        'HR: the onboarding list shows, in amber, how many required questions are still missing and on whose side; inside the form, each empty required question says Not filled in.',
        'Financials: a new Financials tab, in place of Airtable, holds bases of tables with typed fields (currency, selects, rating, formula and more), saved views as a grid, kanban, calendar, gallery or form, CSV import and export, and starter templates; Administrators see it, and other roles get it in Roles.',
      ],
    ),
    VersionRelease(
      version: '0.0.0427',
      date: '13 Oct 2026',
      items: [
        'Work Hours: a clock-out and clock-in less than 5 minutes apart are one shift, including after an edit.',
        'Task Board: New board at the foot of the Board picker; a board\'s owner, or an Administrator, adds people as Owner, Member or Viewer and sets it Open or Private; ⚡ Automate on every board saves easy task rules (assign, notify, alert, set status, move to group) as real Automations rules for that board.',
        'Automations: a rule can move a task to another group on its board, and a board\'s owner can set up its Automate rules without the Automations permission.',
        'Scheduling: Re-Scheduling now warns "Make sure to confirm with the client before rescheduling", and Move asks "Do you wish to proceed with the change?" before anything changes in Workiz.',
        'Scheduling: Re-Scheduling only suggests moves worth the call: the new slot is next to the nearby job\'s window, at most a week earlier or later, with the nearest job within 10 miles, and nothing booked in the next 48 hours is moved.',
      ],
    ),
    VersionRelease(
      version: '0.0.0426',
      date: '13 Oct 2026',
      items: [
        'AI Assistance: a Staff bot tab gives each group of roles its own menu on the staff WhatsApp line, with each topic linked to its Training guide section or Library entry, so editing the guide changes what the bot says. The Daily conversion topic answers with today\'s new-client calls, answered, booked and the conversion. A topic\'s guide pictures follow its text.',
        'Reports: type a phone number in Calls to find every call with it, in or out.',
        'WhatsApp: staff with Reports access can ask the staff line for the calls with a number and get the list plus each recording.',
        'WhatsApp: the staff line answers questions the Library cannot from what the asker can see in EzBizi, read only.',
      ],
    ),
    VersionRelease(
      version: '0.0.0425',
      date: '13 Oct 2026',
      items: [
        'Leads: texts sent to a lead, and the customer\'s text replies, now show in the lead\'s conversation.',
        'Automations: a pop-up alert step says which of the people it names it skipped, and why.',
        'Automations: a rule can text the customer onto the enquiry, so their answer is seen there.',
        'Automations: a WhatsApp to the customer of an enquiry lands on the enquiry, so their answer does too.',
      ],
    ),
    VersionRelease(
      version: '0.0.0424',
      date: '13 Oct 2026',
      items: [
        'Walkthroughs: every main tab now has a (?) beside its title that walks you through each part of the tab.',
        'Fleet: in a narrow vehicle list on a computer, the search and filters scroll with the vehicles instead of filling the pane.',
        'Reports: call reasons show Grok\'s reading by default.',
        'Reports: calls AI says were booked but have no Workiz job get their own list in Calls, and a daily alert you can route in Automations.',
        'WhatsApp: AI answers and the bot\'s own messages are easier to read, with titles, emojis and one fact per line.',
        'Scheduling: a Re-Scheduling tab lists booked jobs that would sit better beside other work, with a confirmed Move. On the Availability map, hover or tap a dot for the job, and zoom with + −. The suggestion radius is in Scheduling › Settings › Suggestions.',
        'Reports: Scoring shows each dispatcher as a card ranked by AI\'s call score, with AI\'s daily and weekly analysis; for Administrators and Lead Managers.',
        'Reports: on a phone, Marketing, Meta and Thumbtack no longer go blank when the dates are opened.',
        'Reports: every tab now reads the same way: one date bar, the key figures, and one table with a Total row; on a phone, one menu for the tabs and each row as a card. Fixed: saved reports showing cents, 24-hour call times, Google Ads money.',
        'WhatsApp: when a customer replies, the message appears in their lead in Leads.',
        'Automations: a notification to several people picked by name now reaches each of them.',
        'Automations: a task made by a rule goes on the place\'s board and is given to the person.',
        'Leads: after hours, auto replies can carry a booking link that books onto the same lead; an approved online booking can send the customer a confirmation with the number to call; the Online Booking card shows the best-fit window for the dispatcher.',
        'AI Assistance: answers in the app and on the WhatsApp staff line also draw on your role\'s training guide, with its video links.',
        'Reports: a caller who already has a job shows Existing job or Rescheduled instead of Not booked; the nightly Same-Day WhatsApp report is easier to read.',
        'Automations: a rule can send a Communication pop-up alert; an auto reply\'s "Book online now" line is left out when the branch has no booking page.',
      ],
    ),
    VersionRelease(
      version: '0.0.0423',
      date: '13 Oct 2026',
      items: [
        'Scheduling: suggested availability looks for booked work within 30 miles instead of 5; you can still change this in Settings › Scheduling. Jobs Workiz could not place on the map no longer show as dots in Africa.',
      ],
    ),
    VersionRelease(
      version: '0.0.0422',
      date: '13 Oct 2026',
      items: [
        'Leads: AI marks a lead Booked when a day was agreed on the call, and shows why.',
        'Leads: Google leads that look like spam or a service we don\'t offer show "Credit?", and Request credit asks Google for a refund.',
        'WhatsApp: staff with Reports access can ask the staff line for figures, for example "how much did MA sell last week".',
        'Leads: Google LS calls no longer count as unread, since there is nothing to reply to.',
      ],
    ),
    VersionRelease(
      version: '0.0.0421',
      date: '13 Oct 2026',
      items: [
        'Reports: calls can be tagged Cancel, Confirmation or Service question, and new calls are split by speaker for coaching.',
        'AI: only calls and leads from Oct 13 onward are read, summarized and scored.',
        'Email: the Inbox tab lists only the inboxes a person may open.',
        'Monitoring › Files: the authenticator code now works for people who sign in through an EzBizi staff account.',
      ],
    ),
    VersionRelease(
      version: '0.0.0420',
      date: '13 Oct 2026',
      items: [
        'Scheduling › Availability: a zip search shows the earliest opening beside a suggested one near work already booked, with a small map of the route. The radius is in Settings › Scheduling.',
        'Leads: a lead with a note shows a note mark in the list; hover it to read the note.',
        'Leads: Awaiting reply is now solid red.',
        'Leads: Google bookings and calls keep the customer’s name and phone once EzBizi has found them.',
        'A1Leads GBP: each Google review shows once, even when it is reachable through more than one Google account.',
        'Task Board: choose a board from a list grouped by company, and set up your own statuses, columns and groups. Tasks can have subtasks.',
        'Reports: task reports show each board’s own statuses and whether a task is a subtask.',
        'Automations: "Change the status" on a task works with each board’s own statuses.',
      ],
    ),
    VersionRelease(
      version: '0.0.0419',
      date: '13 Oct 2026',
      items: [
        'Reports: Performance › Calls has a Score column, AI’s score out of 10 for each call.',
        'Reports: new Thumbtack tab, with lead spend, cost per lead and cost per booked job by category and branch.',
        'Reports: Marketing, Meta and Thumbtack have the from–to date picker. Marketing loads faster and says when the P&L is not filled in for the dates.',
        'AI Assistance answers questions in its own words from your Library, in the app and on WhatsApp for staff.',
        'AI Assistance: the welcome message shows in full in Settings, and WhatsApp group chips no longer cut off on a phone.',
        'Every tab can now have a walkthrough and a how-to video, shown as (?) and ▶ beside its title. The Task Board has the first walkthrough.',
        'Settings › Integrations now shows every provider, connected or not, with its logo and a Connect button on the card.',
        'Leads: Google bookings that were still "Booking through Google" now get the customer\'s name, phone, email and ZIP from Workiz\'s booking email.',
      ],
    ),
    VersionRelease(
      version: '0.0.0418',
      date: '13 Oct 2026',
      items: [
        'Reports: Performance › People can show an AI call score and coaching notes per dispatcher.',
        'Task Board: a new Task Board tab. Every company, franchise and person has a board, shown as a table or a kanban, with each task opening in a side panel and as cards on a phone.',
        'Reports: Task Board tasks can be reported in the Report Builder.',
        'Automations: rules can add a task, give it to somebody and change its status.',
        'Automations: a rule can notify, email, text or WhatsApp whoever a task is given to.',
      ],
    ),
    VersionRelease(
      version: '0.0.0417',
      date: '13 Oct 2026',
      items: [
        'AI: fewer calls are flagged as complaints by mistake; a confirmed or moved booking is no longer a complaint.',
        'Leads: the button that opens a customer\'s photo in the browser is now solid green, so it stands out.',
      ],
    ),
    VersionRelease(
      version: '0.0.0416',
      date: '12 Oct 2026',
      items: [
        'AI: calls are read much faster, so call summaries and speaker splits appear sooner.',
      ],
    ),
    VersionRelease(
      version: '0.0.0415',
      date: '12 Oct 2026',
      items: [
        'Reports › Meta: AI suggests a service category for each Facebook ad campaign; a person confirms it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0414',
      date: '12 Oct 2026',
      items: [
        'Workiz: each branch\'s Workiz phone calls and Workiz\'s call scores now come into EzBizi (for Reports).',
        'Reports: Performance › People shows Workiz\'s own call scores beside EzBizi\'s, for branches Workiz scores (DMV, Illinois, New York).',
      ],
    ),
    VersionRelease(
      version: '0.0.0413',
      date: '12 Oct 2026',
      items: [
        'AI: call summaries first in the lead, a Follow up list, complaint alerts, Draft with AI for reviews, smarter name filling, and a Monday AI summary.',
        'Fleet: the vehicle list opens one state at a time, with a button per state and an All states option.',
        'AI: new leads are read faster, several at a time.',
        'Monitoring › Files works like Windows Explorer: drives on the left, a sortable list (name, modified, size) in the middle and a preview of the chosen file beside it.',
        'Monitoring › Files asks for your authenticator code to open, shows until when it stays open, has Lock now, and lists Desktop, Downloads and the rest under Quick access (once that computer has this update).',
        'Reports: new Marketing tab (ad cost against jobs, sales and estimated profit per branch and source) and Meta tab (Facebook ad spend by campaign, state and category).',
      ],
    ),
    VersionRelease(
      version: '0.0.0412',
      date: '12 Oct 2026',
      items: [
        'Performance › Calls is a table: one line per call with its own columns, and a search. Open a call for its words and recording. People shows who didn\'t pick up and their answer rate.',
      ],
    ),
    VersionRelease(
      version: '0.0.0411',
      date: '12 Oct 2026',
      items: [
        'Anything AI wrote or sorted now says so: a ✦ AI mark on suggested replies and on Performance\'s call reasons.',
        'Performance: the AI\'s call reasons gain Estimate question, Job status, Confirmation, Not a customer and No conversation, so fewer calls land in Other.',
        'Reports › Google Ads: every manager account (MCC) now opens as an overview of the accounts under it, and manager accounts have their own colour in the list.',
        'Reports › Google Ads: a manager\'s overview includes every active account under it; canceled accounts are counted separately instead of as not connected.',
      ],
    ),
    VersionRelease(
      version: '0.0.0410',
      date: '12 Oct 2026',
      items: [
        'Reports: a role given the Performance tab can open it without also needing Build.',
        'Performance › Missed: tap a call to see the caller, the line they called, and the voicemail when they left one.',
        'Leads: call transcripts are split into IVR menu, Dispatcher and Customer, with the menu shown once.',
        'Scheduling › Overbooking: tap any overbooked day to see every job behind it: time, service, job number, client, phone, email, address, technician and notes.',
        'Performance: call transcripts are split into IVR menu, Dispatcher and Customer, like in Leads.',
        'Notifications: tapping one, on desktop or on a phone push (even when the app was closed), now opens the exact email, missed call, suggestion, automation rule, Field alert or onboarding it is about, and a time-clock correction opens that person\'s week in Work Hours.',
        'Grok AI: if Grok stops answering for 30 minutes, the operations address gets an email.',
        'Leads: a website form now reads one line per detail (name, phone, email, appointment, address, service, note), each with its emoji, leaving out empty ones.',
      ],
    ),
    VersionRelease(
      version: '0.0.0409',
      date: '12 Oct 2026',
      items: [
        'Leads: Facebook Messenger enquiries now show the customer\'s name instead of "Unnamed enquiry".',
        'Performance › Calls › Missed: each call now says Inbound or Outbound, with a filter. Outbound calls nobody answered are listed but not counted as missed customers.',
      ],
    ),
    VersionRelease(
      version: '0.0.0408',
      date: '12 Oct 2026',
      items: [
        'Leads: Call opens a dial pad with the number already typed in, instead of the whole Communication screen over Leads.',
        'Leads: Book fills in the street, unit, city, state and ZIP from the customer\'s website form.',
        'Leads: Messenger is now called Facebook and has a blue tag.',
        'Marketing › Social Media: Posts is now three tabs, Dashboard, Compose and Calendar, each grantable per role. Everyone who had Posts keeps all three.',
      ],
    ),
    VersionRelease(
      version: '0.0.0407',
      date: '12 Oct 2026',
      items: [
        'Meta approved EzBizi on 11 October for Facebook Pages, Instagram publishing and WhatsApp.',
        'Marketing › Social Media Management: publish or schedule posts to your Facebook Page, and photo posts to Instagram.',
        'Leads: Facebook Messenger messages to your Page arrive in Leads (Messenger filter), and you can reply from EzBizi.',
        'Leads: older Google bookings now show the customer\'s name too, and older leads that became jobs are marked Completed.',
        'Thumbtack: the nightly phone-number sync now also skips a number Thumbtack rejects when it is the only new one, instead of failing.',
      ],
    ),
    VersionRelease(
      version: '0.0.0406',
      date: '12 Oct 2026',
      items: [
        'Monitoring › Files: drives and folders now open even when Windows locks some of their files.',
      ],
    ),
    VersionRelease(
      version: '0.0.0405',
      date: '12 Oct 2026',
      items: [
        'Monitoring › Files: an online computer\'s drives and files now open, instead of the tab saying the computer looks offline. Empty files download too.',
        'Social: Pinterest posts now publish live.',
      ],
    ),
    VersionRelease(
      version: '0.0.0404',
      date: '9 Oct 2026',
      items: [
        'AI connections: large answers (like website widget settings) now arrive whole, a page or a part at a time, instead of cut off.',
        'In Suggestions you can now drag screenshots and files onto the new-suggestion box to attach them.',
        'Performance report: the AI no longer counts a price quote or an offered time as a booking.',
        'Web Management › SEO Helper: a title or description with no real words, or an unfilled placeholder, is now flagged and can\'t be saved. X (Twitter) title, description and image are their own fields, and the overview and page scores now agree.',
        'Email: opening a message in a Google Workspace mailbox with read sync on now marks it read in Gmail too (that step was failing).',
        'Thumbtack: when Thumbtack rejects one phone number, the nightly phone-number sync skips it and sends the rest instead of failing for every connection.',
        'HR: saving a drawn signature no longer fails for larger drawings.',
      ],
    ),
    VersionRelease(
      version: '0.0.0403',
      date: '9 Oct 2026',
      items: [
        'Thumbtack: the phone-number sync now renews an expired sign-in instead of failing.',
        'When call history expires or is erased, EzBizi now also deletes the call\'s recording from Twilio, as its privacy notes always said.',
      ],
    ),
    VersionRelease(
      version: '0.0.0402',
      date: '9 Oct 2026',
      items: [
        'Leads: a Lost status for real customers who declined or want a service we don\'t offer (set by hand, or by Grok on new leads). Any Archived, Not a lead, Lost or Completed lead goes back to New when the customer writes again.',
        'Job applicants who write in are marked Job applicant and kept in their own Leads tab, out of the lead counts.',
        'Desktop app: if you take calls in EzBizi, automatic updates wait until you set Do Not Disturb, and never close the app during a call. Outside a call, the Install button in the update banner still updates right away.',
        'Memberships: staff can open a preview of the sign-up page that never signs anybody up.',
        'Leads on a phone: a lead\'s status is one picker beside Book instead of a row of buttons.',
      ],
    ),
    VersionRelease(
      version: '0.0.0401',
      date: '9 Oct 2026',
      items: [
        'Android: anyone who refused location is no longer pulled out of the browser in the middle of a sign-in. EzBizi now asks for location only while it is on screen, and only once.',
        'Communication: Messages, Recent calls and Voicemail now name the other person (a customer, lead or colleague) with the number under it, and leads are tagged Lead. A number with no name shows a phone icon.',
        'Voicemail gives the transcription advice once above the list instead of under every message.',
        'WhatsApp reports and their groups are named by company (e.g. Air 75, or A-1 Chimney · Texas) instead of just the branch.',
        'Leads: Google Local Services bookings now show the customer\'s name, phone and ZIP, taken from the matching Reserve with Google booking in Workiz, and link to the job.',
        'Recordings can now play at 1.5× or 2× speed. Calls in a lead\'s conversation are tidier: who called, how long and when at a glance, with the transcript underneath.',
        'Leads: Grok now reads new leads. It fills in a missing name or ZIP from the call transcript, suggests a reply, and marks obvious spam, vendor pitches, robocalls, wrong numbers and job applicants as Not a lead.',
      ],
    ),
    VersionRelease(
      version: '0.0.0400',
      date: '9 Oct 2026',
      items: [
        'Automations are easier to read: each rule says what it does and when it last ran, with search and filters, and clicking a rule opens it with its recent runs. The builder reads as When → Only if → Then.',
        'Leads: the existing-customer bar on a lead is green.',
      ],
    ),
    VersionRelease(
      version: '0.0.0399',
      date: '9 Oct 2026',
      items: [
        'Leads: a new lead that turns into a job is now marked Completed automatically.',
        'Marketing › Social Media: connect a YouTube channel and publish videos to it from Posts.',
        'Reports › Performance is tidier: each call is one row with its badges and a play button, the recording opens only under the call you play, and Calls has one filter bar (Result and Client) instead of three rows of chips.',
        'Report Builder: Workiz jobs and Workiz payments, invoices and estimates can now be reported on.',
        'Automations: a saved WhatsApp step opens with its number, template and blanks, so the rule can be saved again.',
        'The Report Builder now shows a live preview as you pick fields. Columns drag into order and can be renamed, filters, sorting, grouping and totals are built in, and it works on a phone too.',
      ],
    ),
    VersionRelease(
      version: '0.0.0398',
      date: '9 Oct 2026',
      items: [
        'Leads: website form messages show one field per line with a small icon, without the website\'s robot-check code. Ratings from the review link show as Bad Rating or Good Rating instead of Form.',
        'Settings › Integrations: where another module is the place to connect (Newsletter, Marketing or Leads), there\'s now a button that opens it.',
        'Marketing › Posts: you can now publish to Bluesky and X (text and one picture). X accounts need connecting again to post pictures.',
        'Online booking reads friendlier: no step numbers, each step asks in a full sentence, and a ZIP code we don\'t serve online now says so plainly.',
      ],
    ),
    VersionRelease(
      version: '0.0.0397',
      date: '8 Oct 2026',
      items: [
        'Tabs inside a tab now look like a level down: the second row is underlined words and a third row is a small switch (Marketing, Web Management, Memberships, Scheduling settings, HR records, Reports).',
        'Reports: the report library gives report names and descriptions more room.',
      ],
    ),
    VersionRelease(
      version: '0.0.0396',
      date: '8 Oct 2026',
      items: [
        'On a phone, a row of tabs that runs past the screen edge now fades there so you can see there\'s more, and the tab you\'re on always scrolls into view.',
        'Screen readers now announce tabs as tabs (they were read as checkboxes).',
        'The "EzBizi on your phone" card fits a narrow browser window.',
        'Every dropdown shows a dropdown arrow instead of a magnifier, so it no longer looks like a search box.',
        'Leads: an empty list names its source and says when LS Call is left out of All; the In All toggles are labelled; long source names fit in the left rail.',
        'Communication: the Users tab no longer calls itself Team; Settings › Connection says No account instead of a contradicting Uses the organization; an empty Dial has an Open Settings button.',
        'Jobs: a job with no date that is already done now shows under Past, not Upcoming.',
        'Jobs: the Money and Due columns appear only when a job on the list has money, so Title and Where get the room. The Estimates empty list has a New estimate button.',
        'Reports: clearer wording where a button is off or RingCentral isn\'t connected.',
        'Google Ads: with only one account, it loads straight away, and the search no longer says "Search 1 accounts".',
        'Field: the Map now lists every tracked person, including anyone who hasn\'t reported yet, so its count matches the Dashboard. The status banner says whether your location is being shared right now. The arrival distance shows feet and metres. The Dashboard tiles are now all the same size.',
      ],
    ),
    VersionRelease(
      version: '0.0.0395',
      date: '8 Oct 2026',
      items: [
        'Android: alerts, new leads and missed calls now pop up with sound ("EzBizi urgent" in the phone\'s notification settings); everything else stays as it was.',
        'API activity: calls made in the same second now list in the order they happened.',
        'Missed-call notifications now name the line the caller rang, as labelled in RingCentral (e.g. DFW - LS Ads).',
      ],
    ),
    VersionRelease(
      version: '0.0.0394',
      date: '8 Oct 2026',
      items: [
        'Automations: a rule can wait a set number of minutes after its event before acting.',
        'Memberships: automations can text a short link to the membership sign-up page, and it is never sent to someone who is already a member.',
      ],
    ),
    VersionRelease(
      version: '0.0.0393',
      date: '8 Oct 2026',
      items: [
        'Leads: an Overdue filter beside Unreplied shows only the customers waiting too long (the red rows).',
        'Workiz leads: a branch that had never received a Workiz lead now imports new ones; one missed New Jersey enquiry from Oct 7 appears.',
        'Invitation emails now include the link to download the EzBizi apps.',
      ],
    ),
    VersionRelease(
      version: '0.0.0392',
      date: '8 Oct 2026',
      items: [
        'Review links are now short (https://ma.a-1chimney.com/r/Ab3xK9m) and unique per customer and job. A known customer\'s "not so great" form only asks what happened. After a bad review there is no membership offer, and the thank-you is centred.',
        'Automations: a rule switched on only acts on what happens from that moment, never on older events.',
        'Leads: the "customer waiting" warning quotes what the customer wrote, not our automatic reply.',
      ],
    ),
    VersionRelease(
      version: '0.0.0391',
      date: '8 Oct 2026',
      items: [
        'Thank-you review links now open the reviews page of the location that did the job, with that location\'s own Google review link.',
        'The "verify you are human" check now covers the websites connected today too.',
        'Review page: the Join button and the faces now work when opened from a customer website link.',
        'Fixes online booking requests and the chat\'s "typing…" indicator being blocked by browsers.',
      ],
    ),
    VersionRelease(
      version: '0.0.0390',
      date: '8 Oct 2026',
      items: [
        'Thank-you review links now go to the reviews page of the branch that did the job, and are unique to the customer and job, so they never have to type their phone or email.',
        'Memberships: the review page a customer opens after a finished job can show their membership, or offer the plans with a one-tap Join.',
        'Phone: A-1 Chimney\'s approved toll-free 855-955-3322 can text from EzBizi while staying on the platform account; customer replies to it land in Communication.',
      ],
    ),
    VersionRelease(
      version: '0.0.0389',
      date: '8 Oct 2026',
      items: [
        'Website forms and chat now show a "verify you are human" box (Cloudflare Turnstile) on top of the existing robot check.',
        'Websites can take online bookings: customers pick a day and arrival window from your real availability, and dispatchers approve them in Leads › Online Booking. Website forms now ask for the ZIP code.',
        'Automations: a notification with a long message no longer fails; its title is shortened and the full text is underneath.',
      ],
    ),
    VersionRelease(
      version: '0.0.0388',
      date: '8 Oct 2026',
      items: [
        'Get the apps: on a computer, people who have never used the phone app see one Dashboard card with a QR code and the App Store and Google Play buttons. No thanks hides it for good, on every device. The menu under your name now has Get the apps, and on a phone\'s browser a small banner offers the app.',
      ],
    ),
    VersionRelease(
      version: '0.0.0387',
      date: '7 Oct 2026',
      items: [
        'API activity: a change an AI made to a website text or a customer can be put back with one click, unless it has been edited again since.',
        'Notifications: a missed call from a hidden number now says Unknown caller.',
      ],
    ),
    VersionRelease(
      version: '0.0.0386',
      date: '7 Oct 2026',
      items: [
        'Automations: missed calls and voicemails on the company\'s RingCentral lines can now trigger notifications.',
      ],
    ),
    VersionRelease(
      version: '0.0.0385',
      date: '7 Oct 2026',
      items: [
        'You can now see everything an AI or a script did with an API key. In Your account › API keys, each key has an Activity link showing its last few changes, with the rest one click away. Administrators get Settings › API activity: everyone\'s activity, filtered by person and key, with reads hidden unless you ask for them. Each change opens to show every field the way it was and the way it was set, and a deletion shows what was deleted, so a change can be put back by hand.',
      ],
    ),
    VersionRelease(
      version: '0.0.0384',
      date: '7 Oct 2026',
      items: [
        'Communication has a new Alerts tab. Send chosen people an alert, and it pops up in the middle of their screen, over everything, until they press Dismiss. Several arrive one after another, and one sent while they were away is waiting when they come back. The tab lists the alerts you sent, each with who has dismissed it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0383',
      date: '7 Oct 2026',
      items: [
        'Monitoring: camera feeds now show the full wide view instead of a cropped slice.',
      ],
    ),
    VersionRelease(
      version: '0.0.0382',
      date: '7 Oct 2026',
      items: [
        'Customers › Notes from Workiz now shows the notes staff left on the customer\'s jobs, each under its job number.',
        'Roles & permissions: a new API keys row decides which keys each role may make: Viewer (read), Editor (read and edit) or Full access (also delete). Only administrators have it until you tick it. Taking a tick away narrows keys already made, straight away. Your account › API keys asks which kind to make.',
        'Scheduling › Locations is easier to read: "Who serves this address?" at the top, branches grouped by state with one State picker, and Copy and Directions beside each number and address.',
      ],
    ),
    VersionRelease(
      version: '0.0.0381',
      date: '7 Oct 2026',
      items: [
        'Monitoring: click a camera to watch it live, full motion, on the app and the web.',
        'Dashboard: Customize now offers Templates, recommended layouts for a kind of work. One click rearranges your boxes (which show, their order, size and chart), and Undo puts your own layout back.',
      ],
    ),
    VersionRelease(
      version: '0.0.0380',
      date: '7 Oct 2026',
      items: [
        'A1 Leads GBP: phone numbers in the Contact column and on the card now read (513) 390-5722.',
      ],
    ),
    VersionRelease(
      version: '0.0.0379',
      date: '6 Oct 2026',
      items: [
        'Customers: Notes from Workiz also reach the few clients that Workiz\'s own client list leaves out.',
      ],
    ),
    VersionRelease(
      version: '0.0.0378',
      date: '6 Oct 2026',
      items: [
        'Mac: brings everything listed under 0.0.0377, which reached the web, Windows, Android and iPhone first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0377',
      date: '6 Oct 2026',
      items: [
        'Marketing › QR Codes: each card shows its scans and daily scanners over the last 30 days, with a strip of the days. Stats opens 30 or 90 days, day by day, with the busiest day and the all-time total. A scanner is one phone or browser on one day, and nothing about who scanned is kept.',
      ],
    ),
    VersionRelease(
      version: '0.0.0376',
      date: '6 Oct 2026',
      items: [
        'Reports › Performance has three tabs. Overview: the figures (tap one to see its calls), with Not booked and Missed calls side by side. People: the table. Calls: every call, filtered by Missed, Existing, Not booked or New, each showing the first words said.',
        'Reports › Performance: the Workiz accounts moved behind a Sources button, and an account that did not answer shows as one amber line at the top. Calls over several days show their day.',
        'Email › Settings: each inbox has a Notify about new emails switch. It is the same as the New email automation, so changing one changes the other.',
        'Customers: Notes from Workiz says plainly when a branch is not linked to Workiz yet, instead of showing an error with Try again.',
        'HR: the twelve-month chart on the HR dashboard fits narrow screens and iPads.',
        'Performance report: three dispatchers whose names differ between RingCentral and Workiz are now matched.',
        'Inspections: a finished inspection\'s report is linked on its Workiz job.',
        'Workiz: Massachusetts bookings can be credited to who booked them.',
        'Your account: make API keys so an AI like Claude, or a script, can work in EzBizi as you, read only or able to make changes. A new key is shown once with a copy button and the Claude Code setup line, and any key can be revoked.',
        'Marketing › QR Codes: a code is deactivated instead of deleted, so a printed code is never lost. Scanning a deactivated code says it is paused, scans still count, and Reactivate brings back the same destination.',
      ],
    ),
    VersionRelease(
      version: '0.0.0375',
      date: '6 Oct 2026',
      items: [
        'Notifications: lead notification titles are shorter: New "Local Services Call" lead, New reply on "Local Services Text", and so on.',
        'Customers: a customer\'s panel has tabs like a job\'s. Money (for people who can see financials) shows what Workiz has invoiced, what is due, what is past due (in red on the tab) and what is paid. Notes from Workiz shows each branch\'s Workiz notes about the customer.',
        'AI Assistance: a new WhatsApp groups tab picks who gets each WhatsApp report (up to 8 per group), flags anyone with no phone, and copies the invite link once it is a real WhatsApp group. Once a group is set up, the nightly Same-Day Reports and the weekly and monthly PDFs (after the bookkeeper approves them) go out on WhatsApp.',
        'Automations: a rule can post to a staff WhatsApp group, and new triggers fire for the daily account review, a certification problem resolved, a Google review removed and an automatic reply sent.',
        'Email: an inbox can be given to a person, not only to a role, under Who can open this inbox in Email › Settings.',
        'Email: several Workspace addresses can be added at once. Tick them in the directory search, or Select all, then Add.',
        'Leads: the "customer waiting" warning names the phone number, or "A customer", when the lead has no name.',
        'HR: an invitation sent with a contract goes out by email once the employer has signed the paperwork.',
        'HR: every contract now has the same pay options (hourly, salary, absence deduction, commission, overtime, scheduled increases), each turned on or off on its own.',
        'Camera tiles show live video inside the desktop and mobile app, not only through a browser link.',
      ],
    ),
    VersionRelease(
      version: '0.0.0374',
      date: '6 Oct 2026',
      items: [
        'WhatsApp: a new contact is asked "I need a service" or "I want to work with you". A service tap becomes a WhatsApp lead in Leads, which you can answer from Leads within 24 hours. A job tap notifies whoever can add people in HR. Until they tap, the Leads team is told a new WhatsApp message arrived.',
        'Facebook and Instagram lead-ad forms arrive in Leads as Meta Form leads.',
        'Messenger and Instagram messages now start "new lead" automations, which they never did before.',
        'Workiz: who booked each job is back on the bookings report. Workiz\'s new connection stopped telling us, so EzBizi reads it from the old one.',
        'Email: a rule on new emails can show the sender\'s name, the subject and a preview of up to 178 characters. The preview is read only when an active rule uses it.',
        'AI Assistance settings: turn the new-contact question off or on.',
        'A job now opens in tabs: Job, Money and Photos & files. Job shows Workiz\'s own details (System type, Time frames and so on) under the status, read only.',
        'Money, for people who can see financials: the money card, then the invoice and each payment by date with who collected it, then Workiz\'s estimates with their status and the time on site.',
        'Photos & files: the job\'s photos in a grid that opens large. Inspection reports open in a new tab.',
      ],
    ),
    VersionRelease(
      version: '0.0.0373',
      date: '6 Oct 2026',
      items: [
        'Performance report: ready to label each call\'s reason with Claude and Grok side by side (switched off until the privacy page and keys are in place).',
        'A new inspection fills in the client\'s contact details and address, and the job\'s date.',
        'Email: opening an email no longer waits on four slow steps. Pictures now load together, the Google sign-in is reused instead of renewed on every call, one Gmail fetch replaces two, and marking it read in Gmail happens after the email shows.',
      ],
    ),
    VersionRelease(
      version: '0.0.0372',
      date: '6 Oct 2026',
      items: [
        'Reports › Performance shows its dates in words on one button, such as "This week · Mon, Oct 5 – today", instead of Day, Week and Month.',
        'Reports › Performance: click the dates for quick choices showing the dates each one means, a calendar where one click picks a day and two pick a range, or From and To typed in. The arrows step back and forward by the same length, and on a phone the picker opens as its own page.',
        'Leads: a Workiz lead booked through Reserve with Google is announced as a Workiz Online lead.',
      ],
    ),
    VersionRelease(
      version: '0.0.0371',
      date: '6 Oct 2026',
      items: [
        'Email: read and unread now follow Gmail both ways. Opening a message or marking it unread changes it in Gmail too, and an opened message no longer turns bold again on the next refresh. It is on for every mailbox; a shared inbox can switch it off in the mailbox settings.',
        'Leads: a WhatsApp chip, where enquiries from WhatsApp will appear once WhatsApp sends them to Leads.',
      ],
    ),
    VersionRelease(
      version: '0.0.0370',
      date: '6 Oct 2026',
      items: [
        '"Keep me signed in" now starts ticked on the sign-in screen, so you stay signed in after closing the app. Untick it on a shared computer.',
        'Notifications: an Alert me switch in the bell lets each person turn their own pop-ups, phone alerts and notification emails off. Notifications still collect in the bell.',
        'Reports: Experimental is now called Performance, and Builder is Report Builder.',
        'The Performance report loads much faster: Workiz jobs come from EzBizi\'s synced copy instead of being fetched live each time.',
        'Inspection reports: a plain choice now prints the option\'s words instead of its stored key.',
        'Email: adding an address from Workspace now offers the branch subdomain addresses too, such as office@nj.a-1chimney.com.',
        'Leads: another call on a Google Local Services call lead no longer raises a "customer replied" notification.',
        'Notifications from a rule can carry a second line under the headline, and rules can use the new lead headline, details and reply preview wording.',
        'Every website form and live chat is protected by a CAPTCHA that visitors never see; the per-site switch is gone.',
      ],
    ),
    VersionRelease(
      version: '0.0.0369',
      date: '6 Oct 2026',
      items: [
        'Onboarding: the new hire fills in and signs the direct deposit form and the I-9 in the app, pre-filled from their answers. The employer completes I-9 Section 2. Both print in the contract PDF.',
        'The I-9 is the current USCIS edition (01/20/25). The W-4 was already the 2026 form.',
        'Inspection › Builder is redesigned around the form itself: screens run down the left, each saying how many questions it asks and when it appears, and a screen\'s questions are cards that say in plain words what each one is.',
        'Inspection › Builder: click a question and it opens where it is, with its answers, the questions each answer asks next, when it is asked and how it prints. A screen can be shown only when an earlier answer matches.',
        'Inspection › Builder: a live phone beside the questions shows what each answer reveals as you tap it, and the Map sits behind it.',
        'Inspection › Builder: changes save as you go, and technicians keep the published version until you press Publish. On a phone, screens are a list, each opens as a page, and a question opens in place.',
        'Inspection › Builder: the "Look" setting is gone, because it changed nothing.',
      ],
    ),
    VersionRelease(
      version: '0.0.0368',
      date: '6 Oct 2026',
      items: [
        'Workiz: EzBizi now copies each branch\'s Workiz invoices, payments, estimates, time off, calendar events, timesheets and price book, and the custom fields and files on every job.',
      ],
    ),
    VersionRelease(
      version: '0.0.0367',
      date: '6 Oct 2026',
      items: [
        'Monitoring Files: opening a computer now shows its drives instead of "This could not be read".',
        'Onboarding: each pay term can be turned off on its own, and only its lines leave the contract. Turning off Pay basis keeps the pay lines that were answered.',
        'Onboarding: a "TBD" button on the employer\'s terms prints "To be determined" in the contract.',
        'Onboarding: compensation method has quick picks, and short choices such as pay basis show their options as buttons.',
        'Onboarding: "1 answer not saved" no longer appears after everything was saved. It blocked signing until the form was reopened.',
      ],
    ),
    VersionRelease(
      version: '0.0.0366',
      date: '6 Oct 2026',
      items: [
        'Windows app: the background checks Monitoring makes no longer run on the window itself, so a slow check can no longer freeze the app or turn it white.',
        'Windows app: it keeps the size you gave it after the clock-in lock screen or a full-screen training video.',
        'In Inspection › Builder › Flow, each screen is now called by its own name ("Client and site", "The system", and so on) instead of "Screen 1" to "Screen 4".',
      ],
    ),
    VersionRelease(
      version: '0.0.0365',
      date: '6 Oct 2026',
      items: [
        'Jobs: the Workiz number on the jobs list is no longer cut short.',
      ],
    ),
    VersionRelease(
      version: '0.0.0364',
      date: '5 Oct 2026',
      items: [
        'Workiz: a job Workiz deleted no longer stays in EzBizi just because an ad lead was matched to it by phone, or because its status change fired an automation. The lead is matched again to the job it became, such as the Massachusetts copy of a moved job.',
      ],
    ),
    VersionRelease(
      version: '0.0.0363',
      date: '5 Oct 2026',
      items: [
        'Workiz: a job deleted in Workiz, or moved to another branch\'s Workiz account, now leaves EzBizi too, unless someone changed it in EzBizi or something in EzBizi refers to it. Past jobs\' statuses keep up to date again.',
        'Windows, Android, iPhone and Mac: also bring everything listed under 0.0.0362, which reached the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0362',
      date: '5 Oct 2026',
      items: [
        'Workiz: EzBizi can now connect to Workiz\'s v2 API keys. Jobs Workiz\'s old API never listed now arrive too (Connecticut had 7 in the next two weeks, Ohio 5).',
      ],
    ),
    VersionRelease(
      version: '0.0.0361',
      date: '5 Oct 2026',
      items: [
        'HR: downloads of imported A1 Tools records now include the Employee record, the pictures and the uploaded PDFs, and the records without a signed contract can be downloaded too.',
        'Password reset emails are retried if the mail server turns one away, and a failed email no longer stops the ones after it from sending until the system restarts. Two reset emails were lost that way on 5 October; if you asked for one and it never came, ask again.',
        'Marketing has a new QR Codes tab beside Posts and Accounts. Each code is a card showing the code itself, where it sends people (with Change right on the card, and the printed code stays the same), its scans, and SVG or PNG downloads. Delete is in the card\'s ⋮ menu and asks first, because printed copies stop working. New QR code opens a side panel.',
        'Monitoring Files: the drive list now opens even when a computer has an empty DVD or card reader drive.',
        'Settings › Numbers: each line takes less space on a narrow screen (about half the height).',
        'Windows, Android, iPhone and Mac: also bring everything listed under 0.0.0360, which reached the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0360',
      date: '5 Oct 2026',
      items: [
        'Experimental call report: the Existing and Not booked lists show what was said on each recorded call, transcribed on EzBizi\'s own server or taken from the Local Services transcript of the same call.',
        'Settings › Integrations › Workiz: connecting a branch asks for the API secret as well as the token.',
        'Workiz: a client EzBizi already has (same phone, name and zip) is attached to that customer instead of becoming a second one.',
        'RingCentral reports sign in once an hour and wait out RingCentral\'s limits instead of failing; a busy RingCentral is no longer reported as wrong credentials.',
        'Memberships: a visit a member books from the reminder is created in their branch\'s Workiz too, under the plan\'s Workiz job type, and retried a minute later if the Workiz sync is busy. If Workiz refuses it, a trigger tells staff to enter it by hand.',
        'The Members list shows each member\'s phone, and their email when there is one; search finds them by either.',
      ],
    ),
    VersionRelease(
      version: '0.0.0359',
      date: '5 Oct 2026',
      items: [
        'Onboarding: the employer can sign before the new hire has accepted the invitation. The employer\'s signature and the final approval are separate steps.',
        'HR: the downloaded contract PDF now ends with an \'Employee record\' section: bank details, SSN, work hours and everything else on file that the contract wording doesn\'t state. Imported A1 Tools contracts get the record and any uploaded PDFs (such as a W-4) added after the original.',
        'Leads: read and unread are now shared. Once anyone opens a lead, or uses Mark all read, it shows as read for everyone; a new message makes it unread again. Stars stay personal.',
        'Web Management: each website has a Branch setting that decides where its chats, forms and reviews land in Leads.',
        'Scheduling: a job booked under a branch whose service area shares another branch\'s availability is placed by its zip again, so Nationwide\'s Boston North bookings count against A1 - Chimney MA\'s Boston North.',
        'Experimental call report: spam calls are no longer counted; Reached nobody is now Missed calls, with New/Existing filters; cards follow Jose\'s order (Calls, Missed calls, Existing client, New client, Jobs booked, Conv. rate, Not booked); Not booked lists unbooked new-client calls with Convert to booked and its history; dispatchers can be tagged New or Existing; Day/Week/Month periods. Weeks and months include every call, not just the first 1,000.',
        'A1Leads GBP: the customer A1 Leads matched to a review now shows even when the review has no card. You also see whether it was matched by hand or automatically, plus the Workiz account, the last job number and date, the technician or team, the phones, email, address and a Workiz link.',
        'Windows, Android, iPhone and Mac: also bring everything listed under 0.0.0358, which reached the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0358',
      date: '5 Oct 2026',
      items: [
        'Memberships: the renewal reminder can carry a link where the customer picks the day of their next visit, and it books the job, one visit a year however often they tap; staff see each member\'s booked visit on the list, and who hasn\'t picked a day after the reminder. If no day is picked two weeks after the reminder, a trigger lets staff follow up. Plans can name the Workiz job type their visits are booked as.',
      ],
    ),
    VersionRelease(
      version: '0.0.0357',
      date: '5 Oct 2026',
      items: [
        'HR: contracts imported from A1 Tools can be downloaded as the PDF signed in A1 Tools.',
        'HR: a download button for the signed PDF on the Onboarding list and on Records › Onboarding.',
        'Customers › Memberships: a yearly maintenance program with its own Dashboard, a Members list (plan, amount due, state, zip, estimated gross and paid so far) and the plans under Settings, billed yearly or monthly. A reminder trigger fires a month before each year ends, and customers can join from a link sent after a good review, on a page that looks like the business\'s emails with each plan\'s price in plain sight. Monthly plans show both their monthly and yearly price.',
        'Work Hours, and its box on the dashboard, list only the people in the company or branch you are viewing.',
        'Onboarding: the technician now fills in the tools and equipment checklists. Tick what you received, set a quantity, or add an item that isn\'t listed.',
        'Membership plans can say what they include, shown to customers on the join page, which shows your company\'s name.',
        'Windows, Android, iPhone and Mac: also bring everything listed under 0.0.0356, which reached the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0356',
      date: '5 Oct 2026',
      items: [
        'Dashboard › Jobs: for roles with Jobs Financials, the box shows what was earned in the period, per currency, against the period before. Others see no amounts.',
        'Voicemail boxes can belong to a team or a person (Settings › Voicemail › Belongs to). A team\'s or person\'s missed calls leave their messages in that box, and the box lists them. Fixed: a caller who picks a team from the menu and gets no answer now reaches that team\'s overflow or voicemail, not the line\'s. Fixed: a line set to ring one person now goes to voicemail after one round instead of ringing them again.',
        'Recent calls and Records show what the caller pressed in the menu (\'pressed 2\', \'dialed ext 0101\').',
        'Settings › Numbers: tick several lines and change what rings them, their business hours or recording, all at once.',
        'Settings › Numbers has two new filters: \'Not in use\' (no calls in 30 days) and \'Not assigned\' (no one\'s extension holds the line).',
        'Port-in asks for the current carrier\'s account (name, account number, who signs, service address) and the port PIN that US mobile numbers need. The port list shows the PIN and the account number in full to whoever can start a port and only their last two digits to everyone else, and who was sent the letter of authorization to sign.',
        'Marketing › Posts: the TikTok privacy choice no longer shows its hint over its label.',
        'Pickers that show a hint no longer draw their label on top of it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0355',
      date: '5 Oct 2026',
      items: [
        'Scheduling: the morning warning email now lists only service areas with no open spot for the next 10 days or more, each with its next opening, so their ads can be paused. Scheduling › Overbooking shows the same 10-day list, and the Scheduling box on the dashboard counts it.',
        'Communication › Settings › Call flow: General and the call flow chart are now one screen. Your company\'s answer shows once and changes right there, each line says whether it follows the company or has its own, a red box with "Pick who rings" fixes the lines that reach nobody, and opening a line shows its chart. On a phone, a chart box opens its settings in place instead of a new screen.',
        'Call flow also flags a ring group that has nobody available to ring.',
        'Calls now remember which menu key the caller pressed and any extension they typed. Reports can show calls by key pressed and by caller, with the customer\'s name when they\'re on file. Calls before this release show \'No key pressed\'.',
        'The Phone box on the dashboard now flags what needs someone: a phone account that stopped working, and voicemails waiting (over a day in red).',
        'Dashboard › Jobs: flags jobs from the past week still in your first status, and jobs from today on with no service area. The box also loads much faster.',
        'Dashboard boxes show money only to people whose role can see that module\'s financials; Inventory shows stock value to them.',
        'Dashboard › Customize: hide a box, and bring it back with Add a box.',
        'Marketing › Posts: posting to TikTok now asks who can see the video, whether to allow comments, duets and stitches, and whether it promotes a brand, as TikTok requires.',
        'TikTok connections stay connected: their sign-in is renewed automatically.',
        'Menus and voicemail greetings can play a recording instead of reading the text aloud: under Settings › IVR menus or Voicemail, choose \'Use a recording instead\' and upload an MP3 or WAV. If the recording file has been removed, the text is read instead.',
        'Windows, Android, iPhone and Mac: also bring everything listed under 0.0.0354 and 0.0.0353, which reached the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0354',
      date: '5 Oct 2026',
      items: [
        'Dashboard: make each box Small, Medium or Large, and move boxes around, from the new Customize button. Boxes are grouped the way your sidebar is grouped. On a phone, the period is one chip.',
        'Dashboard: each box leads with its main figure and, where there is an earlier period, its change against it.',
        'Dashboard: boxes list what needs somebody. A Large box lists it and a smaller box says how much there is. This covers Leads, Reviews, Business Profile, Ads, Marketing, Automations, Email, Inspection, Inventory, Human Resources, Fleet, Training and Web Management, plus the four below.',
        'Dashboard › Work Hours flags a shift left open past its end and waiting correction requests.',
        'Dashboard, Field box: lists what needs a manager, meaning an SOS waiting, a technician who lost signal, or one stopped too long.',
        'Dashboard › Scheduling: flags days overbooked and service areas fully booked in the next 7 days.',
        'Dashboard: the Reports box warns when a scheduled report stopped sending because its automation switched itself off.',
        'Work Hours: when people edit their own hours, the shift reads Edited, as it does after anyone else\'s edit.',
      ],
    ),
    VersionRelease(
      version: '0.0.0353',
      date: '5 Oct 2026',
      items: [
        'Onboarding: a final approval step. Settings › Human Resources › "Approve onboardings before access". When it is on, paperwork signed by both sides, in either order, waits for the approval of whoever sent it, or of an Administrator. Until then the new starter sees only their paperwork.',
        'Onboarding: a "To approve" filter, and an Approve button on the signed paperwork.',
      ],
    ),
    VersionRelease(
      version: '0.0.0352',
      date: '5 Oct 2026',
      items: [
        'Onboarding: a county the search cannot find can be typed and saved.',
        'Onboarding work days and hours: set the days and hours, or type availability instead (one or the other).',
        'Onboarding: an optional company address typed on the form replaces the company\'s address on that contract.',
        'An invitation can be revoked even after it was accepted. Revoking cancels the paperwork and switches the person\'s access off. A signed onboarding can now be cancelled too.',
        'Web Management, Widgets: every colour of every widget is in a Colours section with a colour picker, and the live preview changes as you pick.',
        'Scheduling › Availability: the answer on screen now refreshes itself every 30 seconds, and right away when you come back to the tab, so a booking made by another dispatcher shows up without searching again.',
        'Communication: the Numbers and Voicemail lists no longer show an unexplained Yes / No; recording and writing down stay in each line\'s settings.',
        'Windows, Android, iPhone and Mac: also bring everything listed under 0.0.0351 and 0.0.0350, which reached the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0351',
      date: '5 Oct 2026',
      items: [
        'Onboarding: the signed contract\'s PDF shows tools and equipment as tables, includes the signed W-4 or W-9, ends with the copy statement, and every contract has the cover; both sides must draw their signature.',
        'Onboarding: a signed W-4 or W-9 opens as the picture of the signed page.',
      ],
    ),
    VersionRelease(
      version: '0.0.0350',
      date: '5 Oct 2026',
      items: [
        'Leads, Settings: Office hours (when the office answers leads; Monday to Friday 8 to 5 unless changed) and an email for customer photos, per branch. Automations can check whether the office is open and say when it opens again, such as tomorrow at 8:00 AM; the rules that use it start switched off.',
      ],
    ),
    VersionRelease(
      version: '0.0.0349',
      date: '2 Oct 2026',
      items: [
        'Automations: {{company_name}}, {{source_name}} and {{thank_you}} are offered as chips with a live example, and a franchise lead\'s message signs with its company\'s name, not the organization\'s.',
        'Automations: Where it applies names each franchise with its company (Connecticut — A-1 Chimney), and Make a copy duplicates any rule, switched off.',
        'Roles: a Seen on leads switch; a role switched off, such as Marketing, is never shown as viewing a lead.',
        'Leads: the conversation says Customer instead of They, and an unnamed lead reads Customer is typing…',
        'Inventory and Fleet open on a new General tab, the module\'s dashboard. Fleet\'s shows vehicles by status, warnings (service due or overdue, insurance expired, expiring or missing, out of service) and, for those who see financials, spending by category. The Fleet register becomes the Vehicles tab.',
        'Human Resources: the tabs read Dashboard, Records, Contracts, Invitations, Onboarding; Works at can be changed on a person\'s record, within your own reach; Invitations, Onboarding and Contracts show count tiles that filter a table.',
        'Onboarding: Download PDF gives the signed contract with the signatures, and the uploaded photos at the back.',
        'Scheduling, Availability: search by street address (its ZIP is looked up) and see the address it matched. An area closed every day shows Closed, and closed dates such as holidays show Closed that day in the list.',
        'Communication: Records, Messages, Team, Voicemail, Users, Reports (with a real bar chart) and every Settings tab (Connection, Numbers, Porting, Ring groups, Voicemail boxes, IVR menus, Hours, Extensions, Blocked callers, Tags, Cost) show tiles over a table. Tags can be renamed, and on a phone Team shows the roster instead of the dial pad.',
        'Marketing, Social Posts: a composer with a live preview and character counters for each network (over the limit blocks publishing), a Posts table with Scheduled, Sent and Failed tiles, and the calendars behind a Compose and Calendar switch.',
        'Marketing, Newsletter: the dashboard shows tiles, a table of every newsletter and a calendar. The composer edits blocks directly on the email, with Desktop and Phone views and a server preview.',
        'Newsletter fixes: saving no longer drops the text of every block (emails went out nearly empty), column blocks show their content, and a block\'s links and pictures only accept web, email and phone addresses.',
        'Reports: New report sits beside the tabs.',
        'Windows app: Download and Export buttons open a Save dialog; they did nothing before. That covers the onboarding PDF, the Settings export, Inspection reports, Fleet papers and CSV exports.',
      ],
    ),
    VersionRelease(
      version: '0.0.0348',
      date: '2 Oct 2026',
      items: [
        'Monitoring: a new Files tab lets a manager browse and download files on a monitored computer. Read-only, its own permission (monitoring.files.view), and every file opened is recorded.',
      ],
    ),
    VersionRelease(
      version: '0.0.0347',
      date: '1 Oct 2026',
      items: [
        'Scheduling › Availability: a zip search with a typo (too few or too many digits) now says it isn\'t a zip code, instead of searching the wrong zip or saying no area covers it. Zip+4 works.',
        'Roles & Permissions: each role can be given the roles its holders may invite.',
        'Human Resources, Records: the people list can be filtered by company or franchise.',
        'Scheduling: a new Locations tab lists every branch with its address, phone and service area, finds the closest branch to an address or zip, and shows each company\'s notes for new dispatchers (edited in Scheduling › Settings › Locations).',
        'Onboarding: an onboarding can be started under the organization or a company, which the contract names as the employer and where the new hire is assigned.',
        'Windows, Android, iPhone and Mac: also bring everything listed under 0.0.0346, which reached the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0346',
      date: '1 Oct 2026',
      items: [
        'Onboarding: the new hire fills in and signs the W-4 or W-9 inside the app.',
        'Inventory: Items, Orders and Locations open with the same row of tiles as Stock, and a tap on a tile shows just those.',
      ],
    ),
    VersionRelease(
      version: '0.0.0345',
      date: '1 Oct 2026',
      items: [
        'Onboarding: the employer can mark the new hire\'s questions as not applying, photos and documents included.',
        'An item opened on a wide screen has a back arrow, so you can return to the full Inventory list. The same arrow now appears in Services and Training.',
        'Live chat: each website\'s chat can have its own Send button, Start chat button and typing-border colours. In Leads, a photo a customer sent opens right in the conversation.',
        'Human Resources, Onboarding: finished onboardings have their own list, apart from those in progress.',
        'Fleet: a vehicle marks the details it should have but does not yet, and the list counts what each vehicle is missing.',
      ],
    ),
    VersionRelease(
      version: '0.0.0344',
      date: '1 Oct 2026',
      items: [
        'Uploading a file from an iPhone browser works; picking a photo did nothing before.',
      ],
    ),
    VersionRelease(
      version: '0.0.0343',
      date: '1 Oct 2026',
      items: [
        'Leads: Send now lines up with the reply box, and a new emoji button beside it opens a tray of emoji. On a phone the status buttons no longer break words in half.',
        'Reports: the Overbooking warning now shows in the Reports list and opens on the next 7 days.',
        'Windows, Android, iPhone and Mac: also bring everything listed under 0.0.0342, which reached the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0342',
      date: '1 Oct 2026',
      items: [
        'Onboarding: you can save your drawn signature and reuse it on the next contract. The contract preview now reads as the printed page, in light and dark mode.',
        'Work Hours: Add shift for HR, request hours for a day you didn\'t clock, and approved requests read Shift corrected.',
        'Human Resources, Records: the people list can be filtered by role.',
        'Monitoring, General: the ownership table scrolls down, so every row can be reached.',
      ],
    ),
    VersionRelease(
      version: '0.0.0341',
      date: '1 Oct 2026',
      items: [
        'Onboarding: the home address is filled in its parts, with address search. The contract can be seen as the PDF it will print as, stamped DRAFT. Each side draws their signature by hand when signing.',
        'Windows: before updating, the app closes any update installer left running from an earlier try, so one stuck update can no longer block every later one.',
        'Onboarding: ✗ on a term also voids the terms tied to it, the contract preview highlights what changed, and contracts are laid out like the A1 Tools agreements.',
        'Leads: bookings made through Google have their own tab and stay out of All.',
        'A website chat\'s ZIP code shows on the lead in Leads, ready for the availability check.',
        'Web Management: every colour setting has a colour picker; typing a colour code still works.',
      ],
    ),
    VersionRelease(
      version: '0.0.0340',
      date: '1 Oct 2026',
      items: [
        'Leads: every message shows the time it was sent.',
        'Work Hours: you can ask for a correction to the start of the shift you are in.',
        'Dashboard boxes with a chart no longer also say \'Nothing to show.\' underneath it.',
        'Dashboard: New customers counts the day someone became a customer, so the A1 Tools import no longer reads as tens of thousands of new customers this week.',
        'Inventory: Stock, Items and Locations read their totals from the server, so what a role may not see reads as unknown rather than zero.',
        'Work Hours: editing opens a side panel, and on a phone a person opens inside the tab.',
        'Website live chat asks for phone, email and ZIP code, so every chat lead in Leads can be called, emailed and placed.',
        'Onboarding: a term that may not apply has ✓ / ✗ beside it, and the state now comes before the county.',
        'Inventory: an open item shows its history, with what was received, issued, moved, counted and sold, when, and by whom.',
        'Windows, Android, iPhone and Mac: also bring everything listed under 0.0.0338 and 0.0.0339, which reached the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0339',
      date: '1 Oct 2026',
      items: [
        'Dashboard: on Day the line charts show the day hour by hour.',
      ],
    ),
    VersionRelease(
      version: '0.0.0338',
      date: '1 Oct 2026',
      items: [
        'Onboarding: an employer\'s term can be switched to Doesn\'t apply, and its clause leaves the contract.',
        'Dashboard: a Day period, and the dashboard opens on today until you pick another period.',
        'Training: Continue opens the guide you read most recently.',
      ],
    ),
    VersionRelease(
      version: '0.0.0337',
      date: '1 Oct 2026',
      items: [
        'Website live chat: visitors see who is answering (name and photo), each reply\'s sender, and \'is typing…\'; they can send a photo; the chat opens at one size; the greeting arrives in seconds. In Leads, a live chat shows when the visitor is typing.',
        'Leads: mark a conversation Completed when the customer needs nothing more; the red warning clears, and it reopens if they write again.',
        'Human Resources dashboard: people brought over from A1 Tools no longer count as joining this week.',
        'AI Assistance: topics down the left, the answer in the middle, and branches found by Ask on the right as cards with Call and Map; on a phone each step is its own page.',
        'Training: Learn opens on where you left off, with Assigned to you beside it, and the library shows each guide on one card with the test that follows it.',
        'Leads: scroll to the bottom of the list and older leads keep loading.',
        'Onboarding: a contract term can be marked as not applying, and its clause is left out of the contract.',
        'Inventory: Stock shows one row per item with its total, value and what is on order, with each place inside it. Four tiles filter it: all items, below reorder, out of stock, on order. Items, Orders and Locations are tables.',
        'Inventory no longer shows a zero for something your role is not allowed to see: on-hand without the Stock tab, and orders without the Orders tab.',
        'Work Hours: totals line up at the right.',
        'Clock In: clocking back in within 2 minutes continues the same shift instead of adding a row.',
        'Human Resources dashboard: six figures across the top (on the books, joined, left, onboarding, stalled invitations, certifications), joined and left over the last 12 months, where people work, who is on the clock or away right now, and named lists of who is waiting on someone and whose certifications are lapsing.',
        'Leads on a phone: the reply templates sit on one line that scrolls sideways instead of filling the screen.',
      ],
    ),
    VersionRelease(
      version: '0.0.0336',
      date: '1 Oct 2026',
      items: [
        'Onboarding: the employer signs their part first, and the new hire is emailed a link to finish theirs.',
        'Onboarding: the county search follows the chosen state, and the work schedule in a contract is saved to the person\'s profile.',
        'Onboarding: the contract now shows beside the form and changes as you type. Pay fields take numbers only. Governing state and county are search pickers, and the county list follows the state. The work schedule is set with a day-and-time picker. The business address has address search.',
        'Leads: phone numbers from Thumbtack and website forms look like Google\'s.',
        'Leads: leaving a lead clears your name from it straight away.',
        'Leads: a customer left waiting turns the row red, stronger the longer they wait.',
        'Emails: automation emails now arrive in EzBizi\'s colours, under your business\'s name, and say which automation sent them; the morning overbooking warning lists each day and area as a report. Notifications and reports share the new look.',
        'iPhone and Mac: also brings everything listed under 0.0.0335, which reached Windows, Android and the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0335',
      date: '1 Oct 2026',
      items: [
        'Field, Map: pick the day. Back and forward arrows, a date picker and Today change the jobs shown and the route you can replay. Search people by name. Company headquarters appear on the map with their logo, once a company has an address.',
      ],
    ),
    VersionRelease(
      version: '0.0.0334',
      date: '1 Oct 2026',
      items: [
        'iPhone and Mac: brings everything listed under 0.0.0332 and 0.0.0333, which reached Windows, Android and the web first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0333',
      date: '30 Sep 2026',
      items: [
        'Leads, Manage, Templates: redesigned, with companies, their templates and the open template side by side, Insert buttons for the placeholders, a live preview, drag to reorder, and copy to other companies.',
        'Leads, Manage: a new Dashboard tab shows leads, answer rate, first-reply time and booked, per day and per person.',
        'Leads, Manage: pause or resume the Google Local Services campaign of an account from its panel.',
        'Ads performance: a By channel table shows spend, clicks, conversions and cost per conversion for Local Services, Search (PPC) and the rest.',
      ],
    ),
    VersionRelease(
      version: '0.0.0332',
      date: '30 Sep 2026',
      items: [
        'Work Hours, Settings: extensions can be 5 or 10 minutes.',
        'Leads: search finds words inside messages and call transcripts, automations can send a morning digest, and the reply box checks spelling on phones.',
      ],
    ),
    VersionRelease(
      version: '0.0.0331',
      date: '30 Sep 2026',
      items: [
        'Leads: the Availability reply fills in the first open day for the ZIP of the customer.',
        'Onboarding: contracts now ask for a tax ID, bank details, a W-4 or W-9, the driver licence and a uniform photo, plus car photos for field roles.',
        'Work Hours: ask for a correction to one of your own shifts; whoever manages Work Hours approves or declines it, and you are told either way.',
        'Training: Fullscreen on a video fills the screen on Windows.',
      ],
    ),
    VersionRelease(
      version: '0.0.0330',
      date: '30 Sep 2026',
      items: [
        'Leads: the company shows on the lead itself, no longer on every row of the list.',
      ],
    ),
    VersionRelease(
      version: '0.0.0329',
      date: '30 Sep 2026',
      items: [
        'Leads on a phone: the details of a lead fold behind a Details button, so the conversation fills the screen.',
        'Leads: stars are now your own; reply templates fill in what the customer has not given yet, and can be edited per company.',
        'Field, Map: clear status colours. Technicians are green when reporting, blue at a job, red when stopped too long and yellow when not reporting; jobs are blue when assigned, yellow when unassigned, and white (or black in light mode) for the one you opened. The routes and the technician bar use the same colours.',
        'Leads: Manage now has Connections and Templates tabs, so reply templates can be edited in the app.',
        'Leads: every lead shows a tag with the company it belongs to, in the list and in the conversation.',
      ],
    ),
    VersionRelease(
      version: '0.0.0328',
      date: '30 Sep 2026',
      items: [
        'Training: guides look and work like A1 Tools: chapters, sections and topics, each topic with all its text, pictures, videos and tables.',
        'Training: topics unlock one at a time, and Complete and Continue opens the next.',
        'Training: videos play inside the app on desktop, phones and the web.',
      ],
    ),
    VersionRelease(
      version: '0.0.0327',
      date: '30 Sep 2026',
      items: [
        'Field, Map: a legend along the bottom explains every pin, job and line colour (a Legend button on phones), and the technician bar is colour-coded, with red for low battery, a phone gone quiet or poor GPS.',
        'Suggestions: say which sidebar tab a suggestion is about, and attach up to five screenshots, pictures, PDFs or .md and .txt notes.',
      ],
    ),
    VersionRelease(
      version: '0.0.0326',
      date: '30 Sep 2026',
      items: [
        'Field, Map: the technician bar shows the operating system version of the phone but no longer its model, and the iPhone app is back in step with the other platforms.',
      ],
    ),
    VersionRelease(
      version: '0.0.0325',
      date: '30 Sep 2026',
      items: [
        'Dial: recent calls are grouped by day and show a name when the number is a customer or a teammate. Repeated calls from one number fold into one row, Missed and Voicemail filter the list, and a missed call says when it was called back. The number box also takes a name and suggests customers and teammates. The keypad is larger, and the person panel shows the customer card over one timeline of calls and texts. On a phone, Dial opens on the calls, with the keypad a button away.',
        'Availability beside a lead or on a phone: the three choices read Zip, Area and Calendar.',
        'Notifications: each one shows the icon of the module it is about and when it happened.',
        'Field, Map: the jobs of the day appear as pins, with a searchable Jobs list by number or customer. Pick a job to see the nearest technicians by live drive time, and pick one for the route, the minutes and a sentence to copy for the caller. Tap a technician for battery, speed, miles, next job, device and app version.',
        'Human Resources, Records: deactivated people are now called inactive.',
        'Leads: the status of a lead is one control, Archive, Not a lead and Lead, with the current one highlighted; Call and Book sit apart from it.',
        'Clock In: the lock screen shows the EzBizi logo on a dark background, the time and the day, your photo and role, and the shift of the day in the clock format of your organization, with Clock In below. On a phone, Clock In sits at the bottom.',
        'Leads: replies an automation sent show as green bubbles, apart from your own and the red ones sent outside EzBizi.',
        'Lock screen: the date is the day of the organization, in its language.',
      ],
    ),
    VersionRelease(
      version: '0.0.0324',
      date: '30 Sep 2026',
      items: [
        'Automations: an automatic first reply only goes to an enquiry that just arrived, never to an older one brought in when an account is connected.',
      ],
    ),
    VersionRelease(
      version: '0.0.0323',
      date: '30 Sep 2026',
      items: [
        'Addresses: booking a lead, adding or editing a customer, the company form and the emergency address of a phone all suggest the address as you type and keep street, unit, city, state and ZIP; the customer made from a booked lead gets every part.',
        'Dates with a time: 16 typed on a 12-hour clock becomes 4 PM instead of being dropped, and an hour or minute no clock has says so.',
        'Invitations: the paperwork can start with the invite, switched on per person.',
        'Leads, Manage: a Connections page instead of the side panel - each provider with how many accounts need attention, their accounts in a table you can narrow to what needs attention or never sent a lead, and an account opened beside the list to name it, move its leads, read its certification or disconnect it.',
        'Onboarding: signing the contract fills in the start date, pay rate, work schedule and issued equipment of the person in Records.',
        'Leads on a phone: booking, a job, a customer and availability open in the side panel instead of a separate screen.',
        'Leads: reconnecting Thumbtack brings its earlier enquiries back straight away.',
        'Leads connections: an account that has stopped fetching says so.',
        'Training: a guide can hold its own images, not only links to other sites.',
      ],
    ),
    VersionRelease(
      version: '0.0.0322',
      date: '30 Sep 2026',
      items: [
        'Leads: a reply sent outside EzBizi, from A1 Tools or Google directly, now shows as a red bubble in the conversation.',
        'Onboarding: a new starter now sees Your paperwork and reaches their onboarding form; it did not appear before.',
        'Human Resources, Records: a missing emergency contact, pay rate or work schedule shows amber and counts toward what is missing; pay counts only for people allowed to see pay.',
        'Leads: call a customer straight from the lead, and put a ready reply in the box with one tap; the replies are set in Settings > Leads.',
      ],
    ),
    VersionRelease(
      version: '0.0.0321',
      date: '30 Sep 2026',
      items: [
        'Your account and Human Resources records: the home address is found with a lookup and kept in parts (street, unit, city, state, country and ZIP), phone numbers show formatted, and the profile fits a phone screen.',
        'Leads: the customer card on a lead is one line until opened, and a Thumbtack connection whose permission has lapsed now shows as broken on every row that used it.',
        'Automations: a new trigger, A lead needs its follow-up, fires once when a customer goes quiet after the automatic first reply, and a message can name the lead source with {{source_name}}.',
        'Leads, Thumbtack: the connection renews itself every day, so a quiet spell with no Thumbtack leads no longer lets it lapse.',
        'Connecting an account: while the sign-in is open, the button says Waiting for the sign-in instead of naming Google for every provider, and in the app the sign-in link can be copied.',
      ],
    ),
    VersionRelease(
      version: '0.0.0320',
      date: '30 Sep 2026',
      items: [
        'Reports: every saved report is listed, including the Work Hours weekly hours reports, with who each covers and the automation that sends it. A Work Hours report opens beside the list with its week of hours, and who is in it can be changed right there. The Send the work hours report step in an automation says who the chosen report covers.',
        'Your profile: while anything is missing, the picture in the top bar glows and pulses and its menu says what is missing; the empty boxes on your profile are amber until filled in. Gender and date of birth count, as they do in Human Resources.',
        'Work Hours: filter the people list by role.',
        'Human Resources, Records: the dates on a record are typed into boxes with the calendar inline instead of a pop-up, and date of birth and start date are amber while empty.',
      ],
    ),
    VersionRelease(
      version: '0.0.0319',
      date: '30 Sep 2026',
      items: [
        'Work Hours: the people list shows who clocked in this week, most recent first; everyone else is one tap away.',
      ],
    ),
    VersionRelease(
      version: '0.0.0318',
      date: '29 Sep 2026',
      items: [
        'Customers: a person the old Workiz jobs added a second time, with the same phone, name and postal code as a customer already on file, is merged into that customer, jobs included, in any branch.',
      ],
    ),
    VersionRelease(
      version: '0.0.0317',
      date: '29 Sep 2026',
      items: [
        'Settings, Roles: Scheduling and Work Hours list their tabs in the order their screens draw them, Overbooking and Reports before Settings.',
        'Reports: a role without the Reports tab no longer sees an empty library pane.',
        'Settings, Roles: Estimates now needs Jobs Financials as well, and the editor says so when it is missing, since the Estimates tab only shows with both.',
        'Jobs and Customers: the Workiz column shows only when the list holds a Workiz number, so an organization without Workiz sees no empty column.',
        'Settings, Roles: Audit log is no longer offered, since no screen reads it yet.',
        'Settings, Roles: the Newsletter tabs (Dashboard, Composer, Settings) and the Social Media tabs (Posts, Accounts) can be granted per role. Every role keeps the panes it had.',
        'Marketing, Newsletter: a newsletter can be deleted from the composer, with a second tap to confirm.',
        'Settings, Roles: the Communication tabs (Phone, Users, Records, Reports, Settings) can be granted per role, and without Settings or Reports their data is refused as well. Every role keeps the tabs it had.',
        'Settings: Organization, Settings and Integrations are each granted per role, and the rows a module keeps under Settings show only to roles holding the Settings tab of that module. Every role keeps what it saw.',
      ],
    ),
    VersionRelease(
      version: '0.0.0316',
      date: '29 Sep 2026',
      items: [
        'Jobs and Customers: a Workiz column beside the EzBizi number in both lists, and a job or customer panel shows its Workiz number under its name.',
        'Settings, Roles: the Leads box that covers the LS Call and LS Text chips is now named LS Call and LS Text, instead of Google.',
      ],
    ),
    VersionRelease(
      version: '0.0.0315',
      date: '29 Sep 2026',
      items: [
        'Jobs, Estimates: a Title column after the number; an estimate opens under its title, with its number and customer beneath, and the title can be typed when creating or editing one. The list is dated, and ordered, by when each estimate was made.',
      ],
    ),
    VersionRelease(
      version: '0.0.0314',
      date: '29 Sep 2026',
      items: [
        'Jobs: an Estimates tab beside Jobs, for roles with the Estimates permissions and Financials. Chips count each status, a line says what was quoted and accepted, and the search finds an estimate by number or customer. An estimate opens beside the list with its lines and total, and moves on from there: Mark sent, Mark accepted, Declined or Archive. New estimate writes a draft. On a phone an estimate opens in place.',
        'Communication, Dial pad: an Availability button opens availability in a side panel without leaving the call, on the zip of the caller when the caller is a customer on file.',
        'Website widgets: the form can take photos, show questions only when they apply, email each enquiry and stop robots; Contact Details can list more numbers with a chosen icon per section; Social Links can add any platform. Each is off until switched on.',
        'Scheduling, Overbooking: a Pick a date chip beside 7, 14, 30 and 60 days shows what is overbooked on that one day; clearing it goes back to the span.',
        'Customers: the Possible duplicates panel now says that only a customer with no jobs can be removed, instead of suggesting a removal EzBizi refuses.',
      ],
    ),
    VersionRelease(
      version: '0.0.0313',
      date: '29 Sep 2026',
      items: [
        'Jobs: estimates are now records in EzBizi, numbered EST-. They need the new Estimates permissions in Settings, Roles, plus the Jobs Financials toggle. The screens to see them are being designed.',
        'Communication, Dial pad: with no microphone connected, Call now says so (connect a headset or microphone, then press Call again) instead of doing nothing.',
        'Jobs: for roles with Financials, the list has money chips (Unpaid, Partly paid, Overdue, Paid, Credit) with their counts and a line of totals, each row shows its money state, and an open job shows its money first, live from Workiz.',
      ],
    ),
    VersionRelease(
      version: '0.0.0312',
      date: '29 Sep 2026',
      items: [
        'Scheduling: a new week of time slots can be scheduled to take over from a date, and two service areas can share one availability pool.',
        'Leads: the sources run down the side of the list, each with its unread count, so the conversation has the full height of the screen. On a phone, one button names the source and opens the list of sources.',
      ],
    ),
    VersionRelease(
      version: '0.0.0311',
      date: '29 Sep 2026',
      items: [
        'Scheduling: a new Overbooking tab lists the days booked past what an area or a franchise takes, and the areas with no spot left in the next 7 days. Each morning EzBizi can send a warning email when there are any.',
        'Leads: a message can be copied in one press, and part of it can be selected.',
        'Leads: All has a switch for each source, and leaves LS Call out until you switch it on.',
        'Automations: a rule can reply to a new Google text lead, so EzBizi can send the first reply instead of another tool.',
        'Jobs: job amounts and service lines now show only to administrators and to roles with the new Financials toggle, on the Jobs row in Settings, Roles. Every other role stops seeing them until you turn it on.',
        'Reports: a job Amount and Currency in a report also need the Financials toggle; without it the builder leaves them out.',
        'Web Management: saving the details of a website works again on sites with page text, and the hours of a site can be typed in another time zone.',
      ],
    ),
    VersionRelease(
      version: '0.0.0310',
      date: '29 Sep 2026',
      items: [
        'Leads: opening a lead shows its newest message, the way a chat does.',
        'Leads: you can see who else has a lead open, and every reply says who sent it.',
        'Leads and Human Resources: a list you drag wider or narrower stays that way after you leave the tab, and Leads opens with the list at 30 percent of the screen.',
      ],
    ),
    VersionRelease(
      version: '0.0.0309',
      date: '29 Sep 2026',
      items: [
        'The dashboard no longer shows any money: no revenue on Jobs and no stock value on Inventory. Stock value stays on the Inventory tab, and each job still shows its own amount.',
        'Human Resources, Records: new sections for pay rates, emergency contacts, notes and the equipment a person holds. Pay rates show only to administrators and to roles given See pay rates.',
        'Web Management: widget, list and SEO Helper changes also show on the website at once.',
      ],
    ),
    VersionRelease(
      version: '0.0.0308',
      date: '29 Sep 2026',
      items: [
        'Notifications: clicking a desktop notification opens what it is about.',
      ],
    ),
    VersionRelease(
      version: '0.0.0307',
      date: '29 Sep 2026',
      items: [
        'Human Resources, Records: drag the edge of the people list to resize it. Every empty personal box except middle name, and the start date, is amber and says Not filled in, and the list shows how many each person is missing.',
        'Leads: recorded calls are transcribed on the EzBizi server itself. The transcript shows right under the recording, four lines with Show all, and says Transcribing while it waits.',
        'Web Management: a change to hours or values made in EzBizi shows on the website at once, instead of after up to two hours.',
        'Web Management, SEO: in Edit hours on several websites, the websites are grouped by company, each group with its own Select all, and the review shows every website, those that change first, in green for what changes and red for what it replaces.',
      ],
    ),
    VersionRelease(
      version: '0.0.0306',
      date: '29 Sep 2026',
      items: [
        'Leads: every source has its own colour, the Google tag says LS Call, LS Text or LS Booking, and a lead shows its Google Ads account and Google status in colour. Leads from Workiz are no longer tagged Google.',
      ],
    ),
    VersionRelease(
      version: '0.0.0305',
      date: '29 Sep 2026',
      items: [
        'Leads: the address a customer wrote is checked with Google and shown with a verified mark, and its ZIP feeds Check availability.',
      ],
    ),
    VersionRelease(
      version: '0.0.0304',
      date: '28 Sep 2026',
      items: [
        'Customers: with a customer open beside the list, the Sort picker shows above the list, so it can be sorted without closing the record.',
        'Leads: Google leads are split into LS Call and LS Text, and Leads opens on All.',
        'Leads: a customer who has waited 10 minutes for a reply gets a red warning in the list, and Customer waiting for a reply, a new automation trigger with a template, can alert the dispatchers.',
        'Leads: the conversation shows a line where the day changes, reactions on the message they answer, who sent each reply, the Google Ads account, the Google status, and the address and ZIP the customer gave, with Check availability for that ZIP.',
        'Leads: Manage sits right after the connected sources, Mark all read is the last item of the filter chips, and a lead that is not New shows its status pill on its row.',
      ],
    ),
    VersionRelease(
      version: '0.0.0303',
      date: '28 Sep 2026',
      items: [
        'Web Management, SEO: the SEO Helper. For each page of a website, edit its search title, description, focus keyphrase and what shows when it is shared, and see checks on the live page, including pages that share a keyphrase, title or description with another. It replaces the page SEO editor of A1 Tools.',
        'Customers: on a phone, a Sort picker above the list sorts it the way the column headings do on a wider screen, for example Name, A to Z or Created, newest first.',
      ],
    ),
    VersionRelease(
      version: '0.0.0302',
      date: '28 Sep 2026',
      items: [
        'Customers: click a column heading to sort the whole list, and click again to reverse it. A new Created column shows when each customer was added to EzBizi; customers brought in from Workiz show the day of the import. The letter headings show only in name order.',
        'A1 Leads, Google reviews: click a column heading to sort, and again to reverse; a new Contact column shows the phone and email of the customer; at half a screen the table scrolls sideways instead of squeezing its columns.',
        'Leads: the status of a lead is a pill beside its name. A new lead offers Archive and Not a lead beside Book, and any other lead offers Back to New.',
      ],
    ),
    VersionRelease(
      version: '0.0.0301',
      date: '28 Sep 2026',
      items: [
        'Automations: a rule that runs on a schedule can be saved and edited again; opening it shows its schedule and time zone, where before the form had none and refused to save.',
      ],
    ),
    VersionRelease(
      version: '0.0.0300',
      date: '28 Sep 2026',
      items: [
        'Work Hours, Reports: a saved weekly hours report, with roles, people added or left out by name and recipients, previews last week or this week so far and sends by email with Send now. The email lists each person with their hours and a total.',
        'Automations: Send the work hours report, a new step, emails a saved weekly hours report; a new template sends it every Sunday at 8 PM.',
      ],
    ),
    VersionRelease(
      version: '0.0.0299',
      date: '28 Sep 2026',
      items: [
        'Web Management: the SEO Helper server side also groups pages that share a keyphrase, title or description, names the other pages behind a shared problem, and can fix a site title template stuck on an old year. Its screen follows.',
        'Leads: when the number of a lead already belongs to a customer, the list shows the customer name with a Customer badge, and the lead shows their address, their jobs and what those came to, with Open customer.',
        'Communication, Call flows: each menu has If nobody presses a key: wait 3 to 30 seconds, play the menu up to 6 times, then end the call or ring the line. The default is unchanged (5 seconds, once, then ring), and the call-flow chart shows the branch.',
      ],
    ),
    VersionRelease(
      version: '0.0.0298',
      date: '28 Sep 2026',
      items: [
        'Recordings: with no speakers or headphones connected, a recording waits and starts playing as soon as some are connected.',
        'Web Management, SEO: Edit hours on several websites opens inside the SEO tab instead of a separate page, and has Select all.',
        'Social Media: when a post fails on one account, Try again sends it to that account again.',
      ],
    ),
    VersionRelease(
      version: '0.0.0297',
      date: '28 Sep 2026',
      items: [
        'Web Management: the server side of the SEO Helper, which reads and writes the search title, description, keyphrase and social fields of each page through the site plugin (1.22.0) and checks the live page. Its screen follows.',
        'Social Media: Publish shows each account answering, then what happened to the post (posted, in drafts, scheduled, or failed with the network reason), with a link per network, See it on the calendar and Write another. Every post appears on the calendar on its day, and on a phone the calendar is an agenda.',
        'Every kind of button is the same height, so a main button lines up with the buttons beside it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0296',
      date: '28 Sep 2026',
      items: [
        'Web Management, SEO: Edit hours on several websites changes the opening hours of several websites at once, with a review before anything is written.',
        'Leads: a contact form sent on one of the websites arrives in Leads, once that site has plugin 1.21.0. The form still sends its own email, and no marketing consent is recorded from it.',
        'Automations: Customer replied, a new trigger, starts a rule when a customer writes again on a lead that already has a conversation; your own replies never set it off. A new template, Tell the office when a customer replies, uses it.',
        'Notifications: opening a lead notification takes you to that lead, not the newest one.',
      ],
    ),
    VersionRelease(
      version: '0.0.0295',
      date: '28 Sep 2026',
      items: [
        'Workiz: accounts with more than 100 upcoming jobs (Connecticut, Illinois, Ohio and New Jersey) now pick up changes and new bookings for the next days every minute; before, the sweep only read their farthest 100 jobs.',
        'Reports, Experimental: Jobs booked and the jobs of each person count every job for Workiz accounts with more than 100 jobs in the period; before, only the farthest 100 were read.',
        'Field, Map: a slim list over the map shows each tracked person with since when, miles today, speed, last seen and state; tap a person to see their route today. The map is quieter, follows light and dark mode, and refreshes every 30 seconds.',
        'Field: on Android, tracking starts even when Google Location Accuracy is turned off.',
        'Field, Map: on Android the map draws when it opens, instead of staying grey until you zoom.',
      ],
    ),
    VersionRelease(
      version: '0.0.0294',
      date: '28 Sep 2026',
      items: [
        'Leads, Accounts: a certification problem reads plainly, for example Background check: no submission.',
        'Leads: on a phone, the note under a conversation no longer sits under the gesture bar.',
        'On a phone, the steps of the automation builder and the contract designer put their buttons under the sentence, and Name and What it is for stack instead of cutting the label short.',
        'Leads: on a phone, the search, the sources and the filters scroll away with the list.',
        'Suggestions: the back arrow sits at the start of the line, and only shows when there is a list to go back to.',
        'Customers: the State and ZIP boxes line up.',
        'Automations: replacing your work with a template asks in place, with Keep mine and Use the template, instead of in a pop-up.',
        'AI Assistance: adding or editing an answer opens in a side panel instead of a pop-up.',
        'Dashboards: on a phone, the period chips fit on one line; the chosen one is shown by its fill.',
        'Monitoring: with no camera yet, the empty screen points to the Add camera button below it.',
        'Monitoring: its tabs are the same single scrolling row as every other module, and tabs a role does not grant are hidden instead of shown with a padlock. On a phone, the General ownership chart is a list instead of a table cut off at the edge.',
        'Communication, Calling out: No numbers yet reads as a next step, not an error.',
        'Web Management, SEO: its sections sit in one scrolling row, without a second headline.',
        'Your account: Password, Two-factor, Text me my codes and Theme no longer say Not given; they show the arrow that opens them.',
        'Workiz: every account syncs again. From 16:06 UTC today the sync stopped after three of the nine accounts, and jobs, customers and statuses from the other six did not arrive; they catch up with this release.',
        'Social Media, Accounts: on a phone, the reason a network is not ready shows in full.',
        'Newsletter: with no newsletter open, the composer no longer shows a stray Saved.',
      ],
    ),
    VersionRelease(
      version: '0.0.0293',
      date: '28 Sep 2026',
      items: [
        'Scheduling, Settings: each service area can have its own daily limit for each weekday. Time slots stay locked until you press Edit time slots, and removing one asks you to type REMOVE. The Overview shows the daily totals for everything in view.',
        'Settings, Notifications: Send email notifications is off unless an administrator turns it on. It was on for every organization; it is now off for all of them.',
        'Social Media, Accounts: someone who works for some branches only sees, manages and posts to the accounts of those branches and their companies, and the organization-wide ones.',
        'One module failing no longer blanks the whole home screen: its card says it could not load, and every other card still shows.',
        'Automations: Send a notification has no email switch any more, and a notification from an automation is never emailed. To email from a rule, add a Send an email step.',
        'On Android, Back returns from a record, a form or a settings section, instead of closing the app.',
        'On a phone, the fields of Add vehicle, Add service and the Inventory create forms no longer slide under their Save bar, date and time boxes fit with their labels, the address State box shows what is typed, and a person record in HR scrolls its header away with the form.',
        'Reports, Google Ads: each Local Services account shows its leads, cost per lead, missed calls, the time to a first reply, the jobs its leads were booked as, their revenue, and revenue per dollar spent.',
        'Leads: a lead is linked to the Workiz job its customer booked within 30 days, matched by phone number, and its thread shows Matched by phone.',
        'Automations: Ads stopped showing, a new trigger, starts a rule when a Local Services campaign that was showing stops, and says who paused it.',
        'Dashboard: the Google Ads box shows certification problems and stopped campaigns first, then the accounts in view.',
      ],
    ),
    VersionRelease(
      version: '0.0.0291',
      date: '28 Sep 2026',
      items: [
        'Leads: Messenger messages to a connected Facebook Page, and direct messages to a connected Instagram account, arrive in Leads. An account connected before this update needs connecting again, once.',
        'Social Media: a post published to Facebook, Instagram or Threads shows View post, which opens it there.',
        'Social Media, Accounts: Instagram comments, on a connected Page, lists the comments on its recent Instagram posts. You can reply as the business, or hide and show a comment.',
        'Connecting Facebook also offers the Pages owned by your Business portfolios.',
        'Leads, Accounts: each Google Ads account shows Certified, or Certification needs attention with each problem on its own line. Its licence, insurance and background check are read every morning, and a licence or insurance that expires within 30 days is listed too.',
        'Automations: Certification problem, a new trigger, starts a rule when the problems of a Google Ads account change.',
      ],
    ),
    VersionRelease(
      version: '0.0.0290',
      date: '28 Sep 2026',
      items: [
        'Leads: a lead from Workiz is dated when it came in, not by the date of its appointment.',
        'Notifications: the email copy of a notification is sent. From 16 Sep, 7,705 of them failed before sending; the bell in the app was not affected.',
        'On a phone, Settings and every Settings tab put each control under its label, instead of squeezing the label to one letter a line.',
        'Communication, Reports: a report by day, the default, loads instead of spinning forever.',
        'Customer Experience, Reviews: on a phone the filter bar wraps instead of running off the screen.',
        'Every kind of button and chip, and the Clock In button, now has the same rounded corner.',
        'On a phone, the top of Jobs, Customers, Fleet, every Inventory list, both Services lists and the Training dashboard scrolls away with the list, leaving more room for it.',
        'Customers: the two pickers above the list share the line, instead of cutting their text short.',
        'Scheduling: the three mode buttons show their icon above the words, all at one height.',
        'Dashboards: Refresh sits on the same line as the period chips, and a card title can take two lines.',
        'Automations: a rule name can take two lines.',
        'The menu rows no longer scroll up under the clock.',
        'Work Hours: with Clock in switched off, the screen says where it is switched on, instead of an error with Try again. Three sentences that still pointed at Settings, Human Resources now point at Work Hours, Settings.',
        'Automations: rule cards side by side are the same height, even when one name takes two lines.',
        'Scheduling, Availability: a day never offers more spots than it has left. Jobs booked past a time slot limit, and jobs with no time set, use up the spots of the day, and its last spots are offered in the earliest time slots.',
      ],
    ),
    VersionRelease(
      version: '0.0.0289',
      date: '28 Sep 2026',
      items: [
        'Web Management, SEO: a Customer review link field under General, for the reviews page of the site - the page to text a customer once a job is done. It is already filled in for the sites that have one.',
      ],
    ),
    VersionRelease(
      version: '0.0.0288',
      date: '28 Sep 2026',
      items: [
        'Jobs and customers imported from Workiz update every minute instead of every five.',
        'Workiz leads are imported every minute instead of every hour.',
        'Scheduling, Availability: an All areas button in Search by Area lists every area, and in the Calendar View shows the availability of all franchises added together.',
      ],
    ),
    VersionRelease(
      version: '0.0.0287',
      date: '28 Sep 2026',
      items: [
        'Scheduling: when a job is booked in Workiz at a zip code its service area does not have yet, EzBizi adds the zip to that area within five minutes. It never moves a zip that is already on another area and never creates a new area.',
      ],
    ),
    VersionRelease(
      version: '0.0.0286',
      date: '28 Sep 2026',
      items: [
        'Google call recordings on Leads now play in the Windows app.',
        'A1Leads GBP: click any review to open it in a side panel with its history and actions, and change the status of a review straight from the table.',
      ],
    ),
    VersionRelease(
      version: '0.0.0285',
      date: '28 Sep 2026',
      items: [
        'New Google Local Services leads and messages arrive within a minute, from every account.',
      ],
    ),
    VersionRelease(
      version: '0.0.0284',
      date: '28 Sep 2026',
      items: [
        'Field: administrators of an organization are tracked like any tracked role, within the same window - while clocked in, during scheduled hours, or always.',
      ],
    ),
    VersionRelease(
      version: '0.0.0283',
      date: '25 Sep 2026',
      items: [
        'Scheduling, Availability, Calendar View: the bar for each day matches the spots left shown above it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0282',
      date: '25 Sep 2026',
      items: [
        'Web Management, Widgets: the City list has a heading level and an Other label, and the consent wording on a form can be up to 1,000 characters.',
      ],
    ),
    VersionRelease(
      version: '0.0.0281',
      date: '25 Sep 2026',
      items: [
        'The incoming-call card can be dragged out of the way, no longer darkens the screen behind it, and is switched on or off for the whole company in Settings, Communication. The personal switch on the Dial pad is gone, so a card someone had turned off shows again unless the company turns it off.',
        'Scheduling, Availability has a Calendar View: pick a franchise or one of its areas to see the month with the spots left each day (green, amber, red), closed days, and days booked over what they take. In Settings, the weekday row of the Overview stays at the top while you scroll.',
      ],
    ),
    VersionRelease(
      version: '0.0.0280',
      date: '25 Sep 2026',
      items: [
        'Web Management, Widgets: Services, Reviews, Before and After, and FAQ, each with its own list and a website widget.',
        'Reviews and Business Profile have moved out of Marketing into a new Customer Experience tab. Nothing about either changes except where it is drawn, and who can see them is unchanged.',
        'Web Management, Widgets: Phone, Opening hours, Map, Page title and Live Chat have the full set of options.',
      ],
    ),
    VersionRelease(
      version: '0.0.0279',
      date: '25 Sep 2026',
      items: [
        'Scheduling Settings, Availability: the Overview groups areas by franchise, with the daily totals of each franchise, and flags areas that have no capacity yet. Tap a franchise name in the list to see that franchise alone, with its daily totals at the top.',
        'On Android, refusing the permission to make calls now refuses the call and says why, instead of asking again, showing an error or pretending a call is on.',
        'Web Management: the Contact form widget has the appointment form A1 Tools had, saving a form no longer wipes the choices of a pick-one question, the Call to action can show the number, address or link itself, and SEO has a Google review link field that the Review faces happy face uses.',
        'Field: SOS from a computer or the web sends without a location. Only the Android and iPhone apps ask for one.',
        'Scheduling: each franchise can have a daily limit, set from its Overview under the daily totals. Once that many jobs are booked for the franchise that day, Availability stops offering the day in all its areas, whatever an area has left, and the tiles show the limit as max N.',
      ],
    ),
    VersionRelease(
      version: '0.0.0278',
      date: '25 Sep 2026',
      items: [
        'New: Field, a tab where managers see where their field team is, keep them safe and plan their day. Dashboard shows who is reporting, anyone at a job, stopped too long or out of signal, and alerts to acknowledge; tracked staff get an SOS button that alerts their managers with their location. Map shows the latest position of each person and replays a route for any day in the last 90 days.',
        'Field, Route: the jobs of a technician for the day in the fastest order from where they are, with drive times and a warning where they would be late. Navigate opens Google Maps, and booked times are never changed.',
        'Field, Settings: when phones share their location - while clocked in (the default), during scheduled hours, or always - and the arrival distance, stopped-too-long time and lost-signal time. Automations gain five triggers: arrived at a job, left a job, stopped too long, lost signal and SOS.',
        'Nobody is tracked until an administrator grants Field, Tracked to a role in Roles, and administrators themselves are never tracked. Tracking runs on the Android and iOS apps only, and the phone always shows when it is sharing.',
        'A1Leads GBP shows the Google reviews of A-1 Chimney live from A1 Leads, within a minute, instead of the snapshot from the day before: new reviews, replies, card changes and removals show up as they happen.',
        'Web Management, Widgets: the Contact details, City list and Social links widgets have the sections A1 Tools drew and more options, shown on the sites once the next EzBizi website plugin update reaches them.',
        'On Android, pressing Call places the call - it had been silently doing nothing, because the app never asked for permission to make calls. It asks now, and a call that is refused no longer takes the person out of their ring group.',
      ],
    ),
    VersionRelease(
      version: '0.0.0277',
      date: '25 Sep 2026',
      items: [
        'Customer match on A1Leads GBP lists the Workiz jobs A1 Leads finds for your search; before, it showed no suggestion on every review. It asks for a name, phone or address to search by.',
        'Scheduling, Schedule: tap a job to assign a technician, unassign one, or move it to another day and time slot. Changes go straight to Workiz, and only technicians who cover the service area of the job in Workiz are offered. Dispatching needs the Scheduling Edit permission.',
        'Web Management, Widgets: each part of a widget has its own style options - font, colour, background, border, corners, spacing and hover colours - and the Call to action widget has the full set of options. The websites show them once the next EzBizi website plugin update reaches them.',
      ],
    ),
    VersionRelease(
      version: '0.0.0276',
      date: '25 Sep 2026',
      items: [
        'Scheduling Settings, Availability: the week is one compact grid - a row per day, the time slots as columns and the total for the day first - and every row and daily-total tile is the same size.',
      ],
    ),
    VersionRelease(
      version: '0.0.0275',
      date: '25 Sep 2026',
      items: [
        'Web Management, Widgets: a widget keeps its company-wide preset but can change any option for itself, in its shortcode or under This widget only in Elementor. It needs version 1.16.0 of the EzBizi website plugin, which rolls out separately.',
        'Availability counts booked jobs by the service area EzBizi placed them in, so a job Workiz filed under an old area name, or under none, still takes its spot in the right area.',
        'A1Leads GBP does what A1 Tools did on a review: Draft with AI in the reply editor, and on each card set its status, add a note, pick the right Workiz customer, or report it to Google. Replying needs the Reply permission; the card actions need the new A1Leads GBP Manage permission.',
      ],
    ),
    VersionRelease(
      version: '0.0.0274',
      date: '25 Sep 2026',
      items: [
        'Scheduling Settings has two sub-tabs. Availability shows the daily totals of each area at the top, updating as you type, with the total of the whole franchise for each day. Schedule lists every Workiz technician, so each can be linked to their EzBizi user.',
        'In Reports, Days until next free spot counts from tomorrow in the organization time zone, as Availability does, since today cannot be booked.',
        'Web Management has Team, Branches and Store locator cards, holding the lists A1 Tools used to keep for the websites. The website plugin that draws them, with the Street View, gallery and video carousel, rolls out to the sites separately.',
        'Every new Google review of A-1 Chimney arrives in Automations as New Google review, once, so a rule can act on it. A new dashboard box counts them, and the ones with 1 to 3 stars.',
        'Jobs shows where each job is: a Where column and one Where picker for franchise and service area, with Not placed for jobs no area holds. A job record has a Service area field, limited to the areas of its own franchise, and phone rows show the area.',
        'An automation can answer a new A-1 Chimney Google review with a Reply on Google step, and there is a template to start from.',
        'Scheduling Settings, Availability: type the total for a day in the new Day box and it splits across the time slots of that day, earliest first (10 becomes 4, 3, 3). Each slot can still be set by hand.',
      ],
    ),
    VersionRelease(
      version: '0.0.0273',
      date: '25 Sep 2026',
      items: [
        'Reports has a service-area column, Days until next free spot. Filter it at a number of days and an automation can warn when an area is booked out that far ahead; an area that is closed or not set up stays blank.',
        'Web Management: a page has a Street View link field, for the Google Maps embed of the place a location page is about. The website plugin that draws it, with a photo gallery and a video carousel like the A1 Tools ones, rolls out to the sites separately.',
      ],
    ),
    VersionRelease(
      version: '0.0.0272',
      date: '25 Sep 2026',
      items: [
        'Web Management, SEO: a field that publishes nothing is amber and says Not filled in, labels sit above the boxes so an empty box no longer looks filled, and each website in the list shows how many fields it is missing.',
        'In Reports, Days until next open day counts closed dates and each area\'s date before which it takes no bookings, not only the weekly pattern.',
        'Replying to A-1 Chimney\'s Google reviews needs the A1Leads GBP Reply permission, ticked in Roles. Seeing the tab no longer includes replying.',
        'A review\'s card shows the Workiz customer A1 Leads matched, their last job and technician, a link to it in Workiz, who has the card, and its updates.',
      ],
    ),
    VersionRelease(
      version: '0.0.0271',
      date: '25 Sep 2026',
      items: [
        'Scheduling Settings can close a particular date or change how many jobs it takes, and set a date before which a service area takes no bookings. Availability follows both.',
        'Scheduling, Schedule shows the day as a board: technicians down the side, time slots across, each job in the cell of the technician on it, and the unassigned jobs beside it. It is read-only; jobs are still assigned in Workiz.',
      ],
    ),
    VersionRelease(
      version: '0.0.0270',
      date: '25 Sep 2026',
      items: [
        'A zip code belongs to one service area across the whole organization, so a zip search has one answer. Scheduling Settings names the area that already holds a zip when you try to add it to another.',
        'Scheduling, Availability is redrawn the way A1 Tools shows it. Search by zip code or pick a franchise, and each area shows its first available day and the five after it, every time slot coloured by the spots left - green, yellow, red, or grey when full. Spots left count real bookings; canceled jobs and jobs with a deposit received do not take one.',
      ],
    ),
    VersionRelease(
      version: '0.0.0269',
      date: '25 Sep 2026',
      items: [
        'AI Assistance has an Ask field, and the WhatsApp staff bot answers what is typed to it: a question is matched against the Library answers that person may read. One clear answer is sent, several come as a list to pick from, and otherwise it looks for a location as before.',
      ],
    ),
    VersionRelease(
      version: '0.0.0268',
      date: '24 Sep 2026',
      items: [
        'The 432 jobs and 500 customers that the Workiz import had created twice are gone, so no A-1 Chimney job shows twice. Revenue never counted them twice.',
        'Every imported Workiz job is now checked against Workiz again over time, closed ones included, so a job whose status changed there long ago catches up here too.',
        'A job moved out of the statuses that mean the money is earned stops counting as revenue, so a past month can go down. A job that returns to one counts from the day it returned.',
      ],
    ),
    VersionRelease(
      version: '0.0.0267',
      date: '24 Sep 2026',
      items: [
        'Automations that run when a job changes status, or is completed, now also run when Workiz moves an imported job - for moves Workiz made in the last two hours, so older history sets nothing off.',
        'Replying from the A1Leads GBP table opens the reply under the review instead of switching to the board.',
        'AI Assistance is a new item in the sidebar. AI shows the WhatsApp staff bot\'s menu and its location search; Library holds the answers the bot gives, which you can add to and edit, each for everyone or for staff only; Settings holds the bot\'s name and welcome. Administrators have it, and other roles can be given it in Roles.',
        'Two Workiz imports running at once can no longer create the same job or customer twice.',
        'Jobs is a table under day headings - Today, Tomorrow, Yesterday and the weekdays - each with the day\'s count. It opens on Upcoming, with Past and All beside it, and the status chips count within that window; Canceled is red. Click a job and the table becomes a list with the job open beside it. Amounts read like \$289.00, and a job with no lines shows a dash instead of USD 0.00.',
        'Customers is the same kind of table under letter headings, with a City column and chips for repeat customers and for customers with no jobs yet. A customer\'s record lists their jobs.',
        'A customer\'s record names the company beside the franchise, as the list row that opened it does.',
        '148 jobs that were in Workiz but never reached EzBizi arrive with the next imports, with their customers.',
      ],
    ),
    VersionRelease(
      version: '0.0.0265',
      date: '24 Sep 2026',
      items: [
        'An automation can send its email, text or WhatsApp to The customer it is about - the customer of the job the rule fired on, looked up when it runs.',
        'The Jobs list loads faster: it no longer fetches the details of every job on the page, only of the one you open.',
        'Web Management, Widgets: Review faces. A happy face sends the visitor to your review link, and a sad one opens a short form whose message lands in Leads rather than on Google. The wording, the background it sits on and the face size are set per preset. It shows on sites with version 1.15.0 of the EzBizi website plugin.',
        'A job with a date can be saved from its panel again. Save was refused with "The scheduled for field must be a valid date", and Scheduled for showed empty, on almost every job. It is also what still stopped the imported jobs that 0.0.0251 said could be saved.',
      ],
    ),
    VersionRelease(
      version: '0.0.0264',
      date: '24 Sep 2026',
      items: [
        'Replying to A-1 Chimney\'s Google reviews is on: anyone who can see A1Leads GBP, with A-1 Chimney in view, can post a public reply, and every attempt is recorded in the audit log.',
        'Jobs imported from Workiz follow Workiz: a job that is done, canceled or in progress there reads the same here, and so do its date and price - unless somebody changed that field in EzBizi, which stays theirs. Past jobs catch up over the next few hours.',
      ],
    ),
    VersionRelease(
      version: '0.0.0263',
      date: '24 Sep 2026',
      items: [
        'HR, Records: pick a person from the list and their record opens beside it, in sections - Personal, Employment, Work, Onboarding, Time off, Training and Access. HR can now edit the name, phone, home address, gender, date of birth and start date. Email, onboarding answers and time off stay read only.',
        'Work Hours is its own item in the sidebar, beside Human Resources, with three tabs: General (what was Work Hours in HR), Paid Time Off (moved from HR) and Settings. Roles that could see those in HR keep the same access in Work Hours.',
        'From a person\'s week in Work Hours, whoever may manage Work Hours can set their work schedule.',
        'The dashboard can show a Work Hours box: who is clocked in now, and the hours clocked in the period.',
        'A1Leads GBP shows A-1 Chimney\'s reviews only while A-1 Chimney is in view - the whole organization, A-1 Chimney or one of its franchises. With another company in view it says whose reviews these are. It opens on the table; a phone still opens on the board.',
      ],
    ),
    VersionRelease(
      version: '0.0.0262',
      date: '24 Sep 2026',
      items: [
        'Scheduling Settings has a Time slots section. Set the windows your business books into once, and every service area uses them. Each area sets how many jobs a window takes on each day.',
        'Automations has sixteen more templates, across leads, jobs, phone, reviews, the team, social posts, the website and computers. The template list has a search bar, which also matches where a template runs.',
      ],
    ),
    VersionRelease(
      version: '0.0.0260',
      date: '24 Sep 2026',
      items: [
        'A1Leads GBP says why there is no Reply button: replying from EzBizi is not switched on yet, or A-1 Chimney is not the company in view.',
        'HR, Work Hours: every shift has a menu to edit its times or delete it, with Clock out now on a shift still open, and every day one to set a tag, add extra hours or write a note. Shifts show when HR edited or ended them. It is there for whoever may correct hours.',
        'After the end of the working day, the lock screen says when the day ended and how many minutes clocking in adds.',
        'In every form, an empty field now looks different from a filled one: the prompt is paler, and the label of a filled field sits small and bold above the value. Dark mode too.',
      ],
    ),
    VersionRelease(
      version: '0.0.0259',
      date: '24 Sep 2026',
      items: [
        'Profit and Loss opens on the first Airtable base that has weekly figures, instead of one that is blank. A table without weeks says so instead of showing nothing.',
        'Automations have an Add to a mailing list step. It puts somebody who enquired on your website on one of your Mailchimp lists, but only if they ticked the box agreeing to marketing email. The box arrives with version 1.14.0 of the EzBizi website plugin.',
      ],
    ),
    VersionRelease(
      version: '0.0.0258',
      date: '24 Sep 2026',
      items: [
        'Settings: Statuses that mean the money is earned opens a list of your own job statuses to tick, instead of a box to type code into.',
        'A job has an Inspections section listing the inspection reports submitted on it, with what was inspected and when. It shows only to people who can see Inspection, and leaves out archived reports.',
      ],
    ),
    VersionRelease(
      version: '0.0.0257',
      date: '24 Sep 2026',
      items: [
        'Google Ads opens on today\'s figures. The other ranges still end yesterday. Custom opens with today already picked.',
        'The Reply permission that 0.0.0255 added under A1Leads GBP is no longer offered on Roles. Answering A-1 Chimney reviews will need only what seeing A1Leads GBP needs, with A-1 Chimney as the company in view. Replying is still not switched on.',
      ],
    ),
    VersionRelease(
      version: '0.0.0256',
      date: '24 Sep 2026',
      items: [
        'Calling a colleague from the Dial pad rings them again - it had been hanging up after a few seconds.',
        'On the call report, Reached nobody opens onto the calls behind it, which you can filter by why: missed, voicemail or spam. It now counts every call nobody answered, so Answered and Reached nobody add up to the total.',
        'The WhatsApp template picker shows each template as its own entry, instead of one unbroken wall of text.',
        'A WhatsApp message that was delivered no longer flips back to undelivered when a late status update arrives out of order.',
        'On Google Ads, the campaign table keeps its header in view while the rows scroll, and the header follows when the table scrolls sideways.',
      ],
    ),
    VersionRelease(
      version: '0.0.0255',
      date: '24 Sep 2026',
      items: [
        'A follow-up job booked by an automation in the evening no longer lands a day late.',
        'Roles has a Reply permission under A1Leads GBP, for answering reviews of A-1 Chimney. Replying is not switched on yet, so the tick does nothing until it is.',
      ],
    ),
    VersionRelease(
      version: '0.0.0254',
      date: '24 Sep 2026',
      items: [
        'Monitoring follows the company you are standing in. The ownership chart, the Map, PC Specs and Desktop Monitoring show what is placed in the company or franchise you have switched to, and the group view still shows everyone. Opening one particular thing - a live view, remote control, a single plan - still works after you switch.',
        'An automation can reply to a Thumbtack, Facebook or Instagram lead, not only to a website chat. Google Local Services leads stay manual, because EzBizi has told Google in writing that a person types every reply there.',
        'Five new automation actions: notify the administrators, hand a customer to another franchise, book a follow-up job, draft a reply to a review, and open the onboarding for somebody you invited. A drafted review reply waits for a person - a rule never publishes one, and never overwrites one somebody wrote. The builder no longer lists anything it cannot run, so Not built yet is gone.',
        'Google Ads can pause and enable campaigns, for anybody holding the new Manage permission on Google Ads, which only administrators have until a role is ticked. There is a Columns menu with fourteen more figures to choose from, and the page comes back straight away with what you last saw instead of reloading.',
        'A1Leads GBP shows the Status and Notes kept on the A-1 bad-review board - as columns in the table, and under each review on the board.',
      ],
    ),
    VersionRelease(
      version: '0.0.0253',
      date: '24 Sep 2026',
      items: [
        'Clocking in after your working day has ended - which is what the lock screen button does if you press it at 5 PM to get your computer back - now gives a short extension, 30 minutes unless your organization chose another length, instead of a shift that stays open until the 16 hour limit. The reminder still warns you and Extend still works.',
        'Work Hours can be granted as a person seeing only their own hours. A new tick in Roles, See everyone\'s hours, is what shows the whole team. Every role that could see Work Hours before this still sees everyone, so nobody loses anything today.',
        'Eleven automation actions that were greyed out as Not built yet can be chosen. They were built and running; the builder was reading a list that did not mention them. Send a report and Assign to a person also show their own fields now, rather than a Message box that was never what got saved.',
        'The automation pickers show Marketing as one category. Newsletter, Web Management, Reviews and Business Profile were each their own heading, split the way permissions are rather than the way the Marketing screen is laid out.',
      ],
    ),
    VersionRelease(
      version: '0.0.0252',
      date: '23 Sep 2026',
      items: [
        'Customer phone numbers read as phone numbers again. Everything imported from Workiz was stored as ten bare digits, so a customer showed 4242095909 rather than +1 (424) 209-5909 - and because the whole app keys on the full form, those numbers were also the wrong thing to call or text. The 617 already in the book have been corrected.',
        'Settings has an Extensions tab, so an extension can be assigned to a person, a ring group or an IVR menu. A caller can now type the extension they want, which the prompt had been promising for weeks.',
        'A1Leads GBP has A1 Tools Board and Table toggle, and the table carries the columns that can be filled honestly: the matched customer with the city under it, the review at three lines, date, rating and reply.',
      ],
    ),
    VersionRelease(
      version: '0.0.0251',
      date: '23 Sep 2026',
      items: [
        'You can save an imported job again. The job screen sends the status with every save, so changing a date came back refusing the status - a field you had not touched and could not see was the problem. A status is only checked when you actually change it now. On this account 550 of 575 jobs were affected.',
        'A picture in an email that cannot be decoded now says so where it sits, instead of breaking the rest of the message. One whose download comes back empty stops spinning rather than waiting for ever.',
      ],
    ),
    VersionRelease(
      version: '0.0.0250',
      date: '23 Sep 2026',
      items: [
        'Your Workiz customers and jobs appear in EzBizi. They arrive on their own every few minutes. One thing to know before you try to edit one: a job keeps the status Workiz gave it, and until your job statuses in Settings include those words a Workiz job can be read but not saved.',
        'Turning the live chat off now covers the whole business. The switch was per website, and a company with more than one site kept answering on the others, so the chat you switched off went on collecting. There are buttons to turn it off or on across every site at once, and one to rebuild them all.',
        'A lead from a website names the website it came from, rather than only the business. Where it used to say Website chat and the company name it now says the address a visitor was actually on.',
        'A1Leads GBP has the Google Reviews tab brought over, read only, with the same filters in the same order and opening on the negative ones. The location filter lists the cities the service can actually see.',
        'A job shows what Inventory issued against it, once anything has been. This arrived in version 0.0.0246 without a note, and nothing has been issued against a job yet, so today every job says so.',
      ],
    ),
    VersionRelease(
      version: '0.0.0249',
      date: '23 Sep 2026',
      items: [
        'Drawing a wall on the Map works after you have zoomed or panned. Every tap was landing on the cell that had been in that spot before you moved, and the map was being stretched out of square. A wall can also be dragged by the handle at its middle, and keeps its length when you push it into a corner.',
        'New Workiz jobs arrive in Leads from now on. Your Workiz history stays where it is - the first run for a branch records where now is rather than pulling years of old records into EzBizi.',
      ],
    ),
    VersionRelease(
      version: '0.0.0248',
      date: '23 Sep 2026',
      items: [
        'Workiz is a lead source in Leads. It is a book of records rather than a channel somebody wrote in, so a Workiz lead has no thread to reply to and does not appear under Unreplied.',
        'A report on service areas can use three new columns: open days a week, jobs a week, and days until the next open day. The last one takes the window you want in the filter, so one column answers however far ahead you are looking.',
      ],
    ),
    VersionRelease(
      version: '0.0.0247',
      date: '23 Sep 2026',
      items: [
        'Reviews and Business Profile are back under Marketing, where they were. Version 0.0.0245 said they had moved to a tab of their own; that was a mistake and this undoes it. The new A1Leads GBP tab is a separate system and EzBizi own Reviews and Business Profile are unchanged.',
        'A1Leads GBP shows A-1 Chimney real Google Business Profile, read from the A1 Leads system. It is a snapshot taken once a day rather than a live view, so every pane names the date of the snapshot it is drawing instead of leaving you to assume it is current.',
      ],
    ),
    VersionRelease(
      version: '0.0.0246',
      date: '23 Sep 2026',
      items: [
        'Revenue counts a job in the period the money was earned rather than the period the job was booked, so a job booked in June and finished in September now counts in September. A closed month also stops changing every time somebody moves an old job along.',
        'A Possible duplicates button on Customers finds people already in the book who look like the same person. The check that runs while you type can only see the one you are writing, so an import that writes thousands nobody typed was invisible to it. The panel says how many groups there are and not only how many it is showing.',
        'Guides and tests can be deleted from the Training settings screen. It asks first, in the row itself, and says what goes with them. Deleting is refused with a reason if the guide or test belongs to a company or franchise you cannot write to.',
        'A person with no desk can be given one straight from the ownership chart - tap the empty Desk cell and pick from the desks nobody is sitting at.',
        'The Assignment column names somebody assigned at the organisation level instead of leaving the cell blank as though they had not been assigned at all.',
        'The Map opens showing the whole floor plan instead of the top corner of it, and has zoom in, zoom out and Fit.',
      ],
    ),
    VersionRelease(
      version: '0.0.0245',
      date: '23 Sep 2026',
      items: [
        'The widget cards under Web Management are the same height as well as the same width, so the status line on each one lands on the same level across a row. The height follows the system text size rather than being a fixed number, so it still holds when that is turned up.',
        'Each step on a call flow is a link to the screen that changes it, so you can go from the chart straight to the setting it describes.',
        'A shift always gets an end now, from the schedule or from a new limit in Settings that starts at 16 hours. Seven shifts had been left open across two businesses, the oldest since 9 September, and nothing that existed was ever going to close them. They close at that limit rather than at the running total they had reached.',
        'Reviews and Business Profile have a tab of their own instead of sitting inside Marketing. Nothing about either one changes except where it is drawn.',
      ],
    ),
    VersionRelease(
      version: '0.0.0244',
      date: '23 Sep 2026',
      items: [
        'Web Management, Widgets is a card for each widget, showing what it is set to right now - On during set hours, 2 questions, Default look - and opening one gives you that widget fields and preview on their own. Four of them had no preview at all before.',
        'Integrations connects accounts as well as listing them. Three of the four families answered with a sentence naming the module that owns the account, so you had to leave the screen to act on what it had just told you.',
        'Opening an integration keeps the sidebar and the header instead of filling the window with a page of its own. Every card did that, because every card went through the same tap.',
        'The ownership chart shows work days and shift as two short columns, Mon - Fri and 8:00 AM - 5:00 PM, instead of printing the whole week. Where the working days do not share one set of hours it says Varies rather than picking one.',
        'A desk can be renamed from the ownership chart, by anyone who may edit the map.',
      ],
    ),
    VersionRelease(
      version: '0.0.0243',
      date: '23 Sep 2026',
      items: [
        'The Newsletter composer shows the email beside the editor again. The preview was coming back from the server correctly and the panel was not giving it any room to draw in.',
      ],
    ),
    VersionRelease(
      version: '0.0.0242',
      date: '22 Sep 2026',
      items: [
        'Extensions now begin with a zero, and the nine that already existed were moved to match -- 101 became 0101, keeping each person their own number rather than renumbering anybody. A menu offers 1 to 9, so an extension starting with one of those digits cannot be told from a menu choice at the instant a caller presses it.',
        'A saved report emailed out says which business it is from. It named the rule that sent it and never the business.',
        'Integrations shows a card for what your business actually uses. Fifteen providers drew fifteen cards and thirteen of them offered to set up something nobody here had connected, so Mailing, Social and Advertising now carry only what is connected, each keeping the sentence that says where to connect more. Reporting keeps all of its cards, because there the card is the only way in.',
        'Facebook Messenger and Instagram arrive in Leads as sources of their own, beside Google, Thumbtack, Chat and Forms, and you answer them from the same thread as everything else.',
        'A window for an incoming call, naming the customer when the number is one of yours and showing the number when it is not, with Accept and Ignore. It is off until you switch it on, from the Dial pad: the strip stays the default, so a ringing phone does not take the app away from whatever you were in the middle of writing.',
        'Monitoring: General is the ownership chart, and the floor plan map has a tab of its own.',
        'Settings, Mail server for sending, no longer says your saved reports and automations go out through a server you connect there. They go out from EzBizi, and have since 16 September. The screen was still describing the week it was built, and it was asking for a password on the strength of it.',
        'The version panel lists 0.0.0240 again. That release shipped with no note of its own, so the panel drew the 0.0.0239 notes under a 0.0.0240 heading.',
        'Integrations reads properly on a narrow phone screen, and the green that means success is one green across the app rather than three.',
      ],
    ),
    VersionRelease(
      version: '0.0.0241',
      date: '22 Sep 2026',
      items: [
        'Integrations is a card for each provider, each with its own screen, and every card says which part of the business the accounts on it belong to.',
        'Automations are cards laid out across the width instead of one full-width row each, so you can see more rules at once, and the template picker draws each template as a card naming the trigger it runs on.',
      ],
    ),
    VersionRelease(
      version: '0.0.0240',
      date: '18 Sep 2026',
      items: [
        'The live chat has an on and off switch per website, under Web Management, and it starts on. Turning it off leaves the chat out of what the website is sent, so it works on whichever version of the plugin a site happens to be running.',
        'A Cities pane for website content, and five more widgets a website can draw from EzBizi.',
        'Our privacy page had said EzBizi never carries mail your business writes to its own customers. Three automation actions do, so the page now says which and when.',
      ],
    ),
    VersionRelease(
      version: '0.0.0239',
      date: '17 Sep 2026',
      items: [
        'The chat bubble is on a website only while somebody is there to answer it. Set the hours per site under Web Management, and the page decides in the reader own browser, so a cached page cannot show the bubble at midnight.',
        'The phone stays registered on a handset too. The desktop fix went out last release; a phone gets no expiry warning from the network at all, so it now renews on the clock instead.',
        'Monitoring tabs are granted one at a time too: the floor plan, PC specs, cameras and desktop screenshots are four separate ticks on a role. Everybody keeps the tabs they could already open, and a tab you may not open stays visible and locked rather than vanishing, so you can tell a missing permission from a missing feature.',
      ],
    ),
    VersionRelease(
      version: '0.0.0238',
      date: '17 Sep 2026',
      items: [
        'Typing into a website form question keeps the letters in that question. Every keystroke used to rebuild the box, so the rest of what you typed landed in another field.',
        'Every popup dropdown in the app is now a list you can search. On short lists it still just opens, so nothing asks you to type where you could point.',
        'Inspections now have a Flow view beside the phone preview in the Builder, drawn the same way as the call flow chart. It shows the order a technician walks the screens in, and marks any screen that can never be shown - a condition asking about an answer that has not been given yet looks identical to a working one in the list.',
        'Paid time off: the allowance and the waiting period can each be set by day, week, month or year, instead of only in days a year.',
        'A role can be given single tabs inside a module rather than the whole module, and the sub tabs inside those. Nobody loses a tab they could open before, and a module with no tab opened to you now says so rather than showing a blank pane.',
        'The phone stays registered. A desk left open all day keeps ringing, instead of going quiet after an hour.',
        'Clicking the EzBizi button on the taskbar brings the window back after you have closed it to the notification area. It used to do nothing, so the only way back in was the small icon beside the clock.',
        'Answering or declining a call on one device stops your other phones ringing.',
        'Make yourself available again and a call that is still ringing starts ringing your phone too.',
        'A caller who asked to be called back now gets one when the person they were waiting for comes off Do Not Disturb, not only when that person finishes a call. Coming off Do Not Disturb is the commoner way of becoming free and the one somebody does on purpose.',
        'Pressing Tab moves to the next field, instead of jumping out to the sidebar and the lists beside it.',
        'The call flow chart redraws as soon as you change something from it.',
        'Work Hours reads newest day first and stops at today, rather than listing days nobody has worked yet.',
      ],
    ),
    VersionRelease(
      version: '0.0.0237',
      date: '17 Sep 2026',
      items: [
        'Notifications work on iPhone. EzBizi never asked for permission, so it was not even listed under Settings then Notifications, and everything behind that was working and invisible.',
        'Google Ads campaigns can be filtered to Local Services or Search, beside Active and Paused.',
        'Another thirty-four screens moved off the old popup dropdown to a list you can search.',
      ],
    ),
    VersionRelease(
      version: '0.0.0236',
      date: '17 Sep 2026',
      items: [
        'A website General tab shows the website settings again. It had filled with a text box for every field on every page, plus Group, Author and Category, which mean nothing for a whole site.',
        'Three website fields you could not see before: City link, State link and Website address.',
        'Paid time off. Turn it on for the business, set how many days a year, how long somebody waits before using them, and whether unused days carry over. Everybody can see their own balance; seeing anybody else needs the HR permission. Off until you set it up, so nothing changes for anyone today.',
        'Twenty screens moved off the old popup dropdown to a list you can search.',
        'A desktop notification now carries its own icon rather than looking one up.',
      ],
    ),
    VersionRelease(
      version: '0.0.0235',
      date: '17 Sep 2026',
      items: [
        'An inspection is drawn as the shape somebody walks through, rather than as a list of questions.',
        'Emailing a report to a customer is back, rebuilt rather than restored.',
        'A picker you can search, on the screen that needed it.',
        'Work Hours reads in Spanish everywhere, including the week arrows and the still-clocked-in row.',
      ],
    ),
    VersionRelease(
      version: '0.0.0234',
      date: '17 Sep 2026',
      items: [
        'Work Hours is a week rather than a day: everyone in one list with their hours, searchable, with a green dot beside whoever is on the clock, and each person opening to their clock in-outs grouped by day with a total, days worked and an average. A day nobody worked still gets a heading rather than vanishing.',
      ],
    ),
    VersionRelease(
      version: '0.0.0233',
      date: '17 Sep 2026',
      items: [
        'Scheduling settings open again. The pane went grey, rail and all, because the server sent each service area week in a shape the screen could not read.',
        'Clock in-out is now Work Hours, and sits beside Dashboard.',
        'Work Hours knows the week for each person: hours worked, days worked, and an average per day, counted from whichever day your organization starts its week on. Everybody is listed, including anyone who worked nothing.',
        'A variable in an automation email or WhatsApp message now carries its value. It was always blank, and every variable box now says which names work.',
        'A text message from a rule can name the caller.',
        'A quotation mark typed into a rule no longer arrives as a string of symbols.',
      ],
    ),
    VersionRelease(
      version: '0.0.0232',
      date: '16 Sep 2026',
      items: [
        'A rule that sends email can no longer exceed its hourly limit if the counter behind that limit stops working.',
        'The run log for a rule that sends a report now names who did not get it, and says so when mail is going nowhere at all. It used to print Sent to 2 people on a machine where nothing had left.',
        'A WhatsApp step now says it handed the message over rather than that it sent it, and records which message it was. Meta returns success for messages it refuses seconds later, so the old line went green above something that never arrived.',
        'Work schedules now follow the clock of the branch or company they belong to, so the end-of-shift reminder and the automatic clock-out happen at the right hour. A schedule saved with a fixed offset kept no daylight saving, which put every reminder an hour late through the summer.',
        'When Google stops answering, the Leads screen says so. A dead sync used to look exactly like a quiet afternoon, and the list refreshing itself every twenty seconds made that worse rather than better.',
        'A photo taken on an iPhone now posts to Facebook and Instagram as a photo. It was being sent as a video file, which is not what it is. The same applies to heif photos and m4v videos.',
        'An automation can send a saved report to a list of people.',
        'A rule can be run for real from its own menu, instead of waiting for the event to happen.',
        'Every step in a rule now offers the right kind of choice: people are searchable, a value with a fixed set is chosen rather than typed, a text step lists only lines approved for messaging, and a WhatsApp step asks which number to send from when a business has more than one.',
      ],
    ),
    VersionRelease(
      version: '0.0.0231',
      date: '16 Sep 2026',
      items: [
        'A scheduled report now covers the place it was saved for. It used to be rendered at whatever view its author happened to have selected in their top bar, so the same report could carry another branch figures without saying so.',
        'Airtable bases and Workiz accounts can be given a name, and arrive named instead of showing their internal id.',
        'Clicking a New Live Chat Lead notification now opens Leads already narrowed to website chat, instead of the dashboard.',
      ],
    ),
    VersionRelease(
      version: '0.0.0230',
      date: '16 Sep 2026',
      items: [
        'Clicking a notification now opens what it is about, instead of only marking it read. Being told a new live chat lead had arrived and given no way to reach it was the complaint.',
        'An email step in an automation can address somebody in the business, a field carried by the event, or an address you type.',
      ],
    ),
    VersionRelease(
      version: '0.0.0229',
      date: '16 Sep 2026',
      items: [
        'An automation can reply to a website live chat enquiry, and a reply a rule sends no longer makes a brand new enquiry look answered.',
        'An automation can resend or withdraw an invitation, hand a job or vehicle to somebody, take vehicle keys back, and switch off a company, branch, person or customer.',
        'Update a field now offers the status and summary on a lead. It was offered on leads with nothing it could set.',
        'A website that stops being recognised by EzBizi now raises an automation trigger, so you choose who hears about it and how, rather than the notice landing somewhere nobody looks.',
        'Airtable and Workiz can be connected from Settings then Integrations, so a business can point EzBizi at its own figures.',
        'Pages and posts open their own screen with shortcodes, author and category read live from each website, and text written once that can be used on as many pages as want it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0228',
      date: '16 Sep 2026',
      items: [
        'An automation can send an email, to the person the event was about.',
        'OS notifications now work outside the app window on Windows, Mac, Android and iPhone. Permission was only ever asked when somebody opened the notification panel, so anyone who never opened it was never asked and every desktop or phone notification quietly did nothing. The app still has to be running.',
        'A notification step can name which roles to reach, and a role that has since been deleted makes the step refuse rather than quietly notifying everybody.',
      ],
    ),
    VersionRelease(
      version: '0.0.0227',
      date: '16 Sep 2026',
      items: [
        'The Leads screen keeps itself up to date. A website chat that arrived while somebody was already looking at the list did not appear at all until they left the tab and came back, so a live conversation could sit unanswered. The list now refreshes every twenty seconds and an open conversation every ten.',
        'Click a box on the call flow chart and a drawer opens the real settings for it, the same panel the settings rail shows, so the two cannot drift apart.',
        'The zoom readout on the call flow chart follows the canvas rather than the buttons.',
        'The Send from list on a text step now shows only the lines approved for messaging, instead of offering ones the carrier would refuse.',
      ],
    ),
    VersionRelease(
      version: '0.0.0226',
      date: '16 Sep 2026',
      items: [
        'Zoom in and out of the call flow chart.',
        'The call flow chart names each queue after its team, and shows what happens once somebody answers a call.',
      ],
    ),
    VersionRelease(
      version: '0.0.0225',
      date: '16 Sep 2026',
      items: [
        'An automation rule can now test words against the body of an arriving email - the words the rule itself already contains, never the message. This is what a Missed Call rule needs, because RingCentral writes Status: Missed in the body and nowhere else.',
        'When an automation does not run, the log now says whether the arriving event carried that field at all, instead of reporting a wrong field name and a value that did not match in the same words. Five versions of one rule failed silently for the same reason and it was unreadable every time.',
        'Disconnecting an advertising account now takes its enquiries off the Leads list until it is connected again. Nothing is deleted, and reconnecting brings them back.',
        'Templates on a website: text written once and used on as many pages as want it.',
        'All inboxes shows a count rather than repeating the list of mailboxes below it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0224',
      date: '16 Sep 2026',
      items: [
        'Pages and posts now open their own screen inside EzBizi, with author and category read live from each website, values that belong to that one page, and free text fields that become shortcodes you can drop anywhere on it.',
        'Four more search engine fields on a website, asked for by name, and a chat can be set to open with an automatic first reply. The automatic reply is off until you turn it on.',
        'A website whose EzBizi token has been refused for a week stops drawing the chat and the form, and tells an administrator what stopped and where to fix it. A bad day at our end changes nothing, and the rest of the site keeps its values.',
      ],
    ),
    VersionRelease(
      version: '0.0.0223',
      date: '16 Sep 2026',
      items: [
        'An automation condition whose value can only be one of a fixed set is now chosen from a list instead of typed from memory.',
        'Lead received now says it covers website live chat and website forms as well as Google Ads and Thumbtack, which it has done since 8 September while the description still named only two of the four.',
        'When Google Ads refuses a report for being over quota, the screen now says whether the limit is on that one account or on EzBizi as a whole.',
        'The lead sync stops asking once Google is refusing on the shared quota, rather than spending the rest of its run on calls that cannot succeed. A single account being rate limited on its own does not stop the others, and the sync picks up again on the next run with no leads lost.',
      ],
    ),
    VersionRelease(
      version: '0.0.0222',
      date: '16 Sep 2026',
      items: [
        'Clocking in no longer leaves the desktop window without its title bar. The lock screen took the bar away and gave it back a moment too early, so the window came out of the lock screen with nothing to drag, minimize or close until you clocked out and in again.',
      ],
    ),
    VersionRelease(
      version: '0.0.0221',
      date: '15 Sep 2026',
      items: [
        'Page every handset on a team at once from the Call tab. One person talks, the team hears it, and the announcement ends when the speaker hangs up. Anybody already on a call is skipped and named back to whoever paged, so you know who missed it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0220',
      date: '15 Sep 2026',
      items: [
        'Live calls on the call flow chart: a queue node shows how many callers are holding on it and how long they have waited, and the header shows how many calls are on the line right now. Nodes that are not queues show no number rather than a guess, because a call in the menu, one ringing a group and one holding all look the same on the record.',
        'An automation that switches itself off after repeated failure now notifies the people who can fix it, instead of only marking a card nobody was looking at.',
        'The message on a notification step is now an ordinary field like every other action rather than the one hand-written box in the builder, and a step can be set to in-app only, so one rule can stop emailing without the business turning email off everywhere.',
        'Tapping a slice of a pie chart shows what share it is, on phones and tablets as well as with a mouse.',
      ],
    ),
    VersionRelease(
      version: '0.0.0219',
      date: '15 Sep 2026',
      items: [
        'The chat and contact form on your websites can reach EzBizi again. The browser was refusing to send the message before it ever left the page, so everything a visitor typed came back as our own did-not-send notice.',
        'The chat bubble now appears by itself on every page of a website instead of only where somebody pasted it, and it no longer borrows the colours of the site it sits on.',
        'Google Ads opens on yesterday rather than an empty range, with a search box for accounts and another for campaigns, sortable columns, and a total across every account under a manager. Accounts in the manager tree that EzBizi holds no token for are counted and named rather than left out of the total.',
        'A Google Ads manager account now says on its own row that opening it reads every account beneath it, so the cost of the click is visible before it is made.',
      ],
    ),
    VersionRelease(
      version: '0.0.0218',
      date: '15 Sep 2026',
      items: [
        'Google Ads now reports one account at a time instead of loading every connected account before it shows anything, and adds a chart, status filters and real date ranges.',
        'The call flow chart draws one box per team rather than one per key, so a team reached by two keys is one box with two arrows into it, and every team has its own queue. Everyone busy no longer looks like it belongs to a single menu option.',
        'The call flow chart follows light and dark mode instead of always being dark, and two keys reaching the same team are drawn as one wire carrying both labels.',
        'The experimental call report loads its Workiz branches at the same time rather than one after another.',
      ],
    ),
    VersionRelease(
      version: '0.0.0217',
      date: '15 Sep 2026',
      items: [
        'The chat and contact form now show on every website, with no switch to turn them on. They were off on every site, and on a site built with Elementor there was no way to place them at all - both now appear in the Elements panel as EzBizi Live Chat and EzBizi Contact Form.',
        'Colleagues on Do not disturb now show a red dot on the Team list instead of a grey one.',
      ],
    ),
    VersionRelease(
      version: '0.0.0216',
      date: '15 Sep 2026',
      items: [
        'Google Ads now appears as a tab inside Reports, and the Reports screen no longer shows its own name twice. The tab was registered and working but nothing drew it, so there was no way to open it.',
        'The EzBizi plugin now has its own item in the WordPress menu instead of hiding under Settings, and the setup guide points at the new place.',
      ],
    ),
    VersionRelease(
      version: '0.0.0215',
      date: '15 Sep 2026',
      items: [
        'Web Management: two new tabs, Pages and Posts, showing everything on your website - what is published, what is still a draft, and when each last changed. Read from the site itself every time, so it is never a stale copy.',
        'Web Management: fixes the publishing account never being created on most websites. It was only attempted while a shortcode was being drawn, and the check it made was about the request rather than the site, so on an ordinary secure site it silently never happened.',
        'The experimental call report now shows average talk time per person. Which Workiz accounts the numbers cover is stated in words, and connecting or disconnecting an account has moved behind Manage Workiz accounts - disconnecting now asks first.',
        'New Google Ads tab under Reports: what each connected Google Ads account spent over a range, with clicks, impressions and conversions beside it, per account and per campaign. Accounts Google will not report on are listed rather than shown as zero spend.',
        'A business can connect its own mail server, and saved reports and automation emails go out through it instead of not going out at all. The screen shows what is set up and when it was last tried, never whether a message arrived.',
      ],
    ),
    VersionRelease(
      version: '0.0.0214',
      date: '15 Sep 2026',
      items: [
        'One ringtone per call, on every platform. The desktop, the browser and the phone each rang twice for a single call, in three different ways, which is why fixing one at a time never settled it. On a phone EzBizi now stays silent on purpose and lets the handset ring: if the app seems to have stopped ringing there, that is the fix.',
        'Callers on hold can press 1 to be rung back instead of waiting, and are called automatically as soon as somebody is free.',
        'Scheduling settings now open on a table of every service area and what each can take on each day of the week, instead of a summary of your slot pattern. A day you are closed shows a dash rather than a zero. The day editor is a row per day instead of a panel per day, so a week fits on one screen.',
      ],
    ),
    VersionRelease(
      version: '0.0.0213',
      date: '15 Sep 2026',
      items: [
        'Take a call that is ringing somebody else. A call ringing a group you are not in now appears on the Dial tab with a Pick up button, instead of going to voicemail while you watch it ring.',
        'The Call Flowchart now shows a skipped step as passed through rather than never reached, and outlines the caller box correctly. Both were drawn from the wrong thing: a step every caller walks was being reported as one nobody reaches.',
        'The experimental call report now says which Workiz accounts its numbers cover, and names Massachusetts as permanently excluded because that account has no API subscription. A total that is quietly missing a branch is worse than one that says what it is missing.',
      ],
    ),
    VersionRelease(
      version: '0.0.0212',
      date: '15 Sep 2026',
      items: [
        'Park a live call and anybody sharing that line can pick it up, instead of hanging up on a caller who needs somebody who is not at their desk. There is a Park button beside Hang up, and the desk shows who is holding.',
        'Communication, then Settings, now has a Call Flowchart: what happens to a caller drawn as a diagram, rather than described as a list of sentences.',
        'The dashboard now says when an automation has stopped itself and is still off. A failure was a notification you could miss; being stopped is a condition, so it now shows where somebody is already looking.',
        'An automation that fails no longer prints the raw technical error on its card. The internal message from a failed request was being shown to whoever opened it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0211',
      date: '14 Sep 2026',
      items: [
        'Answering a call on the desktop app connects it again, instead of sending the caller to voicemail. Changing company restarts the phone so it picks up that company line, and that restart was tearing down a call that was already ringing.',
        'The sending address screen has been removed from Settings. It offered to verify a domain so that mail would appear to come from your own business, and that is not how EzBizi sends. EzBizi mail carries EzBizi messages only - signing in, password resets and joining messages - and anything your business writes goes out through your own mail provider.',
        'In the Experimental call report, two spellings of one name are now counted as one person rather than two.',
      ],
    ),
    VersionRelease(
      version: '0.0.0210',
      date: '14 Sep 2026',
      items: [
        'Voicemail transcription is now one switch for the whole company instead of one per phone line. It was off on all thirteen numbers and not one voicemail had ever been transcribed - not because anybody decided against it, but because turning it on meant visiting thirteen separate line settings.',
        'A ring group can now start with whoever has been free the longest, instead of always starting at the top of the list. A fixed order quietly gives the first person most of the calls and the last almost none. Somebody who has never answered a call goes first.',
        'A Workiz account connects to the branch it belongs to: switch to that branch, paste its token, and it is checked with Workiz before it is saved. Each connected branch shows as a chip you can remove again.',
        'Reports, inspection reports and automations that email somebody now say plainly that this needs the business to have its own email provider connected. EzBizi mail carries EzBizi messages - signing in, password resets and joining messages - and only those.',
      ],
    ),
    VersionRelease(
      version: '0.0.0209',
      date: '14 Sep 2026',
      items: [
        'Callers can now wait in a queue instead of going straight to voicemail, and are told their place in the line.',
        'Ring groups gained a Hold the caller in a queue option, with a hold time, the announcements, and hold music.',
        'Emailing an inspection report to a customer is switched off, and an automation that uses it now records why on every run rather than failing quietly. EzBizi mail carries EzBizi messages only - sign-ins, password resets and account notices - and a report to a customer would go out over it whatever sending address a branch has set up.',
      ],
    ),
    VersionRelease(
      version: '0.0.0208',
      date: '14 Sep 2026',
      items: [
        'The notice that tells somebody their screen is being controlled now appears in the app itself, not only as a Windows tray message. It could previously only appear while the EzBizi window was closed to the tray, so anyone sitting in front of the open app was not told. It is still off unless your organisation turns it on.',
        'Reports has a first Experimental report: how many calls each person took, per day. It needs somebody who can build reports, not only view them, and the tab is hidden from everybody else rather than refusing them after they tap it. Where your organisation has not connected RingCentral or Workiz it says so in plain words instead of leaving you to read a blank.',
        'The report builder no longer offers Current tab as a column. It is overwritten every few seconds, so an exported report showed a value that was true only at the moment of export, and it printed an internal name where the rest of the app shows a proper one.',
      ],
    ),
    VersionRelease(
      version: '0.0.0207',
      date: '14 Sep 2026',
      items: [
        'The EzBizi icon now stays in the notification area for as long as the app is running. It was only added at the moment you closed the window to the tray, so while the app was open there was no icon at all. The same gap silenced the notice that tells you your screen is being controlled by your company: that notice is carried on the icon, so it could only ever appear while the window was hidden, and never while somebody was sitting in front of the open app.',
        'A Confirm your email button now sits at the top of the app, beside the two-factor prompt, for an account whose address has not been confirmed. It sends a fresh link, so the account can clear the condition itself instead of meeting a refusal on every save.',
        'Every message in the customer panel now says who sent it and when. Each one was the same box in a column, told apart only by a faint tint. The name now sits above it, and the bubble takes the side and the full colour that Records already uses for the same conversation.',
        'The availability icon now keeps itself up to date within a minute. Do Not Disturb set on the web reached the desktop immediately, but the icon only showed the change once you switched tabs.',
        'The voicemail list now names the one reason a transcript is missing and says who can turn it on, instead of offering two possible causes and settling neither. Voicemail transcription is off on every number today, it is switched on per number, and the phone network charges for each one. The Records tab shows a different thing: the transcription of the whole call, including the menu the caller listened to before leaving a message.',
      ],
    ),
    VersionRelease(
      version: '0.0.0206',
      date: '14 Sep 2026',
      items: [
        'A call answered on another device, or by a colleague in the ring group, now stops ringing everywhere else on the web. Only the customer hanging up was being noticed - the two events that mean somebody else took the call were dropped, so a browser went on ringing a call that had already ended.',
        'Switching company now picks up that company phone line, instead of leaving the phone dead for the rest of the session.',
        'The phone no longer tells a web browser that it needs a web browser when no line was found.',
        'The mobile icon now narrows the window itself rather than only the app inside it, and works whether or not the window has been moved.',
      ],
    ),
    VersionRelease(
      version: '0.0.0205',
      date: '14 Sep 2026',
      items: [
        'Disconnecting and reconnecting a Google Ads account no longer detaches its enquiries. They are re-linked automatically, without re-reading anything from Google. Enquiries detached before this update are re-linked only when Google next returns them, and the oldest may never be.',
        'A company can be made a sub company, a sub company can be made a company again, and a sub company can be moved to a different parent. It is in a company menu on the Organization screen. It needs somebody who stands at the whole organization, because moving a branch hands the work of its people to a different set of managers.',
        'An arriving call now sounds like a phone ringing rather than like a call you placed. The alert was the tone a caller hears while waiting - the same one played when you ring out - so both ends of a call sounded identical.',
        'Fixed: everybody who already had an account was locked out of changing anything. A new check asked whether each person had confirmed their email address, and nobody who signed up before it existed ever had the chance. Accounts that predate the check are now treated as confirmed.',
      ],
    ),
    VersionRelease(
      version: '0.0.0204',
      date: '14 Sep 2026',
      items: [
        'Reconnecting a Google Ads account now reclaims the enquiries it was disconnected from, instead of leaving them unattached and unanswerable. Each one is picked back up the first time it is seen after this update.',
        'A Google Ads connection is no longer marked broken when Google is merely busy. A rate limit had silently stopped three live accounts for up to four days.',
        'The window shown after connecting an account now names what was connected and where it goes - Google Ads and Thumbtack enquiries say Leads, Business Profile says Reviews - and draws the provider mark instead of appearing as bare text.',
        'An incoming call now rings on Android. Two phone permissions were declared but never actually asked for, so a fresh install could not even check whether Android would let it be a phone. Placing a call and its audio have not been confirmed yet.',
        'Not taking calls now covers your whole account, and the other apps catch up when you look at them. Setting it on the web left the desktop showing you as available - and a call rang. Opening the app somewhere it had never been opened also quietly put you back on the rota. This is the half that the two previous attempts missed.',
        'Human Resources: an invitation now says what became of the message - sent, refused, or not attempted because this installation sends no mail - and one whose outcome your version cannot read says so rather than saying it may have arrived.',
        'Human Resources: a mistyped invitation address can be corrected, and the link that went to the wrong address stops working.',
      ],
    ),
    VersionRelease(
      version: '0.0.0203',
      date: '14 Sep 2026',
      items: [
        'Android takes two steps towards making calls work, both of which were missing. The app now registers with the calling network, and registers the calling account Android requires before it will carry a call at all - until now it had never asked, so the system refused to place or accept anything and a call failed instantly in both directions with nothing on screen explaining why. Calls are not working yet. On a phone you will need to switch EzBizi on under Android Calling accounts, where it appears as Provides calling services for EzBizi; while it is off the phone icon shows a crossed-out handset rather than claiming to be ready.',
        'Notifications are sent by email as well as appearing in the app. The settings screen has promised this since August and the code was not there; it is now. It goes to the same people the in-app notification does, up to fifty of them - above that the bell still works and no mail is sent at all, rather than mail going to some of them.',
        'Turning off in-app notifications no longer silences email as well. It was described as a master switch, which was true of a system with one channel and wrong the moment there were two. Each channel is now its own choice.',
        'Quiet hours still does nothing, and now says plainly what that costs: a notification raised at three in the morning used to be a silent line you found later, and since this release it can ring a phone.',
        'A rule can email a finished inspection report to the customer. There is no send button - it is an automation you attach to a report being submitted, so who may send, when, and to whom are answered per company rather than in code. A company that has not set up its own sending address is refused rather than sent from ours.',
        'Automations can run when Windows reports a computer antivirus, firewall or updates as off or paused, so you can be told about it rather than having to notice. It is raised once when it happens, not again until it is fixed and happens a second time.',
        'Human Resources: an invitation is only reported as emailed when the installation can actually send. On a setup that quietly delivers nowhere it used to say the invitation had been emailed, which is exactly where somebody tests the flow and concludes it works.',
      ],
    ),
    VersionRelease(
      version: '0.0.0202',
      date: '14 Sep 2026',
      items: [
        'Fixed: a report on Human Resources listed everyone in the organization, including people outside the branch the reader works in. It now shows the same people the Human Resources tab does. Anybody who ran that report since 10 September could have seen names, email addresses and phone numbers they were not meant to reach. Nobody did: no report of that kind had been saved, and permission to build or run one has not been given to anybody yet.',
        'A computer whose antivirus, firewall or updates Windows is unhappy about now says so on its card in the machines list, so you can spot it without opening every machine in turn.',
        'Email now says why there is nothing to read - no inbox connected, none in this part of the business, or none given to your role - instead of always suggesting you look in another company.',
        'A mailbox the mail service refuses is now retried for about a day before EzBizi asks somebody to reconnect it, instead of being disconnected on the first refusal.',
        'The home screen says when an inbox has not managed to fetch any mail for a day, even while it still shows as connected.',
      ],
    ),
    VersionRelease(
      version: '0.0.0201',
      date: '14 Sep 2026',
      items: [
        'A computer reports how hard its graphics are working, alongside processor, memory and disk. The reading starts appearing on each machine once that machine has updated to this version - until then it shows a dash, the same as any reading a computer cannot supply.',
        'Monitoring shows what Windows itself says about a computer antivirus, firewall and updates, including when another company antivirus is the one protecting it. A machine running Bitdefender with the built-in one correctly stood down reads as protected rather than as at risk.',
        'Hovering a slice of a pie chart lifts it and shows what share it is.',
        'A mailbox the mail service refuses is now retried for about a day before EzBizi asks somebody to reconnect it, instead of being disconnected on the first refusal.',
      ],
    ),
    VersionRelease(
      version: '0.0.0200',
      date: '11 Sep 2026',
      items: [
        'Do Not Disturb now survives a call you place yourself. Setting yourself to Not taking calls and then ringing somebody put you back on the rota the moment you hung up, with nothing touched. The fix in 0.0.0196 said only the availability toggle could change it - that was true of the app reconnecting and not true of placing a call, and this is the other half of it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0199',
      date: '11 Sep 2026',
      items: [
        'A report can start from ten parts of the business now, up from four: Jobs, Customers, Human Resources, Branches, Services, Inventory, Fleet Management, Training, Leads and Scheduling.',
        'Reports has its own sub tabs: your saved reports, the builder, and a space for things still being tried out.',
      ],
    ),
    VersionRelease(
      version: '0.0.0198',
      date: '11 Sep 2026',
      items: [
        'A mailbox is no longer disconnected by a network timeout or a mail service that briefly stops answering. The message for that fault always said it usually clears on its own - but recording it as disconnected removed the mailbox from the only check that would have noticed it clear, so one blip needed somebody to press Reconnect.',
        'A chart with more slices than there are colours now groups the remainder into one row named for its count, instead of drawing two slices the same colour. The release before this one had eight colours and no answer for the ninth.',
        'A screen reader names every category on a chart, including the ones the picture folds into that one row. What the eye needs grouped and what the ear needs listed are different, and they are now allowed to differ.',
      ],
    ),
    VersionRelease(
      version: '0.0.0197',
      date: '11 Sep 2026',
      items: [
        'The sidebar can be dragged into your own order, and it is saved to your account rather than to the machine you did it on. Settings, Suggestions and Development stay where they are - they are a different list and cannot be dragged into the module one.',
        'Dividers: put a nameless rule anywhere between two modules to group them, drag it like any other row, remove it with the x.',
        'On a computer, Rearrange sits under the module filter and the sidebar widens into a list with handles. On a phone it is the icon beside the wordmark in the drawer. Both go back to normal when you press Done.',
        'A module added later still appears for people who have already arranged their sidebar. It goes to the end rather than being left out.',
        'Fixed: a report could only be created while looking at the whole group. Saving one from a company or a branch was refused. It now files on whichever place you are looking at.',
        'Charts on the home screen draw every slice in its own colour. The palette came from the theme roles, two of which are deliberately the same colour, so on a light screen the first two slices of every pie were identical - Scheduled and In progress looked like one wedge. Eight distinct colours now, picked to stay apart for colour-blind readers, with a separate set for dark mode.',
      ],
    ),
    VersionRelease(
      version: '0.0.0196',
      date: '11 Sep 2026',
      items: [
        'Not taking calls now stays off until you turn it back on yourself. Opening the app or signing back in told the system a device was attached, and that quietly cancelled it. Only the availability toggle changes it now, because only that is a person deciding. It applies to every company you are signed in to, not just the one on screen.',
        'A disconnected inbox now says so on the home screen, instead of reporting that it could not be asked for unread mail. The old wording described a permanent disconnection in the words of a momentary one.',
        'When every inbox is disconnected, all of them are named. Only the first was shown and the rest were dropped - and a card with nothing to show now says everything it has to say rather than just its first sentence, which is the same fault one layer down.',
        'An inbox knocked out by a fault at the mail service now recovers on its own instead of waiting for somebody to reconnect it. Marking it broken had been removing it from the only check that would have noticed it recover. Mail that arrived while it was disconnected is not backfilled.',
        'A disconnected inbox is no longer dialled at all, so it costs the home screen nothing.',
        'Accounts are asked longest-unread first, so when the daily ceiling is reached the shortfall is spread across all of them instead of always falling on the same ones.',
      ],
    ),
    VersionRelease(
      version: '0.0.0195',
      date: '11 Sep 2026',
      items: [
        'Fixed: a report could only be built if it started at Jobs. Reaching across modules was announced in 0.0.0192 and only half worked - a connection between two modules was usable in one direction only, so a report starting at Customers or at People could reach nothing at all. Any module can now be the starting point.',
        'Reading backwards along a connection can return more rows than you started with. A customer with two jobs is two rows in a report of customers and their jobs. That is the answer to the question rather than duplication - each row is one job.',
        'A report can be deleted. The confirmation says what it does not remove: a report is a question about your records, so deleting it leaves every job, customer and person exactly where they were.',
        'The field list is grouped by the module each column comes from and reads like the sidebar, so it is clear which tab a column belongs to. People appears under Human Resources. Branches is the one heading with no tab of its own, because branches are set up in Settings.',
      ],
    ),
    VersionRelease(
      version: '0.0.0194',
      date: '11 Sep 2026',
      items: [
        'Fixed: New report opened to a blank panel. The builder could not be used at all in the two releases before this one; the list of saved reports and running one were unaffected.',
      ],
    ),
    VersionRelease(
      version: '0.0.0193',
      date: '11 Sep 2026',
      items: [
        'Accounts that have been quiet are checked every fifteen minutes instead of every thirty, so an enquiry to a branch that has not heard from anyone in a while arrives sooner.',
        'A report can say who performed a job, and which branch it belongs to. Neither was answerable before - the information was there and nothing could reach it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0192',
      date: '10 Sep 2026',
      items: [
        'Reports: build a report from your own data. Pick a starting record, pull in columns from what it connects to, filter it, save it by name, and anyone you grant it to can run it. Jobs, customers and people to start with - more of the business will be added to what a report can reach.',
        'Reports is administrator-only until you hand it out. Running a saved report and building one are separate permissions, so you can let somebody run what you built without letting them change it.',
        'The machines list leads with the person now, not the computers name, and every card is the same size whether or not it has something to show.',
        'The machines list keeps itself up to date while you watch it, instead of showing what was true when you opened the tab.',
      ],
    ),
    VersionRelease(
      version: '0.0.0191',
      date: '10 Sep 2026',
      items: [
        'An IVR key can send a caller straight to one persons extension. The phone system has dialled extensions from a menu key since extensions existed, but the API rejected the action and the menu editor never offered it, so nobody could switch it on. The editor names the person rather than taking free digits, so a key cannot point at an extension nobody holds.',
        'A call can follow you to your phone. Answer at the desk, press Move to my phone on the call strip, and every other device you are signed in to rings. The customer stays connected throughout and hears ringing rather than silence. Until now the only way to move a call was to hang up on the customer and ring them back.',
        'The pin that keeps the customer panel open now survives leaving Communication and coming back, and follows you to another machine. It used to live only in the screen, so keep this open between calls meant until you look at something else.',
        'Small print across the whole app is now actually smaller than body text. It never was: the style used for captions and secondary lines had no size of its own, so it came out the same size as ordinary text in 113 files. Most screens will look slightly different because of it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0190',
      date: '10 Sep 2026',
      items: [
        'Automations: a rule can now run on a clock - every day, on days of the week you pick, or once a month - at a time you set in the timezone you choose, so it stays right when the clocks change.',
        'PC Specs is now a card for each computer, showing how hard it is working - processor, memory and disk - so a machine that is struggling stands out without opening anything.',
        'Clicking a computer shows everything it has reported: what is inside it, its drives, the network it is on, how long since anyone touched it, and what is open. There is a search box for finding one by machine, person, or what they have in front of them.',
        'A caller who has just rung shows under Unreplied straight away, instead of appearing minutes later.',
        'An enquiry with nothing on it yet says so in EzBizis own words, rather than claiming Google recorded nothing.',
        'An enquiry is fetched before any accounts message history, so a busy day cannot cost you a lead.',
        'A Google booking is named as a booking and says where to find the customers details. It used to arrive as an unnamed enquiry badged as a text message, showing an empty message nobody had sent.',
        'Nobody has to point a company at its own voicemail box any more - a company without one adopts the box it already has.',
        'The Buy a number tab opens straight into the search, and local, toll-free and vanity are each their own. Local takes a state or province and an area code, toll-free takes a prefix, and vanity takes letters, digits and wildcards on whichever kind of number you want them on.',
        'The country picker is searchable and leads with the US and Canada, instead of ninety entries in whatever order they arrived in.',
        'A phone menu can offer a key that repeats the menu. Every phone system has one; this did not, so a caller who missed which key was which had to hang up and ring back.',
        'On the web an incoming call rings once. Two tones were playing at once, and the second one kept going after the caller had hung up.',
      ],
    ),
    VersionRelease(
      version: '0.0.0189',
      date: '10 Sep 2026',
      items: [
        'The lock screen now holds the whole screen: it cannot be minimised, resized or closed, and it lets go by itself when the shift is opened somewhere else or the organization switches the lock off.',
        'PC Specs opens as a list again. Every person is one line showing whether they are on and what they are doing; the details of their machine are one click away instead of always on screen.',
        'The desktop app now reports much more about the machine it runs on - processor, graphics, memory and CPU load, every drive, the network it is on and how much it has moved, how long since anyone touched it, whether the screen is locked, and which programs are open.',
        'It also reports what is installed on a machine, which programs are talking to the internet, and the titles of open browser tabs. Tab titles and the window in front are shown only to a role that can already see screenshots, and a device that is exempt from monitoring reports neither.',
        'What EzBizi records about a persons screen - the window in front and open browser tabs - is now listed separately from what it records about their machine, so a request for the data a business holds about someone answers that question in the right words.',
      ],
    ),
    VersionRelease(
      version: '0.0.0188',
      date: '10 Sep 2026',
      items: [
        'Fixed, Windows: EzBizi could stop part of your desktop accepting clicks. On a wide screen, with EzBizi on one side, a band of the other side went dead - while the app was running, while it was minimised, and until it was killed outright. An invisible window that the softphone needs was being left unmeasured, so it sat where it landed and swallowed every click inside itself. It is now one pixel, inside the window, where it can reach nothing.',
      ],
    ),
    VersionRelease(
      version: '0.0.0187',
      date: '10 Sep 2026',
      items: [
        'Recent calls and Records name the person who answered. An incoming call rings a whole ring group and nothing recorded which of them picked up; the handset that answers now says so.',
        'Recent calls and Voicemail show when a call came in - the clock for today, the date for anything older, in the format your organization uses.',
        'Voicemail shows the message that was left, rather than a transcript of the whole call. The two were sharing one place to be stored, and the voicemail one was being thrown away before it got there, so most messages looked as though nothing had been said.',
        'An enquiry keeps working after its account is disconnected and connected again. Disconnecting used to cut every enquiry that account had already delivered off from the thing that reads and replies to it, in the middle of a conversation, with nothing saying so.',
      ],
    ),
    VersionRelease(
      version: '0.0.0186',
      date: '10 Sep 2026',
      items: [
        'Recent calls and Records name the colleague instead of a long string of letters and numbers. A call to a teammate is addressed to them rather than to a number, and the app was showing you the address.',
        'A caller is told about recording once, not twice. Pressing a menu key made the phone system read the whole greeting again, notice included.',
        'Voicemail catches messages when nobody picks up.',
        'A Thumbtack enquiry can be replied to from the thread. A refusal now names the source that refused rather than always naming Google, which was telling you that Google does not accept replies to calls when the enquiry was a Thumbtack message.',
        'The Leads list follows the view you are in: a company sees its own enquiries, and everything only at organization level. It was showing one company the enquiries of another.',
      ],
    ),
    VersionRelease(
      version: '0.0.0185',
      date: '10 Sep 2026',
      items: [
        'Fixed: after a call ended, the app bar stayed on On a call until the app was restarted. That also kept ring groups skipping that person, so their phone quietly stopped ringing and nothing on screen said why.',
        'Fixed: on a phone, the Dial pad had a back arrow that led into a second copy of the team list and left you stuck there.',
        'An incoming call now rings every device you have open, instead of whichever one the phone network happened to pick. With the desk open on a PC and the app on a phone, only one of them rang and there was no way to tell which it would be. First one to answer takes the call and the rest stop ringing.',
        'On a phone the top bar is no longer crowded: the EzBizi mark and the duplicate company logo are gone, Clock In and Clock Out are an icon, and the light and dark switch moves to Your account. Set up two-factor and Read only now sit in a strip under the bar instead of squeezing the buttons.',
        'A test enquiry is marked as one, so it never reads as your first customer.',
        'The Leads box on the home screen ignores test enquiries when it counts who is waiting.',
        'Android now declares the part of the app an incoming call arrives on, which was missing from every build ever made. Together with the device token in the last release, that is the whole of what was absent - but nobody has yet watched an Android handset register or ring, and this does not claim they do.',
      ],
    ),
    VersionRelease(
      version: '0.0.0184',
      date: '10 Sep 2026',
      items: [
        'Android now supplies the device token from Google that the calling network demands before it will accept a registration at all. The app had never sent one, so an Android phone could not have received a call whatever was set up on the server. What this does NOT say is that a handset now registers or rings: more of the chain is in place, not the whole of it, and nobody has yet watched either happen.',
        'Fixed: placing a call no longer asks you to accept the call you just made. Ringing a colleague raised your own incoming banner and offered you the call you were placing.',
        'Fixed: answering a call on the iPhone call screen no longer asks you to accept it a second time inside the app.',
        'A phone that cannot join the calling network now says what went wrong, instead of telling you to reload the page. It was showing a message about a page and a network for a fault that was neither, because the real reason was being thrown away before anyone could read it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0183',
      date: '9 Sep 2026',
      items: [
        'Calling a colleague rings. The caller hears ringing while it dials, which they did not before - a team call was silence, with no way to tell dialling from failed from already answered.',
        'An incoming call rings the desk, brings the window to the front and posts a notification. The bar across the top was the only signal, and it only helps somebody already looking at the window.',
        'Desktop Monitoring says why a computer is not sending screenshots, instead of leaving a blank space. A locked screen, a Windows install missing its image encoder, an upload the server turned away - and where there is something you can do about it, the message says what.',
        'A computer that is simply switched off is no longer reported as faulty. It says plainly that it is off, and the faulty wording is kept for a machine that is on and genuinely not sending.',
        'Online and offline no longer show as colour alone on the device lists or on the floor plan. A lit bolt means on and a struck-through bolt means off, so the status reads without relying on telling green from grey.',
        'Boxes that can only see part of the business have stopped speaking for all of it. Where a box shows you your own branch or company, it now says so, instead of reporting that the company has none of something when it means none in your view. This covers the home screen boxes for Scheduling, Leads, Reviews, Marketing, Human Resources, Web Management, Fleet, Inspection, Inventory, Jobs, Services, Customers, Business Profile and Training.',
        'The same is now true of boxes that tell you nothing needs your attention, which matters more: every review answered, nothing failed to send, every service area following your pattern. Each says whether that is true of the whole business or only of what you can see, so a problem somewhere you cannot see no longer reads as no problem at all.',
        'Human Resources says when nothing has lapsed or is close to expiring, instead of showing nothing at all. It names the window it is talking about, says whether it means the whole business or only your view, and calls a licence that has already run out lapsed rather than upcoming.',
        'A reply to a lead updates its row in the list straight away, and moves it to the top.',
        'The list of attempts on a training record can be closed again after you open it.',
      ],
    ),
    VersionRelease(
      version: '0.0.0182',
      date: '9 Sep 2026',
      items: [
        'The training dashboard shows every attempt a person has made at a test, not only their latest one. The three most recent are listed, with a link that says how many more there are.',
        'A person who has only ever failed a test now appears on their own training record. Before, only a pass or an assignment put them there.',
        'When the training dashboard has nobody to show for a guide or test, it says so plainly. Before it said nobody was assigned, which was wrong for anyone who can only see part of the organization.',
        'The Calling as list on Dial shows all of the company numbers again. Tapping it was searching for the number already selected, so it only ever offered that one.',
        'A number somebody is mid-call on is marked as being on a call and cannot be picked, so two calls never go out on one line.',
        'Assign training can now be given to a role on its own. It was refused unless Monitor training was given with it, on the mistaken view that it could not be used by itself.',
        'Boxes on the home screen with nothing to show now say so in plain words. They no longer hint that there is more you are not being shown, and they no longer say the business has none of something when what they mean is that your own view has none.',
      ],
    ),
    VersionRelease(
      version: '0.0.0181',
      date: '9 Sep 2026',
      items: [
        'The home screen has a box for Scheduling, Phone and Marketing where each had a note saying something would appear later.',
        'Scheduling shows what you are set up to take each day of the week. The numbers are what you have configured - booked jobs are not counted yet, and the box says so.',
        'Phone shows how many calls were answered and how many were missed, and tells you if voicemails are waiting.',
        'Marketing shows what was published over the period, how many posts are waiting to go out, and how many failed to reach their account. A post sent to three accounts counts as three, because each one can succeed or fail on its own.',
        'Training shows how many people are due to retake a test. It counts people rather than assignments, and only the people you can already see; when there are none it says so in plain words.',
        'Fixed: opening a second ring group for editing without closing the first left the previous group\'s name in the box, so saving could rename the wrong group.',
        'Monitoring has a new General tab that lays your office out on a floor plan. You place a box for each computer, draw the walls, and assign each box to a person; the box is green when that person is online, red when they are offline, and grey when nobody is assigned, or when the person assigned is someone you are not allowed to see.',
        'Clicking a box shows that person in a panel on the right. With nothing selected the panel summarises the whole plan, so you can see how much of the office is on right now.',
        'A floor plan belongs to an organization, a company or a franchise, and you see the plans your own place covers. Editing one needs its own Monitoring permission, given out per role like the others.',
        'In Roles, a permission that needs another one now says so on the row, before you save rather than after. Ticking Assign a guide without Monitor training says so in place, and it never ticks the other box for you.',
        'Human Resources: a dashboard box with nothing to plot leaves the space to its sentences.',
      ],
    ),
    VersionRelease(
      version: '0.0.0180',
      date: '9 Sep 2026',
      items: [
        'You can block a nuisance caller, from the call itself in Records or from Settings - Blocked callers. A blocked caller is turned away before any phone rings, and the call still shows in Records so you can see it happened.',
        'Jobs that came from Thumbtack can report back what happened to them - scheduled, completed, paid or cancelled. Set which of your job statuses mean what under Settings; until you do, nothing is sent.',
        'A rule can now react to a setting being changed. The trigger fires when somebody saves a different value at any level, or drops an override and lets it inherit again, and does not fire when the save changed nothing.',
        'Granting somebody a permission that needs another one now says so instead of saving quietly. Assigning training needs permission to monitor it, because the control lives on the screen that monitor opens.',
        'A new test counts every attempt toward its retake limit, including the one the learner passes. Authors who want retakes after a pass to be free can still turn that off per test.',
        'Tests written before that option existed have been moved to match, so every test behaves the same way unless its author says otherwise.',
        'The dashboard no longer offers a way of drawing a box that it will not keep. Choosing Text on a box that only draws charts drew text and then went back to the chart on the next load.',
        'A dashboard box with only one way to draw it no longer shows a menu at all.',
        'After a call, the person who took it can be given a moment to write it up before the next one reaches them. Set it under Settings - Tags, from 15 seconds to 5 minutes, or leave it off. They can say they are ready sooner.',
        'The Training box on the dashboard stops promising a summary that was never planned, and says where the Training screens actually are.',
      ],
    ),
    VersionRelease(
      version: '0.0.0179',
      date: '9 Sep 2026',
      items: [
        'Training: a test\'s attempt limit counts from your last pass rather than for all time. Somebody who certified, let it lapse and then failed a retake was locked out of certifying again for good, with nothing an administrator could reset.',
        'Training: writing a test warns you when a limit on attempts, an expiry and counting every retake are set together - the one combination that can leave somebody unable to certify again, with nothing an administrator can undo. It warns rather than refuses.',
      ],
    ),
    VersionRelease(
      version: '0.0.0178',
      date: '9 Sep 2026',
      items: [
        'Communication - Settings - Ring groups is about people now. The editor used to carry a second list of every phone number on the account with tick boxes, which read as putting numbers into the group. Which group answers a number is set on that number, under Numbers, or as the company default under General.',
        'A ring group that no number reaches now says so, in red, and says where to choose one. It used to look like a working group and never ring.',
        'A group that IS reached names what reaches it - "Rings when a call comes to General Use" - so you can see what a group does without opening every line.',
      ],
    ),
    VersionRelease(
      version: '0.0.0177',
      date: '9 Sep 2026',
      items: [
        'Scheduling: deleting a service area now asks you to type its name, in the panel rather than a pop-up box. It says how many zip codes go with it, and anything already booked in that area is left alone.',
      ],
    ),
    VersionRelease(
      version: '0.0.0176',
      date: '9 Sep 2026',
      items: [
        'Monitoring: the Lead Developer\'s own machine is never screen-captured, live-viewed, or remote-controlled - it still shows online with its specs, but its screen is off-limits, since that account handles credentials.',
        'The desktop app opens at the size and position you left it, maximized if it was maximized, including after an update. It used to come back at the default size every time.',
        'The incoming call bar goes away when the call does. If the caller hung up, or a colleague answered first, the bar stayed across the top of every screen offering to answer a call that had already ended, until the app was reloaded.',
        'Answering a call no longer leaves you marked "on a call" afterwards. That mattered more than it looked: ring groups skip anybody who is on a call, so the line stopped ringing for that person until they signed out and back in - and the availability control could not clear it, because it deliberately refuses to overwrite a live call.',
        'The availability icon in the top bar keeps up with what you are doing. It read your status once when the app opened and never again, so it showed what was true at sign-in for the rest of the session.',
      ],
    ),
    VersionRelease(
      version: '0.0.0175',
      date: '9 Sep 2026',
      items: [
        'Web Management: the website list and the preview can be dragged wider or narrower, and the screen opens the way you left it.',
        'Leads: an account disconnected inside the Connected sources panel now leaves the panel at once, instead of staying until the panel was closed and reopened.',
        'Leads: Disconnect all, on any provider with more than one account, lets every account go after one confirmation; for Thumbtack the twelve businesses of one consent are tidied one by one and the grant revoked once.',
      ],
    ),
    VersionRelease(
      version: '0.0.0174',
      date: '9 Sep 2026',
      items: [
        'Thumbtack: an enquiry is filed from what Thumbtack sends in the moment, so a slow or failed follow-up read no longer loses it, and a conversation that cannot be read yet arrives as a lead with no messages rather than not at all.',
        'Thumbtack: connecting one account that covers several businesses now keeps every one of them signed in. Thumbtack replaces the sign-in key on every refresh; it was being saved to one business and the others kept the old key until Thumbtack cut all of them off. An account connected before this update needs connecting again once.',
        'Training: a retake no longer uses up an attempt while somebody\'s pass is still valid. This applies to every test already written; authors who want the stricter counting can switch it back on per test.',
        'Scheduling: you can add a service area while looking at a company or the whole organization. It used to refuse and tell you to change the switcher at the top first, which meant the one screen that could have asked which branch the area belonged to was the screen refusing to open. It asks now.',
        'Scheduling: zip codes are separate items you add one at a time and remove individually, instead of one long line of commas where a single mistyped separator could take the whole list with it. Each one saves on its own, so a zip another area already covers is refused on its own rather than refusing every other edit with it.',
        'Scheduling: a zip code can carry the city it is in, and Availability can then be searched by city - useful when somebody on the phone knows the town and not the postcode.',
        'Scheduling: the Availability search suggests as you type, and says whether each suggestion is a service area, a city or a zip code. It used to guess from what you typed - all digits meant a zip, anything else meant an area name - so a city found nothing and said "we do not cover that", which was a wrong answer in the right answer\'s words.',
        'Scheduling: the list of service areas is grouped under the branch that owns each one, with the company named too when two branches share a name. Twenty-six areas from several branches used to arrive as one flat list with nothing saying which belonged to what.',
        'Human Resources: a guide in progress on the Training card now says the screen the person has actually reached, matching what they see in Training.',
        'Web Management: the chat bubble and contact form are drawn beside their settings now, so you can see what a visitor will see while you change it - colours, wording and questions, updating as you type.',
      ],
    ),
    VersionRelease(
      version: '0.0.0173',
      date: '9 Sep 2026',
      items: [
        'You can set your business\'s brand colour in Organization, and see the button it makes on your website before you save it. Pick one of seven, or type a hex code. Until you set one, the widgets use a neutral blue and the screen says so.',
        'Web Management: seven ready-made colours for the chat and form widgets. Pick one and the button text picks itself, so the colours you choose are always readable - typing your own still works, and still warns you if the two are too close.',
        'On a phone, Your account now shows today\'s hours on the Work schedule line - "Today 08:00 - 17:00", or "Off today" - instead of listing which days you work.',
        'If nobody has set your working hours yet, it says so, rather than showing you as off every day.',
        'Leads now arrive from every Google Ads account under a connected manager, not only the accounts the signed-in user owns directly. For A-1 Chimney that is 23 more accounts, Stamford and Hamden among them.',
        'Searching leads works as you type, and a number matches from its first two digits in any format; the email and the last message are searched too.',
        'The divider between the lead list and the conversation can be dragged.',
        'A call in a conversation plays with the same bar as voicemails: play, pause, scrub.',
        'Human Resources: the Training card lays itself out by the space it is given, so it reads the same in a narrow column on a wide window as on a phone.',
        'Times across the app now follow a real place rather than a fixed offset, so they stay right when the clocks change. If your organization never chose a timezone it was on a clock that ignored daylight saving, and times between March and November will now read an hour later than they did.',
        'A clock that should ignore daylight saving is still available: the fixed UTC offsets are all still in the list.',
      ],
    ),
    VersionRelease(
      version: '0.0.0172',
      date: '9 Sep 2026',
      items: [
        'Human Resources: opening a person\'s record now shows their Training card - what they were assigned, what they passed, and what is due for retaking - with three counts that cover every row beneath them, and a note saying which of the two time panels is whose to act on. Someone who cannot reach the person sees why instead of an empty card.',
        'You can set a real place as your timezone now - Eastern, Central, Mountain, Pacific, Alaska, Hawaii and the Canadian equivalents - instead of only a fixed offset from UTC. A place knows when the clocks change, so times stay right all year; the offsets are still there for a clock that should ignore daylight saving.',
        'Business hours no longer says it is not in effect yet. It is: Clock In uses it to tell whether somebody is starting a shift outside hours.',
        'Our privacy policy now describes what happens when somebody uses the chat or contact form on one of your websites, and who to ask if they want their enquiry removed.',
      ],
    ),
    VersionRelease(
      version: '0.0.0171',
      date: '9 Sep 2026',
      items: [
        'Thumbtack: enquiries arrive as leads now. Thumbtack sends its events inside an envelope EzBizi was not opening, so every delivery was accepted and dropped without a trace.',
        'Email: mark a message as handled, so the team can tell what still needs answering. It is separate from Seen by, which stays a record of who looked - an automation can mark mail handled and cannot claim anyone read it.',
        'Email: an automation rule can now mark a message handled. The action that was offered before this ("Mark a message as dealt with") was never built and has been replaced; any rule using it would never have run.',
        'Clock in: people with no personal schedule now follow their branch\'s Business hours, so the lock screen, the end-of-shift reminder and the automatic clock-out work as soon as the organization turns them on.',
        'Web Management: your chat bubble and contact form start from your own brand colour now, not ours - they appear on your website, so they should look like you. Set it once and every site follows; a site that wants its own still overrides it.',
        'Web Management: the text on the button picks itself, black or white, so it stays readable whatever colour you choose.',
        'On a phone, Your account - Work schedule now opens with today at the top and the hours large, with the rest of the week beneath it. The desktop card is unchanged.',
        'Monitoring now records who added, changed or removed a camera, and who took control of a machine and when, in the same audit trail every other action leaves.',
      ],
    ),
    VersionRelease(
      version: '0.0.0170',
      date: '9 Sep 2026',
      items: [
        'Leads now has a row of source tabs - All, Google, Thumbtack, Chat, Forms - instead of the source sitting among the filter chips. It is still one list underneath: a tab narrows it, the same as the filter did.',
      ],
    ),
    VersionRelease(
      version: '0.0.0169',
      date: '8 Sep 2026',
      items: [
        'Training now lets a supervisor assign a guide or a test from the Dashboard, and records who changed what. Creating a guide from a company view works again, and the builder no longer takes the whole window.',
        'The lock screen clock is now big enough to read across a room.',
      ],
    ),
    VersionRelease(
      version: '0.0.0168',
      date: '8 Sep 2026',
      items: [
        'Work schedule per person: Human Resources - Records sets it; Your account shows it, and edits it when the organization allows.',
        'Five minutes before a scheduled shift ends (organization setting), a countdown offers Extend or Clock Out; when it reaches zero the shift is closed on the server.',
        'Lock screen: when the organization turns it on and a role includes Lock screen, the app shows only Clock In while the person is not clocked in on a working day. Windows and macOS cover the desktop; Records can exempt a person.',
        'The Clock in-out list marks shifts closed automatically, started outside schedule, or extended.',
        'Automations can react to a shift being extended, and can tell a shift that closed itself, or started outside the schedule, from an ordinary punch.',
        'A scheduled mailing and a scheduled post now say which clock they are set by, next to the time.',
        'The Newsletter calendar carries the same clock in each day\'s tooltip.',
        'The composer no longer promises the time will land on the branch\'s clock when it will not.',
        'New business hours now start on a real place rather than a fixed offset, so they keep working when the clocks change. Hours created before this keep whatever they were set to and are still shown - they are worth changing to a place.',
      ],
    ),
    VersionRelease(
      version: '0.0.0167',
      date: '8 Sep 2026',
      items: [
        'On a call you make from EzBizi, the person you called now hears that the call is recorded. Until now that announcement was played to whoever placed the call and never to the person on the other end - so the one party who needed telling was the one party who was not told. Calls you receive were always correct.',
        'You are no longer read your own recording warning before an outgoing call connects.',
        'Nothing is announced when the line is not recording, or on an internal call between two colleagues.',
        'Business hours can be opened and edited again. If the hours were created from a line belonging to a branch, the screen took the branch\'s own clock - and that clock was not among the ones the screen offered, so the editor came up blank. Nothing was lost; it could not be opened.',
        'The clock list is places now, not offsets: Eastern - New York rather than UTC-5. Offsets ignore daylight saving, so hours set that way answered an hour early for half the year. Existing hours keep whatever they were set to and are still shown, so nothing changes underneath you - but hours on a fixed offset are worth setting to a place.',
        'The week no longer steps up and down as days are opened and closed, and the form fits a phone. "24-hour" is said once above the week instead of twice on every open day.',
        'Web Management: a Widgets tab. The chat bubble and the contact form are dressed here - colours, wording, and the questions the form asks - and every site that follows the primary changes with it. Nobody edits a WordPress page to change a colour any more.',
        'Hours of operation now lines up on a phone as well as on a desktop. The times shrink to fit on one line rather than wrapping onto a second, which is what left the open days taller than the closed ones.',
        'Web Management: a website can say which branch it answers for, so an enquiry from a branch subdomain lands on that branch instead of at company level.',
        'Leads: replying to a website chat from the thread reaches the person on the page, the same way replying to a Google lead reaches them.',
        'Automations: a rule can now run when somebody starts a live chat or sends a contact form on one of your websites, and can tell which website it was.',
        'Website widget colours now show what a visitor will actually see. The accent and its text, and the text and its background, each render as a swatch of the real pair with a readability score beside them, and a note when the two are too close to read comfortably. It is advice, not a rule - a business whose brand really is low-contrast can still save it.',
        'A lead\'s conversation always reads oldest first, even when two messages arrived in the same second.',
      ],
    ),
    VersionRelease(
      version: '0.0.0166',
      date: '8 Sep 2026',
      items: [
        'Inspection: a report filed without a job now follows the same branch rules as every other report - visible only to people who can see the branch it belongs to. It used to appear in every branch\'s list, and anyone in the organization could open it, download its PDF or archive it by id.',
        'Leads is one list across Google Ads, Thumbtack and your website. Each row shows where it came from, a Source chip narrows the list, and Connected sources at the top opens every provider\'s accounts in one place.',
        'Automations: an event nothing reports yet is now marked in the picker, and a rule already built on one says on its row that nothing will set it off - instead of sitting switched on, looking finished, and never running.',
        'Website enquiries: a chat bubble and a contact form for the customer\'s own website. Both arrive in Leads beside the Google and Thumbtack ones, so a reply typed in the thread reaches the visitor on their page.',
        'Thumbtack enquiries reach the right company when two companies use the same Thumbtack business.',
        'Hours of operation reads as one table: every day the same height, ruled between, and the times sit tight instead of drifting.',
        'On a phone the opening and closing times wrap instead of running off the edge.',
        'Changing how a company answers its phone now needs permission to change phone settings. The screen was only reachable by people who had it, but the underlying request was not checked, so somebody with read-only access to Phone could have altered call routing and switched off call recording.',
        'A branch can no longer change the parent company\'s call handling by reaching up to it. Which settings you can change is bounded by where you work, the same way everything else is.',
        'Automations can now act on what a call was about. Tagging a call raises "A call was tagged", carrying the tag name - so a rule can watch for complaints without watching for bookings. Tagging was already compulsory before hanging up; until now it fed reports and nothing else.',
      ],
    ),
    VersionRelease(
      version: '0.0.0165',
      date: '8 Sep 2026',
      items: [
        'Leads: the Thumbtack tab shows every connected business. It showed one, so an account with several looked like it had one, and disconnecting appeared to do nothing while it was quietly working.',
        'Connected accounts that share a name (twelve Thumbtack businesses all called "A1 Chimney") now show their id beside the name until you name them, so you can tell which one you are renaming or disconnecting.',
      ],
    ),
    VersionRelease(
      version: '0.0.0164',
      date: '8 Sep 2026',
      items: [
        'Thumbtack: a connected account now receives its enquiries. Connecting used to succeed without telling Thumbtack where to send them, so nothing ever arrived. An account connected before this update needs disconnecting and connecting again once.',
        'Disconnecting a Thumbtack account now clears up properly on Thumbtack too: it removes the phone numbers EzBizi registered, deletes the webhook and revokes EzBizi\'s access. Before, it only forgot the account at this end.',
      ],
    ),
    VersionRelease(
      version: '0.0.0163',
      date: '8 Sep 2026',
      items: [
        'Leads you have not opened yet show in bold, you can star a lead, and a note on each lead says who wrote it and when. Filters for Unread, Unreplied and Starred narrow together, and Mark all read clears the list in one go.',
        'A lead can be booked from its conversation: Book creates the customer and the job in the franchise you choose, with a time if you have one, and the lead then shows its job. If the number already belongs to a customer you are asked which one, and nothing is chosen for you.',
        'The Leads list now puts the most recent call or message first, so a lead that just wrote is at the top even if it first came in days ago.',
        'Web Management: EzBizi can publish to a WordPress site now, not just be read from it. Connecting creates a restricted account on your site and hands EzBizi a key for it - one that can write posts, pages and images and nothing else, and that you can revoke from either end.',
        'Connecting a Thumbtack account works. It was refused at the last step of sign-in, and the message said nothing that would have helped anyone.',
        'Voicemails and call recordings play IN the app now, on every platform. On Windows the app used to save the recording into a temporary folder and hand it to whatever your computer opens MP3s with, so the sound came out of another application - or out of nothing at all if the computer had no MP3 player set up. It plays here now, the same way calls do.',
        'Playing a recording gives you a proper transport: pause, and a bar you can drag to go back a few seconds when somebody says a number too fast. It used to be a single Play button, so hearing a number again meant starting the whole message from the beginning.',
        'Recordings are no longer written to your computer\'s temporary folder to be played. A customer\'s voicemail used to be left there, unencrypted, after you listened to it. Nothing is written to disk at any point now.',
      ],
    ),
    VersionRelease(
      version: '0.0.0162',
      date: '8 Sep 2026',
      items: [
        'You can reply to a text enquiry from the lead\'s conversation. The message goes to the person through Google, in the account\'s own name, and appears in the thread at once. Calls cannot be replied to this way and the screen says so.',
        'Email: the line down the side of a quoted message is gone. The quoted text is greyed instead, so it still reads as somebody else\'s words rather than as part of the reply.',
        'Suggestions: the coloured stripe down the side of each row is gone. Whether a suggestion was approved or rejected is still said by the row\'s colour and its heading, which is where it was already being said.',
        'Web Management: the connection steps and the browser snippet are behind two buttons now, in a panel, instead of sitting open above and below the fields you came to edit.',
        'Web Management: the grey panels are gone from the SEO screen. They were on four blocks and not on the rest of the same screen, which read as a mistake rather than a style.',
        'Web Management: SEO has a Social links section - Facebook, Instagram, YouTube, Twitter / X, LinkedIn, TikTok, Yelp, Google Business, Pinterest, BBB, Nextdoor, Houzz, Angi, Thumbtack and HomeAdvisor, each with its own shortcode.',
        'Web Management: a social link follows the primary website like every other detail, and setting one on a branch site overrides just that one link.',
        'Web Management: the address has a Full address box now. Start typing, pick the match, and the street, city, state and ZIP fill themselves - the same lookup Settings has had, on the screen that was still typing all five by hand.',
        'Leads: connect a Thumbtack account and its enquiries arrive as leads, with the customer\'s name, their phone number and the whole conversation on each one. Replies you send from Thumbtack appear on the same thread.',
      ],
    ),
    VersionRelease(
      version: '0.0.0161',
      date: '8 Sep 2026',
      items: [
        'Communication - Settings - General now sets how calls are answered for the whole company, not one number at a time. It used to open with a line picker, so what happens when somebody rings had to be set once per number - and the next number bought answered differently from all the others, with nothing saying it would. A line can still be given its own answer, but it starts from the company\'s rather than from nothing.',
        'Because of that, what each line had been set to has been lifted into the company default and the lines cleared, so calls are answered - and recorded - exactly as before. Where two lines had been set differently, that setting is left blank under General for somebody to choose, rather than one line\'s answer quietly becoming everyone\'s. Ring group assignments are untouched.',
        'A voicemail box set for the company now catches voicemail from every line, so a box is no longer empty until somebody points each number at it by hand.',
        'On a desktop, playing a lead\'s call recording opens it in your audio player, and the thread says so instead of showing a stop control it cannot honour.',
        'Lead conversations are easier to read: your messages are filled in the app\'s colour, the enquirer\'s are outlined, and the open lead is highlighted in the list.',
      ],
    ),
    VersionRelease(
      version: '0.0.0160',
      date: '8 Sep 2026',
      items: [
        'Voicemail recordings play on the desktop app. The Play button did nothing there at all: it was drawn, enabled, pressed, and silent, because the part of the app that plays audio on Windows had never been written - only a placeholder that reported success. Recordings now open in whatever your computer uses for audio, which is what the button says.',
        'A recording that will not play now says why instead of doing nothing at all.',
        'Voicemail shows your messages even when no boxes have been set up. It used to say "no voicemail boxes yet" and stop, so an account with messages and no boxes was shown nothing.',
        'Lead and message times are now shown in the right hour. Google reports them in the advertising account\'s own time zone, and they were being read as UTC, so everything looked four hours older than it was.',
      ],
    ),
    VersionRelease(
      version: '0.0.0159',
      date: '8 Sep 2026',
      items: [
        'Training is now something you can actually use - read a guide, sit its test, and see who owes what.',
      ],
    ),
    VersionRelease(
      version: '0.0.0158',
      date: '8 Sep 2026',
      items: [
        'Communication - Settings - Tags: a company can now require a tag after every call. The person who took the call picks one before the desk will start another. It is off unless you turn it on, and the switch tells you to add a tag first if there are none - a requirement with nothing to choose would stop the phone.',
        'The prompt after a call used to be skippable. It appeared, the desk stayed usable behind it, and the next call went out with the last one still unaccounted for - so reports counted by tag had gaps nobody could see.',
        'Communication - Settings - Ring groups now says what its two lists are for. "Who it rings" is the people; "Lines it answers" is which main numbers route into the group, which is not the same as the line a person calls out from or their extension - those are set per person on Users. A number already taken now names the group that has it, instead of saying only that another group does.',
      ],
    ),
    VersionRelease(
      version: '0.0.0157',
      date: '8 Sep 2026',
      items: [
        'Communication - Phone - Messages: texts from the same number are one conversation now. Two messages from one person used to be two rows headed with the same number, which reads as two strangers, and opening one showed that message alone with no idea what came before it. Opening a conversation shows both sides in order, and the list shows the last thing said with "You:" in front of it when it was us.',
        'Communication - Phone - Voicemail opens on all your voicemails instead of on a box. Boxes only fill once a line is pointed at them, and none were - so every box was empty and the messages that did exist could not be reached from that screen at all. A box nothing is pointed at now says exactly that, rather than showing an empty list that looks like a quiet week.',
        'A voicemail box no longer prints "The company" under a name that already said which company. That line only appears now when it says something the name does not - whose box it is.',
      ],
    ),
    VersionRelease(
      version: '0.0.0156',
      date: '8 Sep 2026',
      items: [
        'Leads shows what was said. Each lead carries the last call or message under its name, with a dot when the enquirer is waiting on a reply, and opening a lead shows the whole conversation. Call recordings play from the thread.',
        'An Unreplied filter lists the leads where the enquirer spoke last.',
        'The lead\'s service ("Fireplace chimney") replaces the category Google assigned, which was wrong for most leads.',
        'On phones, the search, status and Fetch controls no longer overflow the screen.',
        'Your phone now stays on the hook wherever you are in EzBizi. It did '
            'not before: the phone was switched on by opening Communication '
            'and switched off again by leaving it, so between signing in and '
            'going to the phone - and any time you were on Jobs, or '
            'Customers, or anywhere else - a call had nowhere to arrive and '
            'nothing said so. The dial pad looked perfectly fine, because '
            'coming back to it was what turned the phone on.',
        'A call now appears as a bar across the top of whatever you are '
            'doing, with Answer, Decline and a way through to the phone '
            'screen. It is a bar and not a pop-up on purpose: you can answer '
            'and carry on typing up the job you were in the middle of. Once '
            'you are on the call the bar stays, so you can hang up from '
            'wherever you have got to.',
      ],
    ),
    VersionRelease(
      version: '0.0.0155',
      date: '8 Sep 2026',
      items: [
        'Google Ads accounts are now named the way Google names them ("A1 Chimney - Cleveland") instead of by their digits.',
        'Google Ads and Business Profile accounts no longer appear under Pages in Social Media Management; nothing can be posted to them.',
        'Leads now carry every call and message on the enquiry: who spoke, when, call length, and message text. The list shows the latest one under each lead; the detail shows the thread; call recordings play through EzBizi.',
        'Automations gain a trigger, "Enquirer called or messaged".',
      ],
    ),
    VersionRelease(
      version: '0.0.0154',
      date: '8 Sep 2026',
      items: [
        'Web Management: pick a primary website and the others follow it. Set your company name once and every site publishes it, until a site sets its own — which overrides just that one thing, for just that site.',
        'Web Management: the address is separate boxes now — street, suite, city, state, ZIP — and the single line your website shows is built from them.',
        'Web Management: hours of operation is a day-by-day grid with a clock and a closed switch, instead of a line of text you had to type in the right shape.',
        'Web Management: SEO has sections, starting with General, and the fields are smaller so more of them fit on screen at once.',
      ],
    ),
    VersionRelease(
      version: '0.0.0153',
      date: '8 Sep 2026',
      items: [
        'Leads: every connected Google Ads account is visible, and each one '
            'can be given a name and pointed at a franchise. One connect made '
            'twenty-two accounts in the same second, and the screen showed one '
            'of them - whichever came back first - so twenty-one were '
            'invisible and the one on screen was arbitrary.',
        'Leads: an account can be named. Twenty-two rows of phone numbers are '
            'hard to tell apart, so you can call one Connecticut and it keeps '
            'its number beside the name for checking against Google Ads.',
        'Leads: sending an account\'s leads to a franchise moves the leads it '
            'has already produced, and the screen says how many moved. If '
            'there were none to move it says that too, rather than going '
            'quiet and looking like it failed.',
      ],
    ),
    VersionRelease(
      version: '0.0.0152',
      date: '8 Sep 2026',
      items: [
        'Your selected company or franchise stays with your requests, even '
            'when another device changes its view. Shared records remain '
            'readable, with changes limited to the places you manage.',
        'Newsletters keep the mailing account chosen when scheduled, prevent '
            'duplicate sends, and respect unsubscribed recipients. Cancel a '
            'scheduled mailing before changing its content or audience.',
        'Inventory cancellation and stock movements now agree when they '
            'happen together. Late phone updates keep completed calls complete.',
        'Automations preserve where an event happened after a record is '
            'deleted and retry temporary webhook failures without repeating '
            'actions that already succeeded.',
        'Expired sessions clear consistently, and sign-in protection, remote '
            'image loading, and release verification have been strengthened.',
      ],
    ),
    VersionRelease(
      version: '0.0.0151',
      date: '4 Sep 2026',
      items: [
        'Newsletter: write a mailing in blocks or as HTML, with the real email '
            'shown beside the editor as you type. It sends through the mailing '
            'account your company connects - Mailchimp - so it goes out on your '
            'own list and your own sending reputation, not EzBizi\'s.',
        'Newsletter opens on a dashboard: what has gone out, what is waiting, and '
            'a month calendar of everything scheduled. Tap a scheduled mailing to '
            'open and edit it until it goes.',
        'Settings has an Integrations tab listing every account this view is '
            'connected to - social, mailing, advertising - and where each is '
            'managed.',
        'Suggestions: the tab where you write one has the layout you chose - your '
            'suggestions listed on the left, the one you are reading beside them. '
            'And a suggestion marked as in review can no longer carry a note, '
            'because in review tells the author nothing and the note would never '
            'have been seen.',
        'The desktop app opens once. Launching it while it is already open brings '
            'the running window forward instead of starting a second copy.',
        'An inspection event says which branch it happened at, so an automation '
            'rule scoped to one location fires for that location\'s inspections '
            'and not for everybody\'s.',
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
