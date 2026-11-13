<?php
declare(strict_types=1);
namespace Studio;

final class Db {
    private static ?\PDO $pdo = null;
    public static function connection(): \PDO {
        if (!self::$pdo) {
            $db = config('db');
            $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int)($db['port'] ?? 3306) . ';dbname=' . $db['name'] . ';charset=utf8mb4';
            self::$pdo = new \PDO($dsn, $db['user'], $db['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false]);
            self::$pdo->exec("SET time_zone = '+00:00'");
            $version = (string)self::$pdo->query('SELECT VERSION()')->fetchColumn();
            $maria = stripos($version, 'MariaDB') !== false;
            $normalized = preg_replace('/^5\.5\.5-/', '', $version);
            if (!preg_match('/^(\d+\.\d+\.\d+)/', $normalized, $match) || version_compare($match[1], $maria ? '10.6.0' : '8.0.16', '<')) {
                throw new \RuntimeException('Requires MySQL 8.0.16+ or MariaDB 10.6+ for enforced constraints');
            }
        }
        return self::$pdo;
    }
    public static function run(string $sql, array $values = []): \PDOStatement { $statement = self::connection()->prepare($sql); $statement->execute($values); return $statement; }
    public static function one(string $sql, array $values = []): ?array { return self::run($sql, $values)->fetch() ?: null; }
    public static function all(string $sql, array $values = []): array { return self::run($sql, $values)->fetchAll(); }
    public static function id(): int { return (int)self::connection()->lastInsertId(); }
    public static function transaction(callable $callback): mixed {
        $db = self::connection(); $db->beginTransaction();
        try { $value = $callback(); $db->commit(); return $value; } catch (\Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
    }
}

