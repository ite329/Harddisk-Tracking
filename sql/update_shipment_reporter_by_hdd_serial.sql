-- แก้ไขชื่อผู้แจ้ง/ผู้บันทึกใน harddisk_shipments โดยจับคู่จาก hdd_serial
-- สร้างจาก shipment_import.csv จำนวน 94 Serial ไม่ซ้ำ
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE harddisk_shipments
SET reported_by = CASE UPPER(TRIM(hdd_serial))
    WHEN 'WWD2FH7H' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH7E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3M' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH7V' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH24' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3J' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH7L' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN3F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH7Z' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3Q' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH78' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN5J' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH7R' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN42' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH7Q' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN46' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNA5' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNA3' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF45' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNAB' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNB5' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNB0' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN8X' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN5L' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN6A' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN4V' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF5P' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN9F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN7A' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN92' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN9C' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FF5T' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN1H' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN1G' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3P' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN8P' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN48' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3D' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF40' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN52' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FFD4' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF47' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF42' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN4P' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN4F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF4B' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF43' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN5E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN55' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN6J' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN10' THEN 'นายกฤษณะ พันชื่น'
    WHEN 'WWD2FN53' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN6K' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN4D' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN57' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN5P' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF4E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN9E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN9Z' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH6A' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH5C' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN2C' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH6P' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FD7F' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH5F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH99' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH62' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH6K' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH5L' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH0R' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH65' THEN 'นาย เสกสรร จันทร์มาก'
    WHEN 'WWD2FH66' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH5G' THEN 'นายกฤษณะ พันชื่น'
    WHEN 'WWD2FH6L' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH17' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH5E' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH1W' THEN 'นาย อานันท์ โนนดงกลาง'
    WHEN 'WWD2FH5Q' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH5R' THEN 'นายฐานิต ฉันทะประเสริฐ'
    WHEN 'WWD2FH0T' THEN 'นายกฤษณะ พันชื่น'
    WHEN 'WWD2FN1P' THEN 'นายฐานิต ฉันทะประเสริฐ'
    WHEN 'WWD2FN1W' THEN 'นาย เสกสรร จันทร์มาก'
    WHEN 'WWD2FN1F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN1M' THEN 'นายฐานิต ฉันทะประเสริฐ'
    WHEN 'WWD2FN1E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN27' THEN 'นายวันชัย ระมั่ง'
    WHEN 'WWD2FN40' THEN 'นายวันชัย ระมั่ง'
    WHEN 'WWD2FH25' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN3C' THEN 'นายวันชัย ระมั่ง'
    WHEN 'WWD2FN39' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN1Q' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN25' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN1Z' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN1N' THEN 'นาย อานันท์ โนนดงกลาง'
    ELSE reported_by END,
    created_by = CASE UPPER(TRIM(hdd_serial))
    WHEN 'WWD2FH7H' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH7E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3M' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH7V' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH24' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3J' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH7L' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN3F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH7Z' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3Q' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH78' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN5J' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH7R' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN42' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH7Q' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN46' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNA5' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNA3' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF45' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNAB' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNB5' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FNB0' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN8X' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN5L' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN6A' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN4V' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF5P' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN9F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN7A' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN92' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN9C' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FF5T' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN1H' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN1G' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3P' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN8P' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN48' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN3D' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF40' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN52' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FFD4' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF47' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF42' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN4P' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN4F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF4B' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF43' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN5E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN55' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN6J' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN10' THEN 'นายกฤษณะ พันชื่น'
    WHEN 'WWD2FN53' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN6K' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN4D' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN57' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN5P' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FF4E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN9E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN9Z' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH6A' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH5C' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FN2C' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH6P' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FD7F' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH5F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH99' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH62' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH6K' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH5L' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FH0R' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH65' THEN 'นาย เสกสรร จันทร์มาก'
    WHEN 'WWD2FH66' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH5G' THEN 'นายกฤษณะ พันชื่น'
    WHEN 'WWD2FH6L' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FH17' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH5E' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH1W' THEN 'นาย อานันท์ โนนดงกลาง'
    WHEN 'WWD2FH5Q' THEN 'นายณรงค์เดช แสนทวีสุข'
    WHEN 'WWD2FH5R' THEN 'นายฐานิต ฉันทะประเสริฐ'
    WHEN 'WWD2FH0T' THEN 'นายกฤษณะ พันชื่น'
    WHEN 'WWD2FN1P' THEN 'นายฐานิต ฉันทะประเสริฐ'
    WHEN 'WWD2FN1W' THEN 'นาย เสกสรร จันทร์มาก'
    WHEN 'WWD2FN1F' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN1M' THEN 'นายฐานิต ฉันทะประเสริฐ'
    WHEN 'WWD2FN1E' THEN 'นายกฤษติพงษ์ ภูดินดง'
    WHEN 'WWD2FN27' THEN 'นายวันชัย ระมั่ง'
    WHEN 'WWD2FN40' THEN 'นายวันชัย ระมั่ง'
    WHEN 'WWD2FH25' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN3C' THEN 'นายวันชัย ระมั่ง'
    WHEN 'WWD2FN39' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN1Q' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN25' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN1Z' THEN 'นาย สุริยา อุ่นใจ'
    WHEN 'WWD2FN1N' THEN 'นาย อานันท์ โนนดงกลาง'
    ELSE created_by END
