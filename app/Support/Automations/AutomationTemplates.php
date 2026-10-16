<?php

namespace App\Support\Automations;

/**
 * Ready-made rules somebody can start from, the same for every business.
 *
 * Jose, 2026-09-17: *"i want to have Templates as well, these will be global,
 * with basic actions that you can think of we'll be adding more, so people can
 * easily select them and apply them."*
 *
 * ── IN CODE, NOT IN A TABLE, and that is what "global" buys ──────
 *
 * A template is the same in every organization, so a table would hold one
 * identical row per tenant and a migration would have to reconcile them every
 * time the wording changed. Here, adding one is a commit and it exists
 * everywhere at once — which is what Jose asked for when he said "we'll be
 * adding more".
 *
 * ── A TEMPLATE IS A STARTING POINT, NOT A RULE ───────────────────
 *
 * Applying one fills the builder in and saves NOTHING. The person still names
 * it, still sees every condition and step, and still presses the button — so a
 * template cannot switch anything on behind somebody's back, and a bad one
 * costs a glance rather than an incident.
 *
 * That is also why none of these carry an id. A template naming a role, a
 * report or a phone number would be naming something that exists in one
 * business and not in the next, and it would arrive as a picker that looks
 * chosen and is wrong. Every template here fills in words and leaves the
 * choosing to the person.
 */
final class AutomationTemplates
{
    /**
     * @return list<array{key: string, name: string, description: string, trigger_key: string, conditions: ?array, actions: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'new-enquiry',
                'name' => 'Tell the office about every new enquiry',
                'description' => 'Whenever a lead arrives from anywhere — live chat, a form, '
                    .'Google or Thumbtack — everybody who can see it is told, and the message '
                    .'says which one it came from.',
                'trigger_key' => 'lead.received',
                'conditions' => null,
                'actions' => [
                    // Jose's two lines (2026-10-06, title shortened by him
                    // the next day): 'New "Local Services Call" lead', then
                    // number, length, company, place.
                    ['type' => 'notify', 'message' => '{{lead_headline}}', 'details' => '{{lead_details}}'],
                ],
            ],
            [
                'key' => 'website-chat-only',
                'name' => 'Tell the office about website chats only',
                'description' => 'The same, narrowed to the live chat on your own website — '
                    .'useful when the other sources already reach somebody else.',
                'trigger_key' => 'lead.received',
                'conditions' => [
                    'all' => [
                        ['field' => 'source', 'op' => 'eq', 'value' => 'website_chat'],
                    ],
                ],
                'actions' => [
                    ['type' => 'notify', 'message' => 'Somebody is chatting on the website.'],
                ],
            ],
            [
                'key' => 'job-finished',
                'name' => 'Tell the office when a job is finished',
                'description' => 'Fires when a job\'s status becomes completed, so whoever '
                    .'invoices does not have to watch the board.',
                'trigger_key' => 'job.status_changed',
                'conditions' => [
                    'all' => [
                        ['field' => 'status', 'op' => 'eq', 'value' => 'completed'],
                    ],
                ],
                'actions' => [
                    ['type' => 'notify', 'message' => 'Job finished: {{title}}.'],
                ],
            ],
            [
                'key' => 'mailbox-broken',
                'name' => 'Say something when a mailbox stops working',
                'description' => 'A disconnected mailbox is silent by nature — nothing arrives '
                    .'and nothing complains. This is the rule that notices.',
                'trigger_key' => 'email.mailbox_broken',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => 'A mailbox has stopped working: {{address}}. '
                        .'Nothing is arriving from it until somebody reconnects it.'],
                ],
            ],
            [
                'key' => 'welcome-new-person',
                'name' => 'Welcome somebody on their first day',
                'description' => 'Runs when an account is activated, which is the moment a new '
                    .'person can actually open EzBizi.',
                'trigger_key' => 'user.activated',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => '{{name}} has joined and can sign in now.'],
                ],
            ],

            /*
             * ── Added 2026-09-24 ──────────────────────────────────────
             *
             * Jose: "think of what a company would be needing and make some
             * more templates". Every {{field}} and condition below was read
             * off the emitter's payload or the subject's columns, not guessed:
             * a variable the event does not carry renders as nothing, and a
             * condition on it never matches, with no error anywhere.
             */

