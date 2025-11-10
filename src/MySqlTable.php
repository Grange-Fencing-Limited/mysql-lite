<?php

    namespace GrangeFencing\MySqlLite;

    use Exception;
    use PDOException;
    use PDOStatement;

    /**
     * Lightweight helper for executing PDO statements and handling common API responses.
     *
     * This class wraps a MySqlConnection and a prepared PDOStatement to run queries,
     * automatically cast values, and optionally convert empty results into HTTP
     * responses (204/401/403/409) via the Responses helper. Uses an internal
     * `NoRowsHandler` instance to manage automatic no-row responses and atomic behaviour.
     *
     * @package GrangeFencing\MySqlLite
     *
     * @property-read array $firstRow First row of the last result set or an empty array when no data
     * @property-read array $allRows  Alias for ->data; will be an empty array when no data
     */
    class MySqlTable {

        private NoRowsHandler $noRowsHandler;

        /**
         * Database connection wrapper. Contains the PDO connection and connection-level flags.
         *
         * @var MySqlConnection|null
         */
        protected ?MySqlConnection $db = null;

        /**
         * Prepared statement to be executed. Set by prepareSql().
         *
         * @var PDOStatement|null
         */
        public ?PDOStatement $stmt;

        /**
         * Atomic flag to make singleRowReturn apply only to the next execute() call.
         *
         * @var bool
         */
        private bool $atomicSingleRowReturn = false;

        /**
         * When enabled, execute() will return the first row as an associative array
         * instead of an array of rows.
         *
         * @var bool
         */
        private bool $singleRowReturn = false;

        /**
         * When true the results from the current execution will not be saved into ->data.
         * Useful for running statements where the application does not need the returned rows.
         *
         * @var bool
         */
        public bool $noDataSave = false;

        /**
         * Atomic version of $noDataSave which only applies to the next execute() call.
         *
         * @var bool
         */
        public bool $atomicNoDataSave = false;

        /**
         * Flag set to true after a successful execute(). Use to check status without throwing.
         *
         * @var bool
         */
        public bool $wasSuccess = false;

        /**
         * When execute() catches an exception it will be stored here for callers to inspect.
         *
         * @var Exception|null
         */
        public Exception|null $failureCause;

        /**
         * @var array Automatically populated array of data returned from any executed sql query.
         * If singleRowReturn enabled, the array will be associative, else will be an array of associative arrays.
         */
        public array $data = [];
        /**
         * Number of rows affected/returned by the last statement.
         *
         * @var int
         */
        public int $rowCount = 0;

        /**
         * Class constructor - accepts a MySqlConnection instance.
         *
         * @param MySqlConnection $database The database connection wrapper instance.
         */
        public function __construct(MySqlConnection $database) {

            $this->db = $database;
            $this->noRowsHandler = new NoRowsHandler();

        }

        /**
         * Magic getter for convenience properties.
         *
         * - firstRow: returns the first row of ->data or an empty array when no data.
         * - allRows : returns ->data as-is or an empty array when no data.
         *
         * @param string $property Property name requested.
         *
         * @return mixed|null
         */
        public function __get(string $property) {

            if ($property === 'firstRow') {
                if (!empty($this->data)) {
                    return $this->data[0];
                }

                return [];
            }

            if ($property === 'allRows') {
                return $this->data;
            }

            return null;
        }


        /**
         * Configures the behavior for automatically returning a 204 HTTP response when no rows are returned
         * from executing an SQL command.
         *
         * This delegates to the internal `NoRowsHandler`. Use the `$atomic` flag to make the behaviour
         * apply only to the next execute() call.
         *
         * @param bool $enabled Set to `true` to enable automatic 204 response when no data is returned.
         *                       Default is `true`.
         * @param bool $atomic Set to `true` to ensure that the 204 response is only applied to the next
         *                     SQL execution that returns no data. Default is `false`.
         *
         * @return $this
         */
        public function automatic204(bool $enabled = true, bool $atomic = false): static {

            $this->noRowsHandler->set(204, $enabled, $atomic);

            return $this;
        }

        /**
         * Configures the behavior for automatically returning a 401 HTTP response when no rows are affected
         * from executing an SQL command.
         *
         * This delegates to the internal `NoRowsHandler`. Use the `$atomic` flag to make the behaviour
         * apply only to the next execute() call.
         *
         * @param bool $enabled Set to `true` to enable automatic 401 response when no rows are affected.
         *                       Default is `true`.
         * @param string $message The message to return with the 401 response. Default is "Invalid API key or key not set".
         * @param bool $atomic Set to `true` to ensure that the 401 response is only applied to the next execution where no rows are affected.
         *
         * @return $this
         */
        public function automatic401(bool $enabled = true, string $message = "Unauthorized Access", bool $atomic = false): static {

            $this->noRowsHandler->set(401, $enabled, $atomic, $message);

            return $this;
        }

        /**
         * Configures the behavior for automatically returning a 403 HTTP response when no rows are affected
         * from executing an SQL command.
         *
         * Delegates to `NoRowsHandler`. Use the `$atomic` flag to scope this to the next execute() call.
         *
         * @param string $message The message to return with the 403 response.
         * @param bool $enabled Whether the automatic 403 should be enabled.
         * @param bool $atomic When true the behaviour is reset after the next execution.
         *
         * @return $this
         */
        public function automatic403(string $message = "You do not have permission to complete this action", bool $enabled = true, bool $atomic = false): static {

            $this->noRowsHandler->set(403, $enabled, $atomic, $message);

            return $this;
        }

        /**
         * Configures the behavior for automatically returning a 409 HTTP response when no rows are affected
         * from executing an SQL command.
         *
         * Delegates to `NoRowsHandler`. Use the `$atomic` flag to scope this to the next execute() call.
         *
         * @param string $message The message to return with the 409 response.
         * @param bool $enabled Whether the automatic 409 should be enabled.
         * @param bool $atomic When true the behaviour is reset after the next execution.
         *
         * @return $this
         */
        public function automatic409(string $message = "The server was unable to handle this request to an existing record causing a conflict", bool $enabled = true, bool $atomic = false): static {

            $this->noRowsHandler->set(409, $enabled, $atomic, $message);

            return $this;
        }

        /**
         * Configures the behavior to prevent the automatic saving or updating of the `->data` property during
         * subsequent SQL executions. When enabled, the `->data` property will not be updated or overwritten
         * by the results of the SQL commands that follow. However, note that update or insert statements will
         * still clear any existing data sets.
         *
         * This method is useful when you want to control when the `->data` property is modified during batch SQL
         * executions, particularly in scenarios where you don’t want certain SQL statements to impact the stored data.
         *
         * @param bool $enabled Set to `true` to prevent updating/overwriting of the `->data` property. Default is `true`.
         * @param bool $atomic Set to `true` to apply this behavior only to the next SQL execution. Default is `false`.
         *                     This flag is ignored if `$enabled` is `false`.
         *
         * @return $this Returns the instance of the class to allow for method chaining.
         */
        public function dontSaveData(bool $enabled = true, bool $atomic = false): static {

            $this->atomicNoDataSave = $atomic;
            $this->noDataSave = $enabled;

            return $this;
        }

        /**
         * Configures the return format of the data property to return a single row of results as an associative array,
         * instead of an array of results. By default, the `data` property contains an array of results from
         * SQL queries, even if only a single row is returned. Enabling this method alters the behavior so that
         * instead of an array, the `data` property will contain the first row's associative array.
         *
         * Example:
         * - By default (when disabled), the result will be returned as an array of results:
         *   ```
         *   [
         *     ["name" => "xxx", "address" => "xxx"]
         *   ]
         *   ```
         *
         * - When enabled, the result will be returned as the first row's associative array:
         *   ```
         *   ["name" => "xxx", "address" => "xxx"]
         *   ```
         *
         * This is useful when you expect to receive a single row from a query and prefer the data to be returned
         * as an associative array, rather than an array containing that associative array.
         *
         * @param bool $enabled Set to `true` to enable returning the first row of results as an associative array instead
         *                      of an array. Default is `false`, meaning results will be returned as an array of
         *                      rows (even if only one row is returned).
         * @param bool $atomic Optional. When true the single-row return behavior will only apply to the next
         *                      execute() call (atomic). Default is false.
         *
         * @return $this Returns the instance of the class to allow for method chaining.
         */
        public function singleRowReturn(bool $enabled = true, bool $atomic = false): static {

            $this->atomicSingleRowReturn = $atomic;
            $this->singleRowReturn = $enabled;

            return $this;
        }

        /**
         * Sets the value of a specified property, with optional conversion to uppercase, trimming of string values, and wildcard normalisation.
         *
         * Note: this stores the provided value as a dynamic property on the instance (i.e. $this->${property}).
         *
         * @param string $property The name of the property to set.
         * @param mixed $value The value to set for the property.
         * @param bool $upperCase Optional. Whether to convert the value to uppercase. Default is false.
         * @param bool $withWildCards Optional. Whether to include wildcard characters to the property value. Default is false.
         *
         * @return static Returns the instance of the class to allow for method chaining.
         */
        public function setProperty(
            string $property,
            mixed  $value,
            bool   $upperCase = false,
            bool   $withWildCards = false,
        ): static {

            if ($upperCase && is_string($value)) {
                $value = strtoupper($value);
            }
            if (is_string($value)) {
                $value = trim($value);
            }
            if (is_string($value) && $withWildCards) {
                // Normalize wildcards: trim extra % from both ends of the provided value
                $trimmed = trim($value, '%');
                // If the result is empty, fallback to a single '%'
                $value = $trimmed === '' ? '%' : "%$trimmed%";
            }

            $this->$property = $value;

            return $this;
        }

        /**
         * Conditionally sets the value of a specified property, with optional conversion to uppercase, trimming of string values, and encryption.
         * If the condition is not met, an optional else value can be set for the property.
         *
         * @param bool $condition The condition to evaluate.
         * @param string $property The name of the property to set.
         * @param mixed $value The value to set for the property if the condition is true.
         * @param bool $upperCase Optional. Whether to convert the value to uppercase. Default is false.
         * @param mixed $else Optional. The value to set for the property if the condition is false. Default is "__UNDEFINED__".
         * @param bool $encrypted Optional. Whether to encrypt the value. Default is false.
         *
         * @return static Returns the instance of the class to allow for method chaining.
         */
        public function setPropertyIf(
            bool   $condition,
            string $property,
            mixed  $value,
            bool   $upperCase = false,
            mixed  $else = "__UNDEFINED__",
            bool   $encrypted = false,
        ): static {

            if (!$condition) {
                if ($else !== "__UNDEFINED__") {
                    $this->$property = $else;
                }

                return $this;
            }

            return $this->setProperty($property, $value, $upperCase, $encrypted);
        }

        /**
         * Sets the property value from the POST data, with the option to provide a default value,
         * include wildcard characters, and convert the property value to uppercase.
         *
         * @param string $property The name of the property to set.
         * @param string $postKey The key of the POST data to retrieve the value from.
         * @param mixed $default Optional. The default value if the POST data is not set. Default is "__UNDEFINED__".
         * @param bool $withWildCards Optional. Whether to include wildcard characters to the property value. Default is false.
         * @param bool $upperCase Optional. Whether to convert the property value to uppercase. Default is false.
         *
         * @return static Returns the instance of the class to allow for method chaining.
         */
        public function setPropertyFromPost(
            string $property,
            string $postKey,
            mixed  $default = "__UNDEFINED__",
            bool   $withWildCards = false,
            bool   $upperCase = false,
        ): static {

            if ($default !== "__UNDEFINED__") {
                $value = $_POST[$postKey] ?? $default;
            } else {
                $value = $_POST[$postKey] ?? null;
            }
            if ($withWildCards && is_string($value)) {
                // Normalize wildcards: trim extra % from both ends
                $trimmed = trim($value, '%');
                // If the result is empty, fallback to a single '%'
                $value = $trimmed === '' ? '%' : "%$trimmed%";
            }
            if ($upperCase && is_string($value)) {
                $value = strtoupper($value);
            }
            if (is_string($value)) {
                $value = trim($value);
            }

            $this->$property = $value;

            return $this;

        }

        /**
         * Sets the property value from the SESSION data, with the option to provide a default value.
         * If the SESSION data is not set and no default value is provided, a client error response is triggered.
         *
         * @param string $property The name of the property to set.
         * @param string $sessionKey The key of the SESSION data to retrieve the value from.
         * @param mixed $default Optional. The default value if the SESSION data is not set. Default is "__UNDEFINED__".
         *
         * @return static Returns the instance of the class to allow for method chaining.
         */
        public function setPropertyFromSession(
            string $property,
            string $sessionKey,
            mixed  $default = "__UNDEFINED__",
        ): static {

            if ($default !== "__UNDEFINED__") {
                $this->$property = $_SESSION[$sessionKey] ?? $default;
            } else {
                if (!isset($_SESSION[$sessionKey])) {
                    Responses::clientError("There is something wrong with the current session. Try refreshing the page or logging in again");
                }
                $this->$property = $_SESSION[$sessionKey];
            }

            return $this;
        }

        /**
         * Prepares the SQL statement for execution.
         *
         * @param string $sql The SQL statement to prepare.
         *
         * @return static Returns the instance of the class to allow for method chaining.
         */
        public function prepareSql(string $sql): static {

            $this->stmt = $this->db->conn->prepare($sql);

            return $this;

        }

        /**
         * Binds a value to a parameter in the prepared statement.
         *
         * @param string $parameter The parameter placeholder in the SQL statement.
         * @param mixed $value The value to bind to the parameter.
         *
         * @return static Returns the instance of the class to allow for method chaining.
         */
        public function bindParameter(string $parameter, mixed $value): static {

            $this->stmt->bindParam($parameter, $value);

            return $this;

        }

        /**
         * Binds a value to a parameter in the prepared statement.
         *
         * @param string $parameter The parameter placeholder in the SQL statement.
         * @param mixed $value The value to bind to the parameter.
         *
         * @return static Returns the instance of the class to allow for method chaining.
         */
        public function bindValue(string $parameter, mixed $value): static {

            $this->stmt->bindValue($parameter, $value);

            return $this;

        }

        /**
         * Executes the prepared SQL statement and handles various response scenarios.
         * This function manages the execution of the SQL statement, handles potential
         * exceptions, and processes the result set, including automatic response handling
         * for specific conditions (e.g., no rows returned).
         *
         * @return static Returns the instance of the class to allow for method chaining.
         */
        public function execute(): static {

            $this->failureCause = null;

            try {

                if (!$this->stmt->execute()) {

                    $this->logError("Execution failed: " . json_encode($this->stmt->errorInfo()));
                    Responses::serverError();

                }

            }
            catch (PDOException $e) {

                if (
                    $e->getCode() == MYSQL_INTEGRITY_CONSTRAINT_VIOLATION &&
                    $this->noRowsHandler->isSet(409)
                ) {
                    $this->noRowsHandler->handle(0);
                }

                $this->failureCause = $e;
                $this->logError("PDOException: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                Responses::serverError();

            }

            $this->wasSuccess = true;
            $this->rowCount = $this->stmt->rowCount();
            $this->noRowsHandler->handle($this->rowCount);
            $this->noRowsHandler->resetAtomic();
            $this->resetAtomicFlags();

            if (!$this->noDataSave) {

                $this->data = $this->rowCount == 0 ? [] : TypeCaster::castValues($this->stmt);

                if ($this->singleRowReturn && !empty($this->data)) {
                    $this->data = $this->data[0];
                }

            }

            return $this;

        }

        /**
         * Resets atomic flags after execution.
         *
         * @return void
         */
        private function resetAtomicFlags(): void {

            if ($this->atomicNoDataSave) {
                $this->noDataSave = false;
                $this->atomicNoDataSave = false;
            }
            if ($this->atomicSingleRowReturn) {
                $this->singleRowReturn = false;
                $this->atomicSingleRowReturn = false;
            }
        }

        /**
         * Logs an error message.
         *
         * @param string $message The error message to log.
         *
         * @return void
         */
        private function logError(string $message): void {

            if ($this->db->debugToConsole) {
                print_r($message);
            }
            error_log($message);
        }

        /**
         * Handles the failure of an execution, typically by rolling back a transaction
         * if one is active, and setting the success flag to false.
         *
         * @return static Returns the instance of the class to allow for method chaining.
         */
        function executionFailure(): static {

            $this->wasSuccess = false;

            if ($this->db->usingTransaction) {
                $this->db->rollBack();
            }

            return $this;

        }

    }
