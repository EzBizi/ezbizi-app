<?php

namespace Tests\Feature;

use App\Support\Email\HtmlEmailSanitiser;
use Tests\TestCase;

/**
 * HTML mail, stripped of the things that must never run, with the layout
 * that Gmail would keep.
 */
class HtmlEmailSanitiserTest extends TestCase
{
    private HtmlEmailSanitiser $sanitiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitiser = app(HtmlEmailSanitiser::class);
    }

    public function test_tables_and_sized_images_survive_scripts_do_not(): void
    {
        $out = $this->sanitiser->purify(
            '<p>Hello</p>'
            .'<script>alert(1)</script>'
            .'<table width="600"><tr><td>'
            .'<img src="https://cdn.example.net/logo.png" width="140" alt="Logo">'
            .'</td></tr></table>'
            .'<a href="javascript:alert(2)">click</a>'
        );

        $this->assertStringContainsString('Hello', $out);
        $this->assertStringContainsString('<table', $out);
        $this->assertTrue(
            str_contains($out, 'width="140"') || str_contains($out, 'width:140') || str_contains($out, 'width: 140'),
            'the image width the sender asked for was dropped',
        );
        $this->assertStringContainsString('cdn.example.net/logo.png', $out);

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('javascript', strtolower($out));
    }

    public function test_a_two_column_table_keeps_both_cells(): void
    {
        $out = $this->sanitiser->layout(
            '<table width="600" cellpadding="0" cellspacing="0">'
            .'<tr>'
            .'<td width="240" valign="top">'
            .'<img src="https://cdn.example.net/side.png" width="240" alt="">'
            .'</td>'
            .'<td width="360" valign="top" style="padding:16px;background-color:#f8f9fa;">'
            .'<p style="font-size:16px;color:#202124;">Your trial is ready</p>'
            .'<a href="https://console.cloud.google.com/" style="background-color:#1a73e8;color:#fff;padding:10px 18px;display:inline-block;">Open console</a>'
            .'</td>'
            .'</tr></table>'
        );

        $this->assertStringContainsString('<table', $out);
        $this->assertStringContainsString('Your trial is ready', $out);
        $this->assertStringContainsString('Open console', $out);
        $this->assertStringContainsString('cdn.example.net/side.png', $out);
        $this->assertStringContainsString('background-color', $out);
        $this->assertEquals(2, substr_count(strtolower($out), '<td'));
        $this->assertStringNotContainsString('border-collapse:collapse', $out);
    }

    public function test_class_styles_survive_and_css_cannot_fetch(): void
    {
        $out = $this->sanitiser->layout(
            '<style>.hero{width:600px} @import url(https://evil.example/x.css); body{background:url(https://evil.example/pixel.gif)}</style>'
            .'<div class="hero">Banner</div>'
        );

        $this->assertStringContainsString('class="hero"', $out);
        $this->assertStringContainsString('.hero{width:600px}', $out);
        $this->assertStringNotContainsString('@import', $out);
        $this->assertStringNotContainsString('url(', $out);
        $this->assertStringNotContainsString('evil.example', $out);
    }

    /**
     * The desktop and phone apps draw this page in a WebView rather than a
     * sandboxed iframe (Jose 11-12), so the page itself forbids scripts and
     * any load but pictures, styles and fonts.
     */
    public function test_the_page_forbids_scripts_and_loads_only_pictures_styles_and_fonts(): void
    {
        $out = $this->sanitiser->wrap('<p>Hi</p>');

        $this->assertMatchesRegularExpression(
            '/<meta http-equiv="Content-Security-Policy" content="default-src \'none\';[^"]*img-src data: https: http:;/',
            $out,
        );
        $this->assertStringNotContainsString('script-src', $out);
        $this->assertLessThan(strpos($out, '<body'), strpos($out, 'Content-Security-Policy'));
    }

    public function test_the_wrapped_document_is_a_white_page_like_gmail(): void
    {
        $out = $this->sanitiser->wrap('<p>Hi</p>');

        $this->assertStringContainsString('<!DOCTYPE html>', $out);
        $this->assertStringContainsString('background:#fff', $out);
        $this->assertStringContainsString('padding:20px 24px 32px', $out);
        $this->assertStringContainsString('img{max-width:100%;height:auto;}', $out);
        $this->assertStringContainsString('<p>Hi</p>', $out);
    }
}
