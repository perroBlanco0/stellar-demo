<?php declare(strict_types=1);

// Crea/recrea data/caja.sqlite con datos de ejemplo, para que el frontend
// (Angular) tenga algo real que mostrar mientras el backend en Render con
// Postgres no esta listo. Correr con:
//   php -d extension=pdo_sqlite -d extension=sqlite3 seed.php

require __DIR__ . '/vendor/autoload.php';

$dbPath = __DIR__ . '/data/caja.sqlite';
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data');
}
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));

$pdo->exec("
    INSERT INTO cajas (id, nombre, curso, public_key, umbral) VALUES
    (1, 'Caja 4to Medio B', '4to Medio B', 'GBTCGILZACQIAXCCNBWXXPVVRRRSSJTPKA6BESXZA2JVCU25I3ZLFDKV', 2)
");

$pdo->exec("
    INSERT INTO members (caja_id, nombre, public_key) VALUES
    (1, 'Josue Valenzuela', 'GB2KBORZWIBQMWHPJJAQPZUUCBU6263H4ZBAUEEJZ463MP7CKDUQWUKU'),
    (1, 'Tomas B.', 'GASV6TPTNSDV66YD7V7XW6XZU32XEHWSICRU6GWHX4XAZE5ZF75XUZT7'),
    (1, 'Bryan (Directiva)', 'GDN4N3XXUXY5NWWY5XNQYDUQTBGQSXGYRO2EHOMM7M3PFHEY5XWOB3B3')
");

$pdo->exec("
    INSERT INTO proposals (caja_id, destino, monto, motivo, xdr, estado) VALUES
    (1, 'GDN4N3XXUXY5NWWY5XNQYDUQTBGQSXGYRO2EHOMM7M3PFHEY5XWOB3B3', '20', 'Arriendo de cancha', 'AAAAAgAAAAA...MOCK...', 'pendiente')
");

$pdo->exec("
    INSERT INTO proposal_signatures (proposal_id, member_id, firmado_en) VALUES
    (1, 1, CURRENT_TIMESTAMP)
");

echo "Seed listo en $dbPath\n";
echo "1 caja, 3 members, 1 proposal (con 1 de 2 firmas ya reunidas)\n";
