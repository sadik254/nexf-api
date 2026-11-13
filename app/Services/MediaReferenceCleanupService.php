<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The definitive list of editable media references. Historical order snapshots
 * deliberately do not appear here: a past purchase must keep its snapshot.
 */
class MediaReferenceCleanupService
{
    public function clear(string $url): void
    {
        DB::transaction(function () use ($url): void {
            $this->clearScalars($url);
            $this->clearJsonArrays($url);
            $this->clearRichText($url);
            $this->clearSettings($url);
        });
    }

    private function clearScalars(string $url): void
    {
        foreach ([
            ['products', 'thumbnail'],
            ['product_variations', 'image_url'],
            ['homepage_banners', 'image'],
            ['homepage_offer_blocks', 'image'],
            ['homepage_offer_blocks', 'mobile_image'],
            ['homepage_category_cards', 'image'],
            ['homepage_category_cards', 'mobile_image'],
            ['homepage_clients', 'logo'],
            ['homepage_clients', 'logo_dark'],
            ['product_categories', 'image'],
            ['product_categories', 'promo_image'],
            ['sellers', 'store_logo'],
            ['sellers', 'store_image'],
            ['sellers', 'seller_image'],
            ['sellers', 'kyc_document_url'],
            ['admins', 'image'],
            ['seller_staff', 'image'],
            ['resellers', 'image'],
            ['stores', 'logo'],
        ] as [$table, $column]) {
            DB::table($table)->where($column, $url)->update([$column => null]);
        }
    }

    private function clearJsonArrays(string $url): void
    {
        foreach ([
            ['products', 'gallery'], ['products', 'videos'],
            ['reviews', 'images'], ['reviews', 'videos'],
            ['support_messages', 'attachments'],
            ['store_chat_messages', 'attachments'], ['return_requests', 'attachments'],
            ['seller_promotions', 'blocks'],
        ] as [$table, $column]) {
            $this->rewriteJsonColumn($table, $column, $url);
        }
    }

    private function clearRichText(string $url): void
    {
        foreach ([['products', 'description'], ['product_categories', 'description'], ['product_collections', 'description']] as [$table, $column]) {
            DB::table($table)->where($column, 'like', '%'.$url.'%')->orderBy('id')->each(function ($row) use ($table, $column, $url): void {
                DB::table($table)->where('id', $row->id)->update([$column => str_replace($url, '', (string) $row->{$column})]);
            });
        }
    }

    private function clearSettings(string $url): void
    {
        // JSON encoders may escape slashes, so SQL LIKE is not dependable.
        // These configuration rows are intentionally scanned and compared as
        // decoded values instead.
        DB::table('site_settings')->orderBy('key')->each(function ($row) use ($url): void {
            $payload = json_decode((string) $row->payload, true);
            $changed = false;
            $payload = $this->removeUrl($payload, $url, $changed);
            if ($changed) {
                DB::table('site_settings')->where('key', $row->key)->update(['payload' => json_encode($payload), 'updated_at' => now()]);
            }
        });
    }

    private function rewriteJsonColumn(string $table, string $column, string $url): void
    {
        // See clearSettings(): compare decoded JSON for exact URL values.
        DB::table($table)->orderBy('id')->each(function ($row) use ($table, $column, $url): void {
            $value = json_decode((string) $row->{$column}, true);
            $changed = false;
            $value = $this->removeUrl($value, $url, $changed);
            if ($changed) {
                DB::table($table)->where('id', $row->id)->update([$column => json_encode($value), 'updated_at' => now()]);
            }
        });
    }

    /** Replaces exact scalar URL values only; text containing a URL is not modified here. */
    private function removeUrl(mixed $value, string $url, bool &$changed): mixed
    {
        if ($value === $url) {
            $changed = true;
            return null;
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->removeUrl($item, $url, $changed);
        }

        return array_is_list($value) ? array_values(array_filter($value, fn ($item) => $item !== null)) : $value;
    }
}
