<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteInfoController extends Controller
{
    public function show(): JsonResponse
    {
        $saved = SiteSetting::find('footer')?->payload;
        return response()->json($saved ?? config('site-content.footer'));
    }

    public function update(Request $request): JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'blurb' => ['required', 'string', 'max:2000'],
            'address' => ['required', 'string', 'max:300'],
            'helpline' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:150'],
            'hours' => ['required', 'string', 'max:150'],
            'copyright' => ['required', 'string', 'max:200'],
            'contactTitle' => ['required', 'string', 'max:80'],
            'locatorLabel' => ['required', 'string', 'max:80'],
            'locatorHref' => ['required', 'string', 'max:500'],
            'helplineLabel' => ['required', 'string', 'max:80'],
            'socials' => ['required', 'array', 'max:12'],
            'socials.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'socials.*.platform' => ['required', Rule::in(['facebook', 'instagram', 'youtube', 'linkedin', 'tiktok', 'x', 'whatsapp', 'messenger', 'telegram', 'pinterest', 'threads'])],
            'socials.*.href' => ['nullable', 'string', 'max:500'],
            'socials.*.enabled' => ['required', 'boolean'],
            'supportButtons' => ['required', 'array', 'max:3'],
            'supportButtons.*.id' => ['required', Rule::in(['call', 'messenger', 'whatsapp']), 'distinct'],
            'supportButtons.*.href' => ['nullable', 'string', 'max:500'],
            'supportButtons.*.enabled' => ['required', 'boolean'],
            'footerColumns' => ['required', 'array', 'max:6'],
            'footerColumns.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'footerColumns.*.title' => ['required', 'string', 'max:80'],
            'footerColumns.*.links' => ['required', 'array', 'max:20'],
            'footerColumns.*.links.*.label' => ['required', 'string', 'max:100'],
            'footerColumns.*.links.*.href' => ['required', 'string', 'max:500'],
        ]);
        foreach ($data['socials'] as $index => $social) {
            $data['socials'][$index]['href'] = (string) ($social['href'] ?? '');
            if ($social['enabled'] && empty($social['href'])) {
                throw ValidationException::withMessages(["socials.{$index}.href" => 'An enabled social icon needs a link.']);
            }
            if (!empty($social['href']) && !preg_match('~^https?://[^\\s]+$~i', $social['href'])) {
                throw ValidationException::withMessages(["socials.{$index}.href" => 'Use a full HTTP or HTTPS link.']);
            }
        }
        foreach ($data['supportButtons'] as $index => $button) {
            $data['supportButtons'][$index]['href'] = (string) ($button['href'] ?? '');
            if ($button['enabled'] && empty($button['href'])) {
                throw ValidationException::withMessages(["supportButtons.{$index}.href" => 'An enabled support button needs a link or phone number.']);
            }
            if (!empty($button['href']) && $button['id'] !== 'call' && !preg_match('~^https?://[^\\s]+$~i', $button['href'])) {
                throw ValidationException::withMessages(["supportButtons.{$index}.href" => 'Use a full HTTP or HTTPS link.']);
            }
        }
        foreach ($data['footerColumns'] as $columnIndex => $column) {
            foreach ($column['links'] as $linkIndex => $link) {
                if (!$this->safeHref($link['href'])) {
                    throw ValidationException::withMessages(["footerColumns.{$columnIndex}.links.{$linkIndex}.href" => 'Use an internal path or an HTTP or HTTPS link.']);
                }
            }
        }
        if (!$this->safeHref($data['locatorHref'])) {
            throw ValidationException::withMessages(['locatorHref' => 'Use an internal path or an HTTP or HTTPS link.']);
        }
        SiteSetting::updateOrCreate(['key' => 'footer'], ['payload' => $data]);
        return $this->show();
    }

    private function safeHref(string $href): bool
    {
        return (str_starts_with($href, '/') && !str_starts_with($href, '//'))
            || (bool) preg_match('~^https?://[^\\s]+$~i', $href);
    }
}
