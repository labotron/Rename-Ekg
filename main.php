<?php
require_once __DIR__ . '/Logger.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function getYamlConfig($filePath)
{
    $config = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $currentSection = '';
    foreach ($lines as $line) {
        if (preg_match('/^([a-z_]+):/i', $line, $matches)) {
            $currentSection = $matches[1];
        } elseif (preg_match('/^\s+([a-z_]+):\s*(.+)$/i', $line, $matches) && $currentSection) {
            $key = $matches[1];
            $val = trim($matches[2], " '\"");
            $config[$currentSection][$key] = $val;
        }
    }
    return $config;
}

function deleteOldFiles(string $directory, int $monthsOld = 1)
{
    if (!is_dir($directory)) return;
    $files = glob($directory . '/*');
    $thresholdTime = strtotime("-$monthsOld month");

    foreach ($files as $file) {
        if (is_file($file)) {
            $fileTimestamp = filemtime($file);
            if ($fileTimestamp < $thresholdTime) {
                if (unlink($file)) {
                    Logger::info("ลบไฟล์เก่าอายุเกิน $monthsOld เดือน สำเร็จ", ["deleted_file" => basename($file)]);
                }
            }
        }
    }
}

$configPath = __DIR__ . '/configs.yaml';
if (!file_exists($configPath)) {
    die("Error: ไม่พบไฟล์ configs.yaml");
}
$config = getYamlConfig($configPath);

Logger::setOutPath(__DIR__ . '/' . $config['filepath']['logs']);
Logger::setErrOutPath(__DIR__ . '/' . $config['filepath']['logs_err']);

$watcherPath = __DIR__ . '/' . $config['filepath']['watcher'];
$outFolderPath = __DIR__ . '/' . $config['filepath']['out_path'];
$backupFolderPath = __DIR__ . '/' . $config['filepath']['backup'];
$failedFolderPath = __DIR__ . '/' . $config['filepath']['failed'];
$deadLetterPath = __DIR__ . '/' . ($config['filepath']['dead_letter'] ?? 'dead_letter');

// ดึงค่าความยาว (Length) ของ ID แต่ละประเภทจาก configs.yaml (ถ้าไม่มีจะใช้ค่า Default)
$lenHN = isset($config['length']['hn']) ? (int)$config['length']['hn'] : 9;
$lenVN = isset($config['length']['vn']) ? (int)$config['length']['vn'] : 12;
$lenAN = isset($config['length']['an']) ? (int)$config['length']['an'] : 9;
$lenCID = isset($config['length']['cid']) ? (int)$config['length']['cid'] : 13;

foreach ([$watcherPath, $outFolderPath, $backupFolderPath, $failedFolderPath, $deadLetterPath] as $path) {
    if (!is_dir($path)) mkdir($path, 0777, true);
}

