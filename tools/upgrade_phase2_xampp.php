<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
define('STUDIO_ROOT','C:/xampp/studio-private');
$config=require STUDIO_ROOT.'/config.php';
foreach(['helpers','Db','Security','Integrations'] as $file)require STUDIO_ROOT.'/app/'.$file.'.php';
Studio\IntegrationSettings::migrate();
if(Studio\IntegrationSettings::get('meta_client_id')==='')Studio\IntegrationSettings::put('meta_client_id','1169432712613004');
echo 'Phase 2 schema installed. Existing content, config and media preserved.'.PHP_EOL;
echo 'Publishing enabled: '.(Studio\IntegrationSettings::get('publishing_enabled',false)?'yes':'no').PHP_EOL;
