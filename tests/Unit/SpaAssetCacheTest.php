<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Flutter web cache rules that have already bricked a regular browser session.
 *
 * Material icons vanished for anyone who was not in a private window: the JS
 * was current (the version stamp said so) but FontManifest.json had been
 * served as Cache-Control: public, immutable for 30 days, still naming a
 * font file that no longer existed. nginx then served index.html as that
 * "font". These pins are the three things that have to stay true or the
 * same failure comes back the next time an icon is added.
 */
class SpaAssetCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function test_font_manifest_is_an_exact_no_cache_location_on_both_hosts(): void
    {
        foreach (['nginx-app.ezbizi.com.conf', 'nginx-dev.ezbizi.com.conf'] as $file) {
            $conf = $this->conf($file);

            $this->assertMatchesRegularExpression(
                '/location = \/assets\/FontManifest\.json \{[^}]*Cache-Control "no-cache, no-store, must-revalidate"/s',
                $conf,
                $file.' must no-cache FontManifest.json — it is not hashed'
            );
            $this->assertMatchesRegularExpression(
                '/location = \/assets\/AssetManifest\.bin\.json \{[^}]*Cache-Control "no-cache, no-store, must-revalidate"/s',
                $conf,
                $file
            );
        }
    }

    public function test_the_immutable_extension_list_does_not_include_json(): void
    {
        foreach (['nginx-app.ezbizi.com.conf', 'nginx-dev.ezbizi.com.conf'] as $file) {
            $conf = $this->conf($file);

            $this->assertDoesNotMatchRegularExpression(
                '/location ~\*[^\n]*json[^\n]*\$/',
                $conf,
                $file.': putting json on the immutable list is how FontManifest.json '
                .'became a 30-day brick. Exact-match no-cache would win today; if that '
                .'location is deleted, json on this list silently restores the bug.'
            );
        }
    }

    public function test_missing_assets_do_not_fall_back_to_the_spa(): void
    {
        foreach (['nginx-app.ezbizi.com.conf', 'nginx-dev.ezbizi.com.conf'] as $file) {
            $conf = $this->conf($file);

            $this->assertMatchesRegularExpression(
                '/location \^~ \/assets\/ \{[^}]*try_files \$uri =404;/s',
                $conf,
                $file.': a missing MaterialIcons-Regular.otf must 404, not return index.html'
            );
        }
    }

    public function test_index_html_reloads_the_font_manifest_before_the_engine_starts(): void
    {
        $html = file_get_contents($this->root.'/client/web/index.html');

        $this->assertStringContainsString("cache: 'reload'", $html);
        $this->assertStringContainsString('assets/FontManifest.json', $html);
        $this->assertStringContainsString('flutter_bootstrap.js', $html);
        $this->assertStringNotContainsString(
            '<script src="flutter_bootstrap.js" async></script>',
            $html,
            'The bootstrap must load after the FontManifest refetch, not in parallel with it'
        );
    }

    private function conf(string $file): string
    {
        $path = $this->root.'/deploy/'.$file;
        $this->assertFileExists($path);

        return file_get_contents($path);
    }
}
