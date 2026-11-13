<?php

namespace Tests\Feature\Social;

use App\Support\Social\LinkedInOAuth;
use App\Support\Social\PinterestOAuth;
use App\Support\Social\SocialOAuth;
use App\Support\Social\SocialOAuthGrant;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\XOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The exact token requests Pinterest, X and LinkedIn are sent, and what each
 * answer becomes. Pinned so the shared token helper (OAuthTokens) cannot move
 * a byte of the body, an auth header, or a refusal sentence.
 */
class OAuthTokenRequestShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.pinterest.client_id' => 'pin-id',
            'services.pinterest.client_secret' => 'pin-secret',
            'services.pinterest.redirect' => 'https://app.ezbizi.test'.PinterestOAuth::CALLBACK_PATH,
            'services.x.client_id' => 'x-id',
            'services.x.client_secret' => 'x-secret',
            'services.x.redirect' => 'https://app.ezbizi.test'.XOAuth::CALLBACK_PATH,
            'services.linkedin.client_id' => 'li-id',
            'services.linkedin.client_secret' => 'li-secret',
            'services.linkedin.redirect' => 'https://app.ezbizi.test'.LinkedInOAuth::CALLBACK_PATH,
        ]);
    }

    /** @return array<string, array{class-string<SocialOAuth>, string, string, ?string, string, string}> */
    public static function providers(): array
    {
        $redirect = fn (string $path) => urlencode('https://app.ezbizi.test'.$path);

        return [
            'pinterest' => [
                PinterestOAuth::class,
                'https://api.pinterest.com/v5/oauth/token',
                'Pinterest',
                'Basic '.base64_encode('pin-id:pin-secret'),
                'grant_type=authorization_code&code=the-code&redirect_uri='.$redirect(PinterestOAuth::CALLBACK_PATH),
                'grant_type=refresh_token&refresh_token=old-refresh',
            ],
            'x' => [
                XOAuth::class,
                'https://api.x.com/2/oauth2/token',
                'X',
                'Basic '.base64_encode('x-id:x-secret'),
                'code=the-code&grant_type=authorization_code&redirect_uri='.$redirect(XOAuth::CALLBACK_PATH).'&code_verifier=the-verifier',
                'grant_type=refresh_token&refresh_token=old-refresh',
            ],
            'linkedin' => [
                LinkedInOAuth::class,
                'https://www.linkedin.com/oauth/v2/accessToken',
                'LinkedIn',
                null,
                'grant_type=authorization_code&code=the-code&client_id=li-id&client_secret=li-secret&redirect_uri='.$redirect(LinkedInOAuth::CALLBACK_PATH),
                'grant_type=refresh_token&refresh_token=old-refresh&client_id=li-id&client_secret=li-secret',
            ],
        ];
    }

    private function assertOneRequest(string $url, ?string $auth, string $body): void
    {
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === $url
            && $r->body() === $body
            && $r->header('Content-Type') === ['application/x-www-form-urlencoded']
            && $r->header('Authorization') === ($auth === null ? [] : [$auth]));
    }

    private function refusal(callable $call): SocialOAuthRefused
    {
        try {
            $call();
        } catch (SocialOAuthRefused $e) {
            return $e;
        }

        $this->fail('No refusal was thrown.');
    }

    private function assertSameRefusal(SocialOAuthRefused $expected, SocialOAuthRefused $actual): void
    {
        $this->assertSame($expected->getMessage(), $actual->getMessage());
        $this->assertSame($expected->status, $actual->status);
    }

    #[DataProvider('providers')]
    public function test_the_code_exchange_request_and_grant(string $class, string $url, string $network, ?string $auth, string $exchangeBody, string $refreshBody): void
    {
        Http::fake([$url => Http::response([
            'access_token' => 'new-access', 'refresh_token' => 'new-refresh',
            'scope' => 'a:read,b:write', 'expires_in' => '3600',
        ])]);

        $grant = (new $class)->exchange('the-code', 'the-verifier');

        $this->assertOneRequest($url, $auth, $exchangeBody);
        $this->assertEquals(new SocialOAuthGrant('new-access', 'new-refresh', ['a:read', 'b:write'], 3600), $grant);
    }

    #[DataProvider('providers')]
    public function test_a_refused_or_tokenless_exchange(string $class, string $url, string $network): void
    {
        $failed = SocialOAuthRefused::exchangeFailed($network);

        $answers = [
            fn () => Http::response(['access_token' => 'a', 'refresh_token' => 'r'], 500),
            fn () => Http::response(['refresh_token' => 'r']),
            fn () => throw new ConnectionException('down'),
        ];
        Http::fake([$url => function () use (&$answers) {
            return array_shift($answers)();
        }]);

        foreach (range(1, 3) as $_) {
            $this->assertSameRefusal($failed, $this->refusal(fn () => (new $class)->exchange('c', 'v')));
        }
        $this->assertSame([], $answers);
    }

    #[DataProvider('providers')]
    public function test_an_exchange_without_a_refresh_token(string $class, string $url, string $network): void
    {
        Http::fake([$url => Http::response(['access_token' => 'a', 'expires_in' => 60])]);

        if ($class === LinkedInOAuth::class) {
            // LinkedIn hands most apps no refresh token; the connection is still made.
            $this->assertEquals(new SocialOAuthGrant('a', null, [], 60), (new $class)->exchange('c', 'v'));

            return;
        }

        $this->assertSameRefusal(
            SocialOAuthRefused::noLastingPermission($network),
            $this->refusal(fn () => (new $class)->exchange('c', 'v')),
        );
    }

    #[DataProvider('providers')]
    public function test_the_refresh_request_and_rotation(string $class, string $url, string $network, ?string $auth, string $exchangeBody, string $refreshBody): void
    {
        // Fakes stack and the first match wins, so both answers are one sequence.
        Http::fake([$url => Http::sequence()
            ->push(['access_token' => 'fresh', 'refresh_token' => 'rotated', 'expires_in' => 7200, 'scope' => 'x'])
            ->push(['access_token' => 'fresh'])]);

        $grant = (new $class)->refresh('old-refresh');

        $this->assertOneRequest($url, $auth, $refreshBody);
        $this->assertEquals(new SocialOAuthGrant('fresh', 'rotated', [], 7200), $grant);

        $this->assertEquals(new SocialOAuthGrant('fresh', 'old-refresh'), (new $class)->refresh('old-refresh'));
    }

    /** @return array<string, array{class-string<SocialOAuth>, string, string, int, array<string, string>, bool}> */
    public static function refreshAnswers(): array
    {
        $p = [PinterestOAuth::class, 'https://api.pinterest.com/v5/oauth/token', 'Pinterest'];
        $x = [XOAuth::class, 'https://api.x.com/2/oauth2/token', 'X'];
        $l = [LinkedInOAuth::class, 'https://www.linkedin.com/oauth/v2/accessToken', 'LinkedIn'];

        // [.., status, body, dead?]: dead means "connect it again", otherwise a passing failure.
        return [
            'pinterest 400' => [...$p, 400, [], true],
            'pinterest 401' => [...$p, 401, ['error' => 'whatever'], true],
            'pinterest 403' => [...$p, 403, ['error' => 'invalid_grant'], false],
            'pinterest 500' => [...$p, 500, [], false],
            'x 400 invalid_grant' => [...$x, 400, ['error' => 'invalid_grant'], true],
            'x 400 invalid_request' => [...$x, 400, ['error' => 'invalid_request'], true],
            'x 400 other' => [...$x, 400, ['error' => 'other'], false],
            'x 401 invalid_grant' => [...$x, 401, ['error' => 'invalid_grant'], false],
            'linkedin 400 invalid_grant' => [...$l, 400, ['error' => 'invalid_grant'], true],
            'linkedin 200 invalid_grant' => [...$l, 200, ['error' => 'invalid_grant', 'access_token' => 'a'], true],
            'linkedin 401 invalid_request' => [...$l, 401, ['error' => 'invalid_request'], false],
            'linkedin 200 no token' => [...$l, 200, [], false],
        ];
    }

    /** @param array<string, string> $body */
    #[DataProvider('refreshAnswers')]
    public function test_a_refused_refresh(string $class, string $url, string $network, int $status, array $body, bool $dead): void
    {
        Http::fake([$url => Http::response($body, $status)]);

        $this->assertSameRefusal(
            $dead
                ? SocialOAuthRefused::noLongerPermitted($network.' no longer accepts this connection. It needs connecting again.')
                : SocialOAuthRefused::exchangeFailed($network),
            $this->refusal(fn () => (new $class)->refresh('old-refresh')),
        );
    }

    #[DataProvider('providers')]
    public function test_a_refresh_that_reaches_nobody(string $class, string $url, string $network): void
    {
        Http::fake([$url => fn () => throw new ConnectionException('down')]);

        $this->assertSameRefusal(
            SocialOAuthRefused::exchangeFailed($network),
            $this->refusal(fn () => (new $class)->refresh('old-refresh')),
        );
    }
}
