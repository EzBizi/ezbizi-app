<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A printed QR code whose destination can change (Marketing > QR Codes).
 *
 * The image encodes {@see url()} and nothing else, so it never has to be
 * reprinted: changing `destination_url` is the whole point of the row.
 */
class QrCode extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /** No 0/O, 1/I/L: somebody may one day read it off a van and type it. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const LENGTH = 7;

    protected $fillable = [
        'organization_id', 'scope_type', 'scope_id',
        'code', 'name', 'destination_url', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scans' => 'integer',
            'last_scanned_at' => 'datetime',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A QR code the organization printed: its name, the address it currently sends '
            .'people to and how many times it has been scanned. It holds nothing about the '
            .'people who scan it — no address, device or location — and it is kept until '
            .'somebody deletes it.'
        );
    }

    /** What the printed image encodes. Never changes once a code exists. */
    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/q/'.$this->code;
    }

    /** A fresh code nobody holds, in any organization. */
    public static function freshCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (static::query()->withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }

    public static function normalise(string $code): string
    {
        return Str::upper(trim($code));
    }
}
