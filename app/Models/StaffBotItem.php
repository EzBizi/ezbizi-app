<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Training\GuideScreens;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a staff-line menu, and where its answer comes from: a Training
 * guide's chapter and section, a Library entry, or a report EzBizi writes
 * (UI queue 88). It links rather than copies, so the guide stays the one
 * place the words are kept.
 */
class StaffBotItem extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const SOURCE_GUIDE = 'guide';

    public const SOURCE_LIBRARY = 'library';

    public const SOURCE_REPORT = 'report';

    public const SOURCES = [self::SOURCE_GUIDE, self::SOURCE_LIBRARY, self::SOURCE_REPORT];

    /** Reports an item can be made from. None writes itself yet. */
    public const REPORTS = ['daily_conversion' => 'Daily conversion (new clients)'];

    /** WhatsApp's lengths for a list row. */
    public const MAX_TITLE = 24;

    public const MAX_LINE = 72;

    protected $fillable = [
        'organization_id', 'menu_id', 'position', 'title', 'line', 'source',
        'training_guide_id', 'chapter', 'section', 'assistant_entry_id', 'report',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(StaffBotMenu::class, 'menu_id');
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TrainingGuide::class, 'training_guide_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(AssistantEntry::class, 'assistant_entry_id');
    }

    /**
     * What the bot sends for this item, or null when there is nothing to send
     * yet: a guide section with no topics, an entry with no words, a report
     * nothing writes. The editor shows that as "Needs content".
     */
    public function answer(): ?string
    {
        return match ($this->source) {
            self::SOURCE_GUIDE => $this->guideText(),
            self::SOURCE_LIBRARY => trim((string) $this->entry?->body) === '' ? null : (string) $this->entry->body,
            default => null,
        };
    }

    private function guideText(): ?string
    {
        if ($this->guide === null) {
            return null;
        }

        $parts = [];
        foreach (GuideScreens::topics($this->guide, $this->chapter, $this->section) as $topic) {
            $text = trim(GuideScreens::plainText($topic['topic']));
            if ($text !== '') {
                $parts[] = '*'.$topic['title']."*\n".$text;
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A row of a staff-line menu: its words and what it links to. Goes with its menu '
            .'(staff_bot_menus, on delete cascade).'
        );
    }
}
