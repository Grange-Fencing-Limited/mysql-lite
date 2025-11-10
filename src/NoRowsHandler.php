<?php

    namespace GrangeFencing\MySqlLite;

    /**
     * Simple handler to manage automatic HTTP responses when SQL statements return no rows.
     *
     * This class centralises the configuration for what should happen when an executed
     * statement affects or returns zero rows. It supports multiple response codes (204,
     * 401, 403, 409) and provides an "atomic" option so a configured response only
     * applies to the next execution.
     */
    class NoRowsHandler {

        /**
         * Map of HTTP code => enabled flag. When true and rowCount == 0 the corresponding
         * response will be triggered.
         *
         * @var array<int,bool>
         */
        private array $flags = [];

        /**
         * Map of HTTP code => atomic flag. When true the corresponding flag is reset
         * after it has been used once (via resetAtomic()).
         *
         * @var array<int,bool>
         */
        private array $atomic = [];

        /**
         * Optional custom messages to return with certain response codes (for codes that accept a message).
         *
         * @var array<int,string>
         */
        private array $messages = [];

        /**
         * Configure a response behaviour for a specific HTTP code when no rows are returned.
         *
         * @param int $code    HTTP status code to configure (accepted: 204, 401, 403, 409).
         * @param bool $enabled Whether this response should be triggered when rowCount == 0.
         * @param bool $atomic  When true the configured behaviour will be reset after the next execution.
         * @param string $message Optional message to include with responses that support a message.
         *
         * @return void
         */
        public function set(int $code, bool $enabled = true, bool $atomic = false, string $message = ''): void {

            $this->flags[$code] = $enabled;
            $this->atomic[$code] = $atomic;
            if ($message !== "") {
                $this->messages[$code] = $message;
            }
        }

        /**
         * Check whether a handler has been set for a given HTTP code.
         *
         * @param int $code HTTP status code to check
         * @return bool True if enabled, false otherwise
         */
        public function isSet(int $code): bool {

            return $this->flags[$code] ?? false;
        }

        /**
         * Execute configured handlers if the provided row count indicates no rows were returned/affected.
         *
         * This method will iterate configured flags and call the appropriate method on the
         * `Responses` helper. It returns immediately when rowCount > 0.
         *
         * @param int $rowCount The number of rows returned/affected by the last statement.
         * @return void
         */
        public function handle(int $rowCount): void {

            if ($rowCount > 0) return;

            foreach ($this->flags as $code => $enabled) {
                if ($enabled) {
                    $msg = $this->messages[$code] ?? '';
                    match ($code) {
                        204 => Responses::noContent(),
                        401 => Responses::unauthorized($msg),
                        403 => Responses::accessError($msg),
                        409 => Responses::conflict($msg),
                        default => null,
                    };
                }
            }
        }

        /**
         * Reset any atomic flags that were set to apply only once.
         *
         * When a code was configured with the atomic flag, this method will disable
         * that behaviour so subsequent `handle()` calls do not trigger the same response.
         *
         * @return void
         */
        public function resetAtomic(): void {

            foreach ($this->atomic as $code => $isAtomic) {
                if ($isAtomic) {
                    $this->flags[$code] = false;
                    $this->atomic[$code] = false;
                }
            }
        }

    }