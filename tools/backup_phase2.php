<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
$folder = str_replace('\\','/', $argv[1] ?? '');
if (!str_starts_with($folder,'C:/xampp/studio-backups/phase2-') || !is_dir($folder)) { throw new RuntimeException('Invalid backup folder'); }
$c = require 'C:/xampp/studio-private/config.php'; $d=$c['db'];
$pdo = new PDO('mysql:host='.$d['host'].';port='.($d['port']??3306).';dbname='.$d['name'].';charset=utf8mb4',$d['user'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$file=fopen($folder.'/database-before-phase2.sql','x');
fwrite($file,"SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
$pdo->beginTransaction();
foreach($tables as $table){
    if (!preg_match('/^[a-z0-9_]+$/i',$table)) { throw new RuntimeException('Unexpected table name'); }
    $create=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    fwrite($file,$create.";\n");
    foreach($pdo->query('SELECT * FROM `'.$table.'`',PDO::FETCH_ASSOC) as $row){
        $values=array_map(fn($v)=>$v===null?'NULL':$pdo->quote((string)$v),array_values($row));
        fwrite($file,'INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',$values).");\n");
    }
}
$pdo->commit(); fwrite($file,"SET FOREIGN_KEY_CHECKS=1;\n"); fclose($file);
echo 'Database backup saved; '.count($tables).' tables. No credentials printed.'.PHP_EOL;
