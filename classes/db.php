<?php

class DB {
    private $pdo = null;
    private static $instance = null;

    public function __construct()
    {
        try {
            $this->pdo = new PDO(sprintf("sqlite:%s", DB_DATABASE));
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // attempt create table if not exists
            $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FAST;');
            $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS debug_entries (
                    uuid CHAR(48) NOT NULL PRIMARY KEY, 
                    type CHAR(16) NOT NULL, 
                    content VARCHAR(512) DEFAULT '', 
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)
                SQL
            );
            $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS preferences (
                    identifier_code CHAR(16) PRIMARY KEY,
                    value VARCHAR(8) DEFAULT NULL,
                    type CHAR(16) NOT NULL DEFAULT 'string',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
                SQL
            );
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_entries_created ON debug_entries(created_at DESC)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_entries_uuid ON debug_entries(uuid)");
        } catch (\PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public function query($sql, ...$bindings)
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt;
    }

    public static function instance()
    {
        return is_null(static::$instance) ? 
            static::$instance = new self : 
            static::$instance;
    }

    public function getPdo()
    {
        return $this->pdo;
    }

    public function __destroy()
    {
        $this->pdo->close();
    }
}