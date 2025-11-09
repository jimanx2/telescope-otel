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
            $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS debug_entries (
                    uuid CHAR(48) NOT NULL PRIMARY KEY, 
                    type CHAR(16) NOT NULL, 
                    content VARCHAR(512) DEFAULT '', 
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)
                SQL
            );
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

    public function __destroy()
    {
        $this->pdo->close();
    }
}