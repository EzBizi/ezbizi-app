<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every path the Flutter client asks for is a route this API registers.
 *
 * ── Why this test exists ─────────────────────────────────────────
 *
 * The same defect has shipped twice in a week, both times from work built in
 * parallel: the client wrote one path and the server registered another, both
 * halves passed their own tests, and the feature was dead the moment somebody
 * opened it.
 *
 *   - The sending-identity panel called `/mail/sending-identity` while the
 *     server had `/sending-identities`. Caught in review, before release.
 *   - The Email module called `/email/mailboxes` while the server had
 *     `/mailboxes`. NOT caught: it reached production, and the owner found it
 *     by clicking the tab — "The route api/v1/email/mailboxes could not be
 *     found."
 *
 * Neither side was wrong in isolation, which is exactly why neither suite
 * complained. The client's tests mock the transport, so any path "works"; the
 * server's tests call their own routes, so any path works there too. Nothing
 * compared the two, and a contract nobody checks is a contract nobody keeps.
 *
 * ── What this asserts, and what it deliberately does not ─────────
 *
 * It reads the CALL SITES out of the Dart source and asks Laravel's router
 * whether each one resolves. That is a narrow claim — it says the URL exists,
 * not that the verb matches, the body is right or the response is shaped as the
 * screen expects. Those are worth having and are a different, much larger test;
 * this one closes the failure that actually happened, twice.
 *
 * A path built at runtime from a variable cannot be read here, and the regex
 * skips it rather than guessing. So this is a floor rather than a proof: it
 * cannot catch everything, and everything it does catch is a real break.
 */
class ClientCallsRealRoutesTest extends TestCase
{
    private const CLIENT = __DIR__.'/../../client/lib';

    /**
     * `api.get('/customers')`, `widget.api.post('/hr/contracts/$id/preview', …)`.
     *
     * The leading receiver varies — `api`, `widget.api`, `_api` — so it is
     * matched loosely; what matters is the quoted path immediately after the
     * verb. Only single-quoted literals, because that is what the codebase
     * uses and a looser pattern starts matching prose in comments.
     *
     * `${...}` IS MATCHED AHEAD OF THE ORDINARY CHARACTERS, and that ordering is
     * load-bearing. An interpolation can contain a quoted string of its own —
     * `'/notifications/${n['id']}/read'` is real, in notification_bell.dart —
     * and a plain `[^']+` stops dead at the inner quote, yielding the fragment
     * `/notifications/${n[`. That fragment then resolves against nothing and
     * reports as a missing route, which is a false alarm rather than a finding:
     * the first run of this test produced exactly one, and it was this.
     */
    private const CALL = "/\\b(?:\\w+\\.)*api\\.(get|post|put|patch|delete|deleteWithBody|postFile)\\(\\s*'((?:\\\$\\{[^}]*\\}|[^'])+)'/";

    public function test_every_path_the_client_calls_is_a_route_this_api_registers(): void
    {
        $calls = $this->callsInClientSource();

        // The control. A regex that silently stops matching would turn this
        // test into one that passes by examining nothing, which is the failure
        // mode of every test that scans source.
        $this->assertGreaterThan(
            50,
            count($calls),
            'The scan found almost no API calls in the Flutter source, so the pattern has '
            .'stopped matching and this test is asserting nothing. Fix the pattern before '
            .'trusting a green run.',
        );

        $missing = [];

        foreach ($calls as $call => $where) {
            [$verb, $path] = explode(' ', $call, 2);

            if (! $this->resolves($path, $verb)) {
                $missing[] = sprintf('%s %s  (called from %s)', $verb, $path, $where);
            }
        }

        $this->assertSame([], $missing, sprintf(
            "The client calls %d path(s) this API does not register, so the screen behind each "
            ."is dead the moment somebody opens it:\n\n%s\n\n"
            ."Fix whichever side is wrong. The app's convention is that a module namespaces its "
            ."routes under its own key — hr/people, email/mailboxes — so the client is usually "
            ."the one that is right.",
            count($missing),
            implode("\n", $missing),
        ));
    }

    /**
     * Every distinct path the Dart source asks for, mapped to where it is asked.
     *
     * @return array<string, string>  path => first file:line that calls it
     */
    private function callsInClientSource(): array
    {
        $found = [];

        foreach ($this->dartFiles() as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            if (! preg_match_all(self::CALL, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches as $match) {
                $verb = $match[1][0];
                $path = $match[2][0];

                // Built from something this test cannot see. Skipped rather
                // than guessed at — see the class docblock.
                if (! str_starts_with($path, '/')) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;

                $found[$this->verbFor($verb).' '.$path] ??=
                    str_replace(dirname(self::CLIENT).'/', '', $file).':'.$line;
            }
        }

        return $found;
    }

    /** @return string[] */
    private function dartFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::CLIENT, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'dart') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    /** The HTTP method behind each ApiClient helper. */
    private function verbFor(string $method): string
    {
        return match ($method) {
            'postFile'       => 'POST',
            'deleteWithBody' => 'DELETE',
            default          => strtoupper($method),
        };
    }

    /**
     * Does the router have this path, FOR THIS VERB?
     *
     * Dart interpolations become a placeholder — `/hr/contracts/$id/preview` is
     * the client's way of writing `/hr/contracts/{contract}/preview`, and the
     * router matches on shape rather than on the parameter's name. A uuid is
     * used rather than a word because some routes constrain the segment.
     *
     * ── The verb is checked, and the first draft did not check it ──
     *
     * That draft tried every method and accepted the path if ANY resolved, on
     * the reasoning that this test is about spelling rather than about methods.
     * It was wrong, and the mutation said so: reverting the one broken route
     * this test was written for — `email/mailboxes` back to `mailboxes` — left
     * it GREEN, because the sibling POST kept the URL alive for the GET the
     * client actually makes.
     *
     * A test that cannot detect the defect it was written for is worse than no
     * test, because the green is read as coverage. So the verb the client uses
     * is the verb that has to exist.
     */
    private function resolves(string $path, string $verb): bool
    {
        $concrete = preg_replace(
            ['/\$\{[^}]+\}/', '/\$\w+/'],
            '00000000-0000-4000-8000-000000000000',
            $path,
        );

        $request = \Illuminate\Http\Request::create('api/v1'.$concrete, $verb);

        try {
            Route::getRoutes()->match($request);

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
            return false;
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return false;
        }
    }
}
