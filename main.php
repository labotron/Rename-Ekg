<?php
require_once __DIR__ . '/Logger.php';
date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function getYamlConfig($filePath)
{
    $config = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $currentSection = '';
    foreach ($lines as $line) {
        if (preg_match('/^([a-z0-9_]+):/i', $line, $matches)) {
            $currentSection = $matches[1];
        } elseif (preg_match('/^\s+([a-z0-9_]+):\s*(.+)$/i', $line, $matches) && $currentSection) {
            $key = $matches[1];
            $val = trim($matches[2], " '\"");
            $config[$currentSection][$key] = $val;
        }
    }
    return $config;
}

// ปรับปรุงฟังก์ชันให้รองรับการลบไฟล์ตามจำนวนวัน ($daysOld)
function deleteOldFiles(string $directory, int $daysOld = 7)
{
    if (!is_dir($directory)) return;
    $files = glob($directory . '/*');
    $thresholdTime = strtotime("-{$daysOld} days");

    foreach ($files as $file) {
        if (is_file($file)) {
            $fileTimestamp = filemtime($file);
            if ($fileTimestamp < $thresholdTime) {
                if (unlink($file)) {
                    Logger::info("ลบไฟล์เก่าอายุเกิน {$daysOld} วัน สำเร็จ", ["deleted_file" => basename($file)]);
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

// ฟังก์ชันเช็คและจัดการ Path
function getProperPath($baseDir, $pathValue)
{
    // เช็คว่าเป็น Drive Letter (เช่น W:\) หรือ UNC Network Path (เช่น \\192.168...) หรือไม่
    if (preg_match('/^([a-zA-Z]:\\\\|\\\\\\\\|\/)/', $pathValue)) {
        return $pathValue; // ถ้าเป็น Absolute/Network Path ให้ใช้ค่านั้นตรงๆ
    }
    return $baseDir . '/' . $pathValue; // ถ้าเป็นโฟลเดอร์ทั่วไป ให้ต่อท้าย Directory ปัจจุบัน
}

$watcherPath = getProperPath(__DIR__, $config['filepath']['watcher']);
$outFolderPath = getProperPath(__DIR__, $config['filepath']['out_path']);
$backupFolderPath = getProperPath(__DIR__, $config['filepath']['backup']);
$failedFolderPath = getProperPath(__DIR__, $config['filepath']['failed']);
$deadLetterPath = getProperPath(__DIR__, $config['filepath']['dead_letter'] ?? 'dead_letter');

foreach ([$watcherPath, $outFolderPath, $backupFolderPath, $failedFolderPath, $deadLetterPath] as $path) {
    // ใช้ strpos แทน str_starts_with เพื่อให้รองรับ PHP ต่ำกว่าเวอร์ชั่น 8.0
    if (strpos($path, '\\\\') !== 0) {
        if (!is_dir($path)) mkdir($path, 0777, true);
    }
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

            // --- ป้องกันไฟล์ 0 KB หรือไฟล์ที่ยังเขียนไม่เสร็จ ---
            if (is_file($file)) {
                $size1 = filesize($file);
                // ถ้าน้ำหนักเป็น 0 ให้ข้ามลูปนี้ไปก่อน รอรอบถัดไป
                if ($size1 === 0) {
                    continue;
                }

                // หน่วงเวลาสั้นๆ เพื่อเช็คว่าโปรแกรมอื่นกำลังเขียนไฟล์อยู่หรือไม่
                clearstatcache(true, $file);
                sleep(2);
                $size2 = filesize($file);

                // ถ้าขนาดไฟล์ยังเปลี่ยนอยู่ แปลว่าไฟล์ยังเขียนไม่เสร็จ ให้ข้ามไปก่อน
                if ($size1 !== $size2) {
                    continue;
                }
            }
            // -----------------------------------------------------------

            try {
                $rawFileName = pathinfo($file, PATHINFO_FILENAME);
                $extBase = pathinfo($file, PATHINFO_EXTENSION);
                $ext = "." . $extBase;

                $parts = explode('_', $rawFileName);
                $rawID = $parts[0];
                $patientID = preg_replace('/[^0-9]/', '', $rawID);
                $len = strlen($patientID);
                $firstChar = strtolower(substr($rawID, 0, 1));

                // 1. ค้นหา fff (Sequence 3 หลัก) และ Datetime (14 หลัก) จากชื่อไฟล์เดิม
                $fff = '000';
                $datetime = '';
                $lastPart = end($parts);

                // ถ้าตัวท้ายเป็นตัวเลข 1-3 หลัก (เช่น 1, 2, 3) ให้เติม 0 ให้ครบ 3 หลัก
                if (preg_match('/^\d{1,3}$/', $lastPart)) {
                    $fff = str_pad($lastPart, 3, "0", STR_PAD_LEFT);
                    // ถอยไปดูท่อนก่อนหน้า ว่าเป็นวันที่ 14 หลักหรือไม่ (เช่น 20260508160922)
                    $prevPart = prev($parts);
                    if ($prevPart !== false && preg_match('/^\d{14}$/', $prevPart)) {
                        $datetime = $prevPart;
                    }
                } elseif (preg_match('/^\d{14}$/', $lastPart)) {
                    // ถ้าตัวท้ายเป็นวันที่ 14 หลักพอดี (ไม่มี sequence ต่อท้าย)
                    $datetime = $lastPart;
                }

                // ถ้าในชื่อไฟล์ไม่มีวันที่ 14 หลัก ให้ดึงเอาวันที่สร้างไฟล์มาใช้แทน (YYYYMMDDHHNNSS)
                if (empty($datetime)) {
                    $datetime = date('YmdHis', filemtime($file));
                }

                // 2. ดึงค่าความยาวและ Prefix จาก configs.yaml
                $lenCID = isset($config['length_config']['cid_len']) ? (int)$config['length_config']['cid_len'] : 13;
                $lenVN  = isset($config['length_config']['vn_len']) ? (int)$config['length_config']['vn_len'] : 12;
                $lenHN  = isset($config['length_config']['hn_len']) ? (int)$config['length_config']['hn_len'] : 9;
                $lenAN  = isset($config['length_config']['an_len']) ? (int)$config['length_config']['an_len'] : 6;

                // ดึงค่าเป้าหมายการเติมศูนย์จาก config (ถ้าไม่ได้ตั้งไว้ให้ fallback เป็น 9)
                $padTarget = isset($config['length_config']['pad_len']) ? (int)$config['length_config']['pad_len'] : 9;

                // --- ตรวจสอบเงื่อนไขและกำหนด Prefix & Padding ---
                if ($len === $lenCID) {
                    $prefix = $config['prefix_config']['cid_prefix'] ?? "c";
                } elseif ($len === $lenVN) {
                    $prefix = $config['prefix_config']['vn_prefix'] ?? "";
                } elseif ($len === $lenHN && ($firstChar === 'h' || $firstChar === 'H')) {
                    // 1. ตรวจสอบว่าเป็น HN แท้ๆ: ความยาวตรง และ "มี" ตัว 'h' นำหน้า
                    $prefix = $config['prefix_config']['hn_prefix'] ?? "h";
                    $patientID = str_pad($patientID, $padTarget, "0", STR_PAD_LEFT);
                } elseif ($len === $lenAN || $len === $lenHN) {
                    // 2. ปัดเป็น AN หากความยาวตรง AN (เช่น 6 ตัว) หรือความยาวตรง HN (7 ตัว) แต่ไม่มี 'h' นำหน้า
                    $prefix = $config['prefix_config']['an_prefix'] ?? "";
                    $patientID = str_pad($patientID, $padTarget, "0", STR_PAD_LEFT);
                } else {
                    $prefix = ($firstChar === 'h' || $firstChar === 'H') ? "h" : "";
                    $patientID = str_pad($patientID, $padTarget, "0", STR_PAD_LEFT);
                }

                // 3. รวม Datetime + Sequence สำหรับรูปภาพ
                $combined_datetime_fff = $datetime . $fff;

                // ดึง format ปัจจุบันที่ตั้งไว้
                $format = $config['format']['output'] ?? "{PREFIX}{ID}_{fff}{.ext}";
                $format = str_replace('{DATETIME}', '', $format);

                // 4. แทนที่ตัวแปรเพื่อสร้างชื่อไฟล์ใหม่ 
                if (strtolower($extBase) === 'pdf') {
                    // สำหรับ PDF: รูปแบบ YYYYMMDD_HHMMSS (เช่น 123456_20260807_093015.pdf)
                    if (strlen($datetime) === 14) {
                        $formattedDate = substr($datetime, 0, 8) . '_' . substr($datetime, 8, 6);
                    } else {
                        $formattedDate = date('Ymd_His', filemtime($file));
                    }

                    $newName = $prefix . $patientID . '_' . $formattedDate . '.pdf';
                } else {
                    // สำหรับ Image / ไฟล์อื่นๆ ใช้ตาม Pattern Config
                    $newName = str_replace(
                        ['{PREFIX}', '{ID}', '{fff}', '{.ext}'],
                        [$prefix, $patientID, $combined_datetime_fff, $ext],
                        $format
                    );
                }

                $destination = $outFolderPath . DIRECTORY_SEPARATOR . $newName;
                $backupDestination = $backupFolderPath . DIRECTORY_SEPARATOR . $fileName;

                if (copy($file, $backupDestination)) {
                    // เปลี่ยนจาก rename เป็น copy แล้ว unlink เพื่อแก้ปัญหาข้าม Network Drive
                    if (copy($file, $destination)) {
                        unlink($file); // ก๊อปปี้ไป Network สำเร็จ ค่อยลบไฟล์ต้นทางทิ้ง

                        Logger::info("ประมวลผลไฟล์สำเร็จและสำรองข้อมูลแล้ว", [
                            "original" => $fileName,
                            "new_name" => $newName
                        ]);
                    } else {
                        throw new Exception("ไม่สามารถย้ายไฟล์ไปที่ Out Folder (Network) ได้");
                    }
                } else {
                    throw new Exception("ไม่สามารถคัดลอกไฟล์ไปที่ Backup Folder ได้");
                }
            } catch (Exception $e) {
                $failedDest = $failedFolderPath . DIRECTORY_SEPARATOR . $fileName;
                if (@rename($file, $failedDest)) {
                    Logger::error("ประมวลผลไฟล์ล้มเหลว: " . $e->getMessage(), ["file" => $fileName]);
                }
            }
        }

        // --- ลบไฟล์ Backup, Dead Letter และ Log เก่าที่อายุเกิน 7 วัน ---
        $retentionDays = isset($config['retention_config']['retention_days']) ? (int)$config['retention_config']['retention_days'] : 7;

        deleteOldFiles($outFolderPath, $retentionDays);
        deleteOldFiles($backupFolderPath, $retentionDays);
        deleteOldFiles($deadLetterPath, $retentionDays);
        deleteOldFiles(dirname(__DIR__ . '/' . $config['filepath']['logs']), $retentionDays);
        deleteOldFiles(dirname(__DIR__ . '/' . $config['filepath']['logs_err']), $retentionDays);
    }

    sleep(5);
}
