<?php

class Logger
{
    private static array $outPaths = [];
    private static array $errOutPaths = [];

    public static function setOutPath(string ...$paths): void
    {
        self::$outPaths = $paths;
    }

    public static function setErrOutPath(string ...$paths): void
    {
        self::$errOutPaths = $paths;
    }

    private static function formatLog(string $level, string $message, array $fields = []): string
    {
        $logEntry = array_merge([
            'level'     => $level,
            'timestamp' => date('c'),
            'msg'       => $message
        ], $fields);
        return json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    private static function write(string $level, string $message, array $fields = []): void
    {
        $formattedMessage = self::formatLog($level, $message, $fields);

        foreach (self::$outPaths as $path) {
            self::ensureDirectoryExists($path);
            file_put_contents($path, $formattedMessage, FILE_APPEND | LOCK_EX);
        }

        if ($level === 'error') {
            foreach (self::$errOutPaths as $path) {
                self::ensureDirectoryExists($path);
                file_put_contents($path, $formattedMessage, FILE_APPEND | LOCK_EX);
            }
        }
    }

    public static function info(string $message, array $fields = []): void
    {
        self::write('info', $message, $fields);
    }

    public static function error($message, array $fields = []): void
    {
        if ($message instanceof \Throwable) {
            self::write('error', $message->getMessage(), $fields);
        } else {
            self::write('error', (string)$message, $fields);
        }
    }

    private static function ensureDirectoryExists(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
