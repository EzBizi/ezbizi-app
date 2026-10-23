# Telegram bot (staff Tech Assistant)

A second channel for the Tech Assistant, next to the staff WhatsApp line.
Staff link once by sharing their phone number with the bot; it must match the
phone on their EzBizi profile. `/unlink` disconnects. Answers appear in
AI Assistance → Answers with channel Telegram.

## Setup

1. In Telegram, message **@BotFather**, send `/newbot`, pick a name and a
   username. Copy the token it gives you. Do not paste it in chat or the repo.
2. On the server, set in `.env`:
   - `TELEGRAM_BOT_TOKEN` — the BotFather token
   - `TELEGRAM_WEBHOOK_SECRET` — a random string (`openssl rand -hex 32`; letters, digits, `_` and `-` only)
   - `TELEGRAM_ORG_ID` — the id of the organization the bot serves
3. Deploy (`scripts/deploy.sh`), which runs the `telegram_links` migration and
   refreshes the config cache.
4. Register the webhook:

   ```
   php artisan telegram:set-webhook https://<host>/api/v1/telegram/webhook
   ```

The Answers tab then shows "Telegram bot: configured". Tech mode
(`ai_assistance.tech_mode`) and Grok must be on, as for WhatsApp.
