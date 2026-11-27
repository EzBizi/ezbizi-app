# iOS app and App Store — review 2026-11-02 (Apple session)

Measured on 2026-11-02 against App Store Connect (API key, run on the relay) and the repo.

## 1. Working

- Builds 0.0.0511, 0.0.0512 and 0.0.0513 (iOS and macOS uploads, 2026-10-30) are all VALID in App Store Connect. None was rejected.
- iOS 0.0.0502 and macOS 0.0.0502 are READY_FOR_SALE (live). The older versions 0.0.0461 and 0.0.0443 (iOS) and 0.0.0461 and 0.0.0363 (macOS) were also released.
- App Review notes are in the repo, and `scripts/asc-review-notes.py --push` writes them to App Store Connect and reads them back. After the fix below, both platforms read back MATCHES.
- Push-tap handling: a tapped push opens the item it came from (AppDelegate `notification_tap` channel, b58a4311). It compiled on the relay; no tap has been tested on a device.

## 2. Broken

- **The App Review notes claimed "no outside AI service".** Line 31 told Apple the Assistant "uses no outside AI or machine-learning service". In fact, call summaries, lead notes, review-reply drafts and the staff Assistant all send business records to xAI (Grok); see app/Support/Ai and GrokTagger (`api.x.ai`). A reviewer who found this would have a Guideline 2.1 rejection written in our own words. **Fixed:** the notes now name xAI, on iOS and macOS, pushed and read back. Commit SHA below.
- **The iOS notes could not be pushed since 2026-10-16.** The Field commit 3bd1fa65 grew `ios.txt` to 4,286 characters, which is over Apple's 4,000 limit. So Apple never received the safety-recording and background-audio paragraph. Why it went unnoticed: nothing pushed the file after that commit, and the push script is the only check of the length. **Fixed:** trimmed to 3,948 characters and pushed.
- **The App Store screenshots are the September sets.** Live 0.0.0502 shows 5 iPhone 6.5" and 6 iPad screenshots from late August and early September. The 2026-10-06 set (8 iPhone 6.9" and 8 iPad 13") is in the Asset Library but was never attached. Every version since was created with the old sets copied forward. The product page header is still a draft (TODO #40). Fix: attach them to the next version before it's submitted. The desk is asked to tell Apple when the next version exists.
- **The App Store Connect credentials are offline on Windows.** H:\Development\Credentials is a junction to F:\Credentials, and drive F: is not mounted. Every `asc-*` script fails with "missing asc-key.env". The relay's copies of the key worked, which is how everything here was measured.

## 3. Cohesiveness

- The store screenshots predate Field, Monitoring, Leads, Reviews and the AI screens, so the listing shows a smaller product than the app.
- Inventory, Fleet and HR in the demo tenant show mostly zeros, so their store frames look empty next to Jobs and Customers.

## 4. Suggestions

1. Have the release cut run `asc-review-notes.py` (read-only), and fail when a notes file is over 4,000 characters or differs from live. Payoff: a notes edit can't sit unpushed for weeks.
2. Attach the Asset Library screenshot sets to every new version at cut time. Payoff: the store stops showing September.
3. Refresh the demo tenant's dated data (upcoming jobs, inventory movements, fleet spend). Payoff: store frames and the reviewer's walkthrough show a working business, not zeros.
4. Keep a copy of the App Store Connect key reachable without drive F:, or document that the relay holds it. Payoff: build verdicts and notes keep working when the USB drive isn't plugged in.

## 5. Needs Jose

- **AI and privacy.** Apple's Guideline 5.1.2(i) requires the app to say clearly that personal data goes to a third-party AI, and to get the person's permission first. Should the app show a one-time notice and consent before the first AI feature runs? Also: the App Privacy label in the portal doesn't declare that data is shared with xAI. Can you update it in App Store Connect → App Privacy, or should I list exactly what to tick?
- Is drive F: (the credentials USB) meant to stay unplugged? If so, may the App Store Connect key live in another folder on the PC?
