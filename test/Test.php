<?php
declare (strict_types = 1);
namespace Greendrake\AsyncProcess;
use function React\Async\await;
use PHPUnit\Framework\TestCase;

class Test extends TestCase {

    public function testFailure() {
        $this->expectException(NonZeroExitException::class);
        $p = new Promise('no-bananas');
        await($p->get());
    }

    public function testSuccess() {
        $p = new Promise('a=$( expr 10 - 3 ); echo $a');
        $result = await($p->get());
        $this->assertSame('7', $result);
    }

}