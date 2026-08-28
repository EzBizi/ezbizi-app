# Using Web Management without WordPress

EzBizi holds a company's business details; the WordPress plugin is **one client
of a plain JSON API**, not the only way in. Anything that can make an HTTP
request can publish the same values — a static-site build step, a Rails or
Laravel app, a Next.js site, a shell script and `sed`.

There are two ways to do it, and they are not equally good.

---

## Choose server-side if you possibly can

**Put the values into the HTML before it leaves your server.** Fetch at build
time or render time and print the result.

This is the recommended path, and the reason is the module's own purpose. Web
Management exists so a company's name, address and phone are consistent
everywhere — which is a *local SEO* concern. A value injected by JavaScript is
not in the HTML a crawler first receives, cannot feed `application/ld+json`
structured data cleanly, and arrives after first paint. Server-side has none of
those problems, costs nothing at request time if you cache, and degrades to a
stale value rather than a missing one.

Use the **secret token** for this. It never leaves your server.

### The request

```
GET https://app.ezbizi.com/api/v1/web/plugin/values?host=<your-host>&plugin_version=<yours>
Authorization: Bearer ezb_...
```

```json
{
  "site":   { "host": "a-1chimney.com", "label": null, "company": "A-1 Chimney" },
  "fields": [ { "key": "phone", "label": "Phone number", "hint": "…", "shortcode": "[ezbizi_phone]" } ],
  "values": { "phone": "555-0100", "address": "…" },
  "pages":  { "/contact": { "phone": "555-0999" } }
}
```

`values` is the site's answer. `pages` holds per-page overrides keyed by path
(leading slash, no trailing slash, lowercase). Prefer a page's override over the
site value when rendering that page. `fields` is the catalogue, so a field added
in EzBizi appears without you changing code.

### Rules that are not optional

1. **Cache it, and keep the last good copy forever.** Never let a failed request
   blank a live page — serve the previous values instead. An empty footer
   because our API had a bad minute is a worse outcome than a five-minute-old
   phone number. The WordPress plugin does exactly this; so should you.
2. **Never fetch on the visitor's critical path.** Cache with a short TTL and
   refresh in the background, or fetch at build time.
3. **Escape on output.** These are values a person typed into a form.

### PHP

```php
function ezbizi_values(string $token, string $host): array {
    $cache = sys_get_temp_dir().'/ezbizi-'.md5($host).'.json';

    if (is_file($cache) && time() - filemtime($cache) < 300) {
        return json_decode(file_get_contents($cache), true);
    }

    $ch = curl_init('https://app.ezbizi.com/api/v1/web/plugin/values?host='.urlencode($host));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$token],
    ]);
    $body = curl_exec($ch);
    $ok   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
    curl_close($ch);

    // The last good copy outlives any single failure.
    if ($ok) { file_put_contents($cache, $body); }

    return is_file($cache) ? json_decode(file_get_contents($cache), true) : ['values' => []];
}

$v = ezbizi_values(getenv('EZBIZI_TOKEN'), 'a-1chimney.com')['values'];
echo htmlspecialchars($v['phone'] ?? '', ENT_QUOTES, 'UTF-8');
```

### A static site, at build time

```bash
curl -sf -H "Authorization: Bearer $EZBIZI_TOKEN" \
  "https://app.ezbizi.com/api/v1/web/plugin/values?host=a-1chimney.com" \
  > build/ezbizi.json || echo "using previous ezbizi.json"

PHONE=$(jq -r '.values.phone // empty' build/ezbizi.json)
[ -n "$PHONE" ] && sed -i "s|{{PHONE}}|$PHONE|g" build/*.html
```

Failing *soft* is the point: `||` keeps the previous file, and the empty check
means a missing value leaves the placeholder rather than blanking the page.

---

## The browser snippet, when you have nowhere to run code

For a hand-written page, a static host with no build step, or a CMS that only
lets you paste HTML.

```html
<script src="https://app.ezbizi.com/ezbizi.js" data-key="pk_..."></script>

<span data-ezbizi="phone">(555) 555-0100</span>
<a href="mailto:hello@example.com" data-ezbizi-href="email" data-ezbizi="email">hello@example.com</a>
```

**Write the real value between the tags.** The snippet only overwrites when it
has something, so what you type is what crawlers see and what shows if we are
unreachable. That is the whole reason this degrades safely — a `<span>` left
empty will simply stay empty.

`data-ezbizi` sets the text. `data-ezbizi-href` sets the link, adding `mailto:`
or `tel:` for a bare address or number, and refusing any other scheme.

### The two credentials, and why there are two

| | Secret token | Public key |
|---|---|---|
| Looks like | `ezb_…` | `pk_…` |
| Where it belongs | your server, never in a page | in page source, on purpose |
| Stored by us | SHA-256 only, shown once | plaintext, shown whenever you ask |
| Returns | values, plus host/label/company | values only |
| Rotate | "Issue a new token" | independently, without breaking a plugin |

A `<script>` tag is not a private place, so the browser gets a key that is
*meant* to be seen. It is read-only, returns only what is already printed on
your public page, and deliberately does not name your company or host — a key
scraped from page source must not become a map of who uses EzBizi.

---

## Where to find them

**Web Management → SEO**, on the site you registered. The public key is shown
whenever you look; the secret token is shown once, at registration and at
rotation.

## What this does not cover

Writing values back. Every endpoint here is read-only — values change in EzBizi,
by a person with `web_management.edit`, and everything else is a reader.
