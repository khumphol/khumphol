#!/bin/bash
# สร้างฐานข้อมูล aleanor_cloud บน MAMP แล้วนำเข้าสคีมา + ข้อมูลตัวอย่าง
# ใช้: bash sql/install.sh            (สร้างถ้ายังไม่มี)
#      bash sql/install.sh --reset    (ลบทิ้งแล้วสร้างใหม่ — ข้อมูลหายหมด)
set -e
cd "$(dirname "$0")"
DB=${DB_NAME:-aleanor_cloud}
MYSQL=${MYSQL:-/Applications/MAMP/Library/bin/mysql80/bin/mysql}
M=("$MYSQL" -uroot -proot -h127.0.0.1 -P8889 --default-character-set=utf8mb4)
if [ "$1" == "--reset" ]; then "${M[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`" 2>/dev/null; fi
"${M[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci" 2>/dev/null
for f in 0*.sql; do echo "→ $f"; "${M[@]}" "$DB" < "$f" 2> >(grep -v "Using a password" >&2); done
echo "เสร็จ: ฐานข้อมูล $DB"
