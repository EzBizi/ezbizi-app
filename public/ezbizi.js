/*
 * EzBizi — business details on a site that isn't WordPress.
 *
 *   <script src="https://app.ezbizi.com/ezbizi.js" data-key="pk_..."></script>
 *   <span data-ezbizi="phone"></span>
 *   <a data-ezbizi-href="booking_url" data-ezbizi="booking_url">Book now</a>
 *
 * ── Read this before choosing it ──
 *
 * This runs in the visitor's browser, so the values are NOT in the HTML a
 * search engine first receives. For a phone number or address that is exactly
 * the content you most want crawled, and this module exists to make that
 * consistent. Prefer the server-side route — fetch the same values at build or
 * render time and print them into the page — wherever you have anywhere to run
 * code. This file is the escape hatch for sites that have nowhere.
 *
 * ── Why textContent and never innerHTML ──
 *
 * These values are typed by a person into EzBizi and rendered on a public page.
 * innerHTML would make "the phone number field" an injection point into every
 * site that trusts us, which is a bad trade for supporting bold text in an
 * address. Anchors get their href through setAttribute after a scheme check,
 * for the same reason: `javascript:` in a booking link would be our fault.
 */
(function () {
  'use strict';

  var script = document.currentScript;

  if (!script) {
    return;
  }

  var key = script.getAttribute('data-key');
  var base = script.getAttribute('data-base') || 'https://app.ezbizi.com';

  if (!key) {
    return;
  }

  /* Matches the server's normaliser and the WordPress plugin's: leading slash,
   * no trailing slash, lowercase, no query. A page override must resolve the
   * same way in all three or a value appears on two of them and not the third. */
  function path() {
    var p = window.location.pathname || '/';
    p = '/' + p.replace(/^\/+|\/+$/g, '');
    return p === '/' ? '' : p.toLowerCase();
  }

  function valueFrom(payload, name) {
    var here = path();

    if (here && payload.pages && payload.pages[here] && payload.pages[here][name] != null) {
      return String(payload.pages[here][name]);
    }

    return payload.values && payload.values[name] != null ? String(payload.values[name]) : '';
  }

  function safeUrl(raw) {
    if (/^(https?:|mailto:|tel:)/i.test(raw)) {
      return raw;
    }

    /* An address and a phone number are STORED bare, because that is what they
     * are and what a page usually prints. But the commonest thing anyone links
     * is "email us" or "call us", so turning them into a scheme here saves
     * every site a duplicate field. */
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw)) {
      return 'mailto:' + raw;
    }

    if (/^[+()\d][\s\-.()\d]{5,}$/.test(raw)) {
      return 'tel:' + raw.replace(/[^\d+]/g, '');
    }

    /* Anything else is dropped rather than rendered. A `javascript:` booking
     * link would execute on every visitor, and it would be our fault. */
    return '';
  }

  function apply(payload) {
    var text = document.querySelectorAll('[data-ezbizi]');

    for (var i = 0; i < text.length; i++) {
      var value = valueFrom(payload, text[i].getAttribute('data-ezbizi'));

      /* An empty value leaves whatever the page already had, so a site can put
       * a sensible fallback between the tags and never show a blank footer —
       * the same promise the WordPress plugin makes. */
      if (value !== '') {
        text[i].textContent = value;
      }
    }

    var links = document.querySelectorAll('[data-ezbizi-href]');

    for (var j = 0; j < links.length; j++) {
      var href = safeUrl(valueFrom(payload, links[j].getAttribute('data-ezbizi-href')));

      if (href !== '') {
        links[j].setAttribute('href', href);
      }
    }
  }

  function run() {
    /* No Authorization header, deliberately: a custom header would trigger a
     * CORS preflight and double the request count for public data. */
    fetch(base.replace(/\/+$/, '') + '/api/v1/web/public/values?key=' + encodeURIComponent(key))
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (payload) {
        if (payload) {
          apply(payload);
        }
      })
      .catch(function () {
        /* Silence is correct. A failed fetch leaves the page exactly as the
         * author wrote it; announcing our outage in somebody's footer would
         * make our problem their visitor's problem. */
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
