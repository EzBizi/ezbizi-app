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
                    ['type' => 'notify', 'message' => 'New enquiry from {{source}}.'],
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
        ];
    }
}
