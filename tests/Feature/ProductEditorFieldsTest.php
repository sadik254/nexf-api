<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductHtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEditorFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_editor_fields_are_cast_and_persisted(): void
    {
        $product = new Product();
        $product->fill([
            'specification_tables' => [['title' => 'Material', 'rows' => [['label' => 'Fabric', 'value' => 'Cotton']]]],
            'videos' => ['https://example.com/demo.mp4'],
            'weight_kg' => 0.75,
        ]);

        $this->assertSame('Cotton', $product->specification_tables[0]['rows'][0]['value']);
        $this->assertSame(['https://example.com/demo.mp4'], $product->videos);
        $this->assertEquals(0.75, $product->weight_kg);
        $this->assertTrue(\Schema::hasColumns('products', ['specification_tables', 'videos', 'weight_kg']));
    }

    public function test_product_description_removes_unsafe_markup(): void
    {
        $html = app(ProductHtmlSanitizer::class)->clean('<p onclick="evil()">Hello<script>alert(1)</script><a href="javascript:alert(1)">bad</a><a href="https://example.com">good</a></p>');

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }
}
