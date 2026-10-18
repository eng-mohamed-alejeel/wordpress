<?php
/** Pure logo geometry: no files are resized and no media settings are changed. */
define('ABSPATH',__DIR__);require dirname(__DIR__).'/src/Core/BrandLogo.php';use AutoDealership\Core\BrandLogo;
$cases=array(array(1754,897,180,48,array(94,48)),array(1754,897,180,40,array(78,40)),array(1754,897,300,180,array(300,153)),array(300,153,148,100,array(148,75)),array(1000,1000,180,48,array(48,48)),array(10000,100,180,48,array(180,2)),array(0,0,180,48,array(180,45)),array(64,32,180,48,array(64,32)),array(100,10000,180,48,array(1,48)));
foreach($cases as $case){$expected=array_pop($case);$actual=BrandLogo::dimensions(...$case);if($actual!==$expected||$actual[0]>$case[2]||$actual[1]>$case[3]){throw new RuntimeException('Logo geometry regression: '.json_encode($case));}}echo "9 bounded logo geometry checks passed.\n";
