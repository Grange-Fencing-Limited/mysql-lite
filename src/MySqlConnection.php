<?php

    namespace GrangeFencing\MySqlLite;

    use Exception;
    use PDO;
    use PDOException;

    const MYSQL_INTEGRITY_CONSTRAINT_VIOLATION = 23000;

    /**
     * Lightweight wrapper around a PDO MySQL connection with convenience helpers for
     * transactions, debugging and connection management.
     *
     * This class centralises the PDO connection and exposes a small, fluent API for
     * common operations used by the library (begin/commit/rollback, lastInsertId,
     * and a toggle to enable debug output to the console).
     */
    class MySqlConnection {

        /**
         * True when a transaction has been started via beginTransaction(). Prevents
         * nested beginTransaction() calls from calling PDO::beginTransaction again.
         * @var bool
         */
        public bool $usingTransaction = false;

        /**
         * When true log messages will also be printed to the output (useful for local debugging).
         * @var bool
         */
        public bool $debugToConsole = false;

        /**
         * Underlying PDO connection instance. May be null before connect() is called
         * or after close() has been called.
         * @var PDO|null
         */
        public ?PDO $conn;

        /**
         * Construct the connection wrapper and attempt connection using environment
         * variables unless overridden.
         *
         * @param array $overrideConnection Optional keys: database_host, database_port, database_name, database_username, database_password
         */
        public function __construct(array $overrideConnection = []) {
            $this->connect($overrideConnection);
        }

        /**
         * Establish a PDO MySQL connection using environment values merged with any provided overrides.
         *
         * @param array $overrideConnection Optional connection parameters to override environment variables.
         *
         * @return PDO|null The established PDO instance or null on failure (exception is thrown in failure case).
         *@throws Exception If the underlying PDO connection fails to initialize.
         */
        public function connect(array $overrideConnection): ?PDO {

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

                error_log("Database connection failed: " . $exception->getMessage() . "\n" . $exception->getTraceAsString());
                throw new Exception("Connection error: " . $exception->getMessage());

            }

            return $this->conn;

        }

        /**
         * Close the underlying PDO connection.
         *
         * @return void
         */
        public function close(): void {
            $this->conn = null;
        }

        /**
         * Begin a new transaction if one is not already active.
         *
         * @return $this
         */
        public function beginTransaction(): static {
            if (!$this->usingTransaction) {
                $this->conn->beginTransaction();
                $this->usingTransaction = true;
            }
            return $this;
        }

        /**
         * Commit the current transaction if active.
         *
         * @return $this
         */
        public function commit(): static {
            if ($this->usingTransaction) {
                $this->conn->commit();
                $this->usingTransaction = false;
            }
            return $this;
        }

        /**
         * Roll back the current transaction if active.
         *
         * @return $this
         */
        public function rollBack(): static {
            if ($this->usingTransaction) {
                $this->conn->rollBack();
                $this->usingTransaction = false;
            }
            return $this;
        }

        /**
         * Get the last inserted id from the connection.
         *
         * @param string|null $name Optional name for the sequence (MySQL does not use this).
         * @return string|false The last insert id string or false on failure.
         */
        public function lastInsertId(?string $name = null): bool|string {
            return $this->conn->lastInsertId($name);
        }

        /**
         * Enable or disable debug-to-console behaviour.
         *
         * @param bool $enabled True to enable printing debug messages.
         * @return $this
         */
        public function debugToConsole(bool $enabled = true): static {
            $this->debugToConsole = $enabled;
            return $this;
        }

    }