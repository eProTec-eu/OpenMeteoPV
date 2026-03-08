<?php
/**
 * Highcharts-Ansicht für OpenMeteoPV
 * - Prognose (gesamt + je String)
 * - Ist-Leistung aus Archiv
 * - Optional Polar-Chart: Horizon-Maske + Sonnenbahn
 */

// TODO: IDs anpassen
$instanceId_OpenMeteoPV = 11111;   // Instanz-ID deines OpenMeteoPV-Moduls
$archiveId              = 22222;   // Archiv-Instanz-ID
$varId_PVPower          = 33333;   // Variablen-ID der geloggten PV-Ist-Leistung (W)
$varId_HtmlBox          = 44444;   // String-Variable (~HTMLBox)

// 1) Forecast gesamt
$vidForecastTotal = @IPS_GetObjectIDByIdent('ForecastJSON', $instanceId_OpenMeteoPV);
if (!$vidForecastTotal) { echo "ForecastJSON (gesamt) nicht gefunden"; return; }
$forecastTotal = json_decode(GetValue($vidForecastTotal), true);
if (!is_array($forecastTotal) || empty($forecastTotal)) { echo "ForecastJSON leer"; return; }

$now = time();
$from = strtotime(date('Y-m-d 00:00:00', strtotime('-1 day', $now)));
$to   = strtotime(date('Y-m-d 23:59:59', strtotime('+2 day', $now)));

// 2) Ist-Leistung
$seriesIst = [];
if ($varId_PVPower > 0) {
    $raw = AC_GetLoggedValues($archiveId, $varId_PVPower, $from, $to, 0);
    foreach ($raw as $row) $seriesIst[] = [ $row['TimeStamp']*1000, (float)$row['Value'] ];
}

// 3) Prognose gesamt
$seriesForecastTotal = [];
foreach ($forecastTotal as $pt) if (isset($pt['t'],$pt['p_w'])) $seriesForecastTotal[] = [ strtotime($pt['t'])*1000, (float)$pt['p_w'] ];

// 4) Prognose je String
$children = IPS_GetChildrenIDs($instanceId_OpenMeteoPV);
$seriesByArray = [];
foreach ($children as $cid) {
    $obj = IPS_GetObject($cid);
    $ident = $obj['ObjectIdent'] ?? '';
    if (substr($ident, -12) === '_ForecastJSON') {
        $name = preg_replace('/_\d+$/','', str_replace('_ForecastJSON','',$ident));
        $json = json_decode(GetValue($cid), true) ?: [];
        $s = [];
        foreach ($json as $pt) if (isset($pt['t'],$pt['p_w'])) $s[] = [ strtotime($pt['t'])*1000, (float)$pt['p_w'] ];
        if ($s) $seriesByArray[$name] = $s;
    }
}

// 5) Horizon-Serien (optional) + Sonnenbahn
$horizonSeries = [];
foreach ($children as $cid) {
    $obj = IPS_GetObject($cid);
    $ident = $obj['ObjectIdent'] ?? '';
    if (substr($ident, -12) === '_HorizonJSON') {
        $name = preg_replace('/_\d+$/','', str_replace('_HorizonJSON','',$ident));
        $json = json_decode(GetValue($cid), true) ?: [];
        $pts = [];
        foreach ($json as $p) if (isset($p['az'],$p['el'])) $pts[] = [(float)$p['az'], (float)$p['el']];
        if ($pts) $horizonSeries[$name] = $pts;
    }
}

function solarPosApproxDeg(int $ts, float $latDeg, float $lonDeg): array {
    $d = ($ts - 946684800) / 86400.0;
    $L = fmod(280.46 + 0.9856474 * $d, 360.0);
    $g = fmod(357.528 + 0.9856003 * $d, 360.0);
    $lambda = $L + 1.915 * sin(deg2rad($g)) + 0.020 * sin(2*deg2rad($g));
    $epsilon = 23.439 - 0.0000004 * $d;
    $RA = rad2deg(atan2(cos(deg2rad($epsilon))*sin(deg2rad($lambda)), cos(deg2rad($lambda))));
    $dec = rad2deg(asin(sin(deg2rad($epsilon))*sin(deg2rad($lambda))));
    $GMST = fmod(18.697374558 + 24.06570982441908 * $d, 24.0);
    $LST = $GMST*15.0 + $lonDeg;
    $HA = $LST - $RA;
    $x = cos(deg2rad($HA))*cos(deg2rad($dec));
    $y = sin(deg2rad($HA))*cos(deg2rad($dec));
    $z = sin(deg2rad($dec));
    $xhor = $x * sin(deg2rad($latDeg)) - $z * cos(deg2rad($latDeg));
    $yhor = $y;
    $zhor = $x * cos(deg2rad($latDeg)) + $z * sin(deg2rad($latDeg));
    $azimuth = rad2deg(atan2($yhor, $xhor)) + 180.0; // 0..360, 0=Süd
    $zenith  = rad2deg(acos($zhor));
    $elev    = 90.0 - $zenith;
    $az_rel = $azimuth - 180.0; if ($az_rel > 180) $az_rel -= 360; if ($az_rel < -180) $az_rel += 360;
    return ['az' => $az_rel, 'el' => $elev];
}

