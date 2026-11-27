<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Intuit, answered from $this->qbo: the token endpoint (rotating refresh
 * tokens), CompanyInfo, the query endpoint per entity, and CDC. Anything but
 * a GET to the Accounting API fails the test: EzBizi does not write to QuickBooks.
 */
trait FakesQuickBooks
{
    /** @var array<string, mixed> */
    protected array $qbo = [
        'company' => 'Alpha Books LLC',
        'Account' => [],
        'Class' => [],
        'Department' => [],
        'Purchase' => [],
        'Bill' => [],
        'JournalEntry' => [],
        'cdc' => [],
        'refresh_count' => 0,
        'refuse' => false,
    ];

    /** @var list<string> every query sent */
    protected array $qboQueries = [];

    protected function fakeQuickBooks(): void
    {
        Http::fake([
            'oauth.platform.intuit.com/*' => function (Request $r) {
                if (($r['grant_type'] ?? null) === 'refresh_token') {
                    if ($this->qbo['refuse']) {
                        return Http::response(['error' => 'invalid_grant'], 400);
                    }
                    $n = ++$this->qbo['refresh_count'];

                    return Http::response(['access_token' => "access-{$n}", 'refresh_token' => "refresh-{$n}", 'expires_in' => 3600, 'token_type' => 'bearer']);
                }

                return Http::response(['access_token' => 'access-0', 'refresh_token' => 'refresh-0', 'expires_in' => 3600, 'x_refresh_token_expires_in' => 8726400, 'token_type' => 'bearer']);
            },
            'developer.api.intuit.com/*' => Http::response([], 200),
            '*.api.intuit.com/v3/company/*' => function (Request $r) {
                $this->assertSame('GET', $r->method(), 'EzBizi wrote to QuickBooks');
                if ($this->qbo['refuse']) {
                    return Http::response(['Fault' => ['type' => 'AUTHENTICATION']], 401);
                }
                $path = (string) parse_url($r->url(), PHP_URL_PATH);
                if (str_contains($path, '/companyinfo/')) {
                    return Http::response(['CompanyInfo' => ['CompanyName' => $this->qbo['company'], 'Email' => ['Address' => 'books@example.test']]]);
                }
                if (str_ends_with($path, '/cdc')) {
                    return Http::response(['CDCResponse' => [['QueryResponse' => $this->qbo['cdc']]]]);
                }
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
                $sql = (string) ($q['query'] ?? '');
                $this->qboQueries[] = $sql;
                preg_match('/FROM (\w+)/', $sql, $m);
                preg_match('/STARTPOSITION (\d+)/', $sql, $s);
                $rows = array_slice($this->qbo[$m[1]] ?? [], ((int) ($s[1] ?? 1)) - 1, 1000);

                return Http::response(['QueryResponse' => $rows === [] ? [] : [$m[1] => $rows]]);
            },
        ]);
    }

    /** An expense account, an asset account, two classes and a location, as QuickBooks returns them. */
    protected function qboBooks(): void
    {
        $this->qbo['Account'] = [
            ['Id' => '7', 'Name' => 'Advertising', 'FullyQualifiedName' => 'Advertising', 'AccountType' => 'Expense'],
            ['Id' => '8', 'Name' => 'Fuel', 'FullyQualifiedName' => 'Auto:Fuel', 'AccountType' => 'Expense'],
            ['Id' => '35', 'Name' => 'Checking', 'FullyQualifiedName' => 'Checking', 'AccountType' => 'Bank'],
        ];
        $this->qbo['Class'] = [
            ['Id' => '100', 'Name' => 'Miami', 'FullyQualifiedName' => 'Markets:Miami'],
            ['Id' => '101', 'Name' => 'Orlando', 'FullyQualifiedName' => 'Orlando'],
        ];
        $this->qbo['Department'] = [['Id' => '5', 'Name' => 'Tampa', 'FullyQualifiedName' => 'Tampa']];
    }

    protected function qboPurchase(string $id, string $date, array $lines, array $extra = []): array
    {
        return $extra + ['Id' => $id, 'TxnDate' => $date, 'EntityRef' => ['value' => '56', 'name' => 'Shell', 'type' => 'Vendor'],
            'Line' => array_map(fn ($l, $i) => ['Id' => (string) ($i + 1), 'Amount' => $l[0], 'DetailType' => 'AccountBasedExpenseLineDetail',
                'AccountBasedExpenseLineDetail' => array_filter(['AccountRef' => ['value' => $l[1]], 'ClassRef' => isset($l[2]) ? ['value' => $l[2], 'name' => 'c'.$l[2]] : null])],
                $lines, array_keys($lines))];
    }
}
