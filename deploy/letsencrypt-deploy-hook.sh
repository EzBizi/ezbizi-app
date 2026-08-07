#!/bin/bash
#
# VERSIONED COPY of /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh on the
# app box — same arrangement as post-receive and the nginx configs, and for the
# same reason: the live file is the one that runs, this one is the one that is
# reviewable and survives a rebuild. Installation is in DEPLOY.md.
#
# ── Why this exists ──────────────────────────────────────────────
#
# nginx reads its certificates once, at master-process start, and holds them in
# memory for the life of that process. certbot renewing the file on disk changes
# nothing about what is being served — the master keeps handing out the copy it
# started with until something tells it to re-read.
#
# Nothing did. There was no renew_hook in any renewal config and the only deploy
# hook on the box was webmin.sh, which restarts webmin and nothing else. The
# master process had been up since 5 August holding a certificate that expires
# 3 November; certbot would have renewed it quietly around 4 October and
# app.ezbizi.com and dev.ezbizi.com — one certificate covers both — would have
# hard-failed together on the 3rd, thirty days after the renewal that was
# supposed to prevent it. A dated outage with a green certbot log beside it.
#
# ── reload, not restart ──────────────────────────────────────────
#
# `restart` stops the master and starts a new one: every in-flight request is
# dropped, and if the configuration on disk has drifted into something invalid
# the stop succeeds, the start fails, and both hostnames are down — a renewal
# that took the site offline instead of keeping it up.
#
# `reload` forks new workers with the new certificate, lets the old ones finish
# what they are serving, and refuses to do anything at all if the configuration
# does not parse. The worst case is that the old certificate stays in memory,
# which is exactly where this started — bad, and survivable, which restart's
# worst case is not.

set -uo pipefail

# certbot exports these for deploy hooks. Logged rather than acted on: one
# certificate covers both hostnames and nginx has no per-lineage reload, so the
# response to any renewal is the same. They are here so that when this line
# turns up in the journal months from now it says which certificate caused it.
lineage=${RENEWED_LINEAGE:-unknown}
domains=${RENEWED_DOMAINS:-unknown}

say() { echo "$*"; logger -t letsencrypt-nginx-reload "$*"; }

# Not running is not a failure.
#
# certbot renews on a timer that does not care what else is up — during a
# rebuild, or while nginx is stopped for maintenance, the certificate on disk is
# still correctly renewed and there is simply nothing holding a stale copy. nginx
# will read the new file the next time it starts. Reporting a failure here would
# make certbot's exit status say the renewal went wrong when it went fine.
if ! systemctl is-active --quiet nginx; then
  say "nginx is not running — nothing holds a stale certificate, skipping reload ($domains)"
  exit 0
fi

# Tested before reloading, so a configuration that has drifted is reported as
# what it is. `systemctl reload` on a broken config fails with "the control
# process exited with error code", which sends whoever reads it looking at the
# certificate rather than at the config file that is actually broken.
if ! nginx -t 2>/dev/null; then
  say "REFUSING to reload: nginx -t fails, so the running config is not the config on disk"
  nginx -t   # unredirected this time: the reason belongs in certbot's log
  exit 1
fi

if systemctl reload nginx; then
  say "reloaded nginx after renewing $domains (lineage $lineage)"
  exit 0
fi

# Loud on purpose. certbot reports a non-zero deploy hook, and a renewal that
# wrote a new certificate nobody is serving is the failure this whole file is
# about — it must not pass quietly.
say "FAILED to reload nginx after renewing $domains — it is still serving the OLD certificate"
exit 1
