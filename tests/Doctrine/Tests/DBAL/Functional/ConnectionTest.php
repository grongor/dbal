<?php

namespace Doctrine\Tests\DBAL\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\ExceptionConverterDriver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\Tests\DbalFunctionalTestCase;
use Error;
use function in_array;

class ConnectionTest extends DbalFunctionalTestCase
{
    private const TABLE = 'connection_test';

    protected function setUp()
    {
        $this->resetSharedConn();
        parent::setUp();
    }

    protected function tearDown()
    {
        parent::tearDown();
        $this->resetSharedConn();
    }

    public function testGetWrappedConnection()
    {
        self::assertInstanceOf(DriverConnection::class, $this->connection->getWrappedConnection());
    }

    public function testCommitWithRollbackOnlyThrowsException()
    {
        $this->connection->beginTransaction();
        $this->connection->setRollbackOnly();

        $this->expectException(ConnectionException::class);
        $this->connection->commit();
    }

    public function testTransactionNestingBehavior()
    {
        $this->createTestTable();

        try {
            $this->connection->beginTransaction();
            self::assertSame(1, $this->connection->getTransactionNestingLevel());

            try {
                $this->connection->beginTransaction();
                self::assertSame(2, $this->connection->getTransactionNestingLevel());
                $this->connection->insert(self::TABLE, ['id' => 1]);
                self::fail('Expected exception to be thrown because of the unique constraint.');
            } catch (DBALException $e) {
                $this->assertIsUniqueConstraintException($e);
                $this->connection->rollBack();
                self::assertSame(1, $this->connection->getTransactionNestingLevel());
            }
            self::assertTrue($this->connection->isRollbackOnly());

            $this->connection->commit(); // should throw exception
            self::fail('Transaction commit after failed nested transaction should fail.');
        } catch (ConnectionException $e) {
            self::assertSame(1, $this->connection->getTransactionNestingLevel());
            $this->connection->rollBack();
            self::assertSame(0, $this->connection->getTransactionNestingLevel());
        }
    }

    public function testTransactionNestingBehaviorWithSavepoints()
    {
        if (! $this->connection->getDatabasePlatform()->supportsSavepoints()) {
            $this->markTestSkipped('This test requires the platform to support savepoints.');
        }

        $this->createTestTable();

        $this->connection->setNestTransactionsWithSavepoints(true);
        try {
            $this->connection->beginTransaction();
            self::assertSame(1, $this->connection->getTransactionNestingLevel());

            try {
                $this->connection->beginTransaction();
                self::assertSame(2, $this->connection->getTransactionNestingLevel());
                $this->connection->beginTransaction();
                self::assertSame(3, $this->connection->getTransactionNestingLevel());
                $this->connection->commit();
                self::assertSame(2, $this->connection->getTransactionNestingLevel());
                $this->connection->insert(self::TABLE, ['id' => 1]);
                self::fail('Expected exception to be thrown because of the unique constraint.');
            } catch (DBALException $e) {
                $this->assertIsUniqueConstraintException($e);
                $this->connection->rollBack();
                self::assertSame(1, $this->connection->getTransactionNestingLevel());
            }
            self::assertFalse($this->connection->isRollbackOnly());
            try {
                $this->connection->setNestTransactionsWithSavepoints(false);
                self::fail('Should not be able to disable savepoints in usage for nested transactions inside an open transaction.');
            } catch (ConnectionException $e) {
                self::assertTrue($this->connection->getNestTransactionsWithSavepoints());
            }
            $this->connection->commit(); // should not throw exception
        } catch (ConnectionException $e) {
            self::fail('Transaction commit after failed nested transaction should not fail when using savepoints.');
            $this->connection->rollBack();
        }
    }

    public function testTransactionNestingBehaviorCantBeChangedInActiveTransaction()
    {
        if (! $this->connection->getDatabasePlatform()->supportsSavepoints()) {
            $this->markTestSkipped('This test requires the platform to support savepoints.');
        }

        $this->connection->beginTransaction();
        $this->expectException(ConnectionException::class);
        $this->connection->setNestTransactionsWithSavepoints(true);
    }

