<?php

    namespace GrangeFencing\MySqlLite;

    class NoRowsHandler {

        private array $flags = [];
        private array $atomic = [];
        private array $messages = [];

        public function set(int $code, bool $enabled = true, bool $atomic = false, string $message = ''): void {

            $this->flags[$code] = $enabled;
            $this->atomic[$code] = $atomic;
            if ($message !== "") {
                $this->messages[$code] = $message;
            }
        }

        public function isSet(int $code): bool {

            return $this->flags[$code] ?? false;
        }

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

        public function resetAtomic(): void {

            foreach ($this->atomic as $code => $isAtomic) {
                if ($isAtomic) {
                    $this->flags[$code] = false;
                    $this->atomic[$code] = false;
                }
            }
        }

    }