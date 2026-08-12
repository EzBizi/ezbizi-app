<?php

namespace App\Support\Mail;

/**
 * One line a tenant has to add at their DNS host.
 *
 * A record and not a formatted string, because the person acting on it is
 * typing into a form with a Type box, a Name box and a Value box, and pasting
 * "TXT _ezbizi-verify.everdoors.com ezbizi-domain-verification=abc" into the
 * Name box is the mistake everybody makes once. The client can lay out three
 * fields with three copy buttons; it cannot split a sentence back apart.
 *
 * `purpose` exists for the same reason the settings rows carry `help`: three
 * cryptic records with no explanation get published wrong or not at all, and the
 * one who has to fix it is a chimney sweep with a GoDaddy login.
 */
final class DnsRecord
{
    public const TYPE_TXT   = 'TXT';
    public const TYPE_CNAME = 'CNAME';

    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $value,
        public readonly string $purpose,
    ) {}

    public function toArray(): array
    {
        return [
            'type'    => $this->type,
            'name'    => $this->name,
            'value'   => $this->value,
            'purpose' => $this->purpose,
        ];
    }
}
