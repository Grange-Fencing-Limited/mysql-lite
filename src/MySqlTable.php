<?php

    namespace GrangeFencing\MySqlLite;

    use Exception;
    use PDOException;
    use PDOStatement;

    /**
     * Lightweight helper for executing PDO statements and handling common API responses.
     *
     * This class wraps a MySqlConnection and a prepared PDOStatement to run queries,
     * firstRow
     * automatically cast values, and optionally convert empty results into HTTP
     * responses (204/401/403/409) via the Responses helper.
     *
     * @package GrangeFencing\MySqlLite
     *
     * @property-read array|null $firstRow First row of the last result set or empty array
     * @property-read array|null $allRows  Alias for ->data
     */
    class MySqlTable {

        private NoRowsHandler $noRowsHandler;

        /**
         * Database connection wrapper. Contains the PDO connection and connection-level flags.
         * @var MySqlConnection|null
         */
        protected ?MySqlConnection $db = null;

        /**
         * Prepared statement to be executed. Set by prepareSql().
         * @var PDOStatement|null
         */
        public ?PDOStatement $stmt;

        /**
         * Atomic flag to make singleRowReturn apply only to the next execute() call.
         * @var bool
         */
        private bool $atomicSingleRowReturn = false;

        /**
         * When enabled, execute() will return the first row as an associative array/object
         * instead of an array of rows.
         * @var bool
         */
        private bool $singleRowReturn = false;

        /**
         * When true the results from the current execution will not be saved into ->data.
         * Useful for running statements where the application does not need the returned rows.
         * @var bool
         */
        public bool $noDataSave = false;

        /**
         * Atomic version of $noDataSave which only applies to the next execute() call.
         * @var bool
         */
        public bool $atomicNoDataSave = false;

        /**
         * Flag set to true after a successful execute(). Use to check status without throwing.
         * @var bool
         */
        public bool $wasSuccess = false;

        /**
         * When execute() catches an exception it will be stored here for callers to inspect.
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
         * - allRows : returns ->data as-is.
         *
         * @param string $property Property name requested.
         *
         * @return mixed|null
         */
        public function __get(string $property) {

            if($property === 'firstRow') {
                if(!empty($this->data)) {
                    return $this->data[0];
                }
                return null;
            }

            if ($property === 'allRows') {
                if (!empty($this->data)) {
                    return $this->data;
                }
                return null;
            }

            return null;
        }


        /**
         * Configures the behavior for automatically returning a 204 HTTP response when no rows are returned
         * from executing an SQL command. This is particularly useful when executing multiple SQL commands
         * in a single API request, allowing you to specify when to return a 204 status code for an empty
         * result set. The method will also automatically call `exit()` once the 204 response code is generated.
         *
         * By default, the behavior is disabled (i.e., no 204 response will be generated when no data is found).
         * However, when this method is called, the behavior is enabled, and a 204 response will be returned
         * for the next SQL execution that returns no data. If the `atomic` flag is set to `true`, it ensures that
         * the 204 response is only triggered for a specific SQL execution. After this execution, the `onNoRowsWill204`
         * flag will automatically be reset to `false` to avoid conflicting behavior during multiple SQL executions
         * in one API request.
         *
         * This method is useful in cases where you want to ensure that a 204 is returned only for specific
         * SQL commands, while other commands might still return results (even if some do not).
         *
         * @param bool $enabled Set to `true` to enable automatic 204 response when no data is returned.
         *                       Default is `true`. If set to `false`, no 204 response will be generated
         *                       for empty result sets.
         * @param bool $atomic Set to `true` to ensure that the 204 response is only applied to a specific
         *                     SQL execution that returns no data. Default is `false`. This flag is ignored
         *                     if `$enabled` is `false`.
         *
         * @return $this Returns the instance of the class to allow for method chaining.
         *
         * @note When `$atomic` is set to `true` and `$enabled` is also `true`, the `onNoRowsWill204` flag
         *       will be automatically reset to `false` after the specific SQL execution that triggers the 204 response.
         *       This allows for granular control over which SQL executions in a batch request should return a
         *       204 response.
         */
        public function automatic204(bool $enabled = true, bool $atomic = false): static {
            $this->noRowsHandler->set(204, $enabled, $atomic);
            return $this;
        }

        /**
         * Configures the behavior for automatically returning a 401 HTTP response when no rows are affected
         * from executing an SQL command. This is useful when executing SQL statements that modify data and
         * you want to ensure that a 401 response is returned when no changes are made, indicating an invalid
         * API key or unauthorized access. The method will also automatically call `exit()` once the 401 response
         * code is generated.
         *
         * By default, the behavior is enabled (i.e., a 401 response will be returned if no rows are affected).
         * If the `atomic` flag is set to `true`, it ensures that the 401 response is only triggered for the next
         * SQL execution where no rows are affected. After that specific execution, the `onNoRowsWill401` flag will
         * be reset to `false` to avoid conflicting behavior during multiple SQL executions in one API request.
         *
         * This method is useful for ensuring that a 401 response is returned only for specific SQL commands,
         * while other commands might still process normally even if no rows are affected.
         *
         * @param bool $enabled Set to `true` to enable automatic 401 response when no rows are affected.
         *                       Default is `true`. If set to `false`, no 401 response will be generated
         *                       for empty result sets.
         * @param string $message The message to return with the 401 response. Default is "Invalid API key or key not set".
         * @param bool $atomic Set to `true` to ensure that the 401 response is only applied to the specific SQL
         *                     execution where no rows are affected. Default is `false`. This flag is ignored if
         *                     `$enabled` is `false`.
         *
         * @return $this Returns the instance of the class to allow for method chaining.
         *
         * @note When `$atomic` is set to `true` and `$enabled` is also `true`, the `onNoRowsWill401` flag will be
         *       automatically reset to `false` after the specific execution that triggers the 401 response. This
         *       allows for granular control over which SQL executions in a batch request should return a 401 response.
         */
        public function automatic401(bool $enabled = true, string $message = "Unauthorized Access", bool $atomic = false): static {
            $this->noRowsHandler->set(401, $enabled, $atomic, $message);
            return $this;
        }

        /**
         * Configures the behavior for automatically returning a 403 HTTP response when no rows are affected
         * from executing an SQL command. This is useful when performing SQL operations where the user might not
         * have permission to perform the action, ensuring that a 403 response is returned when no rows are affected.
         * The method will also automatically call `exit()` once the 403 response code is generated.
         *
         * By default, the behavior is enabled (i.e., a 403 response will be returned if no rows are affected).
         * If the `atomic` flag is set to `true`, it ensures that the 403 response is only triggered for the next
         * SQL execution where no rows are affected. After that specific execution, the `onNoRowsWill403` flag will
         * be reset to `false` to avoid conflicting behavior during multiple SQL executions in one API request.
         *
         * This method is useful for ensuring that a 403 response is returned only for specific SQL commands,
         * while other commands might still process normally even if no rows are affected.
         *
         * @param string $message The message to return with the 403 response. Default is "You do not have permission to complete this action".
         * @param bool $enabled Set to `true` to enable automatic 403 response when no rows are affected.
         *                       Default is `true`. If set to `false`, no 403 response will be generated
         *                       for empty result sets.
         * @param bool $atomic Set to `true` to ensure that the 403 response is only applied to a specific
         *                     SQL execution where no rows are affected. Default is `false`. This flag is ignored
         *                     if `$enabled` is `false`.
         *
         * @return $this Returns the instance of the class to allow for method chaining.
         *
         * @note When `$atomic` is set to `true` and `$enabled` is also `true`, the `onNoRowsWill403` flag will
         *       be automatically reset to `false` after the specific execution that triggers the 403 response. This
         *       allows for granular control over which SQL executions in a batch request should return a 403 response.
         */
        public function automatic403(string $message = "You do not have permission to complete this action", bool $enabled = true, bool $atomic = false): static {
            $this->noRowsHandler->set(403, $enabled, $atomic, $message);
            return $this;
        }

        /**
         * Configures the behavior for automatically returning a 409 HTTP response when no rows are affected
         * from executing an SQL command. This is useful when there’s a conflict with an existing record in the
         * database, ensuring that a 409 response is returned when no rows are affected. The method will also
         * automatically call `exit()` once the 409 response code is generated.
         *
         * By default, the behavior is enabled (i.e., a 409 response will be returned if no rows are affected).
         * If the `atomic` flag is set to `true`, it ensures that the 409 response is only triggered for the next
         * SQL execution where no rows are affected. After that specific execution, the `onNoRowsWill409` flag will
         * be reset to `false` to avoid conflicting behavior during multiple SQL executions in one API request.
         *
         * This method is useful for ensuring that a 409 response is returned only for specific SQL commands,
         * while other commands might still process normally even if no rows are affected.
         *
         * @param string $message The message to return with the 409 response. Default is "The server was unable to handle this request to an existing record causing a conflict".
         * @param bool $enabled Set to `true` to enable automatic 409 response when no rows are affected.
         *                       Default is `true`. If set to `false`, no 409 response will be generated
         *                       for empty result sets.
         * @param bool $atomic Set to `true` to ensure that the 409 response is only applied to a specific
         *                     SQL execution where no rows are affected. Default is `false`. This flag is ignored
         *                     if `$enabled` is `false`.
         *
         * @return $this Returns the instance of the class to allow for method chaining.
         *
         * @note When `$atomic` is set to `true` and `$enabled` is also `true`, the `onNoRowsWill409` flag will
         *       be automatically reset to `false` after the specific execution that triggers the 409 response.
         *       This allows for granular control over which SQL executions in a batch request should return a
         *       409 response.
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
         * Configures the return format of the data property to return a single row of results as an object,
         * instead of an array of results. By default, the `data` property contains an array of results from
         * SQL queries, even if only a single row is returned. Enabling this method alters the behavior so that
         * instead of an array, the `data` property will contain an object representing the first row of the results.
         *
         * Example:
         * - By default (when disabled), the result will be returned as an array of results:
         *   ```
         *   [
         *     ["name" => "xxx", "address" => "xxx"]
         *   ]
         *   ```
         *
         * - When enabled, the result will be returned as an object of the first row:
         *   ```
         *   (object) ["name" => "xxx", "address" => "xxx"]
         *   ```
         *
         * This is useful when you expect to receive a single row from a query and prefer the data to be returned
         * as an object, rather than an array containing that object.
         *
         * @param bool $enabled Set to `true` to enable returning the first row of results as an object instead
         *                      of an array. Default is `false`, meaning results will be returned as an array of
         *                      rows (even if only one row is returned).
         * @param bool $atomic  Optional. When true the single-row return behavior will only apply to the next
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
         * Sets the value of a specified property, with optional conversion to uppercase, trimming of string values, and encryption.
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
            mixed $value,
            bool $upperCase = false,
            bool $withWildCards = false
        ): static {

            if($upperCase && is_string($value)) {
                $value = strtoupper($value);
            }
            if(is_string($value)) {
                $value = trim($value);
            }
            if(is_string($value) && $withWildCards) {
                // Normalize wildcards: trim extra % from both ends
                $value = trim($this->$property, '%');
                // If the result is empty, fallback to a single '%'
                $value = $value === '' ? '%' : "%{$value}%";
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
            bool $condition,
            string $property,
            mixed $value,
            bool $upperCase = false,
            mixed $else = "__UNDEFINED__",
            bool $encrypted = false
        ): static {

            if(!$condition) {
                if($else !== "__UNDEFINED__") {
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
            mixed $default = "__UNDEFINED__",
            bool $withWildCards = false,
            bool $upperCase = false,
        ): static {

            if($default !== "__UNDEFINED__") {
                $this->$property = $_POST[$postKey] ?? $default;
            } else {
                $this->$property = $_POST[$postKey] ?? null;
            }
            if($withWildCards && is_string($this->$property)) {
                // Normalize wildcards: trim extra % from both ends
                $value = trim($this->$property, '%');
                // If the result is empty, fallback to a single '%'
                $this->$property = $value === '' ? '%' : "%{$value}%";
            }
            if($upperCase && is_string($this->$property)) {
                $this->$property = strtoupper($this->$property);
            }
            if(is_string($this->$property)) {
                $this->$property = trim($this->$property);
            }

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
            mixed $default = "__UNDEFINED__"
        ): static {

            if($default !== "__UNDEFINED__") {
                $this->$property = $_SESSION[$sessionKey] ?? $default;
            } else {
                if(!isset($_SESSION[$sessionKey])) {
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

                if(!$this->stmt->execute()) {

                    $this->logError("Execution failed: " . json_encode($this->stmt->errorInfo()));
                    Responses::serverError();

                }

            } catch(PDOException $e) {

                if(
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

            if(!$this->noDataSave) {

                $this->data = $this->rowCount == 0 ? [] : TypeCaster::castValues($this->stmt);

                if($this->singleRowReturn && !empty($this->data)) {
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

            if($this->atomicNoDataSave) {
                $this->noDataSave = false;
                $this->atomicNoDataSave = false;
            }
            if($this->atomicSingleRowReturn) {
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
            if($this->db->debugToConsole) {
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

            if($this->db->usingTransaction) {
                $this->db->rollBack();
            }

            return $this;

        }

    }
