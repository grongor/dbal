<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\OCI8\Driver as OCI8Driver;
use Doctrine\DBAL\Tests\FunctionalTestCase;
use PDOException;

use PHPUnit\Framework\Error\Warning;

use function in_array;

/**
 * @see https://github.com/doctrine/dbal/issues/3423
 */
class ConnectionDeferredConstraintsTest extends FunctionalTestCase
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
CREATE TABLE with_deferrable_constraints (
    unique_field CHAR(1) NOT NULL
        CONSTRAINT wdc_unique UNIQUE DEFERRABLE INITIALLY DEFERRED
)
SQL;

        $this->connection->executeStatement($createTableQuery);
        $this->connection->executeStatement("INSERT INTO with_deferrable_constraints VALUES ('x')");

        self::$tableCreated = true;
    }

    public function testTransactionalWithDeferredConstraint(): void
    {
        $this->connection->transactional(function (Connection $connection) : void {
            $connection->executeStatement('SET CONSTRAINTS ALL DEFERRED');
            $connection->executeStatement("INSERT INTO with_deferrable_constraints VALUES ('x')");

            $this->setExpectedException();
        });
    }

    public function testTransactionalWithDeferredConstraintAndTransactionNesting(): void
    {
        $this->connection->setNestTransactionsWithSavepoints(true);

        $this->connection->transactional(function (Connection $connection): void {
            $connection->executeStatement('SET CONSTRAINTS ALL DEFERRED');
            $connection->beginTransaction();
            $connection->executeStatement("INSERT INTO with_deferrable_constraints VALUES ('x')");
            $connection->commit();

            $this->setExpectedException();
        });
    }

    public function testCommitWithDeferredConstraint(): void
    {
        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET CONSTRAINTS ALL DEFERRED');
        $this->connection->executeStatement("INSERT INTO with_deferrable_constraints VALUES ('x')");

        $this->setExpectedException();

        $this->connection->commit();
    }

    public function testCommitWithDeferredConstraintAndTransactionNesting(): void
    {
        $this->connection->setNestTransactionsWithSavepoints(true);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET CONSTRAINTS ALL DEFERRED');
        $this->connection->beginTransaction();
        $this->connection->executeStatement("INSERT INTO with_deferrable_constraints VALUES ('x')");
        $this->connection->commit();

        $this->setExpectedException();

        $this->connection->commit();
    }

    private function setExpectedException(): void
    {
        if ($this->connection->getDriver() instanceof OCI8Driver) {
            $this->expectWarning();
        } else {
            $this->expectException(PDOException::class);
        }

        $this->expectExceptionMessage('unique constraint');
    }
}
