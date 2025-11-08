<?php

    namespace GrangeFencing\MySqlLite;

    use Exception;
    use PDO;
    use PDOException;

    const MYSQL_INTEGRITY_CONSTRAINT_VIOLATION = 23000;

    class MySqlConnection {

        public bool $usingTransaction = false;
        public bool $debugToConsole = false;

        public ?PDO $conn;

        public function __construct() {
            $this->connect();
        }

        /**
         * @throws Exception
         */
        public function connect(array $overrideConnection = []): ?PDO {

            $this->conn = null;

            $credentials = array_merge(
                [
                    "database_host" => $_ENV["DATABASE_HOST"],
                    "database_port" => $_ENV["DATABASE_PORT"],
                    "database_name" => $_ENV["DATABASE_NAME"],
                    "database_username" => $_ENV["DATABASE_USERNAME"],
                    "database_password" => $_ENV["DATABASE_PASSWORD"],
                ],
                $overrideConnection
            );

            try {

                $this->conn = new PDO(
                    "mysql:host={$credentials['database_host']};port={$credentials['database_port']};dbname={$credentials['database_name']}",
                    $credentials['database_username'],
                    $credentials['database_password'],
                    [
                        PDO::MYSQL_ATTR_FOUND_ROWS => true,
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]
                );

            }
            catch (PDOException $exception) {

                error_log("Database connection failed: " . $exception->getMessage());
                throw new Exception("Connection error: " . $exception->getMessage());

            }

            return $this->conn;

        }

        public function close(): void {
            $this->conn = null;
        }

        public function beginTransaction(): static {
            if (!$this->usingTransaction) {
                $this->conn->beginTransaction();
                $this->usingTransaction = true;
            }
            return $this;
        }

        public function commit(): static {
            if ($this->usingTransaction) {
                $this->conn->commit();
                $this->usingTransaction = false;
            }
            return $this;
        }

        public function rollBack(): static {
            if ($this->usingTransaction) {
                $this->conn->rollBack();
                $this->usingTransaction = false;
            }
            return $this;
        }

        public function lastInsertId(?string $name = null): bool|string {
            return $this->conn->lastInsertId($name);
        }

        public function debugToConsole(bool $enabled = true): static {
            $this->debugToConsole = $enabled;
            return $this;
        }

    }