<?php

namespace App\Support\Financials\QuickBooks;

use App\Models\SocialConnection;
use Illuminate\Support\Facades\Http;

/**
 * QuickBooks Online Accounting API, read only: GET only, by construction.
 * The host follows QUICKBOOKS_ENVIRONMENT (sandbox realms live on another one).
 */
class QuickBooksClient
{
    public const MINOR_VERSION = 75;

    public const PAGE = 1000;

    public function __construct(private readonly QuickBooksToken $tokens) {}

    public static function base(): string
    {
        return config('services.quickbooks.environment') === 'sandbox'
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
    }

    /** @return array<string, mixed> the decoded body */
    public function get(string $token, string $realm, string $path, array $query = []): array
    {
        $r = Http::withToken($token)->acceptJson()->timeout(30)
            ->get(self::base().'/v3/company/'.rawurlencode($realm).'/'.ltrim($path, '/'), $query + ['minorversion' => self::MINOR_VERSION]);
        if ($r->status() === 401 || $r->status() === 403) {
            throw new QuickBooksRefused(QuickBooksRefused::RECONNECT);
        }
        if ($r->failed()) {
            throw new \RuntimeException('QuickBooks answered HTTP '.$r->status().' for '.strtok($path, '?'));
        }

        return (array) $r->json();
    }

    /** A fresh token for the connection, then the GET. */
    public function read(SocialConnection $c, string $path, array $query = []): array
    {
        return $this->get($this->tokens->for($c), (string) $c->remote_id, $path, $query);
    }

    /**
     * Every row of one entity matching the WHERE, paged by STARTPOSITION.
     *
     * @return list<array<string, mixed>>
     */
    public function all(SocialConnection $c, string $entity, string $where = ''): array
    {
        $rows = [];
        for ($start = 1; ; $start += self::PAGE) {
            $sql = "SELECT * FROM {$entity}".($where !== '' ? " WHERE {$where}" : '')." STARTPOSITION {$start} MAXRESULTS ".self::PAGE;
            $page = array_values((array) ($this->read($c, 'query', ['query' => $sql])['QueryResponse'][$entity] ?? []));
            array_push($rows, ...$page);
            if (count($page) < self::PAGE) {
                return $rows;
            }
        }
    }

    public function companyName(SocialConnection $c): ?string
    {
        $name = $this->read($c, 'companyinfo/'.$c->remote_id)['CompanyInfo']['CompanyName'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