            // Leads
            [
                'key' => 'answer-thumbtack',
                'name' => 'Answer Thumbtack leads straight away',
                'description' => 'Sends a short reply the moment a Thumbtack lead arrives, so '
                    .'nobody is left waiting while the office is busy.',
                'trigger_key' => 'lead.received',
                'conditions' => [
                    'all' => [
                        ['field' => 'source', 'op' => 'eq', 'value' => 'thumbtack'],
                    ],
                ],
                'actions' => [
                    ['type' => 'reply', 'message' => 'Hi {{contact_name}}, thank you for reaching out. '
                        .'We have your request and will be in touch shortly.'],
                ],
            ],
            [
                'key' => 'mailing-list-opt-in',
                'name' => 'Add website visitors who opt in to your mailing list',
                'description' => 'Only people who ticked the box on your website form or chat '
                    .'are added. Everybody else is left off. Choose which Mailchimp list.',
                'trigger_key' => 'lead.received',
                'conditions' => null,
                'actions' => [
                    ['type' => 'add_to_audience', 'audience_id' => ''],
                ],
            ],
            [
                'key' => 'customer-replied',
                'name' => 'Tell the office when a customer replies',
                'description' => 'When somebody who already enquired writes or calls again, on any '
                    .'source. A brand new lead does not set it off, and neither do your own replies.',
                'trigger_key' => 'lead.customer_replied',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => '{{reply_headline}}', 'details' => '{{reply_preview}}'],
                ],
            ],
            [
                'key' => 'customer-waiting',
                'name' => 'Warn dispatchers when a customer is left waiting',
                'description' => 'When a customer has written and nobody has answered for 10 '
                    .'minutes, the dispatchers hear about it. Not for leads already booked.',
                'trigger_key' => 'lead.reply_overdue',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => '{{contact_name}} has waited {{minutes_waiting}} minutes for a reply: {{preview}}'],
                ],
            ],
            [
                'key' => 'lead-won',
                'name' => 'Celebrate a lead that became a customer',
                'description' => 'When an enquiry is turned into a customer and a job, the '
                    .'office hears about it.',
                'trigger_key' => 'lead.converted',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => '{{contact_name}} is now a customer, and their job has been created.'],
                ],
            ],

            // Jobs
            [
                'key' => 'yearly-follow-up',
                'name' => 'Book next year\'s visit when a job is done',
                'description' => 'When a job is completed, a follow-up job for the same customer '
                    .'is booked a year later. Change the number of days for a different interval.',
                'trigger_key' => 'job.completed',
                'conditions' => null,
                'actions' => [
                    ['type' => 'job.create', 'title' => 'Yearly check-up: {{title}}', 'in_days' => '365'],
                ],
            ],
            [
                'key' => 'inspection-report-to-customer',
                'name' => 'Email the inspection report to the customer',
                'description' => 'As soon as a technician submits an inspection, the customer gets '
                    .'the report by email, without anybody in the office sending it.',
                'trigger_key' => 'inspection.submitted',
                'conditions' => null,
                'actions' => [
                    ['type' => 'inspection_report.email'],
                ],
            ],

            // Phone
            [
                'key' => 'missed-call-job',
                'name' => 'Turn every missed call into a call-back',
                'description' => 'A missed call opens a job to ring them back, and the office is '
                    .'told, so a caller who hung up is not a lost customer.',
                'trigger_key' => 'phone.call.missed',
                'conditions' => null,
                'actions' => [
                    ['type' => 'missed.create_job'],
                    ['type' => 'notify', 'message' => 'Missed call from {{from_e164}}. A job has been opened to call them back.'],
                ],
            ],
            [
                'key' => 'new-voicemail',
                'name' => 'Tell the office about a new voicemail',
                'description' => 'Somebody left a message. Everybody who can hear it is told who '
                    .'called.',
                'trigger_key' => 'phone.voicemail.left',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => 'New voicemail from {{from_e164}}.'],
                ],
            ],
            [
                'key' => 'phone-number-broken',
                'name' => 'Warn the administrators when a phone number stops working',
                'description' => 'Calls and texts to a broken number go nowhere, silently. This '
                    .'tells the people who can fix it.',
                'trigger_key' => 'phone.number.broken',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify_administrators', 'message' => 'The phone number {{e164}} has stopped working: {{reason}}'],
                ],
            ],

            // Reviews
            [
                'key' => 'thank-five-stars',
                'name' => 'Draft a thank-you for every 5-star review',
                'description' => 'A reply is written for you and waits as a draft. Nothing is '
                    .'published until somebody reads it and presses the button.',
                'trigger_key' => 'review.received',
                'conditions' => [
                    'all' => [
                        ['field' => 'rating', 'op' => 'eq', 'value' => '5'],
                    ],
                ],
                'actions' => [
                    ['type' => 'draft_reply', 'message' => 'Thank you so much, {{reviewer_name}}. We are '
                        .'glad we could help, and we look forward to seeing you again.'],
                ],
            ],
            [
                'key' => 'low-review',
                'name' => 'Flag a review of 3 stars or less',
                'description' => 'The office hears about it straight away, so an unhappy customer '
                    .'can be answered the same day.',
                'trigger_key' => 'review.received',
                'conditions' => [
                    'all' => [
                        ['field' => 'rating', 'op' => 'lte', 'value' => '3'],
                    ],
                ],
                'actions' => [
                    ['type' => 'notify', 'message' => '{{reviewer_name}} left a {{rating}}-star review. It is worth answering today.'],
                ],
            ],
            // A1 Leads takeover P0 (2026-10-16): his alerts, as rules.
            [
                'key' => 'recent-two-star-review',
                'name' => 'Alert on a new review of 2 stars or less',
                'description' => 'Only reviews written in the last 14 days, so a profile added '
                    .'later does not alert on its old reviews.',
                'trigger_key' => 'review.received',
                'conditions' => [
                    'all' => [
                        ['field' => 'rating', 'op' => 'lte', 'value' => '2'],
                        ['field' => 'age_days', 'op' => 'lte', 'value' => '14'],
                    ],
                ],
                'actions' => [
                    ['type' => 'notify', 'message' => '{{reviewer_name}} left a {{rating}}-star review.', 'details' => '{{comment}}'],
                ],
            ],
            [
                'key' => 'review-removed',
                'name' => 'Say when Google removes a review',
                'description' => 'Google stopped showing a review, confirmed three times, two hours apart.',
                'trigger_key' => 'review.removed',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => 'Google removed the {{rating}}-star review by {{reviewer_name}}.'],
                ],
            ],
            [
                'key' => 'review-reply-failed',
                'name' => 'Say something when a review reply does not publish',
                'description' => 'Google refused a reply. Without this, it looks answered in '
                    .'EzBizi and unanswered on Google.',
                'trigger_key' => 'review.reply_failed',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => 'A reply to a review did not publish: {{reason}}'],
                ],
            ],

            [
                'key' => 'google-five-star-reply',
                'name' => 'Reply to new 5-star Google reviews',
                'description' => 'Posts a thank-you under each new 5-star Google review, through A1 Leads. '
                    .'A review that already has a reply is left alone. A1 Leads checks the words before '
                    .'they go out.',
                'trigger_key' => 'a1leads_gbp.review_received',
                'conditions' => [
                    'all' => [
                        ['field' => 'rating', 'op' => 'eq', 'value' => '5'],
                    ],
                ],
                // No name in it: reviewer_first_name is empty when the name is
                // not safe to greet, and "Thank you, ." is worse than none.
                'actions' => [
                    ['type' => 'review_reply', 'message' => 'Thank you for taking the time to leave such '
                        .'a kind review. We are glad we could help, and we look forward to seeing you again.'],
                ],
            ],

            [
                'key' => 'weekly-work-hours',
                'name' => 'Weekly work hours report',
                'description' => 'Every Sunday at 8 PM, emails each person\'s hours for the week '
                    .'that just finished. Choose which report, made under Work Hours, Reports.',
                'trigger_key' => 'automation.schedule_reached',
                'schedule' => ['kind' => 'weekly', 'days' => ['sunday'], 'time' => '20:00',
                    'timezone' => 'America/New_York'],
                'conditions' => null,
                'actions' => [
                    ['type' => 'send_report', 'report_id' => ''],
                ],
            ],

            // Team
            [
                'key' => 'training-failed',
                'name' => 'Tell the manager when somebody fails a test',
                'description' => 'A failed training test is a conversation to have, not a '
                    .'number to find later.',
                'trigger_key' => 'training.failed',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => 'Somebody did not pass a training test (score {{score}}).'],
                ],
            ],
            [
                'key' => 'training-expired',
                'name' => 'Say when a training pass runs out',
                'description' => 'Some certificates only last so long. When one expires, the '
                    .'people who manage training are told it needs taking again.',
                'trigger_key' => 'training.expired',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => 'A training pass has expired and needs taking again.'],
                ],
            ],
            [
                'key' => 'onboarding-complete',
                'name' => 'Tell the administrators when a new hire finishes onboarding',
                'description' => 'The paperwork is signed and complete, so the rest of the setup '
                    .'can start.',
                'trigger_key' => 'onboarding.completed',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify_administrators', 'message' => 'An onboarding is complete: the {{contract}} contract is signed.'],
                ],
            ],

            // Marketing and website
            [
                'key' => 'social-post-failed',
                'name' => 'Say something when a social post does not publish',
                'description' => 'A scheduled post that fails goes out nowhere. This says which '
                    .'account and why.',
                'trigger_key' => 'social.publish_failed',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => 'A post did not publish to {{account}}: {{because}}'],
                ],
            ],
            [
                'key' => 'website-went-quiet',
                'name' => 'Warn when a website stops checking in',
                'description' => 'If the EzBizi plugin on one of your websites goes silent, its '
                    .'forms and chat may have stopped working too.',
                'trigger_key' => 'website.went_quiet',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify_administrators', 'message' => '{{host}} has not checked in for {{hours_quiet}} hours. Its forms and chat may not be working.'],
                ],
            ],

            // Monitoring
            [
                'key' => 'device-offline',
                'name' => 'Say when a work computer goes offline',
                'description' => 'A computer that stops reporting in may be off, broken or '
                    .'unplugged. The people who look after them are told which one.',
                'trigger_key' => 'monitoring.device_offline',
                'conditions' => null,
                'actions' => [
                    ['type' => 'notify', 'message' => '{{label}} has gone offline.'],
                ],
            ],
        ];
    }
}
