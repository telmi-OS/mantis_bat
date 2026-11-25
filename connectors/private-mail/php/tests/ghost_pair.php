<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/GhostClient.php';
use MantisBat\PrivateMail\GhostClient;
$worker=['data'=>['ghost_uuid'=>'worker','agentic_enabled'=>true,'autonomy_enabled'=>false]];
$autonomous=['data'=>['ghost_uuid'=>'autonomous','agentic_enabled'=>true,'autonomy_enabled'=>true]];
GhostClient::validatePair($worker,$autonomous);
foreach ([$worker, ['data'=>['ghost_uuid'=>'other','agentic_enabled'=>true,'autonomy_enabled'=>false]]] as $invalid) {
    try { GhostClient::validatePair($worker,$invalid); throw new LogicException('Invalid Ghost pair was accepted.'); }
    catch (RuntimeException $expected) {}
}
echo "Ghost pair checks passed.\n";
