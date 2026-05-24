<?php
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
    public function test_phpunit_runs(): void {
        $this->assertSame( 2, 1 + 1 );
    }
}
