<?php

namespace App\Support\Inspection;

use App\Models\InspectionDefinitionGrant;
use App\Support\Access\RoleGrantAccess;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;

/**
 * Named inspections nested under Inspection on the roles editor.
 *
 * Same composition as InboxAccess: a platform permission (the tab) and tenant
 * rows (which named inspections this role may start). A grant behind a closed
 * tab opens nothing.
 */
final class InspectionGrantAccess extends RoleGrantAccess
{
    protected const GRANT = InspectionDefinitionGrant::class;

    protected const COLUMN = 'inspection_definition_id';

    protected const FIELD = 'inspections';

    protected const PLURAL = 'inspections';

    protected const REFUSALS = [
        'unknown' => '%d of the inspections chosen %s no longer in this organization. '
            .'Reload the roles screen — somebody removed one while this was open.',
        'beyond' => 'You cannot grant %s, because you cannot offer %s yourself. Nobody can hand '
            .'out more than they have.',
        'no_module' => 'This organization has no Inspection tab, so there is no named inspection to grant. '
            .'Nothing has been saved.',
        'unreachable' => 'This role cannot open %s, so a named inspection granted to it would open nothing. '
            .'Tick %s itself first, then choose which inspections it may start.',
    ];

    public function __construct(InspectionDirectory $directory, PermissionCatalogue $catalogue, ModuleRegistry $registry)
    {
        parent::__construct($directory, $catalogue, $registry);
    }

    protected function label(object $item): string
    {
        return $item->name;
    }
}
