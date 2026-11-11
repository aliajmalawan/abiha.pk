<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Uploaded pictures are shown whole in their cards: never cropped to fill the card. */
class ImageFitTest extends TestCase
{
    public function test_card_pictures_are_contained_not_cropped(): void
    {
        $css = file_get_contents(resource_path('css/components.css')).file_get_contents(resource_path('css/home.css'));
        foreach (['.product-card__media img', '.home-tile__img img', '.home-col__img img'] as $sel) {
            $this->assertMatchesRegularExpression('/'.preg_quote($sel, '/').'\{[^}]*object-fit:contain/', $css, "$sel must show the whole picture");
        }
        $this->assertStringNotContainsString('object-fit:cover', $css);

        foreach (['cart', 'checkout', 'product', 'sections/promo', 'sections/brand', 'sections/social', 'partials/cart-drawer', 'partials/quick-view'] as $v) {
            $this->assertStringNotContainsString('object-cover', file_get_contents(resource_path("views/storefront/$v.blade.php")), "$v crops its picture");
        }
    }
}