WHERE UPPER(TRIM(hdd_serial)) IN (
    'WWD2FH7H',
    'WWD2FH7E',
    'WWD2FN3M',
    'WWD2FH7V',
    'WWD2FH24',
    'WWD2FN3J',
    'WWD2FH7L',
    'WWD2FN3F',
    'WWD2FH7Z',
    'WWD2FN3Q',
    'WWD2FH78',
    'WWD2FN5J',
    'WWD2FH7R',
    'WWD2FN42',
    'WWD2FH7Q',
    'WWD2FN46',
    'WWD2FNA5',
    'WWD2FNA3',
    'WWD2FF45',
    'WWD2FNAB',
    'WWD2FNB5',
    'WWD2FNB0',
    'WWD2FN8X',
    'WWD2FN5L',
    'WWD2FN6A',
    'WWD2FN4V',
    'WWD2FF5P',
    'WWD2FN9F',
    'WWD2FN7A',
    'WWD2FN92',
    'WWD2FN9C',
    'WWD2FF5T',
    'WWD2FN1H',
    'WWD2FN1G',
    'WWD2FN3P',
    'WWD2FN8P',
    'WWD2FN48',
    'WWD2FN3D',
    'WWD2FF40',
    'WWD2FN52',
    'WWD2FFD4',
    'WWD2FF47',
    'WWD2FF42',
    'WWD2FN4P',
    'WWD2FN4F',
    'WWD2FF4B',
    'WWD2FF43',
    'WWD2FN5E',
    'WWD2FN55',
    'WWD2FN6J',
    'WWD2FN10',
    'WWD2FN53',
    'WWD2FN6K',
    'WWD2FN4D',
    'WWD2FN57',
    'WWD2FN5P',
    'WWD2FF4E',
    'WWD2FN9E',
    'WWD2FN9Z',
    'WWD2FH6A',
    'WWD2FH5C',
    'WWD2FN2C',
    'WWD2FH6P',
    'WWD2FD7F',
    'WWD2FH5F',
    'WWD2FH99',
    'WWD2FH62',
    'WWD2FH6K',
    'WWD2FH5L',
    'WWD2FH0R',
    'WWD2FH65',
    'WWD2FH66',
    'WWD2FH5G',
    'WWD2FH6L',
    'WWD2FH17',
    'WWD2FH5E',
    'WWD2FH1W',
    'WWD2FH5Q',
    'WWD2FH5R',
    'WWD2FH0T',
    'WWD2FN1P',
    'WWD2FN1W',
    'WWD2FN1F',
    'WWD2FN1M',
    'WWD2FN1E',
    'WWD2FN27',
    'WWD2FN40',
    'WWD2FH25',
    'WWD2FN3C',
    'WWD2FN39',
    'WWD2FN1Q',
    'WWD2FN25',
    'WWD2FN1Z',
    'WWD2FN1N'
);

SELECT ROW_COUNT() AS updated_rows;
COMMIT;
