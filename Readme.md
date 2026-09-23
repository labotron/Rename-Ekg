### system requirements

- windows >= 10 64 bit

### configs attribute

```yml
filepath:
  logs: "loggers/logs.log"
  logs_err: "logs/logs_err.log"
  out_path: "HOSxP" #สำหรับ foldor ที่ Rename เส็จเเล้วจะไปวางนี่ Foldor นี้  .jpg , .pdf
  watcher: "received" #สำหรับ foldor อ่านรูปภาพ .jpg , .pdf
  backup: "backup" #สำหรับ foldor Backup .jpg , .pdf  มีอายุเก็บไฟล์ 1 เดือนหลังจากนั้นจะ Delete Auto
  failed: "failed" #สำหรับ foldor รูปภาพที่ Rename ไม่สำเร็จ  รอดูอาการและสามารถทำงานต่อได้
format:
  output: "{PREFIX}{ID}_{fff}{.ext}"

# กำหนดความยาว (len) ของ ID แต่ละประเภท
length_config:
  cid_len: 13
  vn_len: 12
  hn_len: 7
  an_len: 9

# กำหนดตัวอักษรนำหน้า (Prefix)
prefix_config:
  cid_prefix: "c"
  vn_prefix: ""
  hn_prefix: "h"
  an_prefix: ""
```

year: YYYY or YY

### install Rename-NST middleware

- 1. check partfile configs.yml
- 2. คลิกขวา run as adminnistrator at `install.bat`
- 3. คลิกขวา remove service as adminnistrator at `uninstall.bat`

Watcher ➡️ (สำเร็จ) ➡️ Out & Backup
Watcher ➡️ (พัง) ➡️ Failed ➡️ (รอ 5 นาที) ➡️ เด้งกลับไป Watcher (Retry)
Failed ➡️ (วนพังซ้ำๆ เกิน 24 ชม.) ➡️ Dead Letter ➡️ รอ 30 วันลบทิ้งอัตโนมัติ
