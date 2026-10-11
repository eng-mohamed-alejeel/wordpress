<?php
if ( PHP_SAPI !== 'cli' || 2 !== $argc ) { exit(1); }
define( 'ABSPATH', dirname(__DIR__).'/' );
require ABSPATH.'wp-content/plugins/auto-dealership-core/src/Inventory/OnyxWorkbook.php';
$rows=\AutoDealership\Inventory\OnyxWorkbook::read($argv[1]);
$keys=array();$duplicates=array();$lengths=array();$brands=array();$warehouses=array();$empty=array();foreach($rows as $line=>$row){if(1===$line){continue;}$key=$row['A']??'';if(!$key){$empty[]=$line;continue;}if(isset($keys[$key])){$duplicates[]=array($keys[$key],$line,$key);}$keys[$key]=$line;$lengths[strlen($key)]=($lengths[strlen($key)]??0)+1;$brands[$row['B']??'']=($brands[$row['B']??'']??0)+1;$warehouses[$row['I']??'']=($warehouses[$row['I']??'']??0)+1;}
echo json_encode(array('row_count'=>count($rows),'unique_identifiers'=>count($keys),'duplicates'=>$duplicates,'identifier_lengths'=>$lengths,'brands'=>$brands,'warehouses'=>$warehouses,'empty_identifier_rows'=>$empty,'sample'=>array_slice($rows,0,3,true)),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