    public function testSetNestedTransactionsThroughSavepointsNotSupportedThrowsException()
    {
        if ($this->connection->getDatabasePlatform()->supportsSavepoints()) {
            $this->markTestSkipped('This test requires the platform not to support savepoints.');
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Savepoints are not supported by this driver.');

        $this->connection->setNestTransactionsWithSavepoints(true);
    }

    public function testCreateSavepointsNotSupportedThrowsException()
    {
        if ($this->connection->getDatabasePlatform()->supportsSavepoints()) {
            $this->markTestSkipped('This test requires the platform not to support savepoints.');
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Savepoints are not supported by this driver.');

        $this->connection->createSavepoint('foo');
    }

    public function testReleaseSavepointsNotSupportedThrowsException()
    {
        if ($this->connection->getDatabasePlatform()->supportsSavepoints()) {
            $this->markTestSkipped('This test requires the platform not to support savepoints.');
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Savepoints are not supported by this driver.');

        $this->connection->releaseSavepoint('foo');
    }

    public function testRollbackSavepointsNotSupportedThrowsException()
    {
        if ($this->connection->getDatabasePlatform()->supportsSavepoints()) {
            $this->markTestSkipped('This test requires the platform not to support savepoints.');
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Savepoints are not supported by this driver.');

        $this->connection->rollbackSavepoint('foo');
    }

    public function testTransactionBehaviorWithRollback()
    {
        $this->createTestTable();

        try {
            $this->connection->beginTransaction();
            self::assertSame(1, $this->connection->getTransactionNestingLevel());

            $this->connection->insert(self::TABLE, ['id' => 1]);
            self::fail('Expected exception to be thrown because of the unique constraint.');
        } catch (DBALException $e) {
            $this->assertIsUniqueConstraintException($e);
            self::assertSame(1, $this->connection->getTransactionNestingLevel());
            $this->connection->rollBack();
            self::assertSame(0, $this->connection->getTransactionNestingLevel());
        }
    }

    public function testTransactionBehaviour()
    {
        $this->createTestTable();

        $this->connection->beginTransaction();
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->connection->insert(self::TABLE, ['id' => 2]);
        $this->connection->commit();
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testTransactionalWithException()
    {
        $this->createTestTable();

        try {
            $this->connection->transactional(static function (Connection $connection) : void {
                $connection->insert(self::TABLE, ['id' => 1]);
            });
            self::fail('Expected exception to be thrown because of the unique constraint.');
        } catch (DBALException $e) {
            $this->assertIsUniqueConstraintException($e);
            self::assertSame(0, $this->connection->getTransactionNestingLevel());
        }
    }

    public function testTransactionalWithThrowable()
    {
        try {
            $this->connection->transactional(static function ($conn) {
                /** @var Connection $conn */
                $conn->executeQuery($conn->getDatabasePlatform()->getDummySelectSQL());
                throw new Error('Ooops!');
            });
            $this->fail('Expected exception');
        } catch (Error $expected) {
            self::assertEquals(0, $this->connection->getTransactionNestingLevel());
        }
    }

    public function testTransactional()
    {
        $this->createTestTable();

        $res = $this->connection->transactional(static function (Connection $connection) {
            $connection->insert(self::TABLE, ['id' => 2]);
        });

        self::assertNull($res);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testTransactionalReturnValue()
    {
        $res = $this->connection->transactional(static function () {
            return 42;
        });

        self::assertEquals(42, $res);
    }

    /**
     * Tests that the quote function accepts DBAL and PDO types.
     */
    public function testQuote()
    {
        self::assertEquals(
            $this->connection->quote('foo', Type::STRING),
            $this->connection->quote('foo', ParameterType::STRING)
        );
    }

    public function testPingDoesTriggersConnect()
    {
        self::assertTrue($this->connection->ping());
        self::assertTrue($this->connection->isConnected());
    }

    /**
     * @group DBAL-1025
     */
    public function testConnectWithoutExplicitDatabaseName()
    {
        if (in_array($this->connection->getDatabasePlatform()->getName(), ['oracle', 'db2'], true)) {
            $this->markTestSkipped('Platform does not support connecting without database name.');
        }

        $params = $this->connection->getParams();
        unset($params['dbname']);

        $connection = DriverManager::getConnection(
            $params,
            $this->connection->getConfiguration(),
            $this->connection->getEventManager()
        );

        self::assertTrue($connection->connect());

        $connection->close();
    }

    /**
     * @group DBAL-990
     */
    public function testDeterminesDatabasePlatformWhenConnectingToNonExistentDatabase()
    {
        if (in_array($this->connection->getDatabasePlatform()->getName(), ['oracle', 'db2'], true)) {
            $this->markTestSkipped('Platform does not support connecting without database name.');
        }

        $params = $this->connection->getParams();

        $params['dbname'] = 'foo_bar';

        $connection = DriverManager::getConnection(
            $params,
            $this->connection->getConfiguration(),
            $this->connection->getEventManager()
        );

        self::assertInstanceOf(AbstractPlatform::class, $connection->getDatabasePlatform());
        self::assertFalse($connection->isConnected());
        self::assertSame($params, $connection->getParams());

        $connection->close();
    }

    private function createTestTable() : void
    {
        $table = new Table(self::TABLE);
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);

        $this->connection->getSchemaManager()->dropAndCreateTable($table);

        $this->connection->insert(self::TABLE, ['id' => 1]);
    }

    private function assertIsUniqueConstraintException(DBALException $exception) : void
    {
        if (! $this->connection->getDriver() instanceof ExceptionConverterDriver) {
            return;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $exception);
    }
}
