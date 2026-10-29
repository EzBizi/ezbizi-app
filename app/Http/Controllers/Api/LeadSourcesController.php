<?php

namespace App\Http\Controllers\Api;

use App\Models\BusinessLocation;
use App\Models\Lead;
use App\Models\SocialConnection;
use App\Models\WebSite;
use App\Models\WebWidget;
use App\Models\WhatsAppNumber;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leads › Manage › Connections, the sources that are not an ad account: the
 * company's websites, WhatsApp numbers, Facebook and Instagram from the Page
 * connect, and Business Profile (Jose, 2026-10-29: the Website entry was one
 * sentence). Counts go through LeadsController::scopeToReader, so a source
 * here never counts a lead its reader could not open; a source the role does
 * not show is left out entirely.
 */
class LeadSourcesController extends Controller
{
    private const WEB = [Lead::SOURCE_WEBSITE_CHAT, Lead::SOURCE_WEBSITE_FORM, Lead::SOURCE_WEBSITE_BOOKING];

    public function __construct(private readonly SocialConnectionAccess $access) {}

    /** GET /api/v1/leads/sources */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();
        $visible = Lead::sourcesVisibleTo($user);
        $leads = fn (array $sources) => LeadsController::scopeToReader(Lead::query(), $user)->whereIn('leads.source', $sources);
        $tally = fn ($query, string $key) => $query->groupBy($key)
            ->selectRaw($key.' as k, COUNT(*) as n, MAX(leads.received_at) as last')->get()->keyBy('k');
        $out = [];

        if (array_intersect(self::WEB, $visible) !== []) {
            $view = ViewScope::forUser($user);
            $reach = $user->reachableCompanies()->pluck('id')->all();
            $sites = WebSite::query()->with('company', 'franchise:id,name')->orderBy('host')->get()
                ->filter(fn (WebSite $s) => in_array($s->company_id, $reach, true) && $view->coversCompany($s->company_id))
                ->values();
            $counts = $tally($leads(array_values(array_intersect(self::WEB, $visible)))->whereIn('leads.site_host', $sites->pluck('host')), 'leads.site_host');
            // The plugin ships by rollout, not by release, so the newest a site reports is "latest" (Web, 2026-10-29).
            $latest = $sites->pluck('plugin_version')->filter()->reduce(fn ($a, $v) => $a === null || version_compare($v, $a, '>') ? $v : $a);

            $out['web'] = [
                'latest_plugin_version' => $latest,
                'sites' => $sites->map(fn (WebSite $s) => [
                    'id' => $s->id,
                    'host' => $s->host,
                    'label' => $s->label,
                    'is_primary' => (bool) $s->is_primary,
                    'company' => ['id' => $s->company_id, 'name' => $s->company?->name],
                    'franchise' => $s->franchise_id === null ? null : ['id' => $s->franchise_id, 'name' => $s->franchise?->name],
                    'plugin_version' => $s->plugin_version,
                    'outdated' => $s->plugin_version !== null && $latest !== null && version_compare($s->plugin_version, $latest, '<'),
                    'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                    'chat_on' => WebWidget::chatIsOn($s),
                    /*
                     * Edits go through Web's own endpoints and gates, never a
                     * leads tick: leads.edit changing what a website publishes
                     * would widen Web's gate. Branch: PATCH web/sites/{id};
                     * chat: PUT web/sites/{id}/widgets; primary: POST
                     * web/sites/{id}/primary. Booking is a shortcode on the
                     * site's pages, so nothing here can switch it.
                     */
                    'may_edit' => $mayEdit = $user->can('update', $s),
                    'may_edit_chat' => $mayEdit && $user->hasPermission('web_management.widgets.view'),
                    'may_set_primary' => $mayEdit && $user->hasPermission('web_management.seo.view'),
                    // Null until the plugin reports it: unknown, not off.
                    'booking_on' => ($b = $s->getAttribute('has_booking')) === null ? null : (bool) $b,
                    ...$this->counted($counts->get($s->host)),
                ])->all(),
            ];
        }

        if (in_array(Lead::SOURCE_WHATSAPP, $visible, true)) {
            $counts = $tally($leads([Lead::SOURCE_WHATSAPP])->join('whatsapp_contacts', 'whatsapp_contacts.lead_id', '=', 'leads.id'), 'whatsapp_contacts.phone_number_id');
            $out['whatsapp'] = WhatsAppNumber::query()->orderBy('display_number')->get()->map(fn (WhatsAppNumber $n) => [
                'id' => $n->id,
                'number' => $n->display_number,
                'name' => $n->display_name,
                ...$this->counted($counts->get($n->phone_number_id)),
            ])->all();
        }

        foreach ([Lead::SOURCE_FACEBOOK => SocialProvider::FacebookPage, Lead::SOURCE_INSTAGRAM => SocialProvider::InstagramProfessional] as $source => $provider) {
            if (in_array($source, $visible, true)) {
                $connections = $this->connections($user, $provider);
                $counts = $tally($leads([$source])->whereIn('leads.connection_id', $connections->modelKeys()), 'leads.connection_id');
                $out[$source] = $connections->map(fn (SocialConnection $c) => [
                    ...$this->account($c),
                    ...$this->counted($counts->get($c->id)),
                ])->all();
            }
        }

        // Google ended Business Profile chat in 2024: listings, not leads.
        $profiles = $this->connections($user, SocialProvider::GoogleBusinessProfile);
        $places = BusinessLocation::query()->whereIn('connection_id', $profiles->modelKeys())
            ->selectRaw('connection_id, COUNT(*) as n, SUM(franchise_id IS NOT NULL) as mapped')->groupBy('connection_id')->get()->keyBy('connection_id');
        $out['google_business_profile'] = $profiles->map(fn (SocialConnection $c) => [
            ...$this->account($c),
            'locations' => (int) ($places->get($c->id)?->n ?? 0),
            'mapped' => (int) ($places->get($c->id)?->mapped ?? 0),
        ])->all();

        return response()->json(['data' => $out]);
    }

    private function connections($user, SocialProvider $provider): Collection
    {
        return SocialConnection::query()->where('provider', $provider->value)->orderBy('display_name')->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))->values();
    }

    private function account(SocialConnection $c): array
    {
        return ['id' => $c->id, 'name' => $c->label ?: $c->display_name, 'handle' => $c->handle, 'status' => $c->status];
    }

    private function counted($row): array
    {
        return ['leads' => (int) ($row?->n ?? 0), 'last_lead_at' => $row?->last ? Carbon::parse($row->last)->toIso8601String() : null];
    }
}
