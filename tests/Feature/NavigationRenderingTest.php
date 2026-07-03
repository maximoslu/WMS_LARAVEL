<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class NavigationRenderingTest extends TestCase
{
    public function test_module_icon_renders_known_icon_without_error(): void
    {
        $html = Blade::render('<x-module-icon name="stock" />');

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('module-link-icon-svg', $html);
    }

    public function test_module_icon_uses_safe_fallback_for_unknown_modules(): void
    {
        $html = Blade::render('<x-module-icon name="desconocido" />');

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('rect', $html);
    }

    public function test_breadcrumb_renders_svg_separator_without_mojibake_and_keeps_current_item_plain(): void
    {
        $html = Blade::render(
            <<<'BLADE'
<x-breadcrumbs
    :items="[
        ['label' => 'Panel de control', 'href' => '/dashboard', 'icon' => 'dashboard'],
        ['label' => 'Stock', 'href' => '/stock'],
        ['label' => 'Inventario'],
    ]"
/>
BLADE
        );

        $this->assertStringContainsString('Panel de control', $html);
        $this->assertStringContainsString('ops-breadcrumb-separator-icon', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringNotContainsString('â€º', $html);
        $this->assertStringNotContainsString('href="/inventario"', $html);
    }
}
