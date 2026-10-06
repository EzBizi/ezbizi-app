<?php

namespace App\Http\Controllers;

use App\Models\QrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * GET /q/{code} — what a printed QR code opens.
 *
 * Public and tenant-less by nature: the scanner is a stranger with a phone.
 * Read through the query builder because the code is unique across every
 * organization, and that is the only key there is.
 *
 * 302, never 301: a browser caches a 301 for ever, and then changing the
 * destination would not reach anybody who had scanned it before.
 *
 * Deliberately NOT stopped by a lapsed subscription: the code is printed on
 * vehicles, and refusing it punishes the people scanning, not the account.
 */
class QrRedirectController
{
    public function __invoke(string $code): RedirectResponse|Response
    {
        $row = DB::table('qr_codes')->where('code', QrCode::normalise($code))->first(['id', 'destination_url', 'deactivated_at']);

        if ($row === null) {
            return response('This QR code is not in use.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        DB::table('qr_codes')->where('id', $row->id)->update([
            'scans' => DB::raw('scans + 1'),
            'last_scanned_at' => now(),
        ]);

        // Counted while paused too: people still scanning a deactivated van
        // is worth knowing before turning it back on.
        if ($row->deactivated_at !== null) {
            return response('This QR code is paused.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return redirect()->away($row->destination_url, 302)
            ->header('Cache-Control', 'no-store');
    }
}
