#!/bin/bash
# Swap A1 Tools for EzBizi on one or more sites, on the websites box.
#
#   bash a1tools-swap-site.sh dry  nj.a-1chimney.com [more hosts…]
#   bash a1tools-swap-site.sh write nj.a-1chimney.com [more hosts…]
#
# Needs /tmp/a1tools-swap.php (scripts/a1tools-swap.php) beside it. A1's form,
# when a site has one, is printed as a FORM line for form-apply.php on the app
# box, which must run BEFORE the write so the swapped form has its questions.
#
# After a write: the site's EzBizi payload is refreshed, its page caches are
# purged under both hostnames (the www copy survives a bare-domain purge — see
# wp-cache-purge-needs-the-www-host), and the live home page is checked for
# any A1 widget markup left.

set -u
MODE="${1:?dry or write}"; shift
PHP="/opt/plesk/php/8.3/bin/php -d memory_limit=1024M /usr/local/bin/wp"

# host -> install directory, from home_url(), once.
if [ ! -s /tmp/a1swap-installs.txt ] || [ -n "$(find /tmp/a1swap-installs.txt -mmin +120 2>/dev/null)" ]; then
  sudo -n find /var/www/vhosts -maxdepth 6 -type f -path '*/wp-content/plugins/ezbizi/ezbizi.php' 2>/dev/null | while read -r f; do
    D=${f%/wp-content/plugins/ezbizi/ezbizi.php}
    O=$(sudo -n stat -c %U "$D")
    # Marked, because a plugin's warning printed on the same run was once read as the host.
    H=$(sudo -n -u "$O" $PHP --path="$D" eval 'echo "HOST=" . preg_replace("/^www\./", "", parse_url(home_url(), PHP_URL_HOST)), PHP_EOL;' </dev/null 2>/dev/null | grep '^HOST=' | tail -1 | cut -c6-)
    echo "$H $D $O"
  done > /tmp/a1swap-installs.txt
fi

for HOST in "$@"; do
  read -r _ D O < <(grep "^$HOST " /tmp/a1swap-installs.txt)
  if [ -z "${D:-}" ]; then echo "NOSITE $HOST"; continue; fi

  ARG=""; [ "$MODE" = write ] && ARG="write"
  OUT=$(sudo -n -u "$O" $PHP --path="$D" eval-file /tmp/a1tools-swap.php $ARG </dev/null 2>/dev/null | grep '^SWAP ')
  echo "$OUT"

  if [ "$MODE" = write ]; then
    sudo -n -u "$O" $PHP --path="$D" eval 'ezbizi_payload(true); if (class_exists("Cache_Enabler")) Cache_Enabler::clear_complete_cache();' </dev/null >/dev/null 2>&1
    sudo /usr/local/bin/wp-cache-purge "$HOST" >/dev/null 2>&1
    sudo /usr/local/bin/wp-cache-purge "www.$HOST" >/dev/null 2>&1
    LEFT=$(curl -s -m 40 -L "https://$HOST/" | grep -o 'elementor-widget-a1tools[a-z_-]*\|\[a1tools_[a-z_]*' | sort | uniq -c | tr '\n' ' ')
    echo "LIVE $HOST ${LEFT:-clean}"
  fi
done
