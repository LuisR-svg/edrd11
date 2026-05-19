<?php
/**
 * includes/db.php — PDO Database Connection (Singleton)
 * ============================================================
 * Uses PDO with prepared statements throughout the app.
 * Prepared statements prevent SQL injection automatically.
 * ============================================================
 */

require_once __DIR__ . '/config.php';

class DB {
    private static ?PDO $instance = null;

    /**
     * Get the single PDO instance.
     * Usage: $pdo = DB::get();
     */
    public static function get(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // Use real prepared statements
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Log the real error but show a safe message to users
                error_log('DB Connection failed: ' . $e->getMessage());
                die(json_encode(['error' => 'Database connection failed. Please try again later.']));
            }
        }
        return self::$instance;
    }

    // Prevent instantiation and cloning
    private function __construct() {}
    private function __clone() {}
}