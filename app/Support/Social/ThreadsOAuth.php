<?php

namespace App\Support\Social;

/** Threads posting. Replies/DMs are not requested. */
final class ThreadsOAuth extends MetaLoginOAuth
{
    public const CALLBACK_PATH = '/api/v1/social/threads/callback';

    /** @var string[] */
    public const SCOPES = [
        'threads_basic',
        'threads_content_publish',
    ];

    protected const LABEL = 'Threads';

    protected const REDIRECT_CONFIG = 'services.meta.threads_redirect';

    protected const AUTHORIZE_URL = 'https://www.threads.net/oauth/authorize';

    protected const TOKEN_URL = 'https://graph.threads.net/oauth/access_token';

    protected const LONG_TOKEN_URL = 'https://graph.threads.net/access_token';

    protected const LONG_GRANT = 'th_exchange_token';

    protected const REFRESH_URL = 'https://graph.threads.net/refresh_access_token';

    protected const REFRESH_GRANT = 'th_refresh_token';

    protected const ME_URL = 'https://graph.threads.net/v1.0/me';

    protected const ME_FIELDS = 'id,username,name';

    public function provider(): SocialProvider
    {
        return SocialProvider::Threads;
    }

    public function routeKey(): string
    {
        return 'threads';
    }

    public function notReadyBecause(): ?string
    {
        return MetaApp::threadsNotReadyBecause();
    }

    protected function appId(): string
    {
        return MetaApp::threadsId();
    }

    protected function appSecret(): string
    {
        return MetaApp::threadsSecret();
    }
}
