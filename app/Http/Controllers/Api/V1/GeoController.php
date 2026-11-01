<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only reference data for the address and nationality pickers.
 */
class GeoController extends Controller
{
    /** All countries (~250), alphabetical. `search` filters by name or ISO code. */
    public function countries(Request $request): JsonResponse
    {
        $countries = Country::query()
            ->when($this->search($request), fn (Builder $q, string $like) => $q->where(
                fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('iso2', 'like', $like)->orWhere('iso3', 'like', $like),
            ))
            ->orderBy('name')
            ->get(['id', 'name', 'iso2', 'iso3', 'phone_code', 'emoji']);

        return response()->json(['data' => $countries]);
    }

    /** States of a country, alphabetical. */
    public function states(Request $request, Country $country): JsonResponse
    {
        $states = $country->states()
            ->when($this->search($request), fn ($q, string $like) => $q->where('name', 'like', $like))
            ->orderBy('name')
            ->get(['id', 'country_id', 'name', 'code']);

        return response()->json(['data' => $states]);
    }

    /** Cities of a state: paginated and searchable, since some states have thousands. */
    public function cities(Request $request, State $state): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = min(100, (int) $request->integer('per_page', 50));

        $cities = City::query()
            ->where('state_id', $state->id)
            ->when($this->search($request), fn (Builder $q, string $like) => $q->where('name', 'like', $like))
            ->orderBy('name')
            ->paginate($perPage, ['id', 'state_id', 'name']);

        return response()->json([
            'data' => $cities->items(),
            'meta' => [
                'current_page' => $cities->currentPage(),
                'last_page' => $cities->lastPage(),
                'per_page' => $cities->perPage(),
                'total' => $cities->total(),
            ],
        ]);
    }

    /** One entry per nationality ("Pakistani"); the id is a country id, sent as nationality_id. */
    public function nationalities(Request $request): JsonResponse
    {
        $rows = Country::query()
            ->whereNotNull('nationality')->where('nationality', '!=', '')
            ->when($this->search($request), fn (Builder $q, string $like) => $q->where('nationality', 'like', $like))
            ->groupBy('nationality')
            ->selectRaw('MIN(id) as id, nationality as name')
            ->orderBy('nationality')
            ->toBase()
            ->get();

        return response()->json(['data' => $rows->map(fn (object $r) => ['id' => (int) $r->id, 'name' => $r->name])]);
    }

    private function search(Request $request): ?string
    {
        $search = trim((string) $request->query('search', ''));

        return $search === '' ? null : '%'.addcslashes($search, '\\%_').'%';
    }
}
