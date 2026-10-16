<?php

namespace App\Http\Controllers\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Base for API controllers (plan §11.4).
 *
 * Kept separate from any web controller base: the public API is a published
 * product surface with its own versioning and compatibility obligations, and
 * staff control-plane operations deliberately do not live on it.
 */
abstract class Controller
{
    /**
     * Gives controllers $this->authorize().
     *
     * Laravel's base controller carries this by default; this one does not
     * extend it, so it has to be said. Without it `authorize()` is simply an
     * undefined method — which surfaces as a 500 rather than as an unprotected
     * endpoint, but is worth naming so nobody removes it as unused.
     */
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    /**
     * The four pagination keys every paged list sends in `meta`. Spread it
     * (`[...$this->pageMeta($page), 'tiles' => ...]`) where more keys follow.
     *
     * @return array{total: int, per_page: int, current_page: int, last_page: int}
     */
    protected function pageMeta(LengthAwarePaginator $page): array
    {
        return [
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
        ];
    }
}
