<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Persistence;

use App\Infrastructure\Persistence\PdoTransactionManager;
use App\Domain\Persistence\Exception\TransientTransactionException;
use App\Domain\Product\Exception\InsufficientStockException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use RuntimeException;
use Tests\Support\TransactionState;

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

    private static function pdoError(string $sqlState, int $driverCode): PDOException
    {
        $e = new PDOException('SQLSTATE[' . $sqlState . ']: driver error ' . $driverCode);
        $e->errorInfo = [$sqlState, $driverCode, 'msg'];

        return $e;
    }

    public function testRetriesDeadlockThenSucceedsRerunningWholeWork(): void
    {
        $pdo = new TransactionState();
        $sleeps = [];
        $manager = new PdoTransactionManager($pdo, 3, 10, function (int $m) use (&$sleeps): void {
            $sleeps[] = $m;
        });
        $calls = 0;

        $result = $manager->run(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw self::pdoError('40001', 1213);
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
        $this->assertSame(3, $pdo->begins);
        $this->assertSame(2, $pdo->rollbacks);
        $this->assertSame(1, $pdo->commits);
        $this->assertCount(2, $sleeps);
    }

    public function testLockWaitTimeoutIsRetried(): void
    {
        $pdo = new TransactionState();
        $manager = new PdoTransactionManager($pdo, 3, 0);
        $calls = 0;

        $result = $manager->run(function () use (&$calls) {
            if (++$calls === 1) {
                throw self::pdoError('HY000', 1205);
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    public function testExhaustedRetriesThrowTransientTransactionException(): void
    {
        $pdo = new TransactionState();
        $manager = new PdoTransactionManager($pdo, 3, 0);
        $calls = 0;

        try {
            $manager->run(function () use (&$calls): void {
                $calls++;
                throw self::pdoError('40001', 1213);
            });
            $this->fail('Expected TransientTransactionException');
        } catch (TransientTransactionException $e) {
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        }

        $this->assertSame(3, $calls);
        $this->assertSame(3, $pdo->rollbacks);
        $this->assertSame(0, $pdo->commits);
    }

    public function testNonTransientPdoErrorIsNotRetried(): void
    {
        $pdo = new TransactionState();
        $manager = new PdoTransactionManager($pdo, 3, 0);
        $calls = 0;
        $dupe = self::pdoError('23000', 1062);

        try {
            $manager->run(function () use (&$calls, $dupe): void {
                $calls++;
                throw $dupe;
            });
            $this->fail('Expected exception');
        } catch (PDOException $e) {
            $this->assertSame($dupe, $e);
        }

        $this->assertSame(1, $calls);
    }

    public function testDomainAndGenericExceptionsAreNotRetried(): void
    {
        foreach ([new InsufficientStockException(1, 2, 1), new RuntimeException('x')] as $error) {
            $pdo = new TransactionState();
            $manager = new PdoTransactionManager($pdo, 3, 0);
            $calls = 0;

            try {
                $manager->run(function () use (&$calls, $error): void {
                    $calls++;
                    throw $error;
                });
                $this->fail('Expected exception');
            } catch (\Throwable $e) {
                $this->assertSame($error, $e);
            }

            $this->assertSame(1, $calls);
            $this->assertSame(1, $pdo->begins);
            $this->assertSame(1, $pdo->rollbacks);
        }
    }

    public function testJoinedTransactionIsNeverRetried(): void
    {
        $pdo = new TransactionState();
        $pdo->active = true;
        $manager = new PdoTransactionManager($pdo, 3, 0);
        $calls = 0;
        $deadlock = self::pdoError('40001', 1213);

        try {
            $manager->run(function () use (&$calls, $deadlock): void {
                $calls++;
                throw $deadlock;
            });
            $this->fail('Expected exception');
        } catch (PDOException $e) {
            $this->assertSame($deadlock, $e);
        }

        $this->assertSame(1, $calls);
        $this->assertSame(0, $pdo->begins);
        $this->assertSame(0, $pdo->rollbacks);
    }

    public function testSingleAttemptConfigurationDisablesRetry(): void
    {
        $pdo = new TransactionState();
        $manager = new PdoTransactionManager($pdo, 1, 0);
        $calls = 0;

        $this->expectException(TransientTransactionException::class);
        $manager->run(function () use (&$calls): void {
            $calls++;
            throw self::pdoError('40001', 1213);
        });
    }
}