Logger::info("เริ่มระบบ Middleware Service... เฝ้าระวังโฟลเดอร์: " . $watcherPath);
while (true) {

    $failedFiles = glob($failedFolderPath . '/*.*');
    $now = time();

    if (!empty($failedFiles)) {
        foreach ($failedFiles as $fFile) {
            $fileAgeSeconds = $now - filemtime($fFile);

            if ($fileAgeSeconds > 86400) {
                $deadDest = $deadLetterPath . '/' . basename($fFile);
                if (@rename($fFile, $deadDest)) {
                    Logger::error("ไฟล์พังเกิน 24 ชม. ระบบเลิกพยายาม ย้ายไป Dead Letter", ["file" => basename($fFile)]);
                }
            } elseif ($fileAgeSeconds > 300) {
                $retryDest = $watcherPath . '/' . basename($fFile);
                if (@rename($fFile, $retryDest)) {
                    Logger::info("Auto-Retry: ดึงไฟล์กลับไปลองประมวลผลใหม่", ["file" => basename($fFile)]);
                }
            }
        }
    }

    $files = glob($watcherPath . '/*.*');

    if (!empty($files)) {
        foreach ($files as $file) {
            $fileName = basename($file);
            try {
                $rawFileName = pathinfo($file, PATHINFO_FILENAME);
                $extBase = pathinfo($file, PATHINFO_EXTENSION);
                $ext = "." . $extBase;
                $parts = explode('_', $rawFileName);
                $rawID = $parts[0]; // เช่น 'h123456789'

                $patientID = preg_replace('/[^0-9]/', '', $rawID);
                $len = strlen($patientID);

                // จัดการ Timestamp และเช็คว่ามี fff (Millisecond) ติดมาด้วยหรือไม่
                $rawTimestamp = end($parts);
                if (strlen($rawTimestamp) === 14) {
                    // ถ้ามาแค่ 14 หลัก (yyyymmddhhnnss) ให้เติม 000 ตามภาพที่บอกว่า "ส่งค่า/ไม่ส่งค่า ก็ทำได้"
                    $originalTimestamp = $rawTimestamp . '000';
                } else {
                    // กรณีที่มี fff ส่งมาแล้ว (17 หลัก) ให้ใช้ค่าเดิมได้เลย
                    $originalTimestamp = $rawTimestamp;
                }

                // ตรวจสอบประเภท ID จากความยาวที่ตั้งใน configs.yaml
                if ($len === $lenCID) {
                    $prefix = "c";
                    $idType = "CID";
                } elseif ($len === $lenVN) {
                    $prefix = "";
                    $idType = "VN";
                } elseif ($len === $lenHN) {
                    $prefix = "h";
                    $idType = "HN";
                } elseif ($len === $lenAN) {
                    $prefix = "";
                    $idType = "AN";
                }
                // elseif ($len === $lenHN || $len === $lenAN) {
                //     // กรณี HN และ AN ตั้งความยาวเท่ากัน ให้แยกด้วยตัวอักษร 'h' ด้านหน้า
                //     if (strpos(strtolower($rawID), 'h') === 0) {
                //         $prefix = "h";
                //         $idType = "HN";
                //     } else {
                //         $prefix = ""; // AN และ VN จะไม่มี Prefix ตามในภาพ
                //         $idType = "AN";
                //     }
                // } 
                else {
                    $prefix = "";
                    $idType = "UNKNOWN";
                }

                $format = $config['format']['output'];
                $newName = str_replace(
                    ['{PREFIX}', '{ID}', '{TIMESTAMP}', '{.ext}'],
                    [$prefix, $patientID, $originalTimestamp, $ext],
                    $format
                );

                $destination = $outFolderPath . '/' . $newName;
                $backupDestination = $backupFolderPath . '/' . $fileName;

                if (copy($file, $backupDestination)) {
                    if (rename($file, $destination)) {
                        Logger::info("ประมวลผลไฟล์สำเร็จและสำรองข้อมูลแล้ว", [
                            "original" => $fileName,
                            "new_name" => $newName,
                            "type" => $idType
                        ]);
                    } else {
                        throw new Exception("ไม่สามารถย้ายไฟล์ไปที่ Out Folder ได้");
                    }
                } else {
                    throw new Exception("ไม่สามารถคัดลอกไฟล์ไปที่ Backup Folder ได้");
                }
            } catch (Exception $e) {
                Logger::error($e, ["file" => $fileName]);
                $failedDestination = $failedFolderPath . '/' . $fileName;
                @rename($file, $failedDestination);
            }
        }
        deleteOldFiles($outFolderPath, 1);
        deleteOldFiles($backupFolderPath, 1);
        deleteOldFiles($deadLetterPath, 1);
        deleteOldFiles(dirname(__DIR__ . '/' . $config['filepath']['logs']), 1);
        deleteOldFiles(dirname(__DIR__ . '/' . $config['filepath']['logs_err']), 1);
    }

    sleep(5);
}
