<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Persistence;

use App\Infrastructure\Persistence\PdoTransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use RuntimeException;

class PdoTransactionManagerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PDO> */
    private ObjectProphecy $pdo;
    private PdoTransactionManager $manager;

    protected function setUp(): void
    {
        $this->pdo = $this->prophesize(PDO::class);
        $this->manager = new PdoTransactionManager($this->pdo->reveal());
    }

    public function testCommitsAndReturnsResult(): void
    {
        $this->pdo->inTransaction()->willReturn(false);
        $this->pdo->beginTransaction()->willReturn(true)->shouldBeCalledOnce();
        $this->pdo->commit()->willReturn(true)->shouldBeCalledOnce();
        $this->pdo->rollBack()->shouldNotBeCalled();

        $this->assertSame('done', $this->manager->run(fn () => 'done'));
    }

    public function testRollsBackAndRethrowsOnThrowable(): void
    {
        $error = new RuntimeException('boom');
        $this->pdo->inTransaction()->willReturn(false, true);
        $this->pdo->beginTransaction()->willReturn(true)->shouldBeCalledOnce();
        $this->pdo->commit()->shouldNotBeCalled();
        $this->pdo->rollBack()->willReturn(true)->shouldBeCalledOnce();

        try {
            $this->manager->run(function () use ($error): void {
                throw $error;
            });
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame($error, $e);
        }
    }

    public function testDoesNotRollBackWhenTransactionAlreadyEnded(): void
    {
        $this->pdo->inTransaction()->willReturn(false);
        $this->pdo->beginTransaction()->willReturn(true);
        $this->pdo->rollBack()->shouldNotBeCalled();

        $this->expectException(RuntimeException::class);
        $this->manager->run(function (): void {
            throw new RuntimeException('boom');
        });
    }

    public function testJoinsActiveTransactionWithoutControllingIt(): void
    {
        $this->pdo->inTransaction()->willReturn(true);
        $this->pdo->beginTransaction()->shouldNotBeCalled();
        $this->pdo->commit()->shouldNotBeCalled();
        $this->pdo->rollBack()->shouldNotBeCalled();

        $this->assertSame(42, $this->manager->run(fn () => 42));
    }

    public function testJoinedTransactionPropagatesExceptionWithoutRollback(): void
    {
        $this->pdo->inTransaction()->willReturn(true);
        $this->pdo->rollBack()->shouldNotBeCalled();

        $this->expectException(RuntimeException::class);
        $this->manager->run(function (): void {
            throw new RuntimeException('inner');
        });
    }
}
