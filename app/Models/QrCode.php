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
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'qr_codes';

    /** Filed where its scope is: a company, a branch, or the organization (ChangeFeed). */
    public function changeFeedPlace(): array
    {
        return match ($this->scope_type) {
            'company' => [$this->scope_id, null],
            'franchise' => [null, $this->scope_id],
            default => [null, null],
        };
    }

    use HasUuids;

    /** No 0/O, 1/I/L: somebody may one day read it off a van and type it. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const LENGTH = 7;

    /** The picture in the middle (Bar, 01a1f4ba): the company logo, nothing, or an upload. */
    public const IMAGE_LOGO = 'logo';

    public const IMAGE_NONE = 'none';

    public const IMAGE_UPLOAD = 'upload';

    protected $fillable = [
        'organization_id', 'scope_type', 'scope_id',
        'code', 'name', 'destination_url', 'center_image', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scans' => 'integer',
            'last_scanned_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A QR code the organization printed: its name, the address it currently sends '
            .'people to, which picture sits in its middle (a media-library item, kept by that library) and how many times it has been scanned. Each scan is logged with its '
            .'time in qr_code_scans, which keeps no address, device or location. Printed codes are '
            .'deactivated rather than deleted, so it is kept for as long as the organization is.'
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