$lat = IPS_GetProperty($instanceId_OpenMeteoPV, 'Latitude');
$lon = IPS_GetProperty($instanceId_OpenMeteoPV, 'Longitude');
$today = strtotime(date('Y-m-d 00:00:00'));
$solarPath = [];
for ($t = $today + 4*3600; $t <= $today + 21*3600; $t += 900) {
    $pos = solarPosApproxDeg($t, (float)$lat, (float)$lon);
    if ($pos['el'] > 0) $solarPath[] = [ $pos['az'], $pos['el'] ];
}

// Chart 1 Konfiguration
$cfg = [
  'title' => ['text' => 'PV-Prognose & Ist'],
  'chart' => ['zoomType' => 'x'],
  'time'  => ['useUTC' => false],
  'xAxis' => [[
    'type' => 'datetime',
    'plotLines' => [[
      'value' => $now * 1000,
      'color' => '#d00', 'width' => 1, 'zIndex' => 5,
      'label' => ['text' => 'Jetzt', 'rotation' => 0, 'y' => -5, 'style' => ['color' => '#d00']]
    ]]
  ]],
  'yAxis' => [[ 'title' => ['text' => 'Leistung [W]'] ]],
  'legend' => ['enabled' => true],
  'tooltip' => ['shared' => true, 'xDateFormat' => '%e.%m.%Y %H:%M'],
  'series' => []
];

if ($seriesIst) $cfg['series'][] = ['name'=>'Ist (PV)','type'=>'line','data'=>$seriesIst,'color'=>'#0066cc','lineWidth'=>1.5];
$cfg['series'][] = ['name'=>'Prognose gesamt','type'=>'areaspline','data'=>$seriesForecastTotal,'color'=>'#66cc66','fillOpacity'=>0.25,'lineWidth'=>2];
$colors = ['#ff9933','#cc66cc','#66cccc','#cc6666','#66cc99','#9999ff']; $ci=0;
foreach ($seriesByArray as $name=>$s) {
    $cfg['series'][] = ['name'=>"Prognose $name",'type'=>'spline','data'=>$s,'dashStyle'=>'ShortDash','color'=>$colors[$ci % count($colors)],'lineWidth'=>1.5];
    $ci++;
}
$cfg['xAxis'][0]['plotBands'] = [[ 'from' => $now*1000, 'to' => $to*1000, 'color' => 'rgba(200,200,200,0.10)' ]];

$hcJson1 = json_encode($cfg);

$makePolar = !empty($horizonSeries) || !empty($solarPath);
$polarJson = 'null';
if ($makePolar) {
    $polar = [
      'chart' => ['polar' => true],
      'title' => ['text' => 'Horizon-Maske & Sonnenbahn (heute)'],
      'pane'  => ['startAngle' => 0, 'endAngle' => 360],
      'xAxis' => ['tickInterval'=>30, 'min'=>-180, 'max'=>180, 'labels'=>['formatter'=>'function(){return this.value+"°";}']],
      'yAxis' => ['min'=>0, 'max'=>60, 'labels'=>['formatter'=>'function(){return this.value+"°";}']],
      'legend'=> ['enabled'=>true],
      'series'=> []
    ];
    $ci=0; foreach ($horizonSeries as $name=>$pts) {
        $polar['series'][] = ['name'=>"Horizon $name", 'type'=>'line', 'data'=>$pts, 'color'=>$colors[$ci % count($colors)], 'marker'=>['enabled'=>false]]; $ci++; }
    if ($solarPath) $polar['series'][] = ['name'=>'Sonnenbahn','type'=>'line','data'=>$solarPath,'color'=>'#ffaa00','marker'=>['enabled'=>false],'lineWidth'=>2];
    $polarJson = json_encode($polar);
}

$html = '<div id="hc_ompv_1" style="width:100%; height:420px; margin-bottom:12px;"></div>';
$html .= '<div id="hc_ompv_2" style="width:100%; height:320px; display:' . ($makePolar?'block':'none') . ';"></div>';
$html .= '<script src="https://code.highcharts.com/highcharts.js"></script>';
$html .= '<script src="https://code.highcharts.com/highcharts-more.js"></script>';
$html .= '<script>(function(){ Highcharts.chart("hc_ompv_1", ' . $hcJson1 . '); ';
$html .= 'var pc = ' . $polarJson . '; if (pc) { Highcharts.chart("hc_ompv_2", pc); }})();</script>';

SetValueString($varId_HtmlBox, $html);
