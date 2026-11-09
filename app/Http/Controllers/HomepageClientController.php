<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HomepageClient;
use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HomepageClientController extends Controller
{
    public function __construct(private MediaUploadService $media) {}

    public function publicIndex(): JsonResponse
    {
        return response()->json(HomepageClient::query()->where('hidden', false)->orderBy('sort_order')->get());
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        return response()->json(HomepageClient::query()->orderBy('sort_order')->get());
    }

    public function replace(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'clients' => ['present', 'array', 'max:1000'],
            'clients.*.id' => ['sometimes', 'integer', 'exists:homepage_clients,id'],
            'clients.*.name' => ['required', 'string', 'max:120'],
            'clients.*.logo' => ['required', 'url:http,https', 'max:2048'],
            'clients.*.url' => ['nullable', 'url:http,https', 'max:500'],
            'clients.*.industry' => ['nullable', 'string', 'max:80'],
            'clients.*.hidden' => ['required', 'boolean'],
        ]);
        $ids = array_values(array_filter(array_column($data['clients'], 'id')));
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['clients' => 'Duplicate client ID.']);
        }
        foreach ($data['clients'] as $index => $client) {
            if (! $this->media->isR2Original($client['logo'])) {
                throw ValidationException::withMessages(["clients.{$index}.logo" => 'Choose an original image from the media library.']);
            }
        }

        DB::transaction(function () use ($data, $ids): void {
            HomepageClient::query()->whereNotIn('id', $ids)->delete();
            foreach ($data['clients'] as $position => $client) {
                $id = $client['id'] ?? null;
                unset($client['id']);
                HomepageClient::query()->updateOrCreate(['id' => $id], [...$client, 'sort_order' => $position]);
            }
        });

        return $this->index($request);
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user() instanceof Admin, 403);
    }
}
