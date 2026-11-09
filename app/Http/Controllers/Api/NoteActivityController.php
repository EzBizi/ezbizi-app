<?php

namespace App\Http\Controllers\Api;

use App\Support\Notes\NoteActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The @ picker's people and the notes read mark (monday port, 2026-11-09). */
class NoteActivityController extends Controller
{
    public function __construct(private readonly NoteActivity $notes) {}

    /** GET /api/v1/mentionable?type=job&id=… */
    public function mentionable(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->notes->mentionable($this->record($request))
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()]);
    }

    /** POST /api/v1/note-reads {type, id} */
    public function read(Request $request): JsonResponse
    {
        NoteActivity::markRead($this->record($request), $request->user());

        return response()->json(['data' => ['read' => true]]);
    }

    private function record(Request $request): Model
    {
        $input = $request->validate([
            'type' => ['required', Rule::in(array_keys(NoteActivity::TYPES))],
            'id' => ['required', 'uuid'],
        ]);
        $record = NoteActivity::TYPES[$input['type']]::query()->findOrFail($input['id']);
        $this->authorize('view', $record);

        return $record;
    }
}
