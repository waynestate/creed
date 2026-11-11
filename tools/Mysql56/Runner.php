<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tools\Mysql56;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Runs the probes against a live MySQL and reports, for each one, whether the
 * server agreed with the chapter.
 *
 * The session is set up the way Laravel 13 sets up a strict MySQL connection
 * (its MySqlConnector), so the answers are the ones an app gets. Every `creed_probe_*` table is dropped before and after.
 */
final class Runner
{
    /** Laravel 13's strict sql_mode for MySQL older than 8.0.11. */
    public const string SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';

    /** The same from 8.0.11, which removed NO_AUTO_CREATE_USER. */
    public const string SQL_MODE_80 = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    public function __construct(private readonly PDO $pdo) {}

    /** The server's version string, for the report's first line. */
    public function serverVersion(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * @param list<Probe> $probes
     * @return list<array{probe: Probe, failure: string|null}> failure is null when the server agreed
     */
    public function run(array $probes): array
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("set names 'utf8mb4' collate 'utf8mb4_unicode_ci'");
        // Laravel drops NO_AUTO_CREATE_USER from 8.0.11; doing the same lets the probes run on 8.4 too, where each
        // is judged against its 8.4 answer.
        if (version_compare($this->serverVersion(), '8.0.11', '>=')) {
            $this->pdo->exec("set session sql_mode='" . self::SQL_MODE_80 . "'");
        } else {
            $this->pdo->exec("set session sql_mode='" . self::SQL_MODE . "'");
        }

        $this->sweep();
        $version = $this->serverVersion();
        $results = [];
        foreach ($probes as $probe) {
            $results[] = ['probe' => $probe, 'failure' => $this->runOne($probe, $probe->expectationFor($version))];
        }
        $this->sweep();

        return $results;
    }

    private function runOne(Probe $probe, string $expect): ?string
    {
        foreach ($probe->setup as $statement) {
            try {
                // @phpstan-ignore pinstripe.banned.joinedSql (fixed probe SQL from tools/Mysql56/Probes.php, never input)
                $this->pdo->exec($statement);
            } catch (PDOException $e) {
                return 'setup failed: ' . $e->getMessage();
            }
        }

        try {
            // @phpstan-ignore pinstripe.banned.joinedSql (fixed probe SQL from tools/Mysql56/Probes.php, never input)
            $statement = $this->pdo->query($probe->sql);
        } catch (PDOException $e) {
            $code = self::errorCode($e);

            return $code === null ? 'failed without a MySQL error code: ' . $e->getMessage() : $probe->verdict($code, [], null, $expect);
        }

        $value = null;
        if ($statement instanceof PDOStatement && $statement->columnCount() > 0) {
            $row = $statement->fetch(PDO::FETCH_NUM);
            $value = is_array($row) && array_key_exists(0, $row) ? self::text($row[0]) : null;
            $statement->closeCursor();
        }

        return $probe->verdict(null, $this->warnings(), $value, $expect);
    }

    /** @return list<int> */
    private function warnings(): array
    {
        $codes = [];
        $statement = $this->pdo->query('show warnings');
        if ($statement instanceof PDOStatement) {
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (is_array($row) && isset($row['Code']) && is_numeric($row['Code'])) {
                    $codes[] = (int) $row['Code'];
                }
            }
        }

        return $codes;
    }

    /** Drops every table a probe may have left behind, including from an earlier run that stopped halfway. */
    private function sweep(): void
    {
        $statement = $this->pdo->query(
            "select table_name from information_schema.tables where table_schema = schema() and table_name like 'creed\\_probe\\_%'",
        );
        $tables = $statement instanceof PDOStatement ? $statement->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($tables as $table) {
            if (is_string($table) && preg_match('/^creed_probe_[a-z0-9_]+$/', $table) === 1) {
                // @phpstan-ignore pinstripe.banned.joinedSql (a table name the query above read, held to creed_probe_[a-z0-9_]+)
                $this->pdo->exec('drop table if exists `' . str_replace('`', '``', $table) . '`');
            }
        }
    }

    private static function errorCode(PDOException $e): ?int
    {
        $info = $e->errorInfo;

        return is_array($info) && isset($info[1]) && is_numeric($info[1]) ? (int) $info[1] : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
