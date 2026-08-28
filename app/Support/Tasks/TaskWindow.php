<?php

namespace App\Support\Tasks;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The bounded query window a saved view's presentation declares. Every
 * presentation validates its own window shape and nothing else — an unknown
 * shape, or a window belonging to a different presentation, is refused here
 * before TaskViewCodec resolves any identifier (such as a kanban
 * bucket_field) against the board.
 */
final class TaskWindow
{
    private const MAX_SPAN_DAYS = 400;

    private const MAX_BUCKETS = 50;

    private const MAX_PER_PAGE = 200;

    private function __construct(
        private readonly ?int $page = null,
        private readonly ?int $perPage = null,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly ?string $unit = null,
        private readonly ?string $bucketField = null,
        private readonly array $buckets = [],
    ) {}

    public static function parse(TaskPresentation $presentation, ?array $raw): self
    {
        return match ($presentation) {
            TaskPresentation::Table => self::parseTable($raw),
            TaskPresentation::Kanban => self::parseKanban($raw),
            TaskPresentation::Calendar, TaskPresentation::Timeline => self::parseRange($raw, withUnit: false),
            TaskPresentation::Workload => self::parseRange($raw, withUnit: true),
        };
    }

    private static function parseTable(?array $raw): self
    {
        if ($raw === null) {
            return new self;
        }
        self::exactly($raw, ['page', 'per_page']);
        if (! is_int($raw['page']) || $raw['page'] < 1) {
            self::invalid('A table window page must be a positive integer.');
        }
        if (! is_int($raw['per_page']) || $raw['per_page'] < 1 || $raw['per_page'] > self::MAX_PER_PAGE) {
            self::invalid('A table window per_page must be between 1 and '.self::MAX_PER_PAGE.'.');
        }

        return new self(page: $raw['page'], perPage: $raw['per_page']);
    }

    private static function parseKanban(?array $raw): self
    {
        if (! is_array($raw)) {
            self::invalid('A kanban window requires a bucket_field and buckets.');
        }
        self::exactly($raw, ['bucket_field', 'buckets']);
        if (! is_string($raw['bucket_field']) || $raw['bucket_field'] === '') {
            self::invalid('A kanban window requires a bucket_field reference.');
        }
        if (! is_array($raw['buckets']) || ! array_is_list($raw['buckets']) || count($raw['buckets']) > self::MAX_BUCKETS) {
            self::invalid('A kanban window supports at most '.self::MAX_BUCKETS.' buckets.');
        }
        foreach ($raw['buckets'] as $bucket) {
            if (! is_string($bucket) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27}$/i', $bucket)) {
                self::invalid('Each kanban bucket must be a valid record ID.');
            }
        }
        if (count(array_unique($raw['buckets'])) !== count($raw['buckets'])) {
            self::invalid('Kanban buckets must be unique.');
        }

        return new self(bucketField: $raw['bucket_field'], buckets: array_values($raw['buckets']));
    }

    private static function parseRange(?array $raw, bool $withUnit): self
    {
        if (! is_array($raw)) {
            self::invalid('This presentation requires a from and to window.');
        }
        self::exactly($raw, $withUnit ? ['from', 'to', 'unit'] : ['from', 'to']);
        $from = self::date($raw['from'] ?? null);
        $to = self::date($raw['to'] ?? null);
        if ($to < $from) {
            self::invalid('The window "to" date must not be before "from".');
        }
        if ($from->diff($to)->days > self::MAX_SPAN_DAYS) {
            self::invalid('A window may span at most '.self::MAX_SPAN_DAYS.' days.');
        }
        $unit = null;
        if ($withUnit) {
            if (! is_string($raw['unit'] ?? null) || ! in_array($raw['unit'], ['day', 'week'], true)) {
                self::invalid('A workload window unit must be day or week.');
            }
            $unit = $raw['unit'];
        }

        return new self(from: $raw['from'], to: $raw['to'], unit: $unit);
    }

    private static function exactly(array $raw, array $keys): void
    {
        if (array_diff(array_keys($raw), $keys) !== [] || array_diff($keys, array_keys($raw)) !== []) {
            self::invalid('This window must contain exactly '.implode(', ', $keys).'.');
        }
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            self::invalid('A window date must use a real YYYY-MM-DD date.');
        }

        return $date;
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['configuration.window' => $message]);
    }

    public function page(): ?int
    {
        return $this->page;
    }

    public function perPage(): ?int
    {
        return $this->perPage;
    }

    public function from(): ?string
    {
        return $this->from;
    }

    public function to(): ?string
    {
        return $this->to;
    }

    public function unit(): ?string
    {
        return $this->unit;
    }

    public function bucketField(): ?string
    {
        return $this->bucketField;
    }

    public function buckets(): array
    {
        return $this->buckets;
    }

    public function toArray(): ?array
    {
        return match (true) {
            $this->bucketField !== null => ['bucket_field' => $this->bucketField, 'buckets' => $this->buckets],
            $this->from !== null && $this->unit !== null => ['from' => $this->from, 'to' => $this->to, 'unit' => $this->unit],
            $this->from !== null => ['from' => $this->from, 'to' => $this->to],
            $this->page !== null => ['page' => $this->page, 'per_page' => $this->perPage],
            default => null,
        };
    }
}
