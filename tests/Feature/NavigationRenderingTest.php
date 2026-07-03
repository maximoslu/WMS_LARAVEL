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
}
