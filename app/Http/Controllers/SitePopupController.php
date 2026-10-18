<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * First-load storefront popups (3:4 picture or text box), kept as one ordered
 * list in site_settings under "popups". Staff (admin and super_admin) edit it.
 */
class SitePopupController extends Controller
{
    private const THEMES = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];

    /** Live popups only: switched on and inside their dates. */
    public function publicIndex(): JsonResponse
    {
        $today = now()->toDateString();
        $live = array_values(array_filter($this->saved(), fn (array $p) => $p['enabled']
            && (empty($p['startsAt']) || $p['startsAt'] <= $today)
            && (empty($p['endsAt']) || $p['endsAt'] >= $today)));
        // The name is a console label; shoppers don't get it.
        return response()->json(array_map(fn (array $p) => [...$p, 'name' => ''], $live));
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        return response()->json($this->saved());
    }

    /** Replace the whole list (its order is the priority). */
    public function replace(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'popups' => ['present', 'array', 'max:30'],
            'popups.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'popups.*.name' => ['required', 'string', 'max:80'],
            'popups.*.enabled' => ['required', 'boolean'],
            'popups.*.kind' => ['required', Rule::in(['image', 'text'])],
            'popups.*.image' => ['nullable', 'string', 'max:1000'],
            'popups.*.href' => ['nullable', 'string', 'max:500'],
            'popups.*.icon' => ['nullable', 'string', 'max:40'],
            'popups.*.eyebrow' => ['nullable', 'string', 'max:40'],
            'popups.*.title' => ['nullable', 'string', 'max:60'],
            'popups.*.body' => ['nullable', 'string', 'max:180'],
            'popups.*.points' => ['present', 'array', 'max:3'],
            'popups.*.points.*.icon' => ['required', 'string', 'max:40'],
            'popups.*.points.*.text' => ['required', 'string', 'max:50'],
            'popups.*.pointsLayout' => ['sometimes', Rule::in(['list', 'row'])],
            'popups.*.cta' => ['nullable', 'string', 'max:24'],
            'popups.*.theme' => ['required', Rule::in(self::THEMES)],
            'popups.*.pages' => ['required', Rule::in(['home', 'all'])],
            'popups.*.frequency' => ['required', Rule::in(['once', 'daily', 'session', 'always'])],
            'popups.*.delay' => ['required', 'integer', 'min:0', 'max:60'],
            'popups.*.startsAt' => ['nullable', 'date_format:Y-m-d'],
            'popups.*.endsAt' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $rows = [];
        foreach ($data['popups'] as $i => $p) {
            $row = [
                'id' => $p['id'],
                'name' => $p['name'],
                'enabled' => (bool) $p['enabled'],
                'kind' => $p['kind'],
                'image' => (string) ($p['image'] ?? ''),
                'href' => (string) ($p['href'] ?? ''),
                'icon' => (string) ($p['icon'] ?? 'Gift'),
                'eyebrow' => (string) ($p['eyebrow'] ?? ''),
                'title' => (string) ($p['title'] ?? ''),
                'body' => (string) ($p['body'] ?? ''),
                'points' => array_values($p['points']),
                'pointsLayout' => $p['pointsLayout'] ?? 'list',
                'cta' => (string) ($p['cta'] ?? ''),
                'theme' => $p['theme'],
                'pages' => $p['pages'],
                'frequency' => $p['frequency'],
                'delay' => (int) $p['delay'],
                'startsAt' => $p['startsAt'] ?? null,
                'endsAt' => $p['endsAt'] ?? null,
            ];
            if ($row['kind'] === 'image' && $row['image'] === '') {
                throw ValidationException::withMessages(["popups.{$i}.image" => 'A picture popup needs an image.']);
            }
            if ($row['kind'] === 'text' && trim($row['title']) === '') {
                throw ValidationException::withMessages(["popups.{$i}.title" => 'A text popup needs a title.']);
            }
            if ($row['href'] !== '' && !$this->safeHref($row['href'])) {
                throw ValidationException::withMessages(["popups.{$i}.href" => 'Use an internal path or an HTTP or HTTPS link.']);
            }
            if ($row['startsAt'] && $row['endsAt'] && $row['endsAt'] < $row['startsAt']) {
                throw ValidationException::withMessages(["popups.{$i}.endsAt" => 'The end date is before the start date.']);
            }
            $rows[] = array_filter($row, fn ($v) => $v !== null);
        }

        SiteSetting::updateOrCreate(['key' => 'popups'], ['payload' => $rows]);
        return response()->json($this->saved());
    }

    private function saved(): array
    {
        return SiteSetting::find('popups')?->payload ?? [];
    }

    private function authorizeStaff(Request $request): void
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && in_array($admin->role, ['admin', 'super_admin'], true), 403);
    }

    private function safeHref(string $href): bool
    {
        return (str_starts_with($href, '/') && !str_starts_with($href, '//'))
            || (bool) preg_match('~^https?://[^\\s]+$~i', $href);
    }
}
