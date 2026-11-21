<?php

declare(strict_types=1);

namespace Zharp\Tests;

use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zharp\Internal\DbErrors;
use Zharp\Internal\Exceptions;
use Zharp\Internal\Headers;
use Zharp\Internal\SourceContext;

/** Laravel's QueryException, as far as Zharp reads it. */
final class FakeQueryException extends RuntimeException
{
    public function __construct(private string $sql, PDOException $previous, private string $connection = 'pgsql')
    {
        parent::__construct($previous->getMessage() . ' (Connection: pgsql, SQL: insert into "users" ("email") values (ada@example.com))', 0, $previous);
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getConnectionName(): string
    {
        return $this->connection;
    }
}

final class QueryErrorsTest extends TestCase
{
    private static function pdoError(): PDOException
    {
        $e = new PDOException("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_key\"\nDETAIL:  Key (email)=(ada@example.com) already exists.");
        $e->errorInfo = ['23505', 7, 'ERROR:  duplicate key value violates unique constraint "users_email_key"'];

        return $e;
    }

    public function testAFailedQueryIsNamedFromItsCodeAndKeepsNoValues(): void
    {
        $e = new FakeQueryException('insert into "users" ("email") values (?)', self::pdoError());
        $attributes = Exceptions::attributes($e, false);

        self::assertSame('UniqueViolation', $attributes['exception.type']);
        self::assertSame('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "users_email_key"', $attributes['exception.message']);
        self::assertStringNotContainsString('ada@example.com', $attributes['exception.message']);
        self::assertStringNotContainsString('ada@example.com', $attributes['exception.stacktrace']);
        self::assertSame('insert into "users" ("email") values (?)', $attributes['db.statement']);
        self::assertSame('23505', $attributes['db.error.code']);
        self::assertSame('users_email_key', $attributes['db.error.constraint']);
        self::assertSame('postgresql', $attributes['db.system']);
        self::assertStringStartsWith('UniqueViolation: ', Exceptions::title($e));
    }

    public function testMysqlKeepsItsClassAndLosesTheDuplicateValue(): void
    {
        $e = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ada@example.com' for key 'users.email'");
        $e->errorInfo = ['23000', 1062, "Duplicate entry 'ada@example.com' for key 'users.email'"];
        $attributes = Exceptions::attributes($e, true);

        self::assertSame('PDOException', $attributes['exception.type']);
        self::assertSame("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key 'users.email'", $attributes['exception.message']);
        self::assertSame('mysql', $attributes['db.system']);
        self::assertSame('users.email', $attributes['db.error.constraint']);
    }

    public function testEveryLaravelSuffixShapeLosesItsValues(): void
    {
        foreach ([
            'General error: 1 no such table: users (Connection: sqlite, Database: /app/database/database.sqlite, SQL: insert into "users" ("email") values (ada@example.com, Ada))',
            'General error: 1 no such table: users (Connection: pgsql, SQL: insert into "users" ("email") values (ada@example.com))',
            'General error: 1 no such table: users (SQL: insert into "users" ("email") values (ada@example.com))',
        ] as $message) {
            self::assertSame('General error: 1 no such table: users', DbErrors::message($message));
        }
    }

    public function testStacksNeverCarryArguments(): void
    {
        $e = (static fn (string $email) => new RuntimeException('x'))('ada@example.com');
        $stack = Exceptions::stacktrace(self::thrownWith('ada@example.com'));
        self::assertStringNotContainsString('ada@example.com', $stack);
        self::assertMatchesRegularExpression('/#1 .*thrownWith\(\)/', $stack);
        self::assertStringEndsWith('{main}', $stack);
        unset($e);
    }

    private static function thrownWith(string $email): RuntimeException
    {
        return new RuntimeException('failed for a user');
    }

    public function testOtherErrorsAreUntouched(): void
    {
        $e = new RuntimeException('DETAIL: kept');
        $attributes = Exceptions::attributes($e, true);
        self::assertSame('RuntimeException', $attributes['exception.type']);
        self::assertSame('DETAIL: kept', $attributes['exception.message']);
        self::assertArrayNotHasKey('db.system', $attributes);
        self::assertFalse(DbErrors::isQuery($e));
    }

    public function testTheCodeAroundTheAppsLinesGoesWithTheError(): void
    {
        $line = __LINE__ + 1;
        $e = new RuntimeException('bad total');
        $sent = json_decode((string) SourceContext::of($e), true);

        self::assertSame(__FILE__, $sent[0]['file']);
        self::assertSame($line, $sent[0]['line']);
        self::assertSame("\$e = new RuntimeException('bad total');", trim($sent[0]['code']));
        self::assertLessThanOrEqual(5, count($sent[0]['pre']));
        foreach ($sent as $frame) {
            self::assertStringNotContainsString('/vendor/', str_replace(chr(92), '/', $frame['file']));
        }

        SourceContext::$enabled = false;
        try {
            self::assertNull(SourceContext::of($e));
        } finally {
            SourceContext::$enabled = true;
        }
    }

    public function testHeadersSecretsFiltered(): void
    {
        $attributes = Headers::attributes('request', [
            'User-Agent' => ['okhttp/4.12.0'], 'Authorization' => ['Bearer abc'], 'Cookie' => ['sid=1'], 'X-Session-Token' => ['t'],
            'Accept' => ['a', 'b'],
        ]);
        self::assertSame('okhttp/4.12.0', $attributes['http.request.header.user-agent']);
        self::assertSame('[Filtered]', $attributes['http.request.header.authorization']);
        self::assertSame('[Filtered]', $attributes['http.request.header.cookie']);
        self::assertSame('[Filtered]', $attributes['http.request.header.x-session-token']);
        self::assertSame('a, b', $attributes['http.request.header.accept']);
    }
}
