<?php

namespace App\Support\Social;

use Illuminate\Http\Client\Response;

/**
 * Instagram professional via Instagram Login (not Facebook Login).
 *
 * Messaging is not requested. Short-lived tokens are exchanged for long-lived.
 */
final class InstagramOAuth extends MetaLoginOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/instagram/callback';

    /** @var string[] */
    public const SCOPES = [
        'instagram_business_basic',
        'instagram_business_content_publish',
        // Instagram direct messages into Leads, and replying to them.
        'instagram_business_manage_messages',
    ];

    protected const LABEL = 'Instagram';

    protected const REDIRECT_CONFIG = 'services.meta.instagram_redirect';

    protected const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';

    protected const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';

    protected const LONG_TOKEN_URL = 'https://graph.instagram.com/access_token';

    protected const LONG_GRANT = 'ig_exchange_token';

    protected const REFRESH_URL = 'https://graph.instagram.com/refresh_access_token';

    protected const REFRESH_GRANT = 'ig_refresh_token';

    protected const ME_URL = 'https://graph.instagram.com/v21.0/me';

    protected const ME_FIELDS = 'user_id,id,username,name';

    public function provider(): SocialProvider
    {
        return SocialProvider::InstagramProfessional;
    }

    public function routeKey(): string
    {
        return 'instagram';
    }

    public function notReadyBecause(): ?string
    {
        return MetaApp::instagramNotReadyBecause();
    }

    protected function appId(): string
    {
        return MetaApp::instagramId();
    }

    protected function appSecret(): string
    {
        return MetaApp::instagramSecret();
    }

    /*
     * `user_id`, not `id`. Under Instagram Login `id` is the app-scoped
     * id of the person; `user_id` is the professional ACCOUNT - the id
     * Meta's message webhooks carry and /{id}/media and /{id}/messages
     * expect (Meta's get-started page, checked 2026-09-28). Stored as
     * `id`, no Instagram message could ever find its connection.
     */
    protected function accountId(Response $me): string
    {
        return trim((string) ($me->json('user_id') ?? $me->json('id')));
    }
}
