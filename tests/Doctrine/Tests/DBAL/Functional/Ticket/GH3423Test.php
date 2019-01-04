<?php

declare(strict_types=1);

namespace Doctrine\Tests\DBAL\Functional\Ticket;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\Tests\DbalFunctionalTestCase;

/**
 * @see https://github.com/doctrine/dbal/issues/3423
 */
class GH3423Test extends DbalFunctionalTestCase
{
    /** @var bool */
    private static $tableCreated = false;

    protected function setUp()
    {
        parent::setUp();

        if ($this->connection->getDatabasePlatform()->getName() !== 'postgresql') {
            $this->markTestSkipped('Only databases supporting deferrable constraints are eligible for this test.');
        }

        if (self::$tableCreated) {
            return;
        }

        $this->connection->exec(
            '
            CREATE TABLE gh3423 (
                unique_field BOOLEAN NOT NULL
                    CONSTRAINT gh3423_unique
                        UNIQUE
                        DEFERRABLE INITIALLY DEFERRED
            )
            '
        );
        $this->connection->exec('INSERT INTO gh3423 VALUES (true)');

        self::$tableCreated = true;
    }

    protected function tearDown()
    {
        $this->resetSharedConn();

        parent::tearDown();
    }

    /**
     * @group GH3423
     */
    public function testTransactionalWithDeferredConstraint() : void
    {
        $this->expectException(DBALException::class);
        $this->expectExceptionMessage('violates unique constraint "gh3423_unique"');

        $this->connection->transactional(static function (Connection $connection) : void {
            $connection->exec('SET CONSTRAINTS "gh3423_unique" DEFERRED');
            $connection->exec('INSERT INTO gh3423 VALUES (true)');
        });
    }

    /**
     * @group GH3423
     */
    public function testTransactionalWithDeferredConstraintAndTransactionNesting() : void
    {
        $this->expectException(DBALException::class);
        $this->expectExceptionMessage('violates unique constraint "gh3423_unique"');

        $this->connection->setNestTransactionsWithSavepoints(true);

        $this->connection->transactional(static function (Connection $connection) : void {
            $connection->exec('SET CONSTRAINTS "gh3423_unique" DEFERRED');
            $connection->beginTransaction();
            $connection->exec('INSERT INTO gh3423 VALUES (true)');
            $connection->commit();
        });
    }

    /**
     * @group GH3423
     */
    public function testCommitWithDeferredConstraint() : void
    {
        $this->expectException(DBALException::class);
        $this->expectExceptionMessage('violates unique constraint "gh3423_unique"');

        $this->connection->beginTransaction();
        $this->connection->exec('SET CONSTRAINTS "gh3423_unique" DEFERRED');
        $this->connection->exec('INSERT INTO gh3423 VALUES (true)');
        $this->connection->commit();
    }

    /**
     * @group GH3423
     */
    public function testCommitWithDeferredConstraintAndTransactionNesting() : void
    {
        $this->expectException(DBALException::class);
        $this->expectExceptionMessage('violates unique constraint "gh3423_unique"');

        $this->connection->setNestTransactionsWithSavepoints(true);

        $this->connection->beginTransaction();
        $this->connection->exec('SET CONSTRAINTS "gh3423_unique" DEFERRED');
        $this->connection->beginTransaction();
        $this->connection->exec('INSERT INTO gh3423 VALUES (true)');
        $this->connection->commit();
        $this->connection->commit();
    }
}
