# Telegram bot (staff Tech Assistant)

A second channel for the Tech Assistant, next to the staff WhatsApp line.
Staff link once by sharing their phone number with the bot; it must match the
phone on their EzBizi profile. `/unlink` disconnects. Answers appear in
AI Assistance → Answers with channel Telegram.

## Setup (in the app)

1. In Telegram, message **@BotFather**, send `/newbot`, pick a name and a
   username. Copy the token it gives you. Do not paste it in chat or the repo.
2. In EzBizi, organization view: **Settings › Integrations › Messaging ›
   Telegram**, paste the token, **Connect bot** (needs `ai_assistance.manage`).
   The server checks it with `getMe`, generates a webhook secret and calls
   `setWebhook` itself. One bot per organization; a bot already connected to
   another organization is refused.
3. The Telegram page lists linked staff with **Unlink**. The card says
   *Needs attention* when Telegram reports a webhook delivery error.
   Disconnect needs an authenticator code and calls `deleteWebhook`.

Tech mode (`ai_assistance.tech_mode`) and Grok must be on, as for WhatsApp.

## Env fallback (A1-Group's original bot)

`TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET` and `TELEGRAM_ORG_ID` in `.env`
still work, for `TELEGRAM_ORG_ID`'s organization only, registered with
`php artisan telegram:set-webhook https://<host>/api/v1/telegram/webhook`.
The webhook matches an organization's connection secret first, then the env
secret. Sends use the organization's connection token, else the env token only
for `TELEGRAM_ORG_ID`.
