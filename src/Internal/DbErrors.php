<?php

declare(strict_types=1);

namespace Zharp\Internal;

use PDOException;
use Throwable;

/**
 * A failed query's exception, as Zharp shows it: named from its SQLSTATE (23505 → UniqueViolation),
 * with the statement (placeholders, never the values), the database and the code — and without
 * the row's values that drivers and Laravel put in the message: Postgres's "DETAIL: Key
 * (email)=(…)", MySQL's "Duplicate entry '…'", Laravel's "(Connection: …, SQL: … values (…))".
 *
 * @internal
 */
final class DbErrors
{
    private const SQLSTATE = [
        '23505' => 'UniqueViolation', '23503' => 'ForeignKeyViolation', '23502' => 'NotNullViolation', '23514' => 'CheckViolation',
        '23P01' => 'ExclusionViolation', '22001' => 'StringDataRightTruncation', '22003' => 'NumericValueOutOfRange',
        '22P02' => 'InvalidTextRepresentation', '22007' => 'InvalidDatetimeFormat', '22012' => 'DivisionByZero',
        '42P01' => 'UndefinedTable', '42703' => 'UndefinedColumn', '42883' => 'UndefinedFunction', '42601' => 'SyntaxError',
        '42501' => 'InsufficientPrivilege', '40001' => 'SerializationFailure', '40P01' => 'DeadlockDetected',
        '55P03' => 'LockNotAvailable', '57014' => 'QueryCanceled', '53300' => 'TooManyConnections',
    ];

    /** The PDOException behind $e (it, or what it wraps), or null when $e isn't a failed query. */
    public static function pdo(Throwable $e): ?PDOException
    {
        for ($current = $e, $depth = 0; $current !== null && $depth < 5; $current = $current->getPrevious(), $depth++) {
            if ($current instanceof PDOException) {
                return $current;
            }
        }

        return null;
    }

    public static function isQuery(Throwable $e): bool
    {
        return self::pdo($e) !== null;
    }

    /** The SQLSTATE: errorInfo's, else the exception's code when it is one. */
    public static function code(Throwable $e): ?string
    {
        $pdo = self::pdo($e);
        if ($pdo === null) {
            return null;
        }
        $state = $pdo->errorInfo[0] ?? null;
        if (is_string($state) && $state !== '' && $state !== '00000') {
            return $state;
        }
        $code = (string) $pdo->getCode();

        return preg_match('/^[0-9A-Z]{5}$/', $code) === 1 ? $code : null;
    }

    public static function system(Throwable $e): string
    {
        $message = self::pdo($e)?->getMessage() ?? '';
        if (method_exists($e, 'getConnection')) {
            try {
                $driver = $e->getConnection()?->getDriverName();
                if (is_string($driver) && $driver !== '') {
                    return ['pgsql' => 'postgresql', 'sqlsrv' => 'mssql'][$driver] ?? $driver;
                }
            } catch (Throwable) {
            }
        }

        return match (true) {
            str_contains($message, 'ERROR: ') || str_contains($message, 'ERROR:  ') => 'postgresql',
            preg_match('/: \d{4} /', $message) === 1 => 'mysql',
            str_contains($message, 'General error: ') && str_contains(strtolower($message), 'sqlite') => 'sqlite',
            default => 'other_sql',
        };
    }

    /** The type: the SQLSTATE's name for Postgres's codes, else the exception's class. */
    public static function type(Throwable $e): string
    {
        $code = self::code($e);
        if ($code !== null && isset(self::SQLSTATE[$code]) && self::system($e) !== 'mysql') {
            return self::SQLSTATE[$code];
        }

        return get_class($e);
    }

    /** The message the database gave, without the row's values or the SQL with values bound in. */
    public static function message(string $message): string
    {
        // Laravel: "… (Connection: pgsql, SQL: insert into … values (ada@example.com))", newer ones with
        // "Database: …" between too, older ones "(SQL: …)" alone: whatever comes before "SQL:".
        $message = (string) preg_replace('/\s*\((?:[A-Za-z]+: [^,()]*, )*SQL: .*\)\s*$/s', '', $message);
        // Postgres's DETAIL line names the row: "Key (email)=(ada@example.com) already exists."
        $message = (string) preg_replace('/\s*DETAIL:.*$/ms', '', $message);
        // MySQL: "Duplicate entry 'ada@example.com' for key …"
        $message = (string) preg_replace("/Duplicate entry '.*?' for key/s", 'Duplicate entry for key', $message);

        return trim($message);
    }

    /** @return array<string, string> */
    public static function attributes(Throwable $e): array
    {
        $out = ['db.system' => self::system($e)];
        if (method_exists($e, 'getSql')) {
            try {
                $sql = $e->getSql();
                if (is_string($sql) && $sql !== '') {
                    $out['db.statement'] = substr($sql, 0, 4000);
                }
            } catch (Throwable) {
            }
        }
        if (method_exists($e, 'getConnectionName')) {
            try {
                $name = $e->getConnectionName();
                if (is_string($name) && $name !== '') {
                    $out['db.connection'] = $name;
                }
            } catch (Throwable) {
            }
        }
        $code = self::code($e);
        if ($code !== null) {
            $out['db.error.code'] = $code;
        }
        $message = self::pdo($e)?->getMessage() ?? '';
        if (preg_match('/constraint "([^"]+)"/', $message, $m) === 1 || preg_match("/for key '([^']+)'/", $message, $m) === 1) {
            $out['db.error.constraint'] = $m[1];
        }

        return $out;
    }
}
