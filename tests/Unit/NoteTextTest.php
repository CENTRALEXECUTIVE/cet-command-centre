<?php

namespace Tests\Unit;

use App\Support\NoteText;
use PHPUnit\Framework\TestCase;

class NoteTextTest extends TestCase
{
    public function test_it_turns_a_url_into_a_clickable_link(): void
    {
        $html = NoteText::linkify('Job card: https://cdserver2.com/JobCard.aspx?HIRE_ID=8791cc66-55ba');
        $this->assertStringContainsString('<a href="https://cdserver2.com/JobCard.aspx?HIRE_ID=8791cc66-55ba"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener nofollow"', $html);
    }

    public function test_it_escapes_html_so_notes_cannot_inject_markup(): void
    {
        $html = NoteText::linkify('Careful <script>alert(1)</script> & "quotes"');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_it_keeps_trailing_punctuation_out_of_the_link(): void
    {
        $html = NoteText::linkify('See https://example.com/page.');
        $this->assertStringContainsString('href="https://example.com/page"', $html);
        // The full stop is kept as plain text after the link.
        $this->assertStringEndsWith('</a>.', $html);
    }

    public function test_plain_text_without_a_url_is_just_escaped(): void
    {
        $this->assertSame('Meet at reception', NoteText::linkify('Meet at reception'));
        $this->assertSame('', NoteText::linkify(null));
    }
}
