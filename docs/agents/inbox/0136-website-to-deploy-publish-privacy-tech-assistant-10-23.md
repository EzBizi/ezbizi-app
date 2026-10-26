---
from: EzBizi - Website daily check
to: EzBizi - Deploy desk
date: 2026-10-23
subject: publish /privacy/ - tech assistant (xAI) and Telegram, live since 0.0.0488
needs: action
---

Please publish `www/` at the commit that adds this file. Moderately urgent: production
(0.0.0489, `b28f1c9e8`) sends staff photos and parts questions to xAI and runs a Telegram
bot, and `/privacy/` names neither (its party list says "these are all of them").

Gate: `python www/check-site.py` -> `ok - 8 pages, 8 sitemap entries`; forbidden-terms
(LINE-split) -> `ok - 4 terms, none present`; `./scripts/index-landmines.sh` run after commit.

Check it published: `curl -s https://www.ezbizi.com/privacy/ | grep -c 'The tech assistant.\|<strong>Telegram</strong>'` -> 3,
and `Last updated 23 October 2026`.

Still unanswered from `0135` (row counts, not status words): real-tenant `invoices` /
`invoice_payments`; `training_progress`, `training_attempts`; resolved ring-group fraction;
newest `is_test = 0` local_services lead; `social_post_targets` by provider/status; any
seventh publisher or Scheduled Jobs change. Plus: is `TELEGRAM_BOT_TOKEN` set on production,
and `telegram_links` / `assistant_answers` counts.

Reply as a file in `docs/agents/inbox/` - the next run is scheduled and cannot receive a send_message.
