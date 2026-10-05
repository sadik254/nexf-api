<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('homepage_category_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name', 120);
            $table->string('caption', 120)->nullable();
            $table->string('href', 500);
            $table->string('image', 1000);
            $table->string('mobile_image', 1000)->nullable();
            $table->string('cta', 80)->nullable();
            $table->string('theme', 20)->default('blue');
            $table->boolean('hidden')->default(false);
            $table->unsignedInteger('sort_order');
            $table->timestamps();
        });

        $cards = [
            ['men', 'For Men', 'men.png', 'violet'],
            ['women', 'For Women', 'women.png', 'pink'],
            ['kids', 'For Kids', 'kids.png', 'blue'],
            ['t-shirts-polos', 'T-Shirts & Polos', 'boy.png', 'emerald'],
            ['panjabi', 'Panjabi', 'men.png', 'amber'],
            ['salwar-kameez', 'Salwar Kameez', 'women.png', 'pink'],
        ];
        foreach ($cards as $order => [$slug, $name, $image, $theme]) {
            DB::table('homepage_category_cards')->insert([
                'category_id' => DB::table('product_categories')->where('slug', $slug)->value('id'),
                'name' => $name,
                'caption' => null,
                'href' => '/shop?category='.$slug,
                'image' => '/assets/category/'.$image,
                'cta' => 'Shop Now',
                'theme' => $theme,
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void { Schema::dropIfExists('homepage_category_cards'); }
};
