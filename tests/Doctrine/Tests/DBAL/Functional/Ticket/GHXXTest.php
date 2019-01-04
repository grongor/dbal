<?php

declare(strict_types=1);

namespace Doctrine\Tests\DBAL\Functional\Ticket;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\Tests\DbalFunctionalTestCase;

class GHXXTest extends DbalFunctionalTestCase
{
    /** @var bool */
    private static $TABLE_CREATED = false;

    protected function setUp()
    {
        parent::setUp();

        if ($this->_conn->getDatabasePlatform()->getName() !== 'postgresql') {
            $this->markTestSkipped('Only databases supporting deferrable constraints are eligible for this test.');
        }

        if (self::$TABLE_CREATED) {
            return;
        }

        $this->_conn->exec(
            '
            CREATE TABLE ghxx (
                unique_field BOOLEAN NOT NULL
                    CONSTRAINT ghxx_unique
                        UNIQUE
                        DEFERRABLE INITIALLY DEFERRED
            )
            '
        );
        $this->_conn->exec('INSERT INTO ghxx VALUES (true)');

        self::$TABLE_CREATED = true;
    }

    protected function tearDown()
    {
        $this->resetSharedConn();

        parent::tearDown();
    }

    /**
     * @group GHXXX
     */
    public function testTransactionalWithDeferredConstraint() : void
    {
        $this->expectException(DBALException::class);
        $this->expectExceptionMessage('violates unique constraint "ghxx_unique"');

        $this->_conn->transactional(static function (Connection $connection) {
            $connection->exec('SET CONSTRAINTS "ghxx_unique" DEFERRED');
            $connection->exec('INSERT INTO ghxx VALUES (true)');
        });
    }

    /**
     * @group GHXXX
     */
    public function testTransactionalWithDeferredConstraintAndTransactionNesting() : void
    {
        $this->expectException(DBALException::class);
        $this->expectExceptionMessage('violates unique constraint "ghxx_unique"');

        $this->_conn->setNestTransactionsWithSavepoints(true);

        $this->_conn->transactional(static function (Connection $connection) {
            $connection->exec('SET CONSTRAINTS "ghxx_unique" DEFERRED');
            $connection->beginTransaction();
            $connection->exec('INSERT INTO ghxx VALUES (true)');
            $connection->commit();
        });
    }

    /**
     * @group GHXXX
     */
    public function testCommitWithDeferredConstraint() : void
    {
        $this->expectException(DBALException::class);
        $this->expectExceptionMessage('violates unique constraint "ghxx_unique"');

        $this->_conn->beginTransaction();
        $this->_conn->exec('SET CONSTRAINTS "ghxx_unique" DEFERRED');
        $this->_conn->exec('INSERT INTO ghxx VALUES (true)');
        $this->_conn->commit();
    }

    /**
     * @group GHXXX
     */
    public function testCommitWithDeferredConstraintAndTransactionNesting() : void
    {
        $this->expectException(DBALException::class);
        $this->expectExceptionMessage('violates unique constraint "ghxx_unique"');

        $this->_conn->setNestTransactionsWithSavepoints(true);

        $this->_conn->beginTransaction();
        $this->_conn->exec('SET CONSTRAINTS "ghxx_unique" DEFERRED');
        $this->_conn->beginTransaction();
        $this->_conn->exec('INSERT INTO ghxx VALUES (true)');
        $this->_conn->commit();
        $this->_conn->commit();
    }
}
