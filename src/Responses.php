<?php

    namespace GrangeFencing\MySqlLite;

    use JetBrains\PhpStorm\NoReturn;

    class Responses {

        /**
         * Sets the HTTP response code to 200 and returns a JSON-encoded response with an optional message and data.
         * This function is used to indicate a successful operation.
         *
         * @param mixed $data Optional data to include in the response. Default is an empty array.
         * @param string $message Optional message to include in the response. Default is "OK".
         *
         * @return void
         */
        #[NoReturn] public static function success(mixed $data = [], string $message = "OK"): void {

            self::general(200, $message, $data);
        }

        /**
         * Sets the HTTP response code to 204 (No Content) and exits the script.
         * This function is used to indicate that the request was successful, but there is no content to return.
         *
         * @return void
         */
        #[NoReturn] public static function noContent(): void {

            self::general(204);
        }

        /**
         * Sets the HTTP response code to 500 (Internal Server Error) and returns a JSON-encoded response with an optional message.
         * This function is used to indicate a server-side error.
         *
         * @param string $message Optional message to include in the response. Default is "Server Error".
         *
         * @return void
         */
        #[NoReturn] public static function serverError(string $message = "Server Error"): void {

            self::general(500, $message);
        }

        /**
         * Sets the HTTP response code to 400 (Bad Request) and returns a JSON-encoded response with an optional message.
         * This function is used to indicate a client-side error.
         *
         * @param string $message Optional message to include in the response. Default is "Client Error".
         *
         * @return void
         */
        #[NoReturn] public static function clientError(string $message = "Client Error"): void {

            self::general(400, $message);
        }

        /**
         * Sets the HTTP response code to 401 (Unauthorized) and returns a JSON-encoded response with an optional message.
         * This function is used to indicate that the request requires user authentication.
         *
         * @param string $message Optional message to include in the response. Default is "Unauthorized".
         *
         * @return void
         */
        #[NoReturn] public static function unauthorized(string $message = "Unauthorized"): void {

            self::general(401, $message);
        }

        /**
         * Sets the HTTP response code to 403 (Forbidden) and returns a JSON-encoded response with an optional message.
         * This function is used to indicate that the client does not have permission to access the requested resource.
         *
         * @param string $message Optional message to include in the response. Default is "Do you not have access to perform this operation".
         *
         * @return void
         */
        #[NoReturn] public static function accessError(string $message = "You do not have access to perform this operation"): void {

            self::general(403, $message);
        }

        /**
         * Sets the HTTP response code to 409 (Conflict) and returns a JSON-encoded response with an optional message and data.
         * This function is used to indicate that the request could not be completed due to a conflict with the current state of the resource.
         *
         * @param string $message Optional message to include in the response. Default is "An existing record is conflicting with this.".
         * @param mixed $data Optional data to include in the response. Default is an empty array.
         *
         * @return void
         */
        #[NoReturn] public static function conflict(string $message = "An existing record is conflicting with this.", mixed $data = []): void {

            self::general(409, $message, $data);
        }

        /**
         * A general response function to handle various HTTP response codes, messages, and optional data.
         * This can be used for less common response codes or for handling general cases without defining
         * a new function for each one.
         *
         * @param int $responseCode The HTTP status code (e.g., 200, 400, 404, etc.).
         * @param string $message The message to return with the response.
         * @param mixed $data Optional data to return with the response.
         *
         * @return void
         */
        #[NoReturn] public static function general(int $responseCode, string $message = "OK", mixed $data = []): void {

            http_response_code($responseCode);
            echo json_encode(["message" => $message, "data" => $data]);
            exit();
        }

    }