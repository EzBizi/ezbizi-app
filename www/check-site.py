#!/usr/bin/env python3
"""Check the marketing site before it ships.

Run from the repo root: python www/check-site.py

This exists because www/ has no tests and its failure mode is silent: a link to
a page that was renamed, a JSON-LD block with a trailing comma, a sitemap that
forgot a page. None of it 500s. It just quietly stops working and nobody sees it
for a month. Everything here is checkable without a network.
"""
import glob
import html.parser
import json
import os
import re
import sys
import xml.etree.ElementTree as ET

ROOT = os.path.dirname(os.path.abspath(__file__))
SITE = 'https://www.ezbizi.com'
fail = []


def read(p):
    with open(p, encoding='utf-8') as f:
        return f.read()


class Tags(html.parser.HTMLParser):
    """Unclosed <div> is how a footer ends up inside the last section."""
    VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr'}

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.stack = []
        self.bad = []

    def handle_starttag(self, tag, attrs):
        if tag not in self.VOID:
            self.stack.append(tag)

    def handle_endtag(self, tag):
        if tag in self.VOID:
            return
        # Strict on purpose. Popping down to the first match, which is the
        # forgiving thing to do, silently absorbs the missing </div> that this
        # check exists to find - it "repairs" the document and then reports it
        # as clean.
        if self.stack and self.stack[-1] == tag:
            self.stack.pop()
        else:
            self.bad.append('</%s> closes <%s>' % (tag, self.stack[-1] if self.stack else 'nothing'))


pages = sorted(glob.glob(os.path.join(ROOT, '*.html')) +
               glob.glob(os.path.join(ROOT, '*', 'index.html')))
if not pages:
    sys.exit('no pages found under %s' % ROOT)

for p in pages:
    rel = os.path.relpath(p, ROOT).replace('\\', '/')
    doc = read(p)

    for want in ('<link rel="canonical"', '<meta name="description"',
                 '<meta property="og:image"', '<title>'):
        if want not in doc:
            fail.append('%s: missing %s' % (rel, want))

    t = Tags()
    t.feed(doc)
    if t.stack:
        fail.append('%s: unclosed tags %s' % (rel, t.stack))
    for m in t.bad:
        fail.append('%s: mismatched tag - %s' % (rel, m))

    for i, block in enumerate(re.findall(
            r'<script type="application/ld\+json">(.*?)</script>', doc, re.S)):
        try:
            json.loads(block)
        except ValueError as e:
            fail.append('%s: JSON-LD block %d is not valid JSON — %s' % (rel, i + 1, e))

    # Internal links must land on a file that exists.
    for href in re.findall(r'href="([^"]+)"', doc):
        href = href.split('#')[0]
        if href.startswith(SITE):
            href = href[len(SITE):]
        if not href or not href.startswith('/'):
            continue          # external, mailto, or same-page anchor
        target = os.path.join(ROOT, href.strip('/').replace('/', os.sep))
        if os.path.isdir(target):
            target = os.path.join(target, 'index.html')
        if not os.path.exists(target) and not href.endswith('.exe'):
            fail.append('%s: link to %s goes nowhere' % (rel, href))

    if 'pk_REPLACE_WITH_KEY' in doc and '<!--' not in doc.split('pk_REPLACE_WITH_KEY')[0][-400:]:
        fail.append('%s: placeholder pk_ key is live, not commented out' % rel)

# Every page in the sitemap must exist, and every page must be in the sitemap.
smap = os.path.join(ROOT, 'sitemap.xml')
try:
    locs = {e.text for e in ET.parse(smap).getroot().iter(
        '{http://www.sitemaps.org/schemas/sitemap/0.9}loc')}
except ET.ParseError as e:
    fail.append('sitemap.xml does not parse — %s' % e)
    locs = set()

listed = {u[len(SITE):] or '/' for u in locs if u.startswith(SITE)}
ondisk = {'/' if os.path.relpath(p, ROOT) == 'index.html'
          else '/%s/' % os.path.relpath(os.path.dirname(p), ROOT).replace('\\', '/')
          for p in pages}
for missing in sorted(ondisk - listed):
    fail.append('sitemap.xml: %s exists but is not listed' % missing)
for extra in sorted(listed - ondisk):
    fail.append('sitemap.xml: lists %s, which is not on disk' % extra)

print('\n'.join(fail) if fail else 'ok - %d pages, %d sitemap entries' % (len(pages), len(listed)))
sys.exit(1 if fail else 0)
