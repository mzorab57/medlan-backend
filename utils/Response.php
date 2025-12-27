<?php
class Response
{
    public static function json($success, $message, $data = null, $code = 200): void
    {
        jsonResponse($success, $message, $data, $code);
    }
}
