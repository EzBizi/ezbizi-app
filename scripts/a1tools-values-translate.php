<?php
/**
 * EzBizi values imported from A1 that still carry A1 shortcodes, translated
 * to EzBizi's — the same map a1tools-swap.php applies to pages.
 *
 *   php a1tools-values-translate.php            dry run: counts, and what is left untranslated
 *   php a1tools-values-translate.php --write    writes; every changed row is saved first to
 *                                               /tmp/a1-values-backup-<time>.json
 *
 * Runs on the app box against /var/www/ezbizi-current.
 */
require '/var/www/ezbizi-current/vendor/autoload.php';
$app = require '/var/www/ezbizi-current/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WebValue;

$write = ($argv[1] ?? '') === '--write';

$fixed = [
    'state_link' => '[ezbizi_state_anchor]', 'state' => '[ezbizi_state_name]', 'location_state' => '[ezbizi_state_name]',
    'location_city' => '[ezbizi_city]', 'city_name' => '[ezbizi_city]',
    'post_title' => '[ezbizi_post_title]', 'page_title' => '[ezbizi_post_title]',
    'location_phone' => '[ezbizi_phone]', 'city_phone' => '[ezbizi_phone]',
    'website_url' => '[ezbizi_website_url]', 'address' => '[ezbizi_address]', 'city_address' => '[ezbizi_address]',
    'map_address' => '[ezbizi_address]', 'state_web' => '[ezbizi_state_link]', 'city_web' => '[ezbizi_city_link]',
];
$vars = [
    'phone_primary' => 'phone', 'phone_secondary' => 'phone_secondary', 'email_primary' => 'email',
    'email_secondary' => 'email_secondary', 'business_name' => 'company_name', 'tagline' => 'tagline',
    'full_address' => 'address', 'address_single' => 'address', 'city' => 'city', 'city_name' => 'city',
    'zip' => 'postal_code', 'state' => 'state_name', 'location_name' => 'state_name',
    'google_maps_url' => 'maps_url', 'licence' => 'license', 'operating_hours' => 'hours',
];

$left = [];
$n = ['rows' => 0, 'changed' => 0];
$backup = [];

foreach (WebValue::withoutGlobalScopes()->where('value', 'like', '%[a1tools_%')->cursor() as $row) {
    $n['rows']++;
    $new = preg_replace_callback('/\[a1tools_([a-z_]+)([^\]]*)\]/', function ($m) use ($fixed, $vars, &$left) {
        if ($m[1] === 'var' && preg_match('/key\s*=\s*["\x{201C}\x{201D}\']?([a-z0-9_]+)/u', html_entity_decode($m[2], ENT_QUOTES), $k) && isset($vars[$k[1]])) {
            return '[ezbizi_'.$vars[$k[1]].']';
        }

        if (isset($fixed[$m[1]])) {
            return $fixed[$m[1]];
        }

        $left[$m[0]] = ($left[$m[0]] ?? 0) + 1;

        return $m[0];
    }, $row->value);

    if ($new !== $row->value) {
        $n['changed']++;
        $backup[] = ['id' => $row->getKey(), 'site_id' => $row->site_id, 'path' => $row->path, 'key' => $row->key, 'value' => $row->value, 'new' => $new];
    }
}

// The backup is on disk before the first row changes.
if ($write && $backup !== []) {
    file_put_contents('/tmp/a1-values-backup-'.date('Ymd-His').'.json', json_encode($backup)) !== false
        || exit("Could not write the backup; nothing changed.".PHP_EOL);

    foreach ($backup as $b) {
        WebValue::withoutGlobalScopes()->whereKey($b['id'])->update(['value' => $b['new']]);
    }
}

echo json_encode($n + ['untranslated' => $left]), $write ? ' WRITTEN' : ' (dry run)', "\n";
