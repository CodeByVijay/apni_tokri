<?php

declare(strict_types=1);

final class InstallSqlImporter
{
    /**
     * Import a mysqldump-style SQL file (one statement per exec).
     *
     * @return array{ok: bool, message?: string, statements?: int}
     */
    public static function importFile(PDO $pdo, string $absolutePath): array
    {
        if (!is_readable($absolutePath)) {
            return ['ok' => false, 'message' => 'SQL file is not readable.'];
        }
        $sql = file_get_contents($absolutePath);
        if ($sql === false || $sql === '') {
            return ['ok' => false, 'message' => 'SQL file is empty.'];
        }

        $statements = self::splitStatements($sql);
        $ran = 0;
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($statements as $stmt) {
                $trim = trim($stmt);
                if ($trim === '') {
                    continue;
                }
                $stmt = $pdo->query($trim);
                if ($stmt instanceof PDOStatement) {
                    while ($stmt->nextRowset()) {
                        // drain multi-statement / EXECUTE SELECT side effects
                    }
                    $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                }
                $ran++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            error_log('InstallSqlImporter: statement failed: ' . $e->getMessage());
            $detail = trim($e->getMessage());
            if (getenv('APNI_TOKRI_DISPOSABLE_DB') === '1' && $detail !== '') {
                return ['ok' => false, 'message' => 'Database import failed: ' . $detail];
            }

            return ['ok' => false, 'message' => 'Database import failed. Ensure the database is empty and try again.'];
        }

        return ['ok' => true, 'statements' => $ran];
    }

    /** @return list<string> */
    private static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $buffer = '';
        $parts = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (preg_match('/;\s*$/', rtrim($line))) {
                $parts[] = $buffer;
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }
}
