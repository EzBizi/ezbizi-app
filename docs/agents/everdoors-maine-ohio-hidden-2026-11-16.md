# EverDoors: Maine and Ohio hidden (2026-11-16, undo on 2026-11-23)

Jose, 11-16: hide the Maine and Ohio locations across the whole EverDoors
website for a week while a BBB approval is pending.

## Undo in one pass

```
ssh websites 'sudo -n /var/backups/everdoors-mo-20261116/restore.sh'
```

Each changed row was saved, before it was changed, to
`/var/backups/everdoors-mo-20261116/<site>/`. The restore script writes them
back, republishes the hidden pages, removes the redirects, turns indexing back
on and purges the caches. Run `www2` before `www` (the script already does).

## What changed

| Site (install folder) | Rows rewritten | Pages made private |
|---|---|---|
| www.everdoors.com (httpdocs) | 2,384 meta + 4 post bodies, then 1 more meta in a second pass (www2) | 364: /maine/ and /ohio/ with their 80 + 282 town pages, plus the -oh / -me pages (e.g. maineville-oh) |
| call.everdoors.com | 696 meta + 2 post bodies | 0 |
| ct.everdoors.com | 215 meta | 0 |
| ma.everdoors.com | 416 meta | 0 |
| nh.everdoors.com | 112 meta | 0 |
| nj.everdoors.com | 566 meta | 0 |
| pa.everdoors.com | 125 meta | 0 |
| ri.everdoors.com | 75 meta | 0 |
| me.everdoors.com, oh.everdoors.com | `.htaccess`: a marked `Redirect 302 / https://www.everdoors.com/` block at the top (originals in `me.htaccess.orig`, `oh.htaccess.orig`); `blog_public` set to 0 | whole site redirected |

Rows rewritten are `_elementor_data`, `_yoast_wpseo_metadesc`,
`_yoast_wpseo_title`, and `post_content`. What was removed from them:

- the Maine and Ohio entries in the "Local numbers" block, the footer, the
  header state switcher (an `<option>`), the state list and its cards, the
  SVG map shapes, and the pills linking to /service-areas/maine/ and /ohio/;
- `tel:+12072742444` and `tel:+12166662282`, and the ME/OH entries in the
  JSON phone map;
- the Maine and Ohio `State` entries in the JSON-LD `areaServed`;
- "Maine" and "Ohio" from the state lists in running text, so
  "…Rhode Island, Maine, New Jersey, Pennsylvania and Ohio" now reads
  "…Rhode Island, New Jersey and Pennsylvania";
- the "Maine (80 towns)" and "Ohio (82 towns)" sections of /service-areas/.

Private pages drop out of the Yoast sitemap on their own.

Left as is: the photo credit "Boston_and_Maine_6211" (a railroad name in a
Wikimedia file name, on www, ma and call), which is not a location.

Scripts: `/var/backups/everdoors-mo-20261116/hide.php` (the change) and
`/var/backups/everdoors-mo-20261116/restore.php` (the undo).
