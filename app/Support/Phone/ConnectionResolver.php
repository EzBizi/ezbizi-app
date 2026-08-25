<?php

namespace App\Support\Phone;

use App\Models\Franchise;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;

/**
 * Walks a number’s place up to the Twilio account that pays for it (spec §4).
 *
 * Credentials walk up; lines do not. A Georgia number never uses Connecticut’s
 * Account SID — only that company’s connection, else the organization.
 */
final class ConnectionResolver
{
    public function forNumber(PhoneNumber $number): ?PhoneConnection
    {
        return $this->forScope(
            (string) $number->organization_id,
            (string) $number->scope_type,
            (string) $number->scope_id,
        );
    }

    public function forScope(string $organizationId, string $scopeType, string $scopeId): ?PhoneConnection
    {
        return match ($scopeType) {
            PhoneNumber::SCOPE_ORGANIZATION => $this->organizationConnection($organizationId),
            PhoneNumber::SCOPE_COMPANY => $this->companyThenOrganization($organizationId, $scopeId),
            PhoneNumber::SCOPE_FRANCHISE => $this->franchiseWalkUp($organizationId, $scopeId),
            default => null,
        };
    }

    private function franchiseWalkUp(string $organizationId, string $franchiseId): ?PhoneConnection
    {
        $franchise = Franchise::query()
            ->where('organization_id', $organizationId)
            ->whereKey($franchiseId)
            ->first();

        if ($franchise === null || $franchise->company_id === null) {
            return $this->organizationConnection($organizationId);
        }

        return $this->companyThenOrganization($organizationId, (string) $franchise->company_id);
    }

    private function companyThenOrganization(string $organizationId, string $companyId): ?PhoneConnection
    {
        return $this->connectionAt($organizationId, PhoneConnection::SCOPE_COMPANY, $companyId)
            ?? $this->organizationConnection($organizationId);
    }

    private function organizationConnection(string $organizationId): ?PhoneConnection
    {
        return $this->connectionAt($organizationId, PhoneConnection::SCOPE_ORGANIZATION, $organizationId);
    }

    private function connectionAt(string $organizationId, string $scopeType, string $scopeId): ?PhoneConnection
    {
        return PhoneConnection::query()
            ->where('organization_id', $organizationId)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();
    }
}
