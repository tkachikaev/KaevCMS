<?php

namespace Tests\Unit\Updates;

use App\Services\Updates\UpdateUploadLimit;
use Tests\TestCase;

class UpdateUploadLimitTest extends TestCase
{
    public function test_php_shorthand_sizes_are_parsed(): void
    {
        $this->assertSame(8 * 1024 * 1024, UpdateUploadLimit::parse('8M'));
        $this->assertSame(512 * 1024, UpdateUploadLimit::parse('512k'));
        $this->assertSame(1024 ** 3, UpdateUploadLimit::parse(' 1G '));
        $this->assertSame(1000, UpdateUploadLimit::parse('1000'));
        $this->assertSame(0, UpdateUploadLimit::parse('0'));
        $this->assertSame(0, UpdateUploadLimit::parse('unlimited'));
    }

    public function test_the_smallest_of_the_php_and_cms_limits_applies(): void
    {
        config(['cms.updates.maximum_upload_kilobytes' => 524288]);

        $this->assertSame(8 * 1024 ** 2, (new UpdateUploadLimit(['upload_max_filesize' => '64M', 'post_max_size' => '8M']))->bytes());
        $this->assertSame(2 * 1024 ** 2, (new UpdateUploadLimit(['upload_max_filesize' => '2M', 'post_max_size' => '8M']))->bytes());
        // 0 disables a directive; the CMS limit still applies.
        $this->assertSame(524288 * 1024, (new UpdateUploadLimit(['upload_max_filesize' => '0', 'post_max_size' => '0']))->bytes());
    }

    public function test_upload_parts_stay_below_the_limit(): void
    {
        $twoMegabytes = 2 * 1024 * 1024;
        $chunk = (new UpdateUploadLimit(['upload_max_filesize' => '2M', 'post_max_size' => '8M']))->chunkBytes();
        $this->assertLessThan($twoMegabytes, $chunk);
        $this->assertGreaterThan($twoMegabytes * 0.8, $chunk);

        $this->assertSame(4 * 1024 * 1024, (new UpdateUploadLimit(['upload_max_filesize' => '64M', 'post_max_size' => '70M']))->chunkBytes());
        $this->assertSame(64 * 1024, (new UpdateUploadLimit(['upload_max_filesize' => '100K', 'post_max_size' => '100K']))->chunkBytes());
    }

    public function test_sizes_are_formatted_for_people(): void
    {
        $this->assertSame('8 MB', UpdateUploadLimit::format(8 * 1024 ** 2));
        $this->assertSame('15.6 MB', UpdateUploadLimit::format((int) (15.6 * 1024 ** 2)));
        $this->assertSame('1 GB', UpdateUploadLimit::format(1024 ** 3));
        $this->assertSame('512 KB', UpdateUploadLimit::format(512 * 1024));
    }
}
