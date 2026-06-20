<?php
// 武器库 - 复刻 CS2 主要枪械参数
return [
    'ak47' => ['name'=>'AK-47', 'side'=>'T', 'price'=>2700, 'damage'=>36, 'headshot'=>120, 'armorPen'=>0.77, 'fireRate'=>100, 'magazine'=>30, 'reloadTime'=>2500, 'spread'=>0.03, 'moveSpread'=>0.12, 'airSpread'=>0.25, 'bulletSpeed'=>1200, 'bulletDrop'=>0.002, 'auto'=>true],
    'm4a4' => ['name'=>'M4A4',  'side'=>'CT','price'=>3100, 'damage'=>33, 'headshot'=>110, 'armorPen'=>0.7,  'fireRate'=>90,  'magazine'=>30, 'reloadTime'=>2400, 'spread'=>0.025,'moveSpread'=>0.08, 'airSpread'=>0.2,  'bulletSpeed'=>1250, 'bulletDrop'=>0.002, 'auto'=>true],
    'awp'  => ['name'=>'AWP',   'side'=>'both','price'=>4750, 'damage'=>115,'headshot'=>450, 'armorPen'=>0.97, 'fireRate'=>1500,'magazine'=>10, 'reloadTime'=>3500, 'spread'=>0.01, 'moveSpread'=>0.35, 'airSpread'=>0.5,  'bulletSpeed'=>1500, 'bulletDrop'=>0.001, 'auto'=>false, 'scope'=>true],
    'deagle'=>['name'=>'沙漠之鹰','side'=>'both','price'=>700,  'damage'=>63, 'headshot'=>250, 'armorPen'=>0.93, 'fireRate'=>350, 'magazine'=>7,  'reloadTime'=>2200, 'spread'=>0.05, 'moveSpread'=>0.15, 'airSpread'=>0.3,  'bulletSpeed'=>1400, 'bulletDrop'=>0.0025,'auto'=>false],
    'zeus'  =>['name'=>'电击枪', 'side'=>'both','price'=>200,  'damage'=>9999,'fireRate'=>800, 'magazine'=>1,  'reloadTime'=>1500, 'melee'=>true, 'range'=>180, 'auto'=>false],
    'usp'   =>['name'=>'USP-S', 'side'=>'CT','price'=>200,  'damage'=>35, 'headshot'=>110, 'armorPen'=>0.5,  'fireRate'=>300, 'magazine'=>12, 'reloadTime'=>2200, 'spread'=>0.04, 'moveSpread'=>0.1,  'airSpread'=>0.2,  'bulletSpeed'=>1100, 'bulletDrop'=>0.002, 'auto'=>false],
    'glock' =>['name'=>'Glock-18','side'=>'T','price'=>200,  'damage'=>26, 'headshot'=>80,  'armorPen'=>0.47, 'fireRate'=>250, 'magazine'=>20, 'reloadTime'=>2200, 'spread'=>0.045,'moveSpread'=>0.1,  'airSpread'=>0.2,  'bulletSpeed'=>1100, 'bulletDrop'=>0.002, 'auto'=>false],
    'knife' =>['name'=>'匕首',   'side'=>'both','price'=>0,   'damage'=>55, 'headshot'=>150, 'armorPen'=>0.6,  'fireRate'=>400, 'magazine'=>999,'reloadTime'=>0,    'melee'=>true, 'range'=>80,  'auto'=>false],
    'smoke' =>['name'=>'烟雾弹', 'grenade'=>true, 'fuse'=>3000],
    'flash' =>['name'=>'闪光弹', 'grenade'=>true, 'fuse'=>2000, 'blind'=>3000],
    'he'    =>['name'=>'高爆手雷','grenade'=>true,'fuse'=>1500, 'damage'=>100, 'radius'=>4],
    'molotov'=>['name'=>'燃烧瓶','grenade'=>true,'fuse'=>1500, 'damage'=>20,  'radius'=>3, 'duration'=>7000],
];
