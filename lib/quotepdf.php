<?php
declare(strict_types=1);
namespace Komaroff\B2bAssist;
final class QuotePdf
{
 public static function render(array $q):string{
  $root=defined('KOMPLEKT_SOURCE')?KOMPLEKT_SOURCE:dirname(__DIR__,3);
  require_once $root.'/vendor/autoload.php';
  $esc=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $money=fn($n)=>number_format($n/100,2,',',' ').' ₽';
  $html='<!doctype html><html lang="ru"><meta charset="UTF-8"><style>body{font-family:DejaVu Sans;font-size:10px;color:#22312d}h1{font-size:27px}table{border-collapse:collapse;width:100%;margin:22px 0}th,td{text-align:left;border-bottom:1px solid #ccc;padding:10px 5px}th{background:#f0f4f0}.total{font-size:20px}.note{color:#65736b;font-size:9px;word-wrap:break-word}</style><h1>КОМПЛЕКТ</h1><h2>Учебное коммерческое предложение</h2><p>КП-'.$esc($q['id']).' · '.gmdate('d.m.Y H:i',strtotime($q['created_at'])).' UTC</p><p>'.$esc($q['task']).'</p><table><thead><tr><th>Товар</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>';
  foreach($q['lines'] as $l)$html.='<tr><td>'.$esc($l['name']).'<br>'.$esc($l['sku']).'</td><td>'.$l['quantity'].'</td><td>'.$money($l['price_minor']).'</td><td>'.$money($l['sum_minor']).'</td></tr>';
  $html.='</tbody></table><p class="total">Итого: '.$money($q['total_minor']).'</p><p>Цены без НДС. Предложение действует до '.gmdate('d.m.Y H:i',$q['expires_at']).' UTC.</p><p class="note">Авторский учебный проект Сергея Комарова. Вымышленные товары, цены и компании. Не является офертой, счётом или подтверждением реальной поставки.</p><p class="note">Версия: '.$esc($q['revision']).'</p></html>';
  $options=new \Dompdf\Options(['defaultFont'=>'DejaVu Sans','isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false,'chroot'=>$root.'/vendor/dompdf']);
  $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();return $pdf->output();
 }
}
