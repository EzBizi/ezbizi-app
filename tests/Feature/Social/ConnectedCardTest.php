<?php

namespace Tests\Feature\Social;

use App\Support\Social\SocialProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the popup says, and whether it draws anything at all.
 *
 * Both halves of the card failed for Google Ads at once, and each failure was
 * silent in its own way.
 *
 * The sentence was two sprintf() calls in SocialAuthController written for the
 * nine publishing networks. Connecting 37 Google Ads accounts announced
 * "37 Google Ads Pages are connected" and told somebody to "compose from Social
 * Media Management" — a noun Google Ads does not have and a module its
 * enquiries never reach.
 *
 * The picture failed differently. `networkMark()` looks for an SVG named after
 * the provider's route key and returns null when there is no file; `linkGraphic()`
 * then renders an empty string. So a provider with no icon does not get a broken
 * image or an error — it loses the entire graphic and ships a bare card, which
 * is what Jose saw on 2026-09-14 and read, correctly, as unfinished.
 */
class ConnectedCardTest extends TestCase
{
    public static function everyProvider(): array
    {
        return array_map(fn (SocialProvider $p) => [$p], SocialProvider::cases());
    }

    /**
     * The guard the missing icon needed: a provider cannot be added without a
     * mark, because the failure it causes is invisible at runtime.
     */
    #[DataProvider('everyProvider')]
    public function test_every_provider_has_an_icon_for_the_card(SocialProvider $provider): void
    {
        $file = base_path('client/assets/social/'.$provider->routeKey().'.svg');

        $this->assertFileExists(
            $file,
            $provider->label().' has no mark, so linkGraphic() will render nothing and the '
            .'connected card will appear as bare text.',
        );
        $this->assertStringContainsString('<svg', (string) file_get_contents($file));
    }

    #[DataProvider('everyProvider')]
    public function test_no_provider_is_described_as_something_it_is_not(SocialProvider $provider): void
    {
        foreach ([1, 37] as $count) {
            $said = $provider->connectedSentence($count, 'Example Account');

            $this->assertNotSame('', $said);

            // "Page" and Social Media Management belong to the nine networks
            // somebody actually publishes from. The catalogue is the authority
            // on which those are, so this cannot drift when one is added.
            if ($provider->isPublishingNetwork()) {
                continue;
            }

            $this->assertStringNotContainsStringIgnoringCase(
                'Page',
                $said,
                $provider->label().' does not have Pages.',
            );
            $this->assertStringNotContainsString(
                'Social Media Management',
                $said,
                $provider->label().' does not land in Social Media Management.',
            );
        }
    }

    public function test_google_ads_names_leads_as_the_destination(): void
    {
        // The positive half: refusing the wrong words is not the same as saying
        // the right one. A sentence naming no module would pass the test above.
        $said = SocialProvider::GoogleAds->connectedSentence(37, 'A1 Chimney - Chicago');

        $this->assertStringContainsString('37 Google Ads accounts', $said);
        $this->assertStringContainsString('Leads', $said);
    }
}
