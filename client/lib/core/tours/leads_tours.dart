import '../tab_tours.dart';

/// Leads' sources, Customers › Memberships and Customer Experience's two
/// tabs, each its own walkthrough, keyed as tourKeyFor() looks them up (see
/// reports_tours.dart for the pattern).
///
/// Leads' sources are keyed by their names in the module's permission tabs:
/// `leads/Messenger` is the source the rail calls Facebook. The rail is not a
/// TabBarLike, so these run once it says which source is open. Manage's own
/// strip is one, so its tabs are `leads/Connections`, `leads/Templates` and
/// `leads/Dashboard`.
const Map<String, Tour> leadsTours = {
  'leads/Thumbtack': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'Thumbtack',
        body:
            'Only Thumbtack enquiries, with how many are unread. A red mark '
            'means a Thumbtack account has stopped delivering.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'Thumbtack',
        body:
            'Only Thumbtack enquiries, with how many are unread. Tap it for '
            'every source, and how many Thumbtack businesses are connected.',
      ),
      TourStep(
        anchor: 'leads-list',
        title: 'The enquiries',
        body:
            'Awaiting reply means the customer wrote last. Test marks a sample '
            'Thumbtack sent, which has nobody to reply to.',
      ),
      TourStep(
        anchor: 'thread-list',
        title: 'The negotiation',
        body:
            'Every message on the Thumbtack negotiation, the newest at the '
            'bottom.',
      ),
      TourStep(
        anchor: 'reply-box',
        title: 'Reply on Thumbtack',
        body:
            'Your reply goes to the customer on Thumbtack. If the account is '
            'no longer connected, a note says so instead.',
      ),
      TourStep(
        anchor: 'sources-manage',
        title: 'Thumbtack accounts',
        body:
            "Manage lists the Thumbtack businesses connected, and where each "
            "one's leads go.",
      ),
    ],
  ),
  'leads/Chat': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'Chat',
        body:
            'Only chats from the chat box on your website, with how many are '
            'unread.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'Chat',
        body:
            'Only chats from the chat box on your website, with how many are '
            'unread.',
      ),
      TourStep(
        anchor: 'leads-list',
        title: 'The chats',
        body:
            'Awaiting reply means the visitor wrote last. A red warning means '
            'they have waited more than 10 minutes.',
      ),
      TourStep(
        anchor: 'thread-list',
        title: 'A live chat',
        body:
            'While it is open the chat refreshes every few seconds, and says '
            'when the visitor is typing.',
      ),
      TourStep(
        anchor: 'reply-box',
        title: 'Reply in the chat',
        body:
            'Your reply appears in the chat on your website, and the visitor '
            'sees that you are typing.',
      ),
      TourStep(
        anchor: 'sources-manage',
        title: 'Your website',
        body: 'Chats arrive through the EzBizi WordPress plugin on your site.',
      ),
    ],
  ),
  'leads/Forms': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'Forms',
        body: 'Only forms sent from your website, with how many are unread.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'Forms',
        body: 'Only forms sent from your website, with how many are unread.',
      ),
      TourStep(
        anchor: 'leads-list',
        title: 'The forms',
        body:
            'Each one is tagged Form. Good Rating or Bad Rating marks an '
            'answer to the rating form on your review link.',
      ),
      TourStep(
        anchor: 'thread-list',
        title: 'What they sent',
        body:
            'The form as it came in. A form has no chat to answer into, so '
            'there is no reply box.',
      ),
      TourStep(
        anchor: 'lead-call',
        title: 'Call them',
        body: "Opens EzBizi's dial pad with their number on it.",
      ),
      TourStep(
        anchor: 'book-open',
        title: 'Book',
        body:
            'Creates a customer and a job from the form, and marks the lead '
            'booked.',
      ),
      TourStep(
        anchor: 'sources-manage',
        title: 'Your website',
        body: 'Forms arrive through the EzBizi WordPress plugin on your site.',
      ),
    ],
  ),
  'leads/Online Booking': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'Online Booking',
        body:
            "Only appointments asked for on your website's booking widget, "
            'with how many are unread.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'Online Booking',
        body:
            "Only appointments asked for on your website's booking widget, "
            'with how many are unread.',
      ),
      TourStep(
        anchor: 'lead-booking',
        title: 'The requested day',
        body:
            'The day and arrival window they picked, and where the request '
            'stands.',
      ),
      TourStep(
        anchor: 'booking-approve',
        title: 'Approve or decline',
        body:
            'Approve holds the window until the job is booked; Decline gives '
            'it back. Neither one messages the customer.',
      ),
      TourStep(
        anchor: 'booking-fit',
        title: 'How it fits',
        body:
            'How the window sits beside work already booked nearby, or a '
            'better day. The customer does not see this.',
      ),
      TourStep(
        anchor: 'book-open',
        title: 'Book',
        body: 'Creates a customer and a job, and marks the lead booked.',
      ),
    ],
  ),
  'leads/Messenger': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'Facebook',
        body:
            'Only Messenger messages to your Facebook Page, with how many are '
            'unread.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'Facebook',
        body:
            'Only Messenger messages to your Facebook Page, with how many are '
            'unread.',
      ),
      TourStep(
        anchor: 'leads-list',
        title: 'The messages',
        body:
            'Each one is tagged Facebook. Awaiting reply means they wrote '
            'last.',
      ),
      TourStep(
        anchor: 'reply-box',
        title: 'Reply on Messenger',
        body:
            'Your reply goes to them on Messenger, and only within 24 hours '
            'of their last message. After that a note stands in place of the '
            'box.',
      ),
    ],
  ),
  'leads/Instagram': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'Instagram',
        body:
            'Only direct messages to your Instagram account, with how many '
            'are unread.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'Instagram',
        body:
            'Only direct messages to your Instagram account, with how many '
            'are unread.',
      ),
      TourStep(
        anchor: 'leads-list',
        title: 'The messages',
        body:
            'Each one is tagged Instagram. Awaiting reply means they wrote '
            'last.',
      ),
      TourStep(
        anchor: 'reply-box',
        title: 'Reply on Instagram',
        body:
            'Your reply goes to them as an Instagram message, and only within '
            '24 hours of their last message. After that a note stands in '
            'place of the box.',
      ),
    ],
  ),
  'leads/Workiz': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'Workiz',
        body: 'Only leads copied from Workiz, with how many are unread.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'Workiz',
        body: 'Only leads copied from Workiz, with how many are unread.',
      ),
      TourStep(
        anchor: 'leads-list',
        title: 'Records, not messages',
        body:
            'Each one is a lead somebody typed into Workiz, so there is no '
            'reply box and none is in Unreplied.',
      ),
      TourStep(
        anchor: 'lead-call',
        title: 'Call them',
        body: "Opens EzBizi's dial pad with their number on it.",
      ),
      TourStep(
        anchor: 'book-open',
        title: 'Book',
        body: 'Creates a customer and a job, and marks the lead booked.',
      ),
    ],
  ),
  'leads/RC Calls': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'RC Calls',
        body:
            'Calls straight to your own phone lines, from your website, '
            'Google profile or ads. A Google Local Services call is never '
            'shown here twice.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'RC Calls',
        body:
            'Calls straight to your own phone lines, from your website, '
            'Google profile or ads. A Google Local Services call is never '
            'shown here twice.',
      ),
      TourStep(
        anchor: 'leads-list',
        title: 'One caller, one lead',
        body:
            'Each caller is one lead, with every call they made, the line '
            'they rang, the recording and what was said.',
      ),
      TourStep(
        anchor: 'lead-call',
        title: 'Call them back',
        body: "Opens EzBizi's dial pad with their number on it.",
      ),
    ],
  ),
  'leads/WhatsApp': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'source-rail',
        title: 'WhatsApp',
        body:
            'Only people who messaged your WhatsApp number and picked I need '
            'a service, with how many are unread.',
      ),
      TourStep(
        anchor: 'source-button',
        title: 'WhatsApp',
        body:
            'Only people who messaged your WhatsApp number and picked I need '
            'a service, with how many are unread.',
      ),
      TourStep(
        anchor: 'leads-list',
        title: 'The conversations',
        body:
            'Each one is tagged WhatsApp. Awaiting reply means they wrote '
            'last.',
      ),
      TourStep(
        anchor: 'reaction',
        title: 'Reactions',
        body: 'An emoji they react with hangs on the message it answers.',
      ),
      TourStep(
        anchor: 'reply-box',
        title: 'Reply on WhatsApp',
        body:
            'Your reply goes from the WhatsApp number they wrote to, and only '
            'within 24 hours of their last message. After that a note stands '
            'in place of the box.',
      ),
    ],
  ),
  'leads/Connections': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'connections-providers',
        title: 'Where leads come from',
        body:
            'Google Ads, Thumbtack and your website. Each says what is '
            'connected and how many accounts need attention.',
      ),
      TourStep(
        anchor: 'accounts-search',
        title: 'Find an account',
        body: 'By its name, its number, or where its leads go.',
      ),
      TourStep(
        anchor: 'accounts-fold',
        title: 'Never sent a lead',
        body:
            'Accounts that have never sent a lead fold away here. Search '
            'still reaches them.',
      ),
      TourStep(
        anchor: 'account-name',
        title: 'Name it',
        body:
            'The account in your own words. Left empty, it goes back to its '
            'number.',
      ),
      TourStep(
        anchor: 'account-place',
        title: 'Where its leads go',
        body:
            'Company level, or one franchise. Moving it moves its leads with '
            'it.',
      ),
      TourStep(
        anchor: 'account-disconnect',
        title: 'Disconnect',
        body: 'Lets this account go, after asking once.',
      ),
    ],
  ),
  'leads/Templates': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'reply-templates-manager',
        title: 'Reply templates',
        body:
            'The quick replies beside Send, one list for each company, so '
            'each brand signs its own name.',
      ),
      TourStep(
        anchor: 'template-add',
        title: 'New template',
        body:
            "Adds a button to this company's list. Drag one to change the "
            'order of the buttons beside Send.',
      ),
      TourStep(
        anchor: 'template-text',
        title: 'Message',
        body:
            'What the button puts in the reply box. Insert adds a greeting, '
            'the company name, what is missing, or availability.',
      ),
      TourStep(
        anchor: 'template-preview',
        title: 'What the customer gets',
        body: 'The message as a customer would read it, filled in as you type.',
      ),
      TourStep(
        anchor: 'template-copy',
        title: 'Copy to other companies',
        body:
            'Puts this template in each company you tick, replacing one with '
            'the same name.',
      ),
      TourStep(
        anchor: 'templates-save',
        title: 'Save',
        body:
            'Keeps your changes. Until then the buttons beside Send stay as '
            'they were.',
      ),
    ],
  ),
  'leads/Dashboard': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'leads-dashboard',
        title: 'Replies and leads',
        body:
            'Today, 7 days, 30 days, this month or your own dates, for every '
            'number below.',
      ),
      TourStep(
        anchor: 'dashboard-source',
        title: 'Source',
        body: 'All sources, or one at a time.',
      ),
      TourStep(
        anchor: 'tile-waiting',
        title: 'Waiting now',
        body:
            'Leads waiting more than 10 minutes for a reply, over the last 24 '
            'hours.',
      ),
      TourStep(
        anchor: 'dashboard-per-day',
        title: 'Leads per day',
        body: 'Each day by source. Point at a bar for its numbers.',
      ),
      TourStep(
        anchor: 'dashboard-speed',
        title: 'First reply speed',
        body:
            'How soon the first reply came, from under 5 minutes to over 4 '
            'hours.',
      ),
      TourStep(
        anchor: 'dashboard-people',
        title: 'Each person',
        body:
            'Replies, leads answered, first reply, within 10 minutes and '
            'booked, for each person. Automatic replies and replies sent '
            'outside EzBizi have rows of their own.',
      ),
    ],
  ),
  'customers/Memberships': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-memberships',
        title: 'Memberships',
        body:
            'The membership program: Dashboard, Members and Settings, each '
            'opened to a role on its own.',
      ),
      TourStep(
        anchor: 'tour-memberships-tabs',
        title: 'Three parts',
        body:
            'Dashboard for the counts, Members for the people, Settings for '
            'the plans, as your role allows.',
      ),
    ],
  ),
  'customers/Memberships/Dashboard': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-memberships-dashboard',
        title: 'At a glance',
        body:
            'Members active this year, those renewing in the next 30 days, '
            'and those who lapsed. Money shows only for roles that may see it.',
      ),
      TourStep(
        anchor: 'tour-memberships-renewing',
        title: 'Renewing soon',
        body:
            'Each member whose year ends in the next 30 days: their plan, the '
            'renewal date, and whether the reminder went or a visit is booked.',
      ),
      TourStep(
        anchor: 'tour-memberships-by-plan',
        title: 'Members by plan',
        body: 'How many active members each plan has.',
      ),
    ],
  ),
  'customers/Memberships/Members': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-memberships-members-tiles',
        title: 'Who to show',
        body:
            'Active, Renewing soon, Lapsed, and Amount due for roles that see '
            'money. Click one to narrow the list, and again to clear it.',
      ),
      TourStep(
        anchor: 'members-search',
        title: 'Find a member',
        body: 'By name, plan, state, zip, email or phone.',
      ),
      TourStep(
        anchor: 'members-add',
        title: 'Add a member',
        body:
            'Puts a customer on a plan, from the day you pick or today. A '
            'plan must be open to join first, under Settings.',
      ),
      TourStep(
        anchor: 'tour-memberships-members-list',
        title: 'The members',
        body:
            'Each member with their plan, new or renewal, and when their year '
            'renews. Click one to open it.',
      ),
      TourStep(
        anchor: 'tour-memberships-member',
        title: 'One member',
        body:
            'Their year, status, contact details and plan, the renewal '
            'reminder and the next visit.',
      ),
      TourStep(
        anchor: 'member-cancel',
        title: 'Cancel membership',
        body: 'Ends this membership, after asking once.',
      ),
    ],
  ),
  'customers/Memberships/Settings': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-memberships-plans',
        title: 'Your plans',
        body:
            'Each plan with its price, how it is billed, its members this year '
            'and whether it is open to join. Click one to change it, if your '
            'role allows.',
      ),
      TourStep(
        anchor: 'plans-add',
        title: 'Add a plan',
        body: 'Opens a new plan above the list.',
      ),
      TourStep(
        anchor: 'plan-editor',
        title: 'The plan',
        body:
            'Its name, the price for the year, and yearly or monthly. What it '
            'includes shows on the page a customer joins from.',
      ),
      TourStep(
        anchor: 'tour-memberships-renewal-note',
        title: 'Renewal reminder',
        body:
            "A month before a member's year ends, the Renewal due in a month "
            'trigger fires once. What it sends is set in Automations.',
      ),
    ],
  ),
  'customer_experience/Reviews': Tour(
    // 2: the A1Leads GBP takeover (UI queue 101).
    version: 2,
    steps: [
      TourStep(
        anchor: 'tour-customer_experience-unanswered',
        title: 'Unanswered',
        body:
            'On when the tab opens: only the reviews nobody has replied to, '
            'with how many.',
      ),
      TourStep(
        anchor: 'tour-customer_experience-rating',
        title: 'Rating',
        body: 'Negative (1–3★), positive, one star rating, or any.',
      ),
      TourStep(
        anchor: 'reviews-matched',
        since: 2,
        title: 'Time, sort, location, match, status',
        body:
            'Narrow the list further: how recent, the order, which location, '
            'whether the reviewer is matched to a customer, and where working '
            'it has got to.',
      ),
      TourStep(
        anchor: 'reviews-view',
        since: 2,
        title: 'Table or Board',
        body: 'Table is one line a review; Board shows each review whole.',
      ),
      TourStep(
        anchor: 'tour-customer_experience-fetch',
        title: 'Fetch new',
        body: 'Reads new reviews now and says how many arrived.',
      ),
      TourStep(
        anchor: 'tour-reviews-profile',
        title: 'The profile',
        body:
            'Which Business Profile the reviews come from. Change it in '
            'Settings › Integrations.',
      ),
      TourStep(
        anchor: 'tour-customer_experience-reviews',
        title: 'The reviews',
        body:
            'Click a review to open it beside the list: the reply, the matched '
            'customer and their job, its status, notes, and Report to Google.',
        since: 2,
      ),
      TourStep(
        anchor: 'tour-reviews-empty',
        title: 'Nothing here',
        body:
            'Says why: no Business Profile connected, every review answered, '
            'or none for these filters.',
      ),
    ],
  ),
  'customer_experience/Business Profile': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-business-profile-not-showing',
        title: 'Not showing',
        body: 'Only the listings Google is not showing on Maps, with how many.',
      ),
      TourStep(
        anchor: 'tour-customer_experience-check-google',
        title: 'Check Google',
        body:
            'Reads your listings from Google again and says how many it '
            'checked.',
      ),
      TourStep(
        anchor: 'tour-business-profile-account',
        title: 'The profile',
        body:
            'Which Business Profile is connected. Change it in Settings › '
            'Integrations.',
      ),
      TourStep(
        anchor: 'tour-business-profile-listings',
        title: 'Your listings',
        body:
            "Each listing's address and category. A red mark with Google's "
            'own word, such as suspended, means Google is not showing it.',
      ),
      TourStep(
        anchor: 'tour-business-profile-empty',
        title: 'Nothing here',
        body:
            'Says why: no profile connected, every listing showing, or none '
            'fetched yet.',
      ),
    ],
  ),
};
