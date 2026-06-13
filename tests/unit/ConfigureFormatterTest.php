<?php

namespace Ekumanov\ClsFix\Tests\unit;

use Ekumanov\ClsFix\ConfigureFormatter;
use Flarum\Testing\unit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Renderer;

class ConfigureFormatterTest extends TestCase
{
    private function renderer(): Renderer
    {
        $config = new Configurator();
        $config->tags->add('IMG');
        (new ConfigureFormatter())($config);

        return $config->finalize()['renderer'];
    }

    #[Test]
    public function known_dimensions_emit_natural_width_cap(): void
    {
        $html = $this->renderer()->render(
            '<r><IMG src="https://fonts.gstatic.com/s/e/notoemoji/17.0/1f978/32.png" alt="🥸" width="32" height="32"/></r>'
        );

        // Regression: a small image must carry --cls-img-natural-width so the
        // stylesheet caps the wrapper at natural size instead of upscaling it
        // to fill the column (the giant-emoji bug).
        $this->assertStringContainsString('aspect-ratio: 32 / 32;', $html);
        $this->assertStringContainsString('--cls-img-natural-width: 32px;', $html);
        $this->assertStringNotContainsString('data-cls-needs-dims', $html);
    }

    #[Test]
    public function unknown_dimensions_fall_back_to_ratio_var_without_width_cap(): void
    {
        $html = $this->renderer()->render('<r><IMG src="https://example.com/photo.jpg"/></r>');

        // No server-side natural width is known yet: the placeholder uses the
        // 16/9 default and JS reports/caps after load. Must NOT emit a bogus
        // natural-width that would lock the wrapper to the wrong size.
        $this->assertStringContainsString('aspect-ratio: var(--cls-img-ratio, 16 / 9);', $html);
        $this->assertStringNotContainsString('--cls-img-natural-width', $html);
        $this->assertStringContainsString('data-cls-needs-dims="1"', $html);
    }
}
