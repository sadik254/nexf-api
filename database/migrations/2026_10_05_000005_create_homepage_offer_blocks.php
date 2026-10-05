<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('homepage_offer_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('row_type', 20);
            $table->unsignedTinyInteger('slot');
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('cta', 80);
            $table->string('href', 500);
            $table->string('image', 1000);
            $table->string('mobile_image', 1000)->nullable();
            $table->string('theme', 20);
            $table->boolean('hidden')->default(false);
            $table->timestamps();
            $table->unique(['row_type', 'slot']);
        });
        $rows = [
            ['four', 0, 'Macbook M4 Pro', 'Enjoy stunning picture clarity, immersive sound, and smart features designed to elevate your everyday entertainment.', 'Purchase Now', '/shop?collection=macbook', '1.png', 'blue'],
            ['four', 1, 'AirPod Max', 'AirPod Max deliver stunningly detailed, high-fidelity audio for an unparalleled listening experience for you.', 'Shop Now', '/shop?collection=airpod-max', '2.png', 'amber'],
            ['four', 2, 'Flat Discount on Regular Shoes', null, 'Browse Now', '/shop?collection=shoes', '3.png', 'pink'],
            ['four', 3, 'Discover Deals That Defines You', null, 'Browse Now', '/offers', '4.png', 'emerald'],
            ['wide', 0, 'Modern Smartwatches for Everyday on the go', 'Designed to support daily routines, fitness goals, and connected living, these smartwatches combine functional design with a personalised service.', 'Purchase Now', '/shop?collection=smartwatch', '5.png', 'emerald'],
            ['two', 0, 'iPhone 17', 'Enjoy stunning picture clarity, immersive sound, and smart features designed to elevate.', 'Shop Now', '/shop?collection=iphone', '6.png', 'violet'],
            ['two', 1, 'AirPod Pro', 'AirPod Pro deliver stunningly detailed, high-fidelity audio for an unparalleled experience for you.', 'Shop Now', '/shop?collection=airpod-pro', '7.png', 'sky'],
        ];
        foreach ($rows as [$row, $slot, $title, $body, $cta, $href, $image, $theme]) {
            DB::table('homepage_offer_blocks')->insert([
                'row_type' => $row, 'slot' => $slot, 'title' => $title, 'body' => $body,
                'cta' => $cta, 'href' => $href, 'image' => '/assets/offer/'.$image,
                'theme' => $theme, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void { Schema::dropIfExists('homepage_offer_blocks'); }
};
