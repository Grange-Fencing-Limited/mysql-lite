<?php

    namespace GrangeFencing\MySqlLite;

    use PDO;
    use PDOStatement;

    class TypeCaster {

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
            if($stmt->columnCount() == 0) {
                return [];
            }

            $meta = [];
            $rows = [];

            foreach(range(0, $stmt->columnCount() - 1) as $columnIndex) {

                $columnMeta = $stmt->getColumnMeta($columnIndex);
                $meta[$columnMeta["name"]] = $columnMeta["native_type"] ?? "VAR_STRING";

            }

            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

                $rowData = [];

                foreach($row as $key => $value) {

                    if($value == null) {

                        $rowData[$key] = $value;
                        continue;

                    }

                    if(in_array($meta[$key], ["NEWDECIMAL", "DOUBLE", "DECIMAL", "FLOAT", "NUMERIC", "DEC", "FIXED", "REAL", "DOUBLE_PRECISION"])) {

                        $rowData[$key] = (float)$value;
                        continue;

                    }

                    if(in_array($meta[$key], ["LONG", "INT24", "TINYINT", "SMALLINT", "INTEGER", "INT", "SHORT", "TINY", "MEDIUMINT", "BIGINT", "BIT"])) {

                        $rowData[$key] = (int)$value;
                        continue;

                    }

                    $rowData[$key] = $value;

                }

                $rows[] = $rowData;

            }

            return $rows;

        }

    }