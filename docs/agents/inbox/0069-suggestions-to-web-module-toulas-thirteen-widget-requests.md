---
from: suggestions (session "EzBizi - New Suggestions", local_c7e8ae8e-5b4b-4ee3-bfd4-dea0339a5078)
to: web module (local_89561020-da81-4ac1-b953-62324562ea00)
date: 2026-09-30
subject: Jose: build Toula's 13 website-widget suggestions
needs: implementation
---

Jose, 2026-09-30, verbatim: *"Implement all of Toula's request, send them to
the appropiate sessions"*. These 13 are yours. Toula Elwell (A1-Group) sent them
on 2026-09-25, asking for EzBizi's website widgets to match A1 Tools:

1. Missing widgets: Franchise Location, Store Locator, Services, Reviews listing, Before & After, Team, FAQ, Video Carousel, Gallery
2. Contact Form (everything the A1 Web Form does)
3. Live Chat (match A1 Web Chat)
4. Social Links (match A1 Social Icons)
5. Map
6. Opening Hours (match A1 Business Hours)
7. City List
8. Page Title (dynamic)
9. Contact Details (match A1 Contact Info)
10. Call to Action
11. Phone
12. Email
13. Location

Each suggestion has a full feature checklist, 20–35 lines long. Read them
from production. This is read-only:

    ssh app 'cd $(readlink -f /var/www/ezbizi-current) && sudo -u www-data php artisan tinker --execute="foreach(\Illuminate\Support\Facades\DB::table(\"suggestions\")->where(\"created_at\",\">=\",\"2026-09-25\")->where(\"created_at\",\"<\",\"2026-09-26\")->orderBy(\"created_at\")->get([\"id\",\"body\"]) as \$r){echo PHP_EOL.\"### \".\$r->id.PHP_EOL.\$r->body.PHP_EOL;}"'

- Use `DB::table`. The Suggestion model is tenant-scoped and throws in tinker.
- Do not change the suggestion rows. Jose answers them in Development → Suggestions, and answering there is what notifies Toula.
- A1 Tools may be read for behaviour. Its code is never copied.
- When a widget ships, tell Jose which suggestion it covers.

This was written to the inbox because the sending session is tied to a
scheduled task and cannot use `send_message`.
