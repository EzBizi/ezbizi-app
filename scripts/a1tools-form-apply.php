<?php
// php form-apply.php [--write] — /tmp/forms.json: { host: form config from a1tools-swap }.
// Sets each site's EzBizi form to A1's form: questions, words, robot box, consent.
require '/var/www/ezbizi-current/vendor/autoload.php';
$app = require '/var/www/ezbizi-current/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WebSite;
use App\Models\WebWidget;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\WidgetDefaults;

$write = ($argv[1] ?? '') === '--write';
$forms = json_decode(file_get_contents('/tmp/forms.json'), true) ?: [];
$n = ['set' => 0, 'no site' => 0, 'no row' => 0];

foreach ($forms as $host => $form) {
    $site = WebSite::withoutGlobalScopes()->with('organization')->where('host', $host)->first();

    if (! $site) {
        $n['no site']++;
        echo "no site: $host\n";
        continue;
    }

    app(TenantContext::class)->run($site->organization, function () use ($site, $form, $write, &$n, $host) {
        $row = WebWidget::query()->where('site_id', $site->id)->first();
        $config = $row ? $row->config : WebWidget::resolvedFor($site);
        $before = $config['chat'] ?? null;
        $config['form'] = array_merge($config['form'] ?? [], $form);
        $clean = WidgetDefaults::resolve($config);

        if (isset($config['refreshed_at'])) {
            $clean['refreshed_at'] = $config['refreshed_at'];
        }

        // The chat is not this script's business: it must come back as it went in.
        if (json_encode($clean['chat'] ?? null) !== json_encode(WidgetDefaults::resolve(['chat' => $before])['chat'] ?? null)) {
            echo "chat would change on $host - skipped\n";
            return;
        }

        echo str_pad($host, 30), count($clean['form']['fields']), " questions, title \"", $clean['form']['title'], "\"\n";

        if ($write) {
            WebWidget::query()->updateOrCreate(['site_id' => $site->id], ['config' => $clean]);
        }

        $n['set']++;
    });
}

echo json_encode($n), $write ? ' WRITTEN' : ' (dry run)', "\n";
