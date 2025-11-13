<?php

    namespace GrangeFencing\MySqlLite;

    use PDO;
    use PDOStatement;

    class TypeCaster {

        private const array FLOAT_TYPES = [
            "NEWDECIMAL", "DOUBLE", "DECIMAL", "FLOAT", "NUMERIC",
            "DEC", "FIXED", "REAL", "DOUBLE_PRECISION",
        ];

        private const array INT_TYPES = [
            "LONG", "INT24", "TINYINT", "SMALLINT", "INTEGER",
            "INT", "SHORT", "TINY", "MEDIUMINT", "BIT",
        ];

        private const string BIGINT_TYPE = "BIGINT";

        /**
         * Takes in a PDOStatement object and converts numerical values represented as strings into their respective
         * int and float types based on the SQL column meta-data for JSON output with numbers as numbers.
         * This function ensures that numerical values are correctly typed in the resulting array.
         *
         * @param PDOStatement $stmt The PDOStatement object containing the result set to normalize.
         *
         * @return array An array of rows with numerical values converted to their respective types.
         */
        public static function castValues(PDOStatement $stmt): array {

            /**
             * This is the result of INSERT/UPDATE queries
             */
            if($stmt->columnCount() === 0) {
                return [];
            }

            $meta = [];
            $rows = [];

            foreach(range(0, $stmt->columnCount() - 1) as $i) {

                $columnMeta = $stmt->getColumnMeta($i);
                $meta[$columnMeta["name"]] = $columnMeta["native_type"] ?? "VAR_STRING";

            }

            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

                $casted = [];

                foreach($row as $name => $value) {

                    if($value === null) {

                        $casted[$name] = null;
                        continue;

                    }

                    $type = $meta[$name];

                    if(in_array($type, self::FLOAT_TYPES, true)) {
                        $casted[$name] = (float)$value;
                        continue;
                    }

                    if(in_array($type, self::INT_TYPES, true)) {
                        $casted[$name] = (int)$value;
                        continue;
                    }

                    if($type === self::BIGINT_TYPE) {
                        // Only cast if safe within PHP_INT_MAX range
                        // PHP_INT_SIZE check ensures portability
                        if(is_numeric($value) && abs((float)$value) <= PHP_INT_MAX) {
                            $casted[$name] = (int)$value;
                        } else {
                            // Keep as string to avoid overflow
                            $casted[$name] = $value;
                        }
                        continue;
                    }

                    $casted[$name] = $value;

                }

                $rows[] = $casted;

            }

            return $rows;

        }

    }