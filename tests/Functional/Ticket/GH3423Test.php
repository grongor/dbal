<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional\Ticket;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tests\FunctionalTestCase;
use PDOException;

use function in_array;

/**
 * @see https://github.com/doctrine/dbal/issues/3423
 */
class GH3423Test extends FunctionalTestCase
{
    /** @var bool */
    private static $tableCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array($this->connection->getDatabasePlatform()->getName(), ['postgresql', 'oracle'], true)) {
            $this->markTestSkipped('Only databases supporting deferrable constraints are eligible for this test.');
        }

        if (self::$tableCreated) {
            return;
        }

        $createTableQuery = <<<SQL
CREATE TABLE gh3423 (
    unique_field BOOLEAN NOT NULL CONSTRAINT gh3423_unique UNIQUE DEFERRABLE INITIALLY DEFERRED
)
SQL;

        $this->connection->executeStatement($createTableQuery);
        $this->connection->executeStatement('INSERT INTO gh3423 VALUES (true)');

        self::$tableCreated = true;
    }

    /**
     * @group GH3423
     */
    public function testTransactionalWithDeferredConstraint(): void
    {
        $this->connection->transactional(function (Connection $connection) : void {
            $connection->executeStatement('SET CONSTRAINTS "gh3423_unique" DEFERRED');
            $connection->executeStatement('INSERT INTO gh3423 VALUES (true)');

            $this->expectException(PDOException::class);
            $this->expectExceptionMessage('violates unique constraint "gh3423_unique"');
        });
    }

    /**
     * @group GH3423
     */
    public function testTransactionalWithDeferredConstraintAndTransactionNesting(): void
    {
        $this->connection->setNestTransactionsWithSavepoints(true);

        $this->connection->transactional(function (Connection $connection): void {
            $connection->executeStatement('SET CONSTRAINTS "gh3423_unique" DEFERRED');
            $connection->beginTransaction();
            $connection->executeStatement('INSERT INTO gh3423 VALUES (true)');
            $connection->commit();

            $this->expectException(PDOException::class);
            $this->expectExceptionMessage('violates unique constraint "gh3423_unique"');
        });
    }

    /**
     * @group GH3423
     */
    public function testCommitWithDeferredConstraint(): void
    {
        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET CONSTRAINTS "gh3423_unique" DEFERRED');
        $this->connection->executeStatement('INSERT INTO gh3423 VALUES (true)');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('violates unique constraint "gh3423_unique"');

        $this->connection->commit();
    }

    /**
     * @group GH3423
     */
    public function testCommitWithDeferredConstraintAndTransactionNesting(): void
    {
        $this->connection->setNestTransactionsWithSavepoints(true);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET CONSTRAINTS "gh3423_unique" DEFERRED');
        $this->connection->beginTransaction();
        $this->connection->executeStatement('INSERT INTO gh3423 VALUES (true)');
        $this->connection->commit();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('violates unique constraint "gh3423_unique"');

        $this->connection->commit();
    }
}
