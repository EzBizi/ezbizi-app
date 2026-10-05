<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recordings a caller hears instead of text-to-speech: a menu prompt or a
 * voicemail greeting. Jose, 2026-10-05: "automatic audio replies — text to
 * speech or recorded".
 *
 * Stored privately and handed to Twilio as a signed link, the way website form
 * uploads are (web.form.file). Twilio fetches it with no session, so the link
 * is the permission; the uuid name makes it unguessable as well.
 */
class AudioController extends Controller
{
    private const DIRECTORY = 'phone-audio';

    /** POST /api/v1/phone/audio */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('phone.edit'), Response::HTTP_FORBIDDEN);

        $request->validate([
            // What Twilio's <Play> takes. 10 MB is minutes of speech.
            'audio' => ['required', 'file', 'max:10240', 'mimes:mp3,wav'],
        ], [
            'audio.mimes' => 'Upload an MP3 or WAV recording.',
            'audio.max' => 'That recording is over 10 MB. A shorter one, or an MP3, will fit.',
        ]);

        $file = $request->file('audio');
        $name = Str::uuid()->toString().'.'.(strtolower($file->getClientOriginalExtension()) === 'wav' ? 'wav' : 'mp3');
        $file->storeAs(self::DIRECTORY, $name, 'local');

        return response()->json(['data' => ['name' => $name, 'url' => self::url($name)]], Response::HTTP_CREATED);
    }

    /** GET /api/v1/phone/audio/{name}, by its signed link only. */
    public function file(string $name): StreamedResponse
    {
        abort_unless(self::exists($name), Response::HTTP_NOT_FOUND);

        return Storage::disk('local')->response(self::DIRECTORY.'/'.$name);
    }

    /** The link Twilio fetches. Signed, not expiring: a menu plays for years. */
    public static function url(string $name): string
    {
        return URL::signedRoute('phone.audio', ['name' => $name]);
    }

    public static function exists(?string $name): bool
    {
        return is_string($name)
            && preg_match('/^[0-9a-f-]{36}\.(mp3|wav)$/', $name) === 1
            && Storage::disk('local')->exists(self::DIRECTORY.'/'.$name);
    }
}
