<?php // tests/EditorSharedHelpersTest.php
namespace App\Tests;
use App\ChapterChecks;
use App\JsonReply;
use Kip\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The two small helpers the story and chapter editors share: how a request
 *  asks for JSON, and how a schedule input is normalized to one UTC instant. */
final class EditorSharedHelpersTest extends TestCase
{
    private function req(array $headers): Request
    {
        return new Request('POST', '/', [], [], [], '', $headers);
    }

    public function test_json_is_wanted_only_when_accept_says_so(): void
    {
        $this->assertTrue(JsonReply::wanted($this->req(['accept' => 'Application/JSON, text/plain'])));
        $this->assertFalse(JsonReply::wanted($this->req(['accept' => 'text/html'])));
        $this->assertFalse(JsonReply::wanted($this->req([])));
    }

    public function test_json_reply_is_uncached_and_keeps_slashes_and_custom_headers(): void
    {
        $res = JsonReply::of(['to' => '/chapter/edit/a/1'], 422, ['X-Test' => '1']);
        $this->assertSame(422, $res->status);
        $this->assertSame('{"to":"/chapter/edit/a/1"}', $res->body);
        $this->assertSame('application/json', $res->headers['Content-Type']);
        $this->assertSame('no-store', $res->headers['Cache-Control']);
        $this->assertSame('1', $res->headers['X-Test']);
    }

    public function test_schedule_empty_means_publish_now(): void
    {
        $this->assertNull(ChapterChecks::canonicalPublishAt(''));
    }

    public function test_schedule_values_become_one_utc_instant(): void
    {
        $this->assertSame('2030-05-01T09:30:00Z', ChapterChecks::canonicalPublishAt('2030-05-01T09:30'), 'zone-less reads as UTC');
        $this->assertSame('2030-05-01T09:30:15Z', ChapterChecks::canonicalPublishAt('2030-05-01T09:30:15Z'));
        $this->assertSame('2030-05-01T07:30:00Z', ChapterChecks::canonicalPublishAt('2030-05-01T09:30:00+02:00'), 'offsets convert to UTC');
    }

    #[DataProvider('junk')]
    public function test_schedule_junk_throws(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChapterChecks::canonicalPublishAt($raw);
    }

    public static function junk(): array
    {
        return [['tomorrow'], ['2099-13-45T99:99'], ['2030-05-01'], ['2030-05-01 09:30']];
    }
}
