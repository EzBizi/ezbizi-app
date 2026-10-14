import '../tab_tours.dart';

/// Reports' sub tabs, each its own walkthrough (Jose, 2026-10-14: on Report
/// Builder "(?) runs STEP 1 OF 1 — Reports", nothing about the Builder).
///
/// Keyed as `tourKeyFor` looks them up: the module, then the label of the tab
/// open in each strip on screen. A child module drawn in the strip (Google
/// Ads, Profit & Loss) is keyed by the label it has there.
const Map<String, Tour> reportsTours = {
  'reports/Report Builder': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'builder-field-search',
        title: 'Fields',
        body:
            'Find what the report should show, and click a field to add it '
            'as a column.',
      ),
      TourStep(
        anchor: 'builder-columns',
        title: 'Columns',
        body: 'Drag a column to move it. Click one to change or remove it.',
      ),
      TourStep(
        anchor: 'builder-add-filter',
        title: 'Filters',
        body: 'Keep only the rows you want, for example one branch or a date.',
      ),
      TourStep(
        anchor: 'builder-match',
        title: 'All or any',
        body: 'Whether a row has to match every filter, or just one of them.',
      ),
      TourStep(
        anchor: 'builder-add-group',
        title: 'Group',
        body:
            'Rows by a field, such as each technician or each month, with '
            'totals for each group.',
      ),
      TourStep(
        anchor: 'builder-add-total',
        title: 'Totals',
        body: 'A count, a sum or an average of a column.',
      ),
      TourStep(
        anchor: 'builder-preview',
        title: 'Preview',
        body: 'The first rows of the report as it stands, before you save it.',
      ),
    ],
  ),
  'reports/Performance': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'call-report-period',
        title: 'The dates',
        body: 'Which days of calls the report covers.',
      ),
      TourStep(
        anchor: 'call-report-tabs',
        title: 'Overview, People, Calls',
        body:
            'The totals first, then each person who took calls, then every '
            'call on its own.',
      ),
      TourStep(
        anchor: 'overview-missed',
        title: 'Missed calls',
        body: 'Calls nobody answered, split into new and existing customers.',
      ),
      TourStep(
        anchor: 'overview-not-booked',
        title: 'Not booked',
        body: 'Calls that ended without a job, and the reasons why.',
      ),
      TourStep(
        anchor: 'labeller',
        title: 'Reasons by',
        body: 'Which AI read the calls for the Reason column.',
      ),
      TourStep(
        anchor: 'calls-search',
        title: 'Find a call',
        body: 'Narrows the calls as you type.',
      ),
      TourStep(
        anchor: 'call-report-sources',
        title: 'Sources',
        body: 'The Workiz accounts whose calls and jobs this report reads.',
      ),
    ],
  ),
  'reports/Scoring': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-report-dates',
        title: 'The dates',
        body:
            'Which days of scored calls the cards and the ranking cover. Today, Yesterday, 1 week or 1 month in one click.',
      ),
      TourStep(
        anchor: 'scoring-sort',
        title: 'Score or Most improved',
        body:
            'Order everybody by their score, or by how much it rose since the '
            'period before.',
      ),
      TourStep(
        anchor: 'tour-scoring-cards',
        title: 'Each person',
        body:
            'Their score, calls scored and what to work on. Click a card for '
            "the AI's daily and weekly analysis.",
      ),
      TourStep(
        anchor: 'scoring-calls-search',
        title: 'Their calls',
        body:
            "In a person's analysis: their latest three calls and their best "
            'and worst, each with its score. Search finds any of their calls '
            'by number or words.',
      ),
      TourStep(
        anchor: 'scoring-ranking',
        title: 'Ranking',
        body: 'Everybody in order, with how far each moved.',
      ),
      TourStep(
        anchor: 'scoring-empty',
        title: 'Nothing scored yet',
        body: 'No call in these dates has been scored.',
      ),
    ],
  ),
  'reports/Marketing': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-report-dates',
        title: 'The dates',
        body:
            'Which days of ad spend and jobs the report covers. Today, Yesterday, 1 week or 1 month in one click.',
      ),
      TourStep(
        anchor: 'marketing-fact-cost',
        title: 'Ad cost',
        body: 'What the advertising cost over these dates.',
      ),
      TourStep(
        anchor: 'marketing-fact-net',
        title: 'Net profit',
        body: "An estimate: the jobs' profit, less what the ads cost.",
      ),
      TourStep(
        anchor: 'marketing-table',
        title: 'By branch and source',
        body:
            'Each branch and source on its own row, with a total at the foot.',
      ),
      TourStep(
        anchor: 'marketing-empty',
        title: 'Nothing yet',
        body: 'No spend or jobs in these dates.',
      ),
    ],
  ),
  'reports/Meta': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-report-dates',
        title: 'The dates',
        body:
            'Which days of Facebook ad spend the report covers. Today, Yesterday, 1 week or 1 month in one click.',
      ),
      TourStep(
        anchor: 'meta-state',
        title: 'Narrow it',
        body: 'By state, category and where the viewers were.',
      ),
      TourStep(
        anchor: 'meta-totals',
        title: 'Totals',
        body: 'Spend and results for everything the filters keep.',
      ),
      TourStep(
        anchor: 'meta-table',
        title: 'Campaigns',
        body: 'Each campaign on its own row.',
      ),
      TourStep(
        anchor: 'meta-edit-campaigns',
        title: 'Campaign details',
        body: "Set each campaign's state, category and branch.",
      ),
      TourStep(
        anchor: 'meta-empty',
        title: 'Nothing yet',
        body: 'No Facebook ad spend in these dates.',
      ),
    ],
  ),
  'reports/Thumbtack': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-report-dates',
        title: 'The dates',
        body:
            'Which days of Thumbtack leads the report covers. Today, Yesterday, 1 week or 1 month in one click.',
      ),
      TourStep(
        anchor: 'thumbtack-state',
        title: 'Narrow it',
        body: 'By category, state and branch.',
      ),
      TourStep(
        anchor: 'thumbtack-totals',
        title: 'Totals',
        body: 'Spend and charged leads for everything the filters keep.',
      ),
      TourStep(
        anchor: 'thumbtack-table',
        title: 'By category',
        body:
            'Each category with its state and branch: leads, refunds, spend '
            'and how many were booked.',
      ),
      TourStep(
        anchor: 'thumbtack-empty',
        title: 'Nothing yet',
        body: 'No Thumbtack leads in these dates.',
      ),
    ],
  ),
  'reports/Google Ads': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'ads-refresh',
        title: 'Read again',
        body: 'Reads the figures from Google again.',
      ),
      TourStep(
        anchor: 'ads-account-search',
        title: 'Find an account',
        body: 'Narrows the accounts as you type.',
      ),
      TourStep(
        anchor: 'ads-accounts',
        title: 'Accounts',
        body:
            'Managers stand out in their own colour, above the accounts in them.',
      ),
      TourStep(
        anchor: 'ads-account-figures',
        title: 'The account',
        body: 'Cost, clicks and the rest, for the dates chosen.',
      ),
      TourStep(
        anchor: 'ads-campaign-search',
        title: 'Find a campaign',
        body: 'Narrows the campaigns as you type.',
      ),
    ],
  ),
  'reports/Profit & Loss': Tour(
    version: 1,
    steps: [
      TourStep(
        anchor: 'tour-reports-pl-controls',
        title: 'Profit & Loss',
        body: 'Where the figures come from, and which week.',
      ),
    ],
  ),
};
